<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImageCrop;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\ImageTransparency;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\MediaReferenceRemoval;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\ThemeValidator;
use Automattic\SiteBuild\Warnings;

/**
 * Step (opt-in, networked): generate the images collected by CollectImagesStep
 * and wire them into the theme.
 *
 * Input:  images.json + theme/parts/*.html + theme/templates/*.html
 * Output: theme/assets/<filename> for each generated image, the theme markup
 *         rewritten so "theme:./assets/<file>" becomes a URL WordPress can serve
 *         ("/wp-content/themes/<slug>/assets/<file>"), images.json updated
 *         with per-image status, and images.generated.json written last as the
 *         completion artifact consumed by post-image steps.
 *
 * This is gated behind `--with-images` (or bin/images.php) because it is slow
 * (~30-60s/image) and hits the network — unlike the rest of the deterministic
 * build. A single image failing never aborts the build: it is marked "failed"
 * and only media blocks/references to that undeliverable asset are removed.
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
    /** Written only once this step has completed all generation and rewrites. */
    public const COMPLETION_ARTIFACT = 'images.generated.json';

    /** Web-artifact wording is a design-comp cue, not subject matter. */
    private const WEB_ARTIFACT_CONTEXT = '/\b(?:web[- ]?sites?|web[- ]?pages?|home[- ]?pages?'
        . '|landing[- ]?(?:pages?|sites?)|(?:one|single)[- ]page\s+sites?'
        . '|portfolios?(?:\s+(?:web[- ]?sites?|sites?))?|official\s+sites?'
        . '|sitios?\s+(?:web\s+)?oficial(?:es)?|sitios?\s+web'
        . '|páginas?\s+(?:web|de\s+inicio|de\s+aterrizaje)|sites?\s+(?:web|oficial(?:es)?))\b/iu';

    /**
     * @param ?Llm    $llm         used only to rewrite safety-filtered prompts;
     *        null disables that repair (filtered images just fail)
     * @param ?string $repairModel model for the rewrite (the "small" tier);
     *        null falls back to the Llm client's default model
     * @param ?PromptRenderer $renderer renders the repair prompt; null falls
     *        back to the package's prompts/ dir
     */
    public function __construct(
        private ImageClient $images,
        private ?Llm $llm = null,
        private ?string $repairModel = null,
        private ?PromptRenderer $renderer = null,
    ) {
        $this->renderer ??= new PromptRenderer(Package::promptsDir());
    }

    public function id(): string
    {
        return 'generate-images';
    }

    public function label(): string
    {
        return 'Generate AI images';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'images.json',
                'siteSpec.json',
                'designDirection.json',
                'plugin/images.json',
                'theme/parts/*',
                'theme/templates/*',
                // After assemble-pages, multipage section covers live here.
                'plugin/pages/*',
                'theme/theme.json',
            ],
            writes: [
                'images.json',
                self::COMPLETION_ARTIFACT,
                'theme/assets/*',
                'theme/parts/*',
                'theme/templates/*',
                'plugin/images/*',
                'plugin/images.json',
                'plugin/pages/*',
                'warnings.json',
            ],
            concurrent: true,
        );
    }

    /**
     * Hex color the header site-title actually paints. Walks from the title
     * block up to the header root for `textColor`, then maps the slug through
     * theme.json. A white title on a dark header must produce a white mark.
     */
    public static function headerTitleInkHex(Project $project): ?string
    {
        if (!$project->exists('theme/theme.json') || !$project->exists('theme/parts/header.html')) {
            return null;
        }
        $palette = ContrastFixStep::paletteMap($project->readJson('theme/theme.json'));
        $slug = self::headerTitleInkSlug($project->readText('theme/parts/header.html'));
        if ($slug === '' || !isset($palette[$slug])) {
            $slug = isset($palette['contrast']) ? 'contrast' : '';
        }
        if ($slug === '' || !isset($palette[$slug])) {
            return null;
        }
        $hex = trim((string) $palette[$slug]);
        return $hex !== '' ? $hex : null;
    }

    /** Palette slug the site-title inherits, walking parents then the header root. */
    private static function headerTitleInkSlug(string $markup): string
    {
        $doc = BlockMarkup::parse($markup);
        $start = null;
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) === 'site-title') {
                $start = $i;
                break;
            }
        }
        $i = $start;
        while ($i !== null) {
            $slug = trim((string) (($doc->attrs($i) ?? [])['textColor'] ?? ''));
            if ($slug !== '') {
                return $slug;
            }
            $i = $doc->parent($i);
        }
        $top = $doc->topLevel();
        if ($top === null) {
            return '';
        }
        return trim((string) (($doc->attrs($top) ?? [])['textColor'] ?? ''));
    }

    public function run(Project $project): void
    {
        $this->clearCompletion($project);

        if (!$project->exists('images.json')) {
            $this->markComplete($project);
            return; // collect-images never ran or wrote nothing
        }

        $specs = $project->readJson('images.json');
        if ($specs === []) {
            $this->markComplete($project);
            return;
        }

        // Route this run's image-request transcripts into the project's own
        // logs/images/ directory (projects/<slug>/logs/images/) — the sibling of
        // logs/llms/. Set here (not in Pipeline) so bin/images.php, which runs
        // this step directly, logs too.
        ImageLogger::setDir($project->logPath('images'));

        // Site-wide subject matter (topic/area/description — never identity;
        // see siteContext) woven into every image prompt so the model grounds
        // each image in what the site is about.
        $siteContext = self::siteContext(
            $project->exists('siteSpec.json') ? $project->readJson('siteSpec.json') : []
        );

        // The design direction's photographic grade, injected into EVERY prompt
        // so the independently generated images read as one photographic series.
        $imageGrade = DesignDirectionStep::imageGradeFor($project);
        $imageCrop = DesignDirectionStep::imageCropFor($project) ?? '';

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

        // Generate every pending image through ONE pooled batch: concurrency
        // is bounded by the client's rolling pool, so a slow image holds only
        // its own slot instead of a barrier between step-level chunks.
        if ($pending !== []) {
            Narrator::write(sprintf(
                "    generating %d image(s) through the client's rolling pool…\n",
                count($pending)
            ));

            // The grade pass rewrites the subject we deliver, so it owes a
            // durable row: AGENTS.md asks that a removal we could not preserve
            // is recorded in warnings.json, and a log line alone is not enough
            // once delivered output changed.
            $gradeNotes = self::gradeSubjectWarnings($pending, $imageGrade);
            if ($gradeNotes !== []) {
                $project->addWarnings($this->id(), $gradeNotes);
            }

            // Map original images.json indices to generation specs (order kept).
            $indices = array_keys($pending);
            $batchSpecs = array_map(
                fn (array $spec): array => self::generationSpec($spec, $siteContext, $imageGrade, $imageCrop),
                array_values($pending)
            );

            $repairs = []; // original index => the filtered failure's error

            // One image's FINAL result. A safety-filtered prompt (already
            // retried by the client) is repairable: log the failed attempt so
            // the sequence stays inspectable, then hold it for the LLM rewrite
            // pass below instead of marking it failed outright. Everything
            // else finishes and persists immediately, so progress survives an
            // interruption while the rest of the batch is still generating.
            $this->drainBatch($batchSpecs, function (int $pos, array $result) use (
                $project, &$specs, $indices, $batchSpecs, $imageGrade, $imageCrop, &$resolved, &$repairs
            ): void {
                $i = $indices[$pos];
                $filename = (string) $specs[$i]['filename'];

                if ($this->llm !== null && !($result['ok'] ?? false) && ($result['filtered'] ?? false)) {
                    $error = (string) ($result['error'] ?? 'safety-filtered');
                    Narrator::write("    FILTERED {$filename}: {$error}\n");
                    ImageLogger::log($filename, $this->requestLog(
                        $specs[$i],
                        $batchSpecs[$pos],
                        $imageGrade,
                        $imageCrop,
                    ), [], $error);
                    $repairs[$i] = $error;
                    return;
                }

                $this->finish(
                    $project,
                    $specs,
                    $i,
                    $batchSpecs[$pos],
                    $result,
                    $resolved,
                    $imageGrade,
                    $imageCrop,
                );
                $project->writeJsonAtomic('images.json', $specs);
            });

            if ($repairs !== []) {
                $this->repairFiltered(
                    $project,
                    $specs,
                    $repairs,
                    $siteContext,
                    $imageGrade,
                    $imageCrop,
                    $resolved,
                );
            }
        }

        // A failed asset reference is dead UI. Remove only the safe media block
        // that contains each failed source (or a bare matching img tag), leaving
        // every sibling byte-for-byte intact.
        $this->removeFailedImageReferences($project, $specs);

        $project->writeJsonAtomic('images.json', $specs);

        if ($resolved !== []) {
            $this->rewriteMarkup($project, $resolved);
        }

        $this->shipPluginImages($project);
        $this->markComplete($project);
    }

    /**
     * Copy every generated asset the content plugin's manifest lists into
     * plugin/images/, so the seeder can import them into the media library at
     * activation. Content images stay in theme/assets/ too: chrome may share
     * them, and the seeder falls back to the theme copy for any file it
     * cannot import.
     */
    private function shipPluginImages(Project $project): void
    {
        if (!$project->exists('plugin/images.json')) {
            return; // theme-only composition, or assemble-pages never ran
        }
        $roles = [];
        if ($project->exists('images.json')) {
            foreach ((array) $project->readJson('images.json') as $spec) {
                if (is_array($spec) && isset($spec['filename'])) {
                    $roles[(string) $spec['filename']] = (string) ($spec['role'] ?? '');
                }
            }
        }
        $manifest = $project->readJson('plugin/images.json');
        $kept = [];
        $droppedLogo = false;
        foreach ((array) ($manifest['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }
            $filename = (string) ($image['filename'] ?? '');
            if ($filename === '') {
                continue;
            }
            if (($image['role'] ?? '') === 'site-logo' && ($roles[$filename] ?? '') !== 'site-logo') {
                $droppedLogo = true;
                continue;
            }
            if ($project->exists('theme/assets/' . $filename)) {
                $project->writeText('plugin/images/' . $filename, $project->readText('theme/assets/' . $filename));
            }
            $kept[] = $image;
        }
        if ($droppedLogo) {
            $project->writeJson('plugin/images.json', ['images' => $kept]);
        }
    }

    /**
     * Publish the dependency stamp once generation has run. An unresolved image
     * source — a raw AI_IMAGE spec, or a src the collector never saw — is a real
     * defect, but degrade-don't-fail governs here: the build's sections are
     * already paid for, so we deliver through with the defect recorded loudly in
     * warnings.json rather than abort. This matches a failed-but-collected image
     * (which keeps its placeholder and still completes) and the final validator,
     * which reports the same problems as warnings. A blank/broken image ships,
     * but it is surfaced, not silent.
     */
    private function markComplete(Project $project): void
    {
        $problems = ThemeValidator::unresolvedImageSourceProblems($project);
        if ($problems !== []) {
            $project->addWarnings($this->id(), $problems);
        }
        $project->writeJson(self::COMPLETION_ARTIFACT, ['status' => 'completed']);
    }

    /** A failed re-run must not leave a previous run's success stamp behind. */
    private function clearCompletion(Project $project): void
    {
        if (!$project->exists(self::COMPLETION_ARTIFACT)) {
            return;
        }

        $path = $project->path(self::COMPLETION_ARTIFACT);
        if (!is_file($path) || !unlink($path)) {
            throw new \RuntimeException("Could not clear image-generation completion artifact: {$path}");
        }
    }

    /**
     * A compact identity-free subject-matter sentence selected from the site
     * spec, fed into every image prompt so the model grounds each image in what
     * the site is about. Public so tools (e.g. the image-prompt debugger) can
     * reproduce the exact context the step feeds into ImagePromptComposer.
     *
     * The site NAME is deliberately never included (BIGR-768): telling a
     * typography-capable image model the site is called “X” is exactly what a
     * painted-in fake wordmark stands in for — the model typesets a title
     * block for the name it was told about, in the very region reserved for
     * the real HTML copy. Only subject-matter steering survives. A canonical
     * description may itself repeat the site/person name or call the artifact
     * a website, so merely omitting the `name` field does not enforce that
     * boundary. Candidates are accepted whole or rejected whole: the concise
     * topic and area facts take priority, with description as a fallback. This
     * avoids broken prose from deleting an identity in place and avoids
     * preferring a description of the web artifact over its actual subject.
     * Returns '' when every candidate is absent or unsafe.
     *
     * @param array<mixed> $spec
     */
    public static function siteContext(array $spec): string
    {
        $identities = [
            trim((string) ($spec['name'] ?? '')),
            trim((string) ($spec['persona_name'] ?? '')),
            trim((string) ($spec['email_domain'] ?? '')),
        ];
        foreach (['topic', 'area', 'description'] as $field) {
            $candidate = trim((string) ($spec[$field] ?? ''));
            if ($candidate === '' || !self::safeSubjectMatter($candidate, $identities)) {
                continue;
            }
            if ($field !== 'description') {
                $candidate = "The subject matter is {$candidate}";
            }
            // Keep this explicit for PHP 8.1 builds linked against PCRE2
            // before 10.40, where the Unicode STerm property is unavailable.
            return preg_match('/[.!?…。！？｡؟۔।॥]$/u', $candidate)
                ? $candidate
                : $candidate . '.';
        }
        return '';
    }

    /**
     * Reject one complete prose candidate instead of deleting identity words
     * and risking grammatical shards such as "is a bakery".
     *
     * @param list<string> $identities
     */
    public static function safeSubjectMatter(string $candidate, array $identities): bool
    {
        foreach ($identities as $identity) {
            if ($identity === '') {
                continue;
            }
            // Word boundaries do not exist between an identity and particles
            // in scripts such as Japanese. This is optional steering, so a
            // conservative literal substring check is preferable to leaking
            // even one identity-bearing candidate.
            if (mb_stripos($candidate, $identity, 0, 'UTF-8') !== false) {
                return false;
            }
        }
        return preg_match(self::WEB_ARTIFACT_CONTEXT, $candidate) !== 1;
    }

    /**
     * Turn one images.json row into the generation spec the client sends:
     * the composed prompt plus the structured parameters. $subject overrides
     * the row's subject (the repair pass regenerates with a rewritten one).
     *
     * @param array<string,mixed> $spec one images.json row
     * @return array{prompt:string,aspect_ratio:string,sample_image_size:string,mime:string}
     */
    private static function generationSpec(
        array $spec,
        string $siteContext,
        string $imageGrade,
        string $imageCrop = '',
        ?string $subject = null,
    ): array {
        $ratio = ImageCrop::generationRatio(
            $imageCrop,
            (string) ($spec['aspectRatio'] ?? 'landscape'),
            (string) ($spec['pageContext'] ?? ''),
        );
        // A .png placeholder is a transparent-background asset: request PNG
        // bytes, prompt for a flat white background (the image model cannot render
        // alpha), and key that background out after generation.
        $mime = GeminiImage::mimeForFilename((string) ($spec['filename'] ?? ''));
        return [
            'prompt'            => ImagePromptComposer::compose(
                $subject ?? (string) ($spec['subject'] ?? ''),
                (string) ($spec['pageContext'] ?? ''),
                (string) ($spec['style'] ?? ''),
                $siteContext,
                $imageGrade,
                $mime === 'image/png',
                imageCrop: $imageCrop,
            ),
            'aspect_ratio'      => $ratio,
            // Wide images are the full-bleed ones (heroes, banners) — render
            // those at 2K so they stay sharp past ~1366px. Transparent
            // decoratives render small on the page and stay at 1K whatever
            // their ratio.
            'sample_image_size' => GeminiImage::sampleImageSize($ratio, $mime === 'image/png'),
            'mime'              => $mime,
        ];
    }

    /**
     * One warnings.json row per subject the grade pass touched: the clauses it
     * dropped, and the clauses that carried grade wording it could not remove
     * without rewriting the scene. Both matter — the first changed what we
     * delivered, the second means the subject still contradicts the grade.
     *
     * @param array<int,array<string,mixed>> $pending
     * @return list<string>
     */
    private static function gradeSubjectWarnings(array $pending, string $imageGrade): array
    {
        $rows = [];
        foreach ($pending as $spec) {
            $rows = array_merge($rows, self::gradeSubjectWarningsFor(
                (string) ($spec['filename'] ?? ''),
                (string) ($spec['subject'] ?? ''),
                $imageGrade,
            ));
        }
        return $rows;
    }

    /**
     * The rows one subject owes. Split out from the batch above because the
     * repair pass rewrites a subject after that batch has been reported, and
     * a rewritten subject the grade pass edits owes the same receipt.
     *
     * The clauses are reported, not the whole subject: Warnings::value caps a
     * value at 160 characters and a subject runs several hundred, so an
     * authored/delivered pair rendered as the same truncated head twice and
     * showed nothing. AGENTS.md takes `removed` in place of the delivered
     * value for exactly this reason.
     *
     * @return list<string>
     */
    private static function gradeSubjectWarningsFor(string $filename, string $subject, string $imageGrade): array
    {
        $authored = trim($subject);
        // Transparent assets keep their subject: the isolation clause owns
        // the backdrop and no grade is appended.
        if (trim($imageGrade) === '' || $authored === ''
            || GeminiImage::mimeForFilename($filename) === 'image/png') {
            return [];
        }
        $rows = [];
        $result = ImagePromptComposer::stripCompetingGradeTokens($authored, $imageGrade);
        if ($result['removed'] !== []) {
            $rows[] = "images.json '{$filename}': authored subject clause(s) "
                . Warnings::value(implode('; ', $result['removed']))
                . '; delivered removed; disposition photographic grade competing with the'
                . ' site-wide grade, per prompts/image-generation.md:63';
        }
        foreach ($result['kept'] as $clause) {
            $rows[] = "images.json '{$filename}': subject clause " . Warnings::value($clause)
                . '; delivered unchanged; disposition names photographic grade but also names the'
                . ' scene, and no edit removes the grade wording without changing what is rendered';
        }
        return $rows;
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
    private function requestLog(
        array $spec,
        array $genSpec,
        string $imageGrade,
        string $imageCrop = '',
        ?string $subject = null,
    ): array {
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
        ] + ($imageCrop !== '' ? ['image_crop' => $imageCrop] : [])
          + self::deliveredSubjectLog($spec, $imageGrade, $subject);
    }

    /**
     * The subject actually sent, but only when the grade pass changed it.
     * Without this the log shows the authored SUBJECT beside a PROMPT built
     * from a different one, with nothing saying they differ — which is what
     * made an unintended edit hard to notice in a build log.
     *
     * @param array<string,mixed> $spec
     * @return array<string,string>
     */
    private static function deliveredSubjectLog(array $spec, string $imageGrade, ?string $subject): array
    {
        $authored = $subject ?? (string) ($spec['subject'] ?? '');
        $filename = (string) ($spec['filename'] ?? '');
        if (trim($imageGrade) === '' || trim($authored) === '') {
            return [];
        }
        if (GeminiImage::mimeForFilename($filename) === 'image/png') {
            return [];
        }
        $delivered = ImagePromptComposer::stripCompetingGradeTokens($authored, $imageGrade)['subject'];
        return $delivered === trim($authored) ? [] : ['subject_delivered' => $delivered];
    }

    /**
     * Record one generation result: validate the delivery bytes, key out the
     * white background for .png, write the asset, and mark the spec completed;
     * or isolate a generated-content failure to this image and warn. Asset I/O
     * remains fatal because a failed write is operational, not bad model output.
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
        string $imageCrop = '',
        ?string $subject = null
    ): void {
        $filename = (string) $specs[$i]['filename'];
        $logRequest = $this->requestLog($specs[$i], $genSpec, $imageGrade, $imageCrop, $subject);
        try {
            if (!($result['ok'] ?? false) || !isset($result['bytes'])) {
                throw new \RuntimeException((string) ($result['error'] ?? 'unknown error'));
            }
            // Defense in depth: ImageClient implementations are replaceable.
            // WpcomImageClient already requested and, only if needed, locally
            // converted JPEG. This final boundary asserts its contract rather
            // than introducing a second conversion path.
            $bytes = (string) $result['bytes'];
            $this->assertDeliveryMime($bytes, $genSpec['mime']);
            if ($genSpec['mime'] === 'image/png') {
                // The image model cannot render real alpha: the prompt asked for a flat
                // solid white background instead, keyed out here so the asset
                // gets the transparency its .png promises.
                $bytes = ImageTransparency::keyOutBackground($bytes);
                if (($specs[$i]['role'] ?? '') === 'site-logo') {
                    if (!ImageTransparency::isKeyed($bytes)) {
                        unset($specs[$i]['role']);
                        $project->addWarnings($this->id(), [
                            "file='theme/assets/{$filename}'; asset='site-logo.png'; authored role=site-logo; "
                            . 'delivered unkeyed opaque PNG kept as a theme asset only; '
                            . 'disposition=the white-background key wiped out or never ran, so the mark is not a usable logo; '
                            . 'role dropped, plugin manifest row will be removed, title stays visible',
                        ]);
                    } else {
                        $bytes = ImageTransparency::padToSquare($bytes);
                        $ink = self::headerTitleInkHex($project);
                        if ($ink !== null) {
                            $bytes = ImageTransparency::recolorInk($bytes, $ink);
                        }
                    }
                }
            }
            // ImageTransparency fails soft by returning its input. Verify the
            // post-processed bytes as well before choosing the file extension.
            $this->assertDeliveryMime($bytes, $genSpec['mime']);
        } catch (\Throwable $e) {
            $specs[$i]['status'] = 'failed';
            $specs[$i]['error']  = $e->getMessage();
            Narrator::write("    FAILED {$filename}: {$e->getMessage()}\n");
            ImageLogger::log($filename, $logRequest, [], $e->getMessage());
            $this->warnFailure($project, $specs[$i], $i, $genSpec['mime'], $e->getMessage());
            return;
        }

        // Do not classify persistence failures as generated-content defects.
        $project->writeText('theme/assets/' . $filename, $bytes);
        $specs[$i]['status'] = 'completed';
        $specs[$i]['url']    = $this->servedUrl($project, $filename);
        unset($specs[$i]['error']);
        $resolved[$specs[$i]['src']] = $specs[$i]['url'];
        Narrator::write("    generated {$filename}\n");
        ImageLogger::log($filename, $logRequest, [
            'path'  => 'theme/assets/' . $filename,
            'bytes' => strlen($bytes),
        ]);
    }

    /**
     * Second-chance pass for prompts the safety filter rejected even after the
     * client's own retries: a small model rewrites each image's SUBJECT to
     * keep the visual intent but shed whatever tripped the filter, the full
     * prompt is recomposed, and the images are regenerated in one batch. One
     * round only — an image whose repaired prompt is filtered again is marked
     * failed like any other failure. LLM problems are contained per image: a
     * failed rewrite batch is retried one request at a time, and an image
     * whose own rewrite still fails falls back to recording the original
     * failure, so the repair can never break a build.
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
        string $imageCrop,
        array &$resolved
    ): void {
        Narrator::write(sprintf(
            "    rewriting %d safety-filtered prompt(s) with %s…\n",
            count($repairs), $this->repairModel ?? 'the default model'
        ));

        // Rewrite all the rejected subjects in one concurrent LLM batch.
        $requests = [];
        foreach ($repairs as $i => $error) {
            $requests[$i] = [
                'prompt' => $this->renderer->render('image-prompt-repair.md', [
                    'subject' => (string) ($specs[$i]['subject'] ?? ''),
                    'reason'  => $error,
                ]),
            ] + ($this->repairModel !== null ? ['model' => $this->repairModel] : [])
              + ['log_label' => 'image-prompt-repair'];
        }
        // completeBatch is all-or-nothing: one permanently-failed request
        // aborts the whole batch, discarding sibling rewrites that may have
        // succeeded. Fall back to one request at a time so a bad rewrite
        // costs only its own image; the ones that fail again just keep their
        // original filtered failure (handled below as "no usable rewrite").
        try {
            $rewrites = $this->llm->completeBatch($requests)->texts;
        } catch (\Throwable $e) {
            Narrator::write("    batched prompt repair failed ({$e->getMessage()}); retrying rewrites one by one\n");
            $rewrites = [];
            foreach ($requests as $i => $req) {
                try {
                    $rewrites[$i] = $this->llm->complete($req['prompt'], array_diff_key($req, ['prompt' => '']));
                } catch (\Throwable $inner) {
                    $filename = (string) ($specs[$i]['filename'] ?? '');
                    Narrator::write("    prompt rewrite failed for {$filename}: {$inner->getMessage()}\n");
                }
            }
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
                Narrator::write("    FAILED {$filename}: no usable prompt rewrite\n");
                $this->warnFailure(
                    $project,
                    $specs[$i],
                    $i,
                    GeminiImage::mimeForFilename($filename),
                    $error . '; no usable prompt rewrite',
                );
                continue;
            }
            $subjects[$i] = $subject;
            $regenSpecs[$i] = self::generationSpec(
                $specs[$i],
                $siteContext,
                $imageGrade,
                $imageCrop,
                $subject,
            );
        }
        if ($regenSpecs === []) {
            return;
        }

        // The rewrite is a fresh subject the grade pass has never seen, and it
        // is what ships. A rewrite that reintroduces grade wording was being
        // edited with only a log line behind it.
        $repairNotes = [];
        foreach ($subjects as $i => $subject) {
            $repairNotes = array_merge($repairNotes, self::gradeSubjectWarningsFor(
                (string) ($specs[$i]['filename'] ?? ''),
                $subject,
                $imageGrade,
            ));
        }
        if ($repairNotes !== []) {
            $project->addWarnings($this->id(), $repairNotes);
        }

        // Stream repaired images through the same bounded-memory contract as
        // the original batch. Persist each completion immediately so a large
        // filtered cohort cannot rebuild the all-bytes-in-memory/OOM path or
        // lose every successful repair if a later request is interrupted.
        $indices = array_keys($regenSpecs);
        $batchSpecs = array_values($regenSpecs);
        $this->drainBatch($batchSpecs, function (int $pos, array $result) use (
            $project, &$specs, $indices, $batchSpecs, $imageGrade, $imageCrop, &$resolved, $subjects
        ): void {
            $i = $indices[$pos];
            $this->finish(
                $project,
                $specs,
                $i,
                $batchSpecs[$pos],
                $result,
                $resolved,
                $imageGrade,
                $imageCrop,
                $subjects[$i]
            );
            $project->writeJsonAtomic('images.json', $specs);
        });
    }

    /**
     * Run one generation batch, guaranteeing $handle sees exactly one final
     * result per spec: duplicate deliveries for a position are ignored, and a
     * client that omitted a result (or delivered no onResult) still yields
     * one final record per image.
     *
     * @param array<int,array{prompt:string,aspect_ratio:string,sample_image_size:string,mime:string}> $batchSpecs
     * @param callable(int,array<string,mixed>):void $handle one batch position's final result
     */
    private function drainBatch(array $batchSpecs, callable $handle): void
    {
        $handled = []; // batch position => true, so stragglers get exactly one pass
        $once = function (int $pos, array $result) use ($handle, &$handled): void {
            if (isset($handled[$pos])) {
                return;
            }
            $handled[$pos] = true;
            $handle($pos, $result);
        };

        $results = $this->images->generateBatch($batchSpecs, $once);
        foreach (array_keys($batchSpecs) as $pos) {
            if (!isset($handled[$pos])) {
                $once($pos, $results[$pos] ?? ['ok' => false, 'error' => 'no result returned']);
            }
        }
    }

    /** Reject unrecognized or mislabeled bytes at the final write boundary. */
    private function assertDeliveryMime(string $bytes, string $requestedMime): void
    {
        $detected = GeminiImage::mimeFromBytes($bytes);
        if ($detected === $requestedMime) {
            return;
        }
        throw new \RuntimeException(
            "Image client delivery failed MIME postcondition: requested {$requestedMime}; detected "
            . ($detected ?? 'unrecognized')
            . '; delivered removed'
        );
    }

    /** @param array<string,mixed> $spec */
    private function warnFailure(
        Project $project,
        array $spec,
        int $i,
        string $mime,
        string $error,
    ): void {
        $filename = (string) ($spec['filename'] ?? 'unknown');
        $source = (string) ($spec['src'] ?? 'unknown source');
        $locations = array_values(array_filter(
            array_map('strval', (array) ($spec['sources'] ?? [])),
            static fn (string $value): bool => $value !== '',
        ));
        $where = $locations === [] ? '' : ' at ' . implode(', ', $locations);
        $project->addWarnings($this->id(), [
            "images.json[{$i}] / theme/assets/{$filename}: authored MIME {$mime} "
            . "for {$source}{$where}; delivered removed; disposition: failed generated asset omitted; "
            . "container media or media-only wrapper/reference removed where structurally safe; "
            . "error: {$error}",
        ]);
    }

    /**
     * Remove references to failed generated assets transactionally per markup
     * file. A structurally safe innermost block is the preferred isolation
     * unit; recovered bare img placeholders fall back to removing that tag.
     *
     * @param array<int,array<string,mixed>> $specs
     */
    private function removeFailedImageReferences(Project $project, array $specs): void
    {
        $sources = [];
        foreach ($specs as $spec) {
            if (($spec['status'] ?? '') !== 'failed') {
                continue;
            }
            $source = (string) ($spec['src'] ?? '');
            if ($source !== '') {
                $sources[$source] = true;
            }
        }
        if ($sources === []) {
            return;
        }

        $root = rtrim($project->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($project->markupFiles() as $absolute) {
            $relative = str_starts_with($absolute, $root)
                ? str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root)))
                : $absolute;
            $content = $project->readText($relative);
            $updated = $content;
            foreach (array_keys($sources) as $source) {
                $removal = MediaReferenceRemoval::removeSourceWithReport($updated, $source);
                $candidate = $removal['markup'];
                if (MediaReferenceRemoval::position($candidate, $source) !== null) {
                    // The source sits in malformed/unclosed markup without a
                    // safe block span. Keep this source's pre-cleanup bytes and
                    // report the residual instead of half-mutating the file.
                    $project->addWarnings($this->id(), [
                        "{$relative}: authored media source {$source}; delivered retained in unsafe markup; "
                        . 'disposition: pre-cleanup bytes kept because no safe media isolation was available',
                    ]);
                    continue;
                }
                $updated = $candidate;
                foreach ($removal['removedCaptions'] as $caption) {
                    $project->addWarnings($this->id(), [
                        "{$relative}: block wp:paragraph at byte {$caption['start']}; authored caption "
                        . Warnings::value($caption['text']) . "; delivered removed; disposition: caption "
                        . "removed with unavailable media source {$source} instead of shipping an orphaned description",
                    ]);
                }
            }
            if ($updated !== $content) {
                $project->writeText($relative, $updated);
            }
        }
    }




    /** Root-relative URL the theme's assets are served at in Playground. */
    private function servedUrl(Project $project, string $filename): string
    {
        return "/wp-content/themes/{$project->slug()}/assets/{$filename}";
    }

    /**
     * Replace every "theme:./assets/<file>" reference (img src and wp:cover url)
     * with the served URL, in every theme markup file and in assembled content
     * plugin pages (assemble-pages inlines section markup into plugin/pages/*;
     * CLI --with-images and hosts that schedule this step after assemble must
     * rewrite those files or multipage covers keep theme: placeholders).
     *
     * @param array<string,string> $resolved theme: src => served URL
     */
    private function rewriteMarkup(Project $project, array $resolved): void
    {
        foreach ($project->themeFiles() as $rel) {
            $content = $project->readText('theme/' . $rel);
            $updated = strtr($content, $resolved);
            if ($updated !== $content) {
                $project->writeText('theme/' . $rel, $updated);
            }
        }

        // Multipage section markup is inlined into the content plugin after
        // assemble-pages; rewrite placeholders there too (wpcom BIGR-703 /
        // CLI --with-images after the full default pipeline).
        foreach (glob($project->path('plugin/pages/*.html')) ?: [] as $abs) {
            $rel = 'plugin/pages/' . basename($abs);
            $content = $project->readText($rel);
            $updated = strtr($content, $resolved);
            if ($updated !== $content) {
                $project->writeText($rel, $updated);
            }
        }
    }
}
