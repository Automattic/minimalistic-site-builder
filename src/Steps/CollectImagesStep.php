<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

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
        $textureSources = [];

        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            $parsed = self::parseAndNormalize($content);
            if ($parsed['content'] !== $content) {
                $project->writeText('theme/' . $rel, $parsed['content']);
            }
            if (str_contains($parsed['content'], GeneratedMarkup::STAGE_TEXTURE_ASSET)) {
                $textureSources[] = $rel;
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
        // carries the canonical asset path. The subject is reviewed here —
        // the design direction's global image grade still applies at
        // prompt-composition time like every other asset.
        $textureFilename = basename(GeneratedMarkup::STAGE_TEXTURE_ASSET);
        if ($textureSources !== [] && !isset($byFilename[$textureFilename])) {
            $byFilename[$textureFilename] = self::stageTextureSpec($textureSources);
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
     * @return array<string,mixed>
     */
    public static function stageTextureSpec(array $sources): array
    {
        return [
            'filename' => basename(GeneratedMarkup::STAGE_TEXTURE_ASSET),
            'src' => GeneratedMarkup::STAGE_TEXTURE_ASSET,
            'subject' => 'A seamless repeating tone-on-tone surface texture — subtle paper grain, plaster,'
                . ' linen, or stone — extremely low contrast, near-uniform tone, no objects, no lettering,'
                . ' no distinct shapes, no vignette',
            'pageContext' => 'tiled page-canvas texture running behind the site header and the hero copy;'
                . ' it must stay quiet enough that readable text sits directly on it',
            'style' => 'photorealistic',
            'aspectRatio' => 'square',
            'sources' => $sources,
            'status' => 'pending',
        ];
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
