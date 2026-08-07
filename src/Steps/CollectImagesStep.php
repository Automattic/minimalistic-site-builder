<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Warnings;

/**
 * Step (deterministic): collect the AI image placeholders the sections step
 * emitted into the theme markup, so they can be generated.
 *
 * Input:  theme/parts/*.html
 * Output: images.json — an array of image specs, one per unique asset filename:
 *           { filename, src, subject, pageContext, style, aspectRatio, status, sources[] }
 *         plus in-place normalization of malformed AI_IMAGE url/src values to
 *         canonical theme asset paths.
 *
 * Placeholders follow the telex convention: an <img> whose src is a theme-relative
 * "theme:./assets/<name>.jpg" path (".png" for transparent-background assets)
 * and whose alt is "AI_IMAGE: subject | page-context
 * | style | aspect-ratio". The subject describes what to render and from what POV; the
 * page-context describes where/how the image is used (it is context for the generator,
 * not part of the rendered subject — see ImagePromptComposer). This step is
 * deterministic and makes no network calls, so it always runs as part of the
 * build; the heavier GenerateImagesStep is opt-in.
 *
 * It runs BEFORE fix-blocks on purpose: the block re-serializer strips the alt
 * from wp:cover background images, so the AI_IMAGE spec is only intact in the raw
 * section markup. images.json is then the durable record of what to generate.
 *
 * The model sometimes drops the "AI_IMAGE:" spec straight into a wp:cover "url"
 * or a bare <img> src instead of the alt convention. Those values are decoded,
 * collected, and rewritten immediately to a synthetic theme asset path. Doing
 * that before fix-blocks gives every downstream step the same canonical path:
 * block serialization cannot change the later rewrite key, assemble-pages sees
 * a content asset to import, and raw prompt text never reaches final markup.
 */
final class CollectImagesStep implements Step
{
    public const STAGE_TEXTURE_PURPOSE = 'stage-texture-backdrop';

    public function id(): string
    {
        return 'collect-images';
    }

    public function label(): string
    {
        return 'Collect AI image placeholders';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/parts/*', 'theme/theme.json', 'theme/assets/*'],
            writes: [
                'images.json',
                'theme/parts/*',
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        /** @var array<string,array<string,mixed>> $byFilename keyed by filename, deduped */
        $byFilename = [];
        $warnings = [];
        $textureSources = [];
        $textureAlreadyServed = false;
        $reservedOrdinarySources = [];

        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            $parsed = self::parseAndNormalize($content);
            if ($parsed['content'] !== $content) {
                $project->writeText('theme/' . $rel, $parsed['content']);
            }
            if ($parsed['reservedOrdinaryPlaceholder']) {
                $reservedOrdinarySources[] = $rel;
                $warnings[] = "file=" . Warnings::value('theme/' . $rel)
                    . "; block='AI_IMAGE media owner'; authored source="
                    . Warnings::value(GeneratedMarkup::STAGE_TEXTURE_ASSET)
                    . '; delivered source=' . Warnings::value(GeneratedMarkup::STAGE_TEXTURE_ASSET)
                    . '; disposition=reserved ordinary placeholder retained because its parsed media owner '
                    . 'could not be atomically synchronized; code-owned stage texture mapping suppressed';
            }
            if (self::containsCommittedStageTexture($parsed['content'])) {
                $textureSources[] = $rel;
                $textureAlreadyServed = $textureAlreadyServed
                    || preg_match(
                        '~/wp-content/themes/[a-z0-9_-]+/assets/stage_backdrop-texture\.jpg~i',
                        $parsed['content'],
                    ) === 1;
            }
            foreach ($parsed['images'] as $img) {
                $cappedForFooter = false;
                $authoredRatio = $img['aspectRatio'] ?? null;
                if ($rel === 'parts/footer.html'
                    && is_string($authoredRatio)
                    && self::isPortraitAspectRatio($authoredRatio)
                ) {
                    $img['aspectRatio'] = 'square';
                    $cappedForFooter = true;
                    $warnings[] = "file='theme/parts/footer.html'; block='AI_IMAGE placeholder'; asset="
                        . Warnings::value($img['src'] ?? $img['filename'] ?? 'unknown') . '; authored aspect-ratio='
                        . Warnings::value($authoredRatio) . '; delivered aspect-ratio="square"; '
                        . 'disposition=portrait-oriented footer image capped after placeholder recovery '
                        . 'so it cannot stretch the footer band';
                }

                $filename = $img['filename'];
                if (isset($byFilename[$filename])) {
                    // Same asset referenced from another file — just record the source.
                    $byFilename[$filename]['sources'][] = $rel;
                    if ($cappedForFooter) {
                        // A shared asset must use the footer-safe shape even if
                        // an earlier non-footer source introduced it first.
                        $byFilename[$filename]['aspectRatio'] = 'square';
                    }
                    continue;
                }
                $img['sources'] = [$rel];
                $img['status']  = 'pending';
                $byFilename[$filename] = $img;
            }
        }

