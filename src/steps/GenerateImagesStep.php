<?php
declare(strict_types=1);

/**
 * Step (opt-in, networked): generate the images collected by CollectImagesStep
 * and wire them into the theme.
 *
 * Input:  images.json + theme/parts/*.html + theme/templates/*.html
 * Output: theme/assets/<filename> for each generated image, the theme markup
 *         rewritten so "theme:./assets/<file>" becomes a URL WordPress can serve
 *         ("/wp-content/themes/<slug>/assets/<file>"), and images.json updated
 *         with per-image status.
 *
 * This is gated behind `--with-images` (or bin/images.php) because it is slow
 * (~30-60s/image) and hits the network — unlike the rest of the deterministic
 * build. A single image failing never aborts the build: it is marked "failed"
 * and its placeholder is left untouched.
 */
final class GenerateImagesStep implements Step
{
    public function __construct(private ImageClient $images) {}

    public function id(): string
    {
        return 'generate-images';
    }

    public function label(): string
    {
        return 'Generate AI images';
    }

    public function run(Project $project): void
    {
        if (!$project->exists('images.json')) {
            return; // collect-images never ran or wrote nothing
        }

        $specs = $project->readJson('images.json');
        if ($specs === []) {
            return;
        }

        $assetDir = $project->themePath('assets');
        if (!is_dir($assetDir) && !mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
            throw new RuntimeException("Could not create assets directory: {$assetDir}");
        }

        $resolved = []; // theme: src => served URL, for the markup rewrite
        foreach ($specs as $i => $spec) {
            if (($spec['status'] ?? 'pending') === 'completed') {
                $resolved[$spec['src']] = $this->servedUrl($project, $spec['filename']);
                continue;
            }

            $filename = (string) $spec['filename'];
            try {
                $bytes = $this->images->generate((string) $spec['prompt'], [
                    'aspect_ratio' => WpcomImageClient::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape')),
                ]);
                $project->writeText('theme/assets/' . $filename, $bytes);

                $specs[$i]['status'] = 'completed';
                $specs[$i]['url']    = $this->servedUrl($project, $filename);
                $resolved[$spec['src']] = $specs[$i]['url'];
                fwrite(STDERR, "    generated {$filename}\n");
            } catch (Throwable $e) {
                $specs[$i]['status'] = 'failed';
                $specs[$i]['error']  = $e->getMessage();
                fwrite(STDERR, "    FAILED {$filename}: {$e->getMessage()}\n");
            }
        }

        $project->writeJson('images.json', $specs);

        if ($resolved !== []) {
            $this->rewriteMarkup($project, $resolved);
        }
    }

    /** Root-relative URL the theme's assets are served at in Playground. */
    private function servedUrl(Project $project, string $filename): string
    {
        return "/wp-content/themes/{$project->slug()}/assets/{$filename}";
    }

    /**
     * Replace every "theme:./assets/<file>" reference (img src and wp:cover url)
     * with the served URL, in every theme markup file.
     *
     * @param array<string,string> $resolved theme: src => served URL
     */
    private function rewriteMarkup(Project $project, array $resolved): void
    {
        foreach (['parts', 'templates'] as $dir) {
            foreach (glob($project->themePath($dir . '/*.html')) ?: [] as $abs) {
                $rel = $dir . '/' . basename($abs);
                $content = $project->readText('theme/' . $rel);
                $updated = strtr($content, $resolved);
                if ($updated !== $content) {
                    $project->writeText('theme/' . $rel, $updated);
                }
            }
        }
    }
}
