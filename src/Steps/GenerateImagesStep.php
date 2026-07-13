<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\ImageTransparency;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\WpcomImageClient;

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
 *
 * A prompt the endpoint's safety filter rejects (the client retries those like
 * transient failures first — 3 more attempts by default) gets one repair pass
 * when an Llm is provided: a small model rewrites the SUBJECT to shed whatever
 * tripped the filter, the prompt is recomposed, and the image is regenerated.
 * Without an Llm, or when the repaired prompt is filtered too, the image is
 * marked "failed" like any other failure.
 */
final class GenerateImagesStep implements Step
{
    /** How many images to generate concurrently per batch. */
    private const BATCH_SIZE = 10;

    /**
     * @param ?Llm    $llm         used only to rewrite safety-filtered prompts;
     *        null disables that repair (filtered images just fail)
     * @param ?string $repairModel model for the rewrite (the "small" tier);
     *        null falls back to the Llm client's default model
     */
    public function __construct(
        private ImageClient $images,
        private ?Llm $llm = null,
        private ?string $repairModel = null,
    ) {}

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

        // The design direction's photographic grade, injected into EVERY prompt
        // so the independently generated images read as one photographic series.
        $imageGrade = DesignDirectionStep::imageGradeFor($project);

        $assetDir = $project->themePath('assets');
        if (!is_dir($assetDir) && !mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
            throw new \RuntimeException("Could not create assets directory: {$assetDir}");
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
            $batchSpecs = array_map(
                fn (array $spec): array => self::generationSpec($spec, $siteContext, $imageGrade),
                array_values($batch)
            );

            $results = $this->images->generateBatch($batchSpecs);

            $repairs = []; // original index => the filtered failure's error
            foreach ($indices as $pos => $i) {
                $filename = (string) $specs[$i]['filename'];
                $result = $results[$pos] ?? ['ok' => false, 'error' => 'no result returned'];

                // A safety-filtered prompt (already retried by the client) is
                // repairable: log the failed attempt so the sequence stays
                // inspectable, then hold it for the LLM rewrite pass below
                // instead of marking it failed outright.
                if ($this->llm !== null && !($result['ok'] ?? false) && ($result['filtered'] ?? false)) {
                    $error = (string) ($result['error'] ?? 'safety-filtered');
                    fwrite(STDERR, "    FILTERED {$filename}: {$error}\n");
                    ImageLogger::log($filename, $this->requestLog($specs[$i], $batchSpecs[$pos], $imageGrade), [], $error);
                    $repairs[$i] = $error;
                    continue;
                }

                $this->finish($project, $specs, $i, $batchSpecs[$pos], $result, $resolved, $imageGrade);
            }

            if ($repairs !== []) {
                $this->repairFiltered($project, $specs, $repairs, $siteContext, $imageGrade, $resolved);
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
     * A compact, factual site-context phrase built from the site spec's name,
     * topic and description, fed into every image prompt so the model knows
     * what the site is about. Returns '' when the spec carries none of those
     * facts. Public so tools (e.g. the image-prompt debugger) can reproduce
     * the exact context the step feeds into ImagePromptComposer.
     *
     * Shaped to read after "…on" in the composer's guidance sentence ("This
     * image is used as X on {siteContext}"), so it leads with a noun phrase:
     * `the website “Name”` (or `a website` when the spec has no name),
     * followed by the description as its own sentence. The topic is included
     * only when there is NO description — a description restates what the
     * site is about, and repeating the topic next to it reads like a stutter.
     *
     * @param array<mixed> $spec
     */
    public static function siteContext(array $spec): string
    {
        $name        = trim((string) ($spec['name'] ?? ''));
        $topic       = trim((string) ($spec['topic'] ?? ''));
        $description = trim((string) ($spec['description'] ?? ''));

        if ($name === '' && $topic === '' && $description === '') {
            return '';
        }

        $lead = $name !== '' ? "the website “{$name}”" : 'a website';
        if ($topic !== '' && $description === '') {
            $lead .= ($name !== '' ? ', about ' : ' about ') . $topic;
        }

        return $description === '' ? "{$lead}." : "{$lead}. {$description}";
    }

    /**
     * Turn one images.json row into the generation spec the client sends:
     * the composed prompt plus the structured parameters. $subject overrides
     * the row's subject (the repair pass regenerates with a rewritten one).
     *
     * @param array<string,mixed> $spec one images.json row
     * @return array{prompt:string,aspect_ratio:string,sample_image_size:string,mime:string}
     */
    private static function generationSpec(array $spec, string $siteContext, string $imageGrade, ?string $subject = null): array
    {
        $ratio = WpcomImageClient::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape'));
        // A .png placeholder is a transparent-background asset: request PNG
        // bytes, prompt for a flat white background (Imagen cannot render
        // alpha), and key that background out after generation.
        $mime = WpcomImageClient::mimeForFilename((string) ($spec['filename'] ?? ''));
        return [
            'prompt'            => ImagePromptComposer::compose(
                $subject ?? (string) ($spec['subject'] ?? ''),
                (string) ($spec['pageContext'] ?? ''),
                (string) ($spec['style'] ?? ''),
                $siteContext,
                $imageGrade,
                $mime === 'image/png',
            ),
            'aspect_ratio'      => $ratio,
            // Wide images are the full-bleed ones (heroes, banners) — render
            // those at 2K so they stay sharp past ~1366px. Transparent
            // decoratives render small on the page and stay at 1K whatever
            // their ratio.
            'sample_image_size' => WpcomImageClient::sampleImageSize($ratio, $mime === 'image/png'),
            'mime'              => $mime,
        ];
    }

    /**
     * The full prompt + every parameter that shaped one request, in the shape
     * ImageLogger::log() records — logged whether the request succeeds or
     * fails. $subject overrides the row's subject for a repaired request, so
     * the log shows what was actually asked for.
     *
     * @param array<string,mixed> $spec one images.json row
     * @param array{prompt:string,aspect_ratio:string,sample_image_size:string,mime:string} $genSpec
     * @return array<string,string>
     */
    private function requestLog(array $spec, array $genSpec, string $imageGrade, ?string $subject = null): array
    {
        return [
            'model'             => $this->images->model(),
            'prompt'            => $genSpec['prompt'],
            'aspect_ratio'      => $genSpec['aspect_ratio'],
            'sample_image_size' => $genSpec['sample_image_size'],
            'mime'              => $genSpec['mime'],
            'subject'           => $subject ?? (string) ($spec['subject'] ?? ''),
            'page_context'      => (string) ($spec['pageContext'] ?? ''),
            'style'             => (string) ($spec['style'] ?? ''),
            'image_grade'       => $imageGrade,
        ];
    }

    /**
     * Record one generation result: write the asset (keying out the white
     * background for .png) and mark the spec completed, or mark it failed —
     * and log the request either way. A single image must never abort the
     * build, so both a generation failure and a write failure (disk full, bad
     * path) are contained here.
     *
     * @param array<int,array<string,mixed>> $specs images.json rows, mutated in place
     * @param array{prompt:string,aspect_ratio:string,sample_image_size:string,mime:string} $genSpec
     * @param array{ok:bool,bytes?:string,error?:string,filtered?:bool} $result
     * @param array<string,string> $resolved theme: src => served URL, mutated in place
     */
    private function finish(
        Project $project,
        array &$specs,
        int $i,
        array $genSpec,
        array $result,
        array &$resolved,
        string $imageGrade,
        ?string $subject = null
    ): void {
        $filename = (string) $specs[$i]['filename'];
        $logRequest = $this->requestLog($specs[$i], $genSpec, $imageGrade, $subject);
        try {
            if (!($result['ok'] ?? false) || !isset($result['bytes'])) {
                throw new \RuntimeException((string) ($result['error'] ?? 'unknown error'));
            }
            $bytes = (string) $result['bytes'];
            if ($genSpec['mime'] === 'image/png') {
                // Imagen cannot render real alpha: the prompt asked for a flat
                // solid white background instead, keyed out here so the asset
                // gets the transparency its .png promises.
                $bytes = ImageTransparency::keyOutBackground($bytes);
            }
            $project->writeText('theme/assets/' . $filename, $bytes);
            $specs[$i]['status'] = 'completed';
            $specs[$i]['url']    = $this->servedUrl($project, $filename);
            unset($specs[$i]['error']);
            $resolved[$specs[$i]['src']] = $specs[$i]['url'];
            fwrite(STDERR, "    generated {$filename}\n");
            ImageLogger::log($filename, $logRequest, [
                'path'  => 'theme/assets/' . $filename,
                'bytes' => strlen((string) $result['bytes']),
            ]);
        } catch (\Throwable $e) {
            $specs[$i]['status'] = 'failed';
            $specs[$i]['error']  = $e->getMessage();
            fwrite(STDERR, "    FAILED {$filename}: {$e->getMessage()}\n");
            ImageLogger::log($filename, $logRequest, [], $e->getMessage());
        }
    }

    /**
     * Second-chance pass for prompts the safety filter rejected even after the
     * client's own retries: a small model rewrites each image's SUBJECT to
     * keep the visual intent but shed whatever tripped the filter, the full
     * prompt is recomposed, and the images are regenerated in one batch. One
     * round only — an image whose repaired prompt is filtered again is marked
     * failed like any other failure — and any LLM problem falls back to
     * recording the original failure, so the repair can never break a build.
     *
     * @param array<int,array<string,mixed>> $specs   images.json rows, mutated in place
     * @param array<int,string>              $repairs original index => the filtered failure's error
     * @param array<string,string>           $resolved theme: src => served URL, mutated in place
     */
    private function repairFiltered(
        Project $project,
        array &$specs,
        array $repairs,
        string $siteContext,
        string $imageGrade,
        array &$resolved
    ): void {
        fwrite(STDERR, sprintf(
            "    rewriting %d safety-filtered prompt(s) with %s…\n",
            count($repairs), $this->repairModel ?? 'the default model'
        ));

        // Rewrite all the rejected subjects in one concurrent LLM batch.
        $renderer = new PromptRenderer(Package::promptsDir());
        $requests = [];
        foreach ($repairs as $i => $error) {
            $requests[$i] = [
                'prompt' => $renderer->render('image-prompt-repair.md', [
                    'subject' => (string) ($specs[$i]['subject'] ?? ''),
                    'reason'  => $error,
                ]),
            ] + ($this->repairModel !== null ? ['model' => $this->repairModel] : [])
              + ['log_label' => 'image-prompt-repair'];
        }
        try {
            $rewrites = $this->llm->completeBatch($requests);
        } catch (\Throwable $e) {
            fwrite(STDERR, "    prompt repair failed: {$e->getMessage()}\n");
            $rewrites = [];
        }

        // Regenerate every image that got a usable rewrite, concurrently (the
        // client retries transient/filtered failures again on its own).
        $regenSpecs = [];
        $subjects = [];
        foreach ($repairs as $i => $error) {
            $subject = trim(trim((string) ($rewrites[$i] ?? '')), "\"'");
            if ($subject === '') {
                // No usable rewrite: record the original failure (the filtered
                // attempt itself is already logged).
                $filename = (string) $specs[$i]['filename'];
                $specs[$i]['status'] = 'failed';
                $specs[$i]['error']  = $error;
                fwrite(STDERR, "    FAILED {$filename}: no usable prompt rewrite\n");
                continue;
            }
            $subjects[$i] = $subject;
            $regenSpecs[$i] = self::generationSpec($specs[$i], $siteContext, $imageGrade, $subject);
        }
        if ($regenSpecs === []) {
            return;
        }

        $results = $this->images->generateBatch(array_values($regenSpecs));
        foreach (array_keys($regenSpecs) as $pos => $i) {
            $result = $results[$pos] ?? ['ok' => false, 'error' => 'no result returned'];
            $this->finish($project, $specs, $i, $regenSpecs[$i], $result, $resolved, $imageGrade, $subjects[$i]);
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
