<?php
declare(strict_types=1);

/**
 * Step (deterministic): collect the AI image placeholders the sections step
 * emitted into the theme markup, so they can be generated.
 *
 * Input:  theme/parts/*.html + theme/templates/*.html
 * Output: images.json — an array of image specs, one per unique asset filename:
 *           { filename, src, description, style, aspectRatio, prompt, status, sources[] }
 *
 * Placeholders follow the telex convention: an <img> whose src is a theme-relative
 * "theme:./assets/<name>.jpg" path and whose alt is "AI_IMAGE: description | style |
 * aspect-ratio". This step is pure parsing — no network — so it always runs as part
 * of the build; the heavier GenerateImagesStep is opt-in.
 *
 * It runs BEFORE fix-blocks on purpose: the block re-serializer strips the alt
 * from wp:cover background images, so the AI_IMAGE spec is only intact in the raw
 * section markup. images.json is then the durable record of what to generate.
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
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $images = [];
        foreach ($matches as $match) {
            $imgTag = $match[0];
            $alt    = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

            if (!preg_match('/src=(["\'])(theme:\.\/assets\/([a-z0-9-]+\.(?:jpe?g|png)))\1/i', $imgTag, $srcMatch)) {
                continue; // no theme-relative asset src — skip
            }
            $src      = $srcMatch[2];
            $filename = $srcMatch[3];

            // description | style | aspect-ratio (description may itself contain pipes).
            $parts = explode('|', $alt);
            if (count($parts) < 3) {
                continue;
            }
            $aspectRatio = strtolower(trim(array_pop($parts)));
            $style       = strtolower(trim(array_pop($parts)));
            $description = trim(implode('|', $parts));
            if ($description === '') {
                continue;
            }

            $prompt = $style !== '' ? "{$description}. Style: {$style}" : $description;

            $images[] = [
                'filename'    => $filename,
                'src'         => $src,
                'description' => $description,
                'style'       => $style,
                'aspectRatio' => $aspectRatio,
                'prompt'      => $prompt,
            ];
        }
        return $images;
    }
}
