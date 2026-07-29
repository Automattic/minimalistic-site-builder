<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Imagen;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): collect the AI image placeholders the sections step
 * emitted into the theme markup, so they can be generated.
 *
 * Input:  theme/parts/*.html
 * Output: images.json — an array of image specs, one per unique asset filename:
 *           { filename, src, subject, pageContext, style, aspectRatio, status, sources[] }
 *         plus in-place normalization of malformed AI_IMAGE url/src values to
 *         canonical theme asset paths, and enforcement of the opaque-asset
 *         policy below (warnings.json records what it changed).
 *
 * OPAQUE-ASSET POLICY (BIGR-739): generated imagery is content imagery only —
 * the prompts no longer allow decorative ornaments or transparent `.png`
 * assets, because the image model cannot match the theme's palette hexes or
 * draw crisp small-scale geometry, so ornaments ship as off-palette, wobbly
 * marks that undermine the design. Prompt rules alone have leaked before, so
 * this step enforces the policy deterministically on whatever the sections
 * step emitted:
 *   - a `.png` placeholder that reads as DECORATIVE (its AI_IMAGE alt names an
 *     ornament/motif/icon, or its wp:image block declares a tiny display
 *     width) is removed — the whole wp:image block — since a decorative mark
 *     is by definition safe to drop (AGENTS.md rung 3), and the removal is
 *     recorded in warnings.json (rung 4);
 *   - any other `.png` reference (a content image mis-extensioned, or a cover
 *     background) is rewritten to `.jpg` so it generates as a normal opaque
 *     image (rung 1) — also recorded, since the delivered format changed.
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
    /**
     * Words in an AI_IMAGE alt that mark a `.png` placeholder as decorative
     * (an ornament to remove, not a content image to convert). Matched with
     * word boundaries so content subjects like "iconic skyline" don't trip
     * the bare forms.
     */
    private const DECORATIVE_ALT_PATTERN = '/\b(ornament\w*|decorat\w*|flourish\w*|motif\w*'
        . '|divider\w*|glyph\w*|crest\w*|emblem\w*|sprig\w*|rosette\w*|logo\w*'
        . '|icons?|marks?|ticks?|stamps?|badges?)\b/i';

    /**
     * A wp:image block whose declared width is at or below this is a tiny
     * inline mark, not a content image — decorative even when its alt names
     * no ornament word. Audited ornaments displayed at 14-104px; content
     * images either carry no fixed pixel width or a much larger one.
     */
    private const SMALL_ORNAMENT_MAX_WIDTH_PX = 160;

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
            reads: ['theme/parts/*'],
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

        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            $parsed = self::parseAndNormalize($content);
            $parsed = self::enforceOpaquePolicy($parsed['content'], $parsed['images']);
            if ($parsed['content'] !== $content) {
                $project->writeText('theme/' . $rel, $parsed['content']);
            }
            foreach ($parsed['removed'] as $removed) {
                $warnings[] = "{$rel}: removed decorative transparent image block for {$removed['filename']}"
                    . " (\"{$removed['context']}\") — decorative generated ornaments are not shipped (BIGR-739)";
            }
            foreach ($parsed['converted'] as $from => $to) {
                $warnings[] = "{$rel}: converted transparent asset {$from} to opaque {$to}"
                    . " — transparent generated assets are not shipped (BIGR-739)";
            }
            foreach ($parsed['images'] as $img) {
                $filename = $img['filename'];
                if (isset($byFilename[$filename])) {
                    // Same asset referenced from another file — just record the source.
                    $byFilename[$filename]['sources'][] = $rel;
                    continue;
                }
                $img['sources'] = [$rel];
                $img['status']  = 'pending';
                $byFilename[$filename] = $img;
            }
        }

        if ($warnings !== []) {
            $project->addWarnings($this->id(), $warnings);
        }
        $project->writeJson('images.json', array_values($byFilename));
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
     * @return array{content:string,images:array<int,array<string,mixed>>}
     */
    private static function parseAndNormalize(string $content): array
    {
        if (!str_contains($content, 'AI_IMAGE:')) {
            return ['content' => $content, 'images' => []];
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

        return ['content' => $recovered['content'], 'images' => $images];
    }

    /**
     * Enforce the opaque-asset policy (see the class docblock) on normalized
     * markup and its parsed specs: remove the wp:image block of every
     * decorative `.png` placeholder, rewrite every surviving `.png` asset
     * reference (src and cover url alike) to `.jpg`, and reconcile the spec
     * list with both edits. Pure (no I/O) so it is unit-testable.
     *
     * @param array<int,array<string,mixed>> $images parsed specs for $content
     * @return array{
     *     content:string,
     *     images:array<int,array<string,mixed>>,
     *     removed:array<int,array{filename:string,context:string}>,
     *     converted:array<string,string>
     * }
     */
    public static function enforceOpaquePolicy(string $content, array $images): array
    {
        $removed = [];

        // Pass 1: drop the whole wp:image block of every decorative `.png`
        // placeholder. wp:image blocks never nest, so the non-greedy body
        // match cannot swallow a sibling block.
        $blockPattern = '/<!--\s*wp:image\b(?<attrs>.*?)-->(?<body>.*?)<!--\s*\/wp:image\s*-->/is';
        $content = (string) preg_replace_callback(
            $blockPattern,
            static function (array $match) use (&$removed): string {
                $spec = self::pngPlaceholderIn($match['body']);
                if ($spec === null || !self::isDecorative($spec['alt'], $match['attrs'])) {
                    return $match[0];
                }
                $removed[] = ['filename' => $spec['filename'], 'context' => $spec['context']];
                return '';
            },
            $content
        );
        // Collapse the blank runs the removed blocks leave behind.
        if ($removed !== []) {
            $content = (string) preg_replace("/\n{3,}/", "\n\n", $content);
        }

        // Pass 2: any `.png` placeholder still referenced is a content image
        // in the wrong format (or a cover background) — rewrite every
        // reference to the asset, src and JSON url alike, to `.jpg`.
        $converted = [];
        foreach (self::pngPlaceholderFilenames($content) as $filename) {
            $jpg = substr($filename, 0, -4) . '.jpg';
            $converted[$filename] = $jpg;
            $content = str_replace("theme:./assets/{$filename}", "theme:./assets/{$jpg}", $content);
        }

        // Reconcile the spec list: removed assets no longer exist, converted
        // ones generate under their opaque name.
        $removedFilenames = array_fill_keys(array_column($removed, 'filename'), true);
        $kept = [];
        foreach ($images as $img) {
            $filename = $img['filename'];
            if (isset($removedFilenames[$filename]) && !isset($converted[$filename])) {
                continue;
            }
            if (isset($converted[$filename])) {
                $img['filename'] = $converted[$filename];
                $img['src']      = 'theme:./assets/' . $converted[$filename];
            }
            $kept[] = $img;
        }

        return ['content' => $content, 'images' => $kept, 'removed' => $removed, 'converted' => $converted];
    }

    /**
     * The first canonical `.png` AI_IMAGE placeholder inside one wp:image
     * block body, or null. Returns the filename plus the alt text (for the
     * decorative test) and its page-context field (for the warning row).
     *
     * @return array{filename:string,alt:string,context:string}|null
     */
    private static function pngPlaceholderIn(string $body): ?array
    {
        if (!preg_match('/<img[^>]+alt=(["\'])AI_IMAGE:\s*(.*?)\1[^>]*>/is', $body, $imgMatch)) {
            return null;
        }
        if (!preg_match('/src=(["\'])theme:\.\/assets\/([a-z0-9-]+\.png)\1/i', $imgMatch[0], $srcMatch)) {
            return null;
        }
        $alt = html_entity_decode($imgMatch[2], ENT_QUOTES | ENT_HTML5);
        // subject | page-context | style | aspect-ratio — the context is the
        // second-to-last-but-one field; tolerate malformed alts by falling
        // back to the whole alt.
        $parts = explode('|', $alt);
        $context = count($parts) >= 4 ? trim($parts[count($parts) - 3]) : trim($alt);
        return ['filename' => $srcMatch[2], 'alt' => $alt, 'context' => $context];
    }

    /** Whether a `.png` placeholder reads as a decorative ornament. */
    private static function isDecorative(string $alt, string $blockAttrs): bool
    {
        if (preg_match(self::DECORATIVE_ALT_PATTERN, $alt)) {
            return true;
        }
        return preg_match('/"width"\s*:\s*"?(\d+)/', $blockAttrs, $m)
            && (int) $m[1] <= self::SMALL_ORNAMENT_MAX_WIDTH_PX;
    }

    /**
     * Filenames of every canonical `.png` AI_IMAGE placeholder remaining in
     * the markup, deduped.
     *
     * @return array<int,string>
     */
    private static function pngPlaceholderFilenames(string $content): array
    {
        $filenames = [];
        $pattern = '/<img[^>]+alt=(["\'])AI_IMAGE:.*?\1[^>]*>/is';
        preg_match_all($pattern, $content, $matches);
        foreach ($matches[0] as $imgTag) {
            if (preg_match('/src=(["\'])theme:\.\/assets\/([a-z0-9-]+\.png)\1/i', $imgTag, $m)) {
                $filenames[$m[2]] = true;
            }
        }
        return array_keys($filenames);
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

            if (!preg_match('/src=(["\'])(theme:\.\/assets\/([a-z0-9-]+\.(?:jpe?g|png)))\1/i', $imgTag, $srcMatch)) {
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
     * The aspect ratio named anywhere in a recovered spec. Explicit supported
     * ratios survive, unsupported numeric ratios are mapped by Imagen to the
     * closest supported shape, and named ratios remain named. Defaults to
     * landscape, the full-bleed default.
     */
    private static function sniffAspectRatio(string $body): string
    {
        $matched = preg_match('/ratio:\s*(\d+:\d+|square|portrait|landscape)/i', $body, $m)
            || preg_match('/\b(\d+:\d+|square|portrait|landscape)\b/i', $body, $m);
        if (!$matched) {
            return 'landscape';
        }

        $ratio = strtolower($m[1]);
        return preg_match('/^\d+:\d+$/', $ratio) ? Imagen::aspectRatio($ratio) : $ratio;
    }
}
