<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): collect the AI image placeholders the sections step
 * emitted into the theme markup, so they can be generated.
 *
 * Input:  theme/parts/*.html + theme/templates/*.html
 * Output: images.json — an array of image specs, one per unique asset filename:
 *           { filename, src, subject, pageContext, style, aspectRatio, status, sources[] }
 *
 * Placeholders follow the telex convention: an <img> whose src is a theme-relative
 * "theme:./assets/<name>.jpg" path (".png" for transparent-background assets)
 * and whose alt is "AI_IMAGE: subject | page-context
 * | style | aspect-ratio". The subject describes what to render and from what POV; the
 * page-context describes where/how the image is used (it is context for the generator,
 * not part of the rendered subject — see ImagePromptComposer). This step is pure
 * parsing — no network — so it always runs as part of the build; the heavier
 * GenerateImagesStep is opt-in.
 *
 * It runs BEFORE fix-blocks on purpose: the block re-serializer strips the alt
 * from wp:cover background images, so the AI_IMAGE spec is only intact in the raw
 * section markup. images.json is then the durable record of what to generate.
 *
 * The model sometimes drops the "AI_IMAGE:" spec straight into a wp:cover "url"
 * or a bare <img> src instead of the alt convention; parseRecovered() collects
 * those too, so they still generate rather than shipping as raw prompt text (a
 * leak that survives to the final markup is caught by ThemeValidator).
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
            // Templates are only scanned when they exist; in the default graph
            // they are written by assemble-pages, which runs after this step.
            reads: ['theme/parts/*'],
            writes: ['images.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        /** @var array<string,array<string,mixed>> $byFilename keyed by filename, deduped */
        $byFilename = [];

        foreach ($this->themeHtmlFiles($project) as $rel) {
            $content = $project->readText('theme/' . $rel);
            foreach (self::parsePlaceholders($content) as $img) {
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
        foreach (['parts', 'templates'] as $dir) {
            foreach (glob($project->themePath($dir . '/*.html')) ?: [] as $abs) {
                $files[] = $dir . '/' . basename($abs);
            }
        }
        return $files;
    }

    /**
     * Extract image specs from one markup file. Matches <img> tags whose alt
     * begins with the AI_IMAGE marker; the filename comes from the src attribute.
     * Pure (no I/O) so it is unit-testable.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parsePlaceholders(string $content): array
    {
        if (!str_contains($content, 'AI_IMAGE:')) {
            return [];
        }

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

        return array_merge($images, self::parseRecovered($content));
    }

    /**
     * Recover AI_IMAGE specs the model placed where a resolved asset path
     * belongs — a wp:cover block's "url" or a bare <img> src — instead of the
     * documented "<img alt=\"AI_IMAGE: …\" src=\"theme:./assets/…\">" form.
     * Each becomes a spec whose `src` is the exact placeholder string, so
     * GenerateImagesStep rewrites every occurrence (cover url + inner img src
     * share the same string) to the served URL. Deduped by that string, so a
     * cover and its background <img> collapse to one image.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parseRecovered(string $content): array
    {
        // The placeholder as a JSON "url" value, or as a bare src attribute.
        $patterns = [
            '/"url"\s*:\s*"(?P<lit>AI_IMAGE:(?:[^"\\\\]|\\\\.)*)"/is',
            '/\bsrc=(["\'])(?P<lit>AI_IMAGE:.*?)\1/is',
        ];

        $images = [];
        $seen   = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $literal = $match['lit'];
                if (isset($seen[$literal])) {
                    continue; // same placeholder from the cover url and its <img>
                }
                $seen[$literal] = true;

                $body    = trim(html_entity_decode(substr($literal, strlen('AI_IMAGE:')), ENT_QUOTES | ENT_HTML5));
                $subject = trim(explode('|', $body, 2)[0]);
                if ('' === $subject) {
                    continue;
                }

                $images[] = [
                    'filename'    => self::synthesizeFilename($subject, $literal),
                    'src'         => $literal,
                    'subject'     => $subject,
                    'pageContext' => '',
                    'style'       => '',
                    'aspectRatio' => self::sniffAspectRatio($body),
                ];
            }
        }
        return $images;
    }

    /**
     * A deterministic "<subject-slug>-<hash>.jpg" filename for a recovered
     * placeholder. The hash keys on the exact placeholder string, so identical
     * placeholders (a cover url and its background img) share a filename and
     * dedupe, while distinct ones never collide.
     */
    private static function synthesizeFilename(string $subject, string $literal): string
    {
        $slug = rtrim(substr(ProjectStore::slugify($subject), 0, 40), '-') ?: 'image';
        return $slug . '-' . substr(sha1($literal), 0, 8) . '.jpg';
    }

    /**
     * The aspect ratio named anywhere in a recovered spec — an explicit
     * "ratio:16:9", a bare "W:H", or a "landscape"/"portrait"/"square" word.
     * The "ratio:" prefix wins over a bare token so it can't be pre-empted by a
     * stray one earlier in the string. Defaults to landscape, the full-bleed default.
     */
    private static function sniffAspectRatio(string $body): string
    {
        if (preg_match('/ratio:\s*(\d+:\d+|square|portrait|landscape)/i', $body, $m)
            || preg_match('/\b(\d+:\d+|square|portrait|landscape)\b/i', $body, $m)
        ) {
            return strtolower($m[1]);
        }
        return 'landscape';
    }
}
