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
 *         their synthetic theme asset paths.
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
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        /** @var array<string,array<string,mixed>> $byFilename keyed by filename, deduped */
        $byFilename = [];

        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            $parsed = self::parseAndNormalize($content);
            if ($parsed['content'] !== $content) {
                $project->writeText('theme/' . $rel, $parsed['content']);
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

        $recovered = self::recoverPlaceholders($content);
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
     * are rewritten in place to that synthetic theme asset path.
     *
     * @return array{content:string,images:array<int,array<string,mixed>>}
     */
    private static function recoverPlaceholders(string $content): array
    {
        /** @var array<string,array<string,mixed>> $byPrompt semantic prompt => spec */
        $byPrompt = [];

        $imageFor = static function (string $literal, bool $json) use (&$byPrompt): ?array {
            $semantic = self::decodeRecoveredLiteral($literal, $json);
            $body = trim(substr($semantic, strlen('AI_IMAGE:')));
            $subject = trim(explode('|', $body, 2)[0]);
            if ($subject === '') {
                return null;
            }

            $promptKey = 'AI_IMAGE:' . $body;
            if (!isset($byPrompt[$promptKey])) {
                $filename = self::synthesizeFilename($subject, $promptKey);
                $byPrompt[$promptKey] = [
                    'filename'    => $filename,
                    'src'         => 'theme:./assets/' . $filename,
                    'subject'     => $subject,
                    'pageContext' => '',
                    'style'       => '',
                    'aspectRatio' => self::sniffAspectRatio($body),
                ];
            }
            return $byPrompt[$promptKey];
        };

        // Block-comment JSON. Match "src" as well as "url" so any block that
        // puts the prompt in a JSON source field gets the same repair.
        $jsonPattern = '/(?P<prefix>"(?:url|src)"\s*:\s*")'
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
        $srcPattern = '/(?P<prefix>\bsrc\s*=\s*(?P<quote>["\']))'
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