        // Textured stage canvas (BIGR-776): the header/hero roots reference
        // the texture through a root background style, not an <img>
        // placeholder, so its code-owned spec is synthesized whenever a part
        // carries the exact marker + block-background contract. Ordinary
        // generated media that copies the reserved filename is renamed before
        // this synthesis, so it cannot claim the code-owned slot.
        $textureFilename = basename(GeneratedMarkup::STAGE_TEXTURE_ASSET);
        if ($textureSources !== [] && $reservedOrdinarySources === []) {
            $textureSpec = self::stageTextureSpec(
                $textureSources,
                self::stageTextureTargetColor($project),
            );
            if ($textureAlreadyServed && $project->exists('theme/assets/' . $textureFilename)) {
                $textureSpec['status'] = 'completed';
                $textureSpec['url'] = "/wp-content/themes/{$project->slug()}/assets/{$textureFilename}";
            }
            $byFilename[$textureFilename] = $textureSpec;
        }

        $project->writeJson('images.json', array_values($byFilename));
        $project->addWarnings($this->id(), $warnings);
    }

    /**
     * The code-owned generation spec for the textured stage canvas (BIGR-776).
     * Shared with GenerateImagesStep's backstop: HeaderHeroStep paints the
     * canonical texture path AFTER this step has written images.json on a
     * first full-pipeline run, so the generator synthesizes the same spec
     * when it finds the reference in markup with no spec on file.
     *
     * @param list<string> $sources theme-relative markup paths referencing the texture
     * @param int $attempt 0 for the first generation; retries pass 1, 2, … to
     *        rotate to the next material, since both the recitation filter and
     *        the busyness gate reject stochastically per material
     * @return array<string,mixed>
     */
    public static function stageTextureSpec(array $sources, ?string $targetColor = null, int $attempt = 0): array
    {
        $targetColor = is_string($targetColor) && preg_match('/^#[0-9a-f]{6}$/i', $targetColor) === 1
            ? strtoupper($targetColor)
            : null;
        $tone = $targetColor === null
            ? 'a single quiet pale neutral tone'
            : "a single quiet tone of {$targetColor}, the actual delivered hero surface color";
        // One concrete material per site, not a menu, and no stock-texture
        // vocabulary: a "seamless tone-on-tone surface texture" prompt is
        // stock-photo title language and trips the image model's
        // IMAGE_RECITATION filter, while a specific flat-lit head-on surface
        // generates reliably and still passes the busyness gate.
        // (Macro/close-up wording is deliberately absent too — it invites
        // deep relief and oblique shadows that fail the gate.) The pick keys
        // on the delivered surface color so reruns stay stable.
        $materials = [
            ['handmade cold-pressed paper', 'flattened fiber grain'],
            ['finely troweled lime plaster', 'fine trowel marks'],
            ['tightly woven natural linen', 'flat even weave'],
            ['smoothly honed limestone', 'soft mineral speckle'],
        ];
        [$material, $grain] = $materials[(crc32($targetColor ?? '') + $attempt) % count($materials)];
        return [
            'filename' => basename(GeneratedMarkup::STAGE_TEXTURE_ASSET),
            'src' => GeneratedMarkup::STAGE_TEXTURE_ASSET,
            'subject' => "A flat expanse of {$material} photographed head-on in perfectly even"
                . ' diffuse light, one continuous unbroken surface filling the entire frame and'
                . " continuing uniformly past every edge. The whole surface is {$tone}, its"
                . " {$grain} soft but clearly visible: gentle low-contrast material grain that"
                . ' readable text can sit directly on. No deep relief, no cast shadows, no'
                . ' objects, no lettering, no folds, no cracks, no stains, no vignette.',
            'pageContext' => 'tiled page-canvas texture running behind the site header and the hero copy;'
                . ' it must stay quiet enough that readable text sits directly on it',
            'style' => 'photorealistic',
            'aspectRatio' => 'square',
            'sources' => $sources,
            'status' => 'pending',
            'purpose' => self::STAGE_TEXTURE_PURPOSE,
            'targetColor' => $targetColor,
        ];
    }

    /** @param array<string,mixed> $spec */
    public static function isStageTextureSpec(array $spec): bool
    {
        return ($spec['purpose'] ?? null) === self::STAGE_TEXTURE_PURPOSE
            && ($spec['src'] ?? null) === GeneratedMarkup::STAGE_TEXTURE_ASSET;
    }

    /**
     * True only for the complete code-owned backdrop contract. A plain media
     * reference to a similarly named file must never synthesize a stage tile.
     */
    public static function containsCommittedStageTexture(string $markup): bool
    {
        try {
            $document = BlockMarkup::parse($markup);
        } catch (\Throwable) {
            return false;
        }
        foreach ($document->indices() as $index) {
            $attrs = $document->attrs($index);
            if (!$document->isStructurallySafe($index)
                || !is_array($attrs)
                || !self::isCommittedStageTextureAttrs($attrs)
            ) {
                continue;
            }
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
            $background = is_array($style['background'] ?? null) ? $style['background'] : [];
            $image = is_array($background['backgroundImage'] ?? null) ? $background['backgroundImage'] : [];
            $source = $image['url'] ?? null;
            $end = $document->endOffset($index);
            $start = $document->openingOffset($index);
            if (is_string($source)
                && $end !== null
                && GeneratedMarkup::hasExactStageTextureContract(
                    substr($markup, $start, $end - $start),
                    $source,
                )
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exact delivered hero tone that keeps the opaque tile inside the surface
     * and contrast contracts. Different hero targets are incompatible: the
     * generator then rejects the texture and delivers the solid fallbacks.
     */
    public static function stageTextureTargetColor(Project $project, bool $allMarkup = false): ?string
    {
        if (!$project->exists('theme/theme.json')) {
            return null;
        }
        $palette = ContrastFixStep::paletteMap($project->readJson('theme/theme.json'));
        $targets = [];
        $sawHeroBackdrop = false;
        $unresolvedHeroSurface = false;
        $files = $allMarkup
            ? $project->markupFiles()
            : (glob($project->themePath('parts/*.html')) ?: []);
        foreach ($files as $absolute) {
            $relative = $project->relative($absolute);
            if ($relative === 'theme/parts/header.html') {
                continue;
            }
            $markup = $project->readText($relative);
            try {
                $document = BlockMarkup::parse($markup);
            } catch (\Throwable) {
                continue;
            }
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index);
                if (!$document->isStructurallySafe($index)
                    || !is_array($attrs)
                    || !self::isCommittedStageTextureAttrs($attrs)
                ) {
                    continue;
                }
                $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                $background = is_array($style['background'] ?? null) ? $style['background'] : [];
                $image = is_array($background['backgroundImage'] ?? null) ? $background['backgroundImage'] : [];
                $source = $image['url'] ?? null;
                $end = $document->endOffset($index);
                $start = $document->openingOffset($index);
                if (!is_string($source)
                    || $end === null
                    || !GeneratedMarkup::hasExactStageTextureContract(
                        substr($markup, $start, $end - $start),
                        $source,
                    )
                ) {
                    continue;
                }
                $sawHeroBackdrop = true;
                $color = self::stageSurfaceColor($attrs, $palette);
                if ($color === null) {
                    $unresolvedHeroSurface = true;
                } else {
                    $targets[strtoupper($color)] = true;
                }
            }
        }
        if (!$sawHeroBackdrop) {
            return null;
        }
        return !$unresolvedHeroSurface && count($targets) === 1 ? array_key_first($targets) : null;
    }

    /** @param array<string,mixed> $attrs */
    public static function isCommittedStageTextureAttrs(array $attrs): bool
    {
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
        $background = is_array($style['background'] ?? null) ? $style['background'] : [];
        $image = is_array($background['backgroundImage'] ?? null) ? $background['backgroundImage'] : [];
        $className = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
        $classes = preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return GeneratedMarkup::isStageTextureSource($image['url'] ?? null)
            && in_array(GeneratedMarkup::STAGE_TEXTURE_CLASS, $classes, true);
    }

    /** @param array<string,mixed> $attrs @param array<string,string> $palette */
    private static function stageSurfaceColor(array $attrs, array $palette): ?string
    {
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
        $colorStyle = is_array($style['color'] ?? null) ? $style['color'] : [];
        foreach ([$colorStyle['background'] ?? null, $attrs['backgroundColor'] ?? null] as $value) {
            $hex = ContrastFixStep::paletteHex($palette, $value);
            if ($hex !== null) {
                return $hex;
            }
        }
        return null;
    }

    /** Theme-relative paths of every markup file that may hold image placeholders. */
    private function themeHtmlFiles(Project $project): array
    {
        $files = [];
        foreach (glob($project->themePath('parts/*.html')) ?: [] as $abs) {
            $files[] = 'parts/' . basename($abs);
        }
        return $files;
    }

    /**
     * Extract image specs from one markup file. Matches <img> tags whose alt
     * begins with the AI_IMAGE marker; the filename comes from the src attribute.
     * Pure (no I/O) so it is unit-testable. Recovered specs report the
     * canonical theme asset path that run() writes into the markup.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parsePlaceholders(string $content): array
    {
        return self::parseAndNormalize($content)['images'];
    }

    /**
     * Parse canonical placeholders and recover malformed URL/source forms,
     * returning the normalized markup alongside their shared image specs.
     *
     * @return array{content:string,images:array<int,array<string,mixed>>,reservedOrdinaryPlaceholder:bool}
     */
    private static function parseAndNormalize(string $content): array
    {
        if (!str_contains($content, 'AI_IMAGE:')) {
            return ['content' => $content, 'images' => [], 'reservedOrdinaryPlaceholder' => false];
        }

        // Canonical placeholders in the original markup double as recovery
        // targets: a malformed cover "url" whose subject matches its inner
        // img's documented AI_IMAGE alt must adopt that img's asset path, not
        // synthesize a second image for the same background.
        $canonicalSrcBySubject = [];
        foreach (self::parseCanonicalPlaceholders($content) as $image) {
            $canonicalSrcBySubject[self::normalizeSubject($image['subject'])] = $image['src'];
        }

        $recovered = self::recoverPlaceholders($content, $canonicalSrcBySubject);
        $images = self::parseCanonicalPlaceholders($recovered['content']);

        // A malformed src can coexist with a valid AI_IMAGE alt. Once recovery
        // gives that tag a canonical theme path, the canonical parser above
        // produces the richer four-field spec under the same filename. Keep it
        // and discard the recovery fallback rather than generating twice.
        $seen = array_fill_keys(array_column($images, 'filename'), true);
        foreach ($recovered['images'] as $image) {
            if (!isset($seen[$image['filename']])) {
                $images[] = $image;
                $seen[$image['filename']] = true;
            }
        }

        // The stage tile's filename is reserved even if generated content
        // copies it into an ordinary AI_IMAGE media block. Rename that media
        // deterministically and only at media url/src boundaries; a true
        // marker + Group background keeps the code-owned source untouched.
        $normalized = $recovered['content'];
        // Bind each replacement to the same valid canonical img tag that
        // produced its spec. A malformed reserved AI_IMAGE tag still needs a
        // warning, but must not consume the next valid tag's replacement.
        /** @var array<int,string> reserved-tag ordinal => replacement */
        $reservedReplacements = [];
        $replacementValues = [];
        $validImageIndex = 0;
        $reservedTagOrdinal = 0;
        preg_match_all('/<img\b[^>]*>/is', $normalized, $allTags);
        foreach ($allTags[0] ?? [] as $tag) {
            $reservedTag = self::isReservedAiImageTag($tag, GeneratedMarkup::STAGE_TEXTURE_ASSET);
            $parsedTag = self::parseCanonicalPlaceholders($tag);
            if ($parsedTag !== []) {
                $imageIndex = $validImageIndex++;
                if (($images[$imageIndex]['src'] ?? null) === GeneratedMarkup::STAGE_TEXTURE_ASSET
                    && $reservedTag
                ) {
                    $original = $images[$imageIndex];
                    $replacement = 'theme:./assets/' . self::synthesizeFilename(
                        (string) ($original['subject'] ?? 'stage texture media'),
                        'reserved-stage-texture-media|'
                            . json_encode($original, JSON_UNESCAPED_SLASHES),
                    );
                    $reservedReplacements[$reservedTagOrdinal] = $replacement;
                    $replacementValues[] = $replacement;
                    $images[$imageIndex]['src'] = $replacement;
                    $images[$imageIndex]['filename'] = basename($replacement);
                }
            }
            if ($reservedTag) {
                $reservedTagOrdinal++;
            }
        }
        if ($reservedReplacements !== []) {
            $renamed = self::renameReservedMediaSources(
                $normalized,
                GeneratedMarkup::STAGE_TEXTURE_ASSET,
                $reservedReplacements,
            );
            $normalized = $renamed['content'];
            // Do not pay for a replacement whose generated reference stayed
            // at the reserved source because its owner could not be updated.
            $rewritten = array_fill_keys($renamed['rewritten'], true);
            $images = array_values(array_filter(
                $images,
                static fn (array $image): bool => !in_array($image['src'] ?? null, $replacementValues, true)
                    || isset($rewritten[$image['src']]),
            ));
        }

        return [
            'content' => $normalized,
            'images' => $images,
            'reservedOrdinaryPlaceholder' => self::containsReservedOrdinaryAiPlaceholder($normalized),
        ];
    }

    /**
     * Parse the documented "<img alt=AI_IMAGE src=theme:./assets/...>" form.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parseCanonicalPlaceholders(string $content): array
    {
        // alt=(quote)AI_IMAGE: ... (same quote). Backreference \1 matches the
        // opening quote type so quotes inside the alt don't truncate the match.
        $pattern = '/<img[^>]+alt=(["\'])AI_IMAGE:\s*(.*?)\1[^>]*>/is';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $images = [];
        foreach ($matches as $match) {
            $imgTag = $match[0];
            $alt    = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

            if (!preg_match('/src=(["\'])(theme:\.\/assets\/([a-z0-9_-]+\.(?:jpe?g|png)))\1/i', $imgTag, $srcMatch)) {
                continue; // no theme-relative asset src — skip
            }
            $src      = $srcMatch[2];
            $filename = $srcMatch[3];

            // subject | page-context | style | aspect-ratio. We pop the three
            // trailing fixed fields from the end, so the subject (the lead, the
            // only field meant to be rich) may itself contain pipes.
            $parts = explode('|', $alt);
            if (count($parts) < 4) {
                continue;
            }
            $aspectRatio = strtolower(trim(array_pop($parts)));
            $style       = strtolower(trim(array_pop($parts)));
            $pageContext = trim(array_pop($parts));
            $subject     = trim(implode('|', $parts));
            if ($subject === '') {
                continue;
            }

            $images[] = [
                'filename'    => $filename,
                'src'         => $src,
                'subject'     => $subject,
                'pageContext' => $pageContext,
                'style'       => $style,
                'aspectRatio' => $aspectRatio,
            ];
        }

        return $images;
    }

    /**
     * Rewrite a reserved source only where the exact img is bare or its
     * smallest parsed media owner can be updated in the same transaction.
     *
     * A crossed or missing closer does not make the two independently bounded
     * edits unsafe: the opening comment and img tag are still exact byte
     * ranges. Conversely, rewriting the img without its parsed owner's source
     * attribute would create a split-brain block, so that tag is retained and
     * surfaced through containsReservedOrdinaryAiPlaceholder().
     *
     * @param array<int,string> $replacements reserved-tag ordinal => deterministic source
     * @return array{content:string,rewritten:list<string>}
     */
    private static function renameReservedMediaSources(string $content, string $old, array $replacements): array
    {
        try {
            $document = BlockMarkup::parse($content);
            /** @var array<int,string> $owners */
            $owners = [];
            /** @var array<int,list<int>> $ownerOrdinals */
            $ownerOrdinals = [];
            /** @var array<int,true> $invalidOwners */
            $invalidOwners = [];
            /** @var array<int,string|null> $rewriteTags exact reserved AI img ordinal => replacement */
            $rewriteTags = [];
            $tagOrdinal = 0;
            preg_match_all('/<img\b[^>]*>/is', $content, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] ?? [] as [$tag, $tagStart]) {
                if (!self::isReservedAiImageTag($tag, $old)) {
                    continue;
                }
                $new = $replacements[$tagOrdinal] ?? null;
                if (!is_string($new)) {
                    $rewriteTags[$tagOrdinal++] = null;
                    continue;
                }

                // A media block owns only the saved HTML before its first
                // child block. Looking through all inner HTML would let a
                // nested placeholder rename an unrelated ancestor Cover's
                // own background URL. Pick the smallest media owner whose
                // independently bounded own-HTML range contains the img.
                $tagEnd = $tagStart + strlen($tag);
                $owner = null;
                $ownerLength = PHP_INT_MAX;
                foreach ($document->indices() as $index) {
                    if (!in_array($document->name($index), ['image', 'cover', 'media-text'], true)) {
                        continue;
                    }
                    $ownStart = $document->openingOffset($index) + $document->openingLength($index);
                    $children = $document->children($index);
                    $ownEnd = $children === []
                        ? $document->innerEndOffset($index)
                        : $document->openingOffset($children[0]);
                    if ($tagStart < $ownStart || $tagEnd > $ownEnd) {
                        continue;
                    }
                    $length = $ownEnd - $ownStart;
                    if ($length < $ownerLength) {
                        $owner = $index;
                        $ownerLength = $length;
                    }
                }
                if ($owner === null) {
                    // No parsed media owner means the exact img tag itself is
                    // the smallest complete unit and can be renamed directly.
                    $rewriteTags[$tagOrdinal++] = $new;
                    continue;
                }

                $attrs = $document->attrs($owner);
                $ownerCanChange = false;
                if (is_array($attrs)) {
                    foreach (['url', 'src', 'mediaUrl'] as $key) {
                        if (($attrs[$key] ?? null) === $old) {
                            $ownerCanChange = true;
                            break;
                        }
                    }
                }
                $ordinal = $tagOrdinal++;
                $rewriteTags[$ordinal] = $ownerCanChange ? $new : null;
                if (!$ownerCanChange) {
                    continue;
                }
                $ownerOrdinals[$owner][] = $ordinal;
                if (isset($invalidOwners[$owner])) {
                    $rewriteTags[$ordinal] = null;
                    continue;
                }
                if (isset($owners[$owner]) && $owners[$owner] !== $new) {
                    // One media owner cannot truthfully point at two distinct
                    // primary sources. Invalidate the entire owner, including
                    // tags tentatively accepted earlier, so its comment attrs
                    // and every saved tag retain one coherent old source.
                    $invalidOwners[$owner] = true;
                    unset($owners[$owner]);
                    foreach ($ownerOrdinals[$owner] as $ownedOrdinal) {
                        $rewriteTags[$ownedOrdinal] = null;
                    }
                    continue;
                }
                $owners[$owner] = $new;
            }

            foreach ($owners as $index => $new) {
                $attrs = $document->attrs($index);
                if (!is_array($attrs)) {
                    continue;
                }
                $changed = false;
                foreach (['url', 'src', 'mediaUrl'] as $key) {
                    if (($attrs[$key] ?? null) === $old) {
                        $attrs[$key] = $new;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $document->setAttrs($index, $attrs);
                }
            }
            $rendered = $document->render();

            $tagOrdinal = 0;
            $rewritten = [];
            $delivered = preg_replace_callback(
                '/<img\b[^>]*>/is',
                static function (array $match) use (
                    $old,
                    $rewriteTags,
                    &$tagOrdinal,
                    &$rewritten,
                ): string {
                    if (!self::isReservedAiImageTag($match[0], $old)) {
                        return $match[0];
                    }
                    $new = $rewriteTags[$tagOrdinal++] ?? null;
                    if (!is_string($new)) {
                        return $match[0];
                    }
                    $rewritten[] = $new;
                    return (string) preg_replace(
                        '/(\bsrc\s*=\s*["\'])' . preg_quote($old, '/') . '(["\'])/i',
                        '$1' . $new . '$2',
                        $match[0],
                        1,
                    );
                },
                $rendered,
            );
            return is_string($delivered)
                ? ['content' => $delivered, 'rewritten' => $rewritten]
                : ['content' => $content, 'rewritten' => []];
        } catch (\Throwable) {
            // Without a parsed ownership map we cannot prove that a tag is
            // truly bare. Retain the whole pre-normalization unit; the caller
            // warns and suppresses stage-texture mapping.
            return ['content' => $content, 'rewritten' => []];
        }
    }

    /** Whether unresolved ordinary generated media still claims the code-owned source. */
    public static function containsReservedOrdinaryAiPlaceholder(string $content): bool
    {
        if (!str_contains($content, 'AI_IMAGE:')
            || !str_contains($content, GeneratedMarkup::STAGE_TEXTURE_ASSET)
        ) {
            return false;
        }
        preg_match_all('/<img\b[^>]*>/is', $content, $tags);
        foreach ($tags[0] ?? [] as $tag) {
            if (self::isReservedAiImageTag($tag, GeneratedMarkup::STAGE_TEXTURE_ASSET)) {
                return true;
            }
        }
        return false;
    }

    /** Whether one saved img is the ordinary AI placeholder claiming the reserved source. */
    private static function isReservedAiImageTag(string $tag, string $source): bool
    {
        return preg_match(
            '~\bsrc\s*=\s*(["\'])' . preg_quote($source, '~') . '\1~i',
            $tag,
        ) === 1 && preg_match('/\balt\s*=\s*(["\'])AI_IMAGE:/i', $tag) === 1;
    }

    /**
     * Recover AI_IMAGE specs the model placed where a resolved asset path
     * belongs — a wp:cover block's "url" or a bare <img> src — instead of the
     * documented "<img alt=\"AI_IMAGE: …\" src=\"theme:./assets/…\">" form.
     *
     * JSON escapes and HTML entities are decoded before hashing/deduplication,
     * so a cover "url" containing "\u0026" and its <img> src containing
     * "&amp;" resolve to one semantic prompt and one filename. Both contexts
     * are rewritten in place to one theme asset path — the same-file canonical
     * placeholder's path when one names the same subject (so a half-canonical
     * cover keeps its url and rendered src on one asset), otherwise a
     * synthetic one.
     *
     * @param array<string,string> $canonicalSrcBySubject normalized subject => theme: src
     * @return array{content:string,images:array<int,array<string,mixed>>}
     */
    private static function recoverPlaceholders(string $content, array $canonicalSrcBySubject): array
    {
        /** @var array<string,array<string,mixed>> $byPrompt semantic prompt => spec */
        $byPrompt = [];

        $imageFor = static function (string $literal, bool $json) use (&$byPrompt, $canonicalSrcBySubject): ?array {
            $semantic = self::decodeRecoveredLiteral($literal, $json);
            $body = trim(substr($semantic, strlen('AI_IMAGE:')));
            $subject = trim(explode('|', $body, 2)[0]);
            if ($subject === '') {
                return null;
            }

            $promptKey = 'AI_IMAGE:' . $body;
            if (!isset($byPrompt[$promptKey])) {
                $src = $canonicalSrcBySubject[self::normalizeSubject($subject)]
                    ?? 'theme:./assets/' . self::synthesizeFilename($subject, $promptKey);
                $byPrompt[$promptKey] = [
                    'filename'    => substr($src, strlen('theme:./assets/')),
                    'src'         => $src,
                    'subject'     => $subject,
                    'pageContext' => '',
                    'style'       => '',
                    'aspectRatio' => self::sniffAspectRatio($body),
                ];
            }
            return $byPrompt[$promptKey];
        };

        // Block-comment JSON. Match "src" as well as "url" so any block that
        // puts the prompt in a JSON source field gets the same repair. Leading
        // whitespace inside the value is tolerated (and dropped on rewrite) so
        // every shape ThemeValidator flags as a JSON source is also repaired.
        $jsonPattern = '/(?P<prefix>"(?:url|src)"\s*:\s*")\s*'
            . '(?P<lit>AI_IMAGE:(?:[^"\\\\]|\\\\.)*)(?P<suffix>")/is';
        $content = (string) preg_replace_callback(
            $jsonPattern,
            static function (array $match) use ($imageFor): string {
                $image = $imageFor($match['lit'], true);
                return $image === null
                    ? $match[0]
                    : $match['prefix'] . $image['src'] . $match['suffix'];
            },
            $content
        );

        // Rendered HTML. Keep the replacement scoped to src= so an identical
        // AI_IMAGE alt remains available to the canonical parser above.
        // Leading whitespace inside the quotes is tolerated, mirroring the
        // validator's detection.
        $srcPattern = '/(?P<prefix>\bsrc\s*=\s*(?P<quote>["\']))\s*'
            . '(?P<lit>AI_IMAGE:.*?)(?P<suffix>\k<quote>)/is';
        $content = (string) preg_replace_callback(
            $srcPattern,
            static function (array $match) use ($imageFor): string {
                $image = $imageFor($match['lit'], false);
                return $image === null
                    ? $match[0]
                    : $match['prefix'] . $image['src'] . $match['suffix'];
            },
            $content
        );

        // Unquoted src=AI_IMAGE:… — the value necessarily ends at the first
        // whitespace or ">", so a piped spec loses everything past the subject's
        // first word; a truncated recovery still beats shipping the raw prompt
        // (or, with images requested, failing the whole build at the gate).
        // The rewrite adds the quotes the model omitted.
        $bareSrcPattern = '/\bsrc\s*=\s*(?P<lit>AI_IMAGE:[^\s>"\'`=]*)/i';
        $content = (string) preg_replace_callback(
            $bareSrcPattern,
            static function (array $match) use ($imageFor): string {
                $image = $imageFor($match['lit'], false);
                return $image === null
                    ? $match[0]
                    : 'src="' . $image['src'] . '"';
            },
            $content
        );

        return ['content' => $content, 'images' => array_values($byPrompt)];
    }

    /** Decode one captured URL/source value to the prompt text it represents. */
    private static function decodeRecoveredLiteral(string $literal, bool $json): string
    {
        if ($json) {
            $decoded = json_decode('"' . $literal . '"', true);
            if (is_string($decoded)) {
                $literal = $decoded;
            }
        }
        return trim(html_entity_decode($literal, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * The comparison key under which a recovered subject matches a canonical
     * placeholder's subject: case- and whitespace-insensitive.
     */
    private static function normalizeSubject(string $subject): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($subject)));
    }

    /**
     * A deterministic "<subject-slug>-<hash>.jpg" filename for a recovered
     * placeholder. The hash keys on the decoded semantic prompt, so serializer-
     * equivalent spellings share a filename while distinct prompts do not.
     */
    private static function synthesizeFilename(string $subject, string $literal): string
    {
        $slug = rtrim(substr(ProjectStore::slugify($subject), 0, 40), '-') ?: 'image';
        return $slug . '-' . substr(sha1($literal), 0, 8) . '.jpg';
    }

    /**
     * The aspect ratio in a recovered spec. The documented structured form's
     * trailing pipe field wins over ratio-like words in its subject, context or
     * style; malformed forms then fall back to an explicit `ratio:` label and,
     * finally, a heuristic keyword anywhere in the value. Explicit supported
     * ratios survive, unsupported numeric ratios are mapped by GeminiImage to
     * the closest supported shape, and named ratios remain named. Defaults to
     * landscape, the full-bleed default.
     */
    private static function sniffAspectRatio(string $body): string
    {
        $token = '(\d+:\d+|card-landscape|card-portrait|ultrawide|square|portrait|landscape)';
        $patterns = [
            // Canonical AI_IMAGE: subject | page context | style | aspect ratio.
            '/\|\s*(?:ratio:\s*)?' . $token . '\s*$/i',
            '/ratio:\s*' . $token . '/i',
            '/\b' . $token . '\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $body, $m)) {
                continue;
            }
            $ratio = strtolower($m[1]);
            return preg_match('/^\d+:\d+$/', $ratio) ? GeminiImage::aspectRatio($ratio) : $ratio;
        }

        return 'landscape';
    }

    /** Whether Gemini's delivered shape for this authored ratio is portrait. */
    private static function isPortraitAspectRatio(string $ratio): bool
    {
        if (preg_match('/^(\d+):(\d+)$/', GeminiImage::aspectRatio($ratio), $parts) !== 1) {
            return false;
        }
        return (int) $parts[1] < (int) $parts[2];
    }
}
