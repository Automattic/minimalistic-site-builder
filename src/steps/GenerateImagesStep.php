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
    /** How many images to generate concurrently per batch. */
    private const BATCH_SIZE = 5;

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

        // Route this run's image-request transcripts into the project's own
        // logs/images/ directory (projects/<slug>/logs/images/) — the sibling of
        // logs/llms/. Set here (not in Pipeline) so bin/images.php, which runs
        // this step directly, logs too.
        ImageLogger::setDir($project->logPath('images'));

        // Site-wide context (name/topic/description) prepended to every image
        // prompt so the model grounds each image in what the site is about.
        $siteContext = self::siteContext(
            $project->exists('siteSpec.json') ? $project->readJson('siteSpec.json') : []
        );

        $assetDir = $project->themePath('assets');
        if (!is_dir($assetDir) && !mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
            throw new RuntimeException("Could not create assets directory: {$assetDir}");
        }

        $resolved = []; // theme: src => served URL, for the markup rewrite

        // Already-completed images need no work — just record them for the rewrite.
        $pending = [];
        foreach ($specs as $i => $spec) {
            if (($spec['status'] ?? 'pending') === 'completed') {
                $resolved[$spec['src']] = $this->servedUrl($project, $spec['filename']);
                continue;
            }
            $pending[$i] = $spec; // preserve the original images.json index
        }

        // Generate the pending images in concurrent batches rather than one by
        // one: a slow Imagen round-trip per call otherwise dominates the step.
        $batches = array_chunk($pending, self::BATCH_SIZE, true);
        $batchCount = count($batches);
        if ($pending !== []) {
            fwrite(STDERR, sprintf(
                "    generating %d image(s) in %d batch(es) of up to %d…\n",
                count($pending), $batchCount, self::BATCH_SIZE
            ));
        }
        foreach ($batches as $b => $batch) {
            fwrite(STDERR, sprintf("    batch %d/%d: %d image(s)\n", $b + 1, $batchCount, count($batch)));

            // Map this batch's original indices to generation specs (order kept).
            $indices = array_keys($batch);
            $batchSpecs = array_map(fn (array $spec): array => [
                'prompt'       => ImagePromptComposer::compose(
                    (string) ($spec['subject'] ?? ''),
                    (string) ($spec['pageContext'] ?? ''),
                    (string) ($spec['style'] ?? ''),
                    $siteContext,
                ),
                'aspect_ratio' => WpcomImageClient::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape')),
            ], array_values($batch));

            $results = $this->images->generateBatch($batchSpecs);

            foreach ($indices as $pos => $i) {
                $filename = (string) $specs[$i]['filename'];
                $result = $results[$pos] ?? ['ok' => false, 'error' => 'no result returned'];

                // The full prompt + every parameter that shaped this request,
                // logged below whether it succeeds or fails.
                $logRequest = [
                    'model'        => $this->images->model(),
                    'prompt'       => (string) $batchSpecs[$pos]['prompt'],
                    'aspect_ratio' => (string) $batchSpecs[$pos]['aspect_ratio'],
                    'subject'      => (string) ($specs[$i]['subject'] ?? ''),
                    'page_context' => (string) ($specs[$i]['pageContext'] ?? ''),
                    'style'        => (string) ($specs[$i]['style'] ?? ''),
                ];

                // A single image must never abort the build — isolate both a
                // generation failure and a write failure (disk full, bad path).
                try {
                    if (!($result['ok'] ?? false) || !isset($result['bytes'])) {
                        throw new RuntimeException((string) ($result['error'] ?? 'unknown error'));
                    }
                    $project->writeText('theme/assets/' . $filename, (string) $result['bytes']);
                    $specs[$i]['status'] = 'completed';
                    $specs[$i]['url']    = $this->servedUrl($project, $filename);
                    unset($specs[$i]['error']);
                    $resolved[$specs[$i]['src']] = $specs[$i]['url'];
                    fwrite(STDERR, "    generated {$filename}\n");
                    ImageLogger::log($filename, $logRequest, [
                        'path'  => 'theme/assets/' . $filename,
                        'bytes' => strlen((string) $result['bytes']),
                    ]);
                } catch (Throwable $e) {
                    $specs[$i]['status'] = 'failed';
                    $specs[$i]['error']  = $e->getMessage();
                    fwrite(STDERR, "    FAILED {$filename}: {$e->getMessage()}\n");
                    ImageLogger::log($filename, $logRequest, [], $e->getMessage());
                }
            }

            // Persist after each batch so progress survives an interruption.
            $project->writeJson('images.json', $specs);
        }

        $project->writeJson('images.json', $specs);

        if ($resolved !== []) {
            $this->rewriteMarkup($project, $resolved);
        }
    }

    /**
     * A compact, factual context sentence built from the site spec's name, topic
     * and description, prepended to each image prompt so the model knows what the
     * site is about. Returns '' when the spec carries none of those facts. Public
     * so tools (e.g. the image-prompt debugger) can reproduce the exact context
     * the step feeds into ImagePromptComposer.
     *
     * @param array<mixed> $spec
     */
    public static function siteContext(array $spec): string
    {
        $name        = trim((string) ($spec['name'] ?? ''));
        $topic       = trim((string) ($spec['topic'] ?? ''));
        $description = trim((string) ($spec['description'] ?? ''));

        $lead = [];
        if ($name !== '') {
            $lead[] = "the website “{$name}”";
        }
        if ($topic !== '') {
            $lead[] = "about {$topic}";
        }

        $parts = [];
        if ($lead !== []) {
            $parts[] = 'Image for ' . implode(' ', $lead) . '.';
        }
        if ($description !== '') {
            $parts[] = $description;
        }
        return implode(' ', $parts);
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
