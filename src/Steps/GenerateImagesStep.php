<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\ImageTransparency;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\ThemeValidator;
use Automattic\SiteBuild\Warnings;
use Automattic\SiteBuild\Units\GeneratedMarkup;

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

    /**
     * Total generation attempts for the stage texture (the batch attempt
     * plus retryStageTexture's material rotations). Probed per-material
     * delivery is roughly 50-85%, so three rotated attempts put combined
     * delivery in the high nineties without unbounded API spend.
     */
    private const STAGE_TEXTURE_ATTEMPTS = 3;

    /**
     * Minimum multiple of ContrastMath::NORMAL_TEXT the surface-to-foreground
     * ratio must clear before MODEL generation is worth attempting: a
     * generated tile's darkest grain dips well below the surface's own ratio
     * (observed misses: 4.34-4.45:1 on a surface barely above 4.5:1), while
     * the procedural tile's tighter grain still fits inside this band.
     */
    private const STAGE_TEXTURE_CONTRAST_HEADROOM = 1.1;

    /**
     * Delivery-gate bounds for the stage texture, shared by the gate
     * (assertStageTextureDelivery), the tone aligner and the synthesizer so
     * the three stay tuned against one declared table. All spreads are
     * fractions of the channel range; drifts are 8-bit channel units.
     */
    private const STAGE_TEXTURE_MAX_DRIFT = 20.0;           // mean vs delivered surface
    private const STAGE_TEXTURE_MAX_ALIGNABLE_DRIFT = 48.0; // beyond: color request ignored
    private const STAGE_TEXTURE_MAX_SOURCE_SPREAD = 0.36;   // full-res max-min pre-filter
    private const STAGE_TEXTURE_MAX_SOURCE_DEVIATION = 0.08;
    private const STAGE_TEXTURE_MAX_CENTRAL_LUMINANCE_SPREAD = 0.12; // thumbnail 5th-95th pct
    private const STAGE_TEXTURE_MAX_FULL_LUMINANCE_SPREAD = 0.28;
    private const STAGE_TEXTURE_MAX_CENTRAL_CHANNEL_SPREAD = 36;
    private const STAGE_TEXTURE_MAX_FULL_CHANNEL_SPREAD = 72;

    /**
     * Palette-geometry feasibility of a committed stage texture, decidable
     * BEFORE any generation: the delivery gate requires every delivered
     * foreground to hold ContrastMath::NORMAL_TEXT against every tile pixel,
     * and the tile's mean is bound to the delivered surface color, so the
     * surface-to-foreground ratio caps what any tile can achieve.
     *
     * @param array<string,mixed> $spec one images.json row
     * @return 'generate'|'synthesize'|'impossible' 'generate' when model
     *         attempts can pass; 'synthesize' when only the procedural
     *         tile's tight grain can; 'impossible' when nothing can.
     */
    private static function stageTextureFeasibility(array $spec): string
    {
        $target = ContrastMath::hexToRgb((string) ($spec['targetColor'] ?? ''));
        if ($target === null) {
            return 'generate'; // the unresolvable-target guard owns this case
        }
        $floor = INF;
        foreach ((array) ($spec['foregroundColors'] ?? []) as $hex) {
            $foreground = ContrastMath::hexToRgb((string) $hex);
            if ($foreground !== null) {
                $floor = min($floor, ContrastMath::ratio($foreground, $target));
            }
        }
        if ($floor < ContrastMath::NORMAL_TEXT) {
            return 'impossible';
        }
        if ($floor < ContrastMath::NORMAL_TEXT * self::STAGE_TEXTURE_CONTRAST_HEADROOM) {
            return 'synthesize';
        }
        return 'generate';
    }

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
                'theme/theme.json',
                'theme/assets/*',
                'plugin/images.json',
                'theme/parts/*',
                'theme/templates/*',
                // After assemble-pages, multipage section covers live here.
                'plugin/pages/*',
            ],
            writes: [
                'images.json',
                self::COMPLETION_ARTIFACT,
                'theme/assets/*',
                'theme/parts/*',
                'theme/templates/*',
                'plugin/images/*',
                'plugin/pages/*',
                'warnings.json',
            ],
            concurrent: true,
        );
    }

    public function run(Project $project): void
    {
        $this->clearCompletion($project);

        if (!$project->exists('images.json')) {
            $this->markComplete($project);
            return; // collect-images never ran or wrote nothing
        }

        $specs = $project->readJson('images.json');
        $specsBeforeCollision = $specs;
        self::canonicalizeStageTextureUrls($project);
        $specs = $this->degradeReservedStageCollision($project, $specs);
        $specs = $this->normalizeReservedManifestOwners($project, $specs);
        if ($specs !== $specsBeforeCollision) {
            $project->writeJsonAtomic('images.json', $specs);
        }

        // Textured stage canvas backstop (BIGR-776): in the default graph
        // HeaderHeroStep paints the canonical texture path onto the header
        // and hero roots AFTER collect-images has written images.json, so a
        // first full-pipeline run reaches this step with the reference in
        // markup but no spec on file. Synthesize the same code-owned spec so
        // the tile actually generates; a later re-collect owns it as usual.
        $textureSources = self::stageTextureSources($project);
        if ($textureSources !== []) {
            $textureIndex = null;
            foreach ($specs as $i => $spec) {
                if (is_array($spec) && CollectImagesStep::isStageTextureSpec($spec)) {
                    $textureIndex = $i;
                    break;
                }
            }
            $canonical = CollectImagesStep::stageTextureSpec(
                $textureSources,
                CollectImagesStep::stageTextureTargetColor($project, allMarkup: true),
            );
            $canonical['foregroundColors'] = self::stageTextureForegroundColors($project);
            if ($textureIndex === null) {
                $specs[] = $canonical;
            } else {
                // Keep durable progress fields while refreshing the code-owned
                // prompt contract and the complete set of current references.
                foreach (['status', 'url', 'error'] as $runtimeField) {
                    if (array_key_exists($runtimeField, $specs[$textureIndex])) {
                        $canonical[$runtimeField] = $specs[$textureIndex][$runtimeField];
                    }
                }
                $specs[$textureIndex] = $canonical;
            }
            $textureIndex ??= array_key_last($specs);
            $textureFilename = basename(GeneratedMarkup::STAGE_TEXTURE_ASSET);
            if ($textureIndex !== null
                && in_array(($specs[$textureIndex]['status'] ?? 'pending'), ['', 'pending'], true)
                && $project->exists('theme/assets/' . $textureFilename)
            ) {
                // Recover an interrupted/full rerun without paying for the
                // same tile again. The completed branch below still verifies
                // MIME, target, visual quietness, and every foreground before
                // trusting these existing bytes.
                $specs[$textureIndex]['status'] = 'completed';
                $specs[$textureIndex]['url'] = $this->servedUrl($project, $textureFilename);
                unset($specs[$textureIndex]['error']);
            }
            $project->writeJson('images.json', $specs);
        }

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

        // The design direction's photographic grade, injected into ordinary
        // imagery so those assets read as one series. The code-owned surface
        // texture stays bound to its exact delivered palette target instead.
        $imageGrade = DesignDirectionStep::imageGradeFor($project);

        $assetDir = $project->themePath('assets');
        if (!is_dir($assetDir) && !mkdir($assetDir, 0775, true) && !is_dir($assetDir)) {
            throw new \RuntimeException("Could not create assets directory: {$assetDir}");
        }

        $resolved = []; // theme: src => served URL, for the markup rewrite

        // Already-completed images need no work — just record them for the rewrite.
        $pending = [];
        foreach ($specs as $i => $spec) {
            $isStageTexture = CollectImagesStep::isStageTextureSpec($spec);
            if ($isStageTexture && $textureSources === []) {
                // Cleanup from a previous failed run removed the complete
                // marker/background contract. Do not regenerate an unused
                // orphan on subsequent invocations.
                continue;
            }
            $targetColor = is_string($spec['targetColor'] ?? null) ? $spec['targetColor'] : '';
            if ($isStageTexture && ContrastMath::hexToRgb($targetColor) === null) {
                $error = 'stage texture has no single resolvable delivered hero surface color';
                $specs[$i]['status'] = 'failed';
                $specs[$i]['error'] = $error;
                unset($specs[$i]['url']);
                $this->warnFailure(
                    $project,
                    $specs[$i],
                    $i,
                    GeminiImage::mimeForFilename((string) ($spec['filename'] ?? '')),
                    $error,
                );
                continue;
            }
            if (($spec['status'] ?? 'pending') === 'completed') {
                if ($isStageTexture) {
                    $filename = (string) ($spec['filename'] ?? '');
                    $mime = GeminiImage::mimeForFilename($filename);
                    if (!$project->exists('theme/assets/' . $filename)) {
                        // A missing cache entry is repairable: generate it in
                        // this run. An actual read failure remains fatal I/O.
                        $specs[$i]['status'] = 'pending';
                        unset($specs[$i]['url']);
                        $spec = $specs[$i];
                    } else {
                        $bytes = $project->readText('theme/assets/' . $filename);
                        try {
                            $this->assertDeliveryMime($bytes, $mime);
                            self::assertStageTextureDelivery($spec, $bytes);
                        } catch (\Throwable $error) {
                            // The retry ladder regenerates failed textures and
                            // warns only its own final failure.
                            $specs[$i]['status'] = 'failed';
                            $specs[$i]['error'] = $error->getMessage();
                            unset($specs[$i]['url']);
                            continue;
                        }
                    }
                }
                if (($specs[$i]['status'] ?? null) === 'completed') {
                    $resolved[$spec['src']] = $this->servedUrl($project, $spec['filename']);
                    continue;
                }
            }
            if ($isStageTexture) {
                // The gate's outcome is decidable from palette geometry alone
                // (BIGR-776): keep doomed textures out of the model batch
                // entirely instead of burning attempts on them.
                $feasibility = self::stageTextureFeasibility($specs[$i]);
                if ($feasibility === 'impossible') {
                    $error = sprintf(
                        'stage texture cannot satisfy %.1f:1 against the delivered foregrounds'
                            . ' at surface %s; no tile can pass, degrading to solid without generation',
                        ContrastMath::NORMAL_TEXT,
                        $targetColor,
                    );
                    $specs[$i]['status'] = 'failed';
                    $specs[$i]['error'] = $error;
                    unset($specs[$i]['url']);
                    $this->warnFailure(
                        $project,
                        $specs[$i],
                        $i,
                        GeminiImage::mimeForFilename((string) ($spec['filename'] ?? '')),
                        $error,
                    );
                    continue;
                }
                if ($feasibility === 'synthesize') {
                    Narrator::write(
                        "    stage texture: palette contrast headroom too small for generated"
                        . " grain; skipping model attempts for the procedural tile\n"
                    );
                    continue; // retryStageTexture synthesizes it after the batch
                }
            }
            $pending[$i] = $specs[$i]; // preserve the original images.json index
        }

        // Generate every pending image through ONE pooled batch: concurrency
        // is bounded by the client's rolling pool, so a slow image holds only
        // its own slot instead of a barrier between step-level chunks.
        if ($pending !== []) {
            Narrator::write(sprintf(
                "    generating %d image(s) through the client's rolling pool…\n",
                count($pending)
            ));

            // Map original images.json indices to generation specs (order kept).
            $indices = array_keys($pending);
            $batchSpecs = array_map(
                fn (array $spec): array => self::generationSpec($spec, $siteContext, $imageGrade),
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
                $project, &$specs, $indices, $batchSpecs, $imageGrade, &$resolved, &$repairs
            ): void {
                $i = $indices[$pos];
                $filename = (string) $specs[$i]['filename'];

                // The stage texture's prompt is code-owned and its failures
                // (recitation filter, busyness gate) are stochastic per
                // material, so it gets the material-rotation retry ladder
                // below instead of an LLM subject rewrite.
                if (
                    $this->llm !== null && !($result['ok'] ?? false) && ($result['filtered'] ?? false)
                    && !CollectImagesStep::isStageTextureSpec($specs[$i])
                ) {
                    $error = (string) ($result['error'] ?? 'safety-filtered');
                    Narrator::write("    FILTERED {$filename}: {$error}\n");
                    ImageLogger::log($filename, $this->requestLog($specs[$i], $batchSpecs[$pos], $imageGrade), [], $error);
                    $repairs[$i] = $error;
                    return;
                }

                $this->finish($project, $specs, $i, $batchSpecs[$pos], $result, $resolved, $imageGrade);
                $project->writeJsonAtomic('images.json', $specs);
            });

            if ($repairs !== []) {
                $this->repairFiltered($project, $specs, $repairs, $siteContext, $imageGrade, $resolved);
            }
        }

        $this->retryStageTexture($project, $specs, $textureSources, $siteContext, $imageGrade, $resolved);

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
     * A residual ordinary AI placeholder cannot share the reserved stage
     * source. Drop only that smallest dead ordinary-media unit; exact stage
     * roots remain unambiguous because their URL resolution is block-scoped.
     *
     * @param array<int,array<string,mixed>> $specs
     * @return array<int,array<string,mixed>>
     */
    private function degradeReservedStageCollision(Project $project, array $specs): array
    {
        $collisions = [];
        $warnings = [];
        foreach ($project->markupFiles() as $absolute) {
            $relative = $project->relative($absolute);
            if (CollectImagesStep::containsReservedOrdinaryAiPlaceholder($project->readText($relative))) {
                $collisions[] = $relative;
            }
        }
        if ($collisions === []) {
            return $specs;
        }

        foreach ($collisions as $relative) {
            $content = $project->readText($relative);
            [$updated, $removed, $retained] = self::removeOrdinaryReservedMediaOnly(
                $content,
                GeneratedMarkup::STAGE_TEXTURE_ASSET,
            );
            if ($updated !== $content) {
                $project->writeText($relative, $updated);
            }
            $warnings[] = "file=" . Warnings::value($relative)
                . "; block='reserved ordinary AI_IMAGE media owner'; authored source="
                . Warnings::value(GeneratedMarkup::STAGE_TEXTURE_ASSET)
                . '; delivered=' . Warnings::value($retained
                    ? 'unsafe media bytes retained and unwired'
                    : ($removed ? 'media removed' : 'ordinary reference absent'))
                . '; disposition=' . ($retained
                    ? 'no safe ordinary-media boundary was available; the scoped stage resolver leaves it unwired for later repair'
                    : 'ordinary media was isolated at its smallest safe owner while legitimate stage roots remained generated');
        }
        $project->addWarnings($this->id(), $warnings);
        return $specs;
    }

    /** Keep the reserved asset/source under one code-owned stage spec writer. */
    private function normalizeReservedManifestOwners(Project $project, array $specs): array
    {
        $kept = [];
        $sawStage = false;
        $usedFilenames = [];
        $claimedFilenames = [];
        foreach ($specs as $candidateSpec) {
            if (is_array($candidateSpec)
                && is_string($candidateSpec['filename'] ?? null)
                && trim($candidateSpec['filename']) !== ''
            ) {
                $claimedFilenames[$candidateSpec['filename']] = true;
            }
        }
        $removeReservedOrdinaryReferences = false;
        $reservedFilename = basename(GeneratedMarkup::STAGE_TEXTURE_ASSET);
        foreach ($specs as $index => $spec) {
            if (!is_array($spec)) {
                $project->addWarnings($this->id(), [
                    "file='images.json'; block='images.json[{$index}]'; authored value="
                        . Warnings::value($spec)
                        . '; delivered="spec removed"; disposition=malformed generated manifest row was not an object',
                ]);
                continue;
            }
            if (!is_string($spec['src'] ?? null)
                || trim($spec['src']) === ''
                || !is_string($spec['filename'] ?? null)
                || trim($spec['filename']) === ''
            ) {
                $project->addWarnings($this->id(), [
                    "file='images.json'; block='images.json[{$index}]'; authored value="
                        . Warnings::value($spec)
                        . '; delivered="spec removed"; disposition=generated manifest row had no usable string '
                        . 'source and filename pair',
                ]);
                continue;
            }
            $stage = CollectImagesStep::isStageTextureSpec($spec);
            $source = $spec['src'] ?? null;
            $filename = $spec['filename'] ?? null;
            $reservedSource = $source === GeneratedMarkup::STAGE_TEXTURE_ASSET;
            $reservedName = $filename === $reservedFilename;
            if ($stage && $sawStage) {
                $project->addWarnings($this->id(), [
                    "file='images.json'; block='images.json[{$index}]'; authored source="
                        . Warnings::value($source)
                        . '; authored filename=' . Warnings::value($filename)
                        . '; delivered="spec removed"; disposition=duplicate stage manifest row cannot become '
                        . 'a second writer for the validated tile bytes',
                ]);
                continue;
            }
            if (!$stage && $reservedName && !$reservedSource) {
                $sourceFilename = self::themeSourceFilename($source);
                if ($sourceFilename !== null && $sourceFilename !== $reservedFilename) {
                    $candidate = $sourceFilename;
                    if (isset($claimedFilenames[$candidate]) || isset($usedFilenames[$candidate])) {
                        $extension = pathinfo($candidate, PATHINFO_EXTENSION);
                        $stem = pathinfo($candidate, PATHINFO_FILENAME);
                        $suffix = substr(
                            sha1((string) $source . '|' . json_encode($spec, JSON_UNESCAPED_SLASHES)),
                            0,
                            8,
                        );
                        $candidate = $stem . '-' . $suffix . '.' . $extension;
                        $ordinal = 2;
                        while (isset($claimedFilenames[$candidate]) || isset($usedFilenames[$candidate])) {
                            $candidate = $stem . '-' . $suffix . '-' . $ordinal++ . '.' . $extension;
                        }
                    }
                    $spec['filename'] = $candidate;
                    $spec['status'] = 'pending';
                    unset($spec['url'], $spec['error']);
                    $filename = $candidate;
                    $reservedName = false;
                    $claimedFilenames[$candidate] = true;
                }
            }
            if (!$stage && ($reservedSource || $reservedName)) {
                $removeReservedOrdinaryReferences = $removeReservedOrdinaryReferences || $reservedSource;
                $project->addWarnings($this->id(), [
                    "file='images.json'; block='images.json[{$index}]'; authored source="
                        . Warnings::value($source)
                        . '; authored filename=' . Warnings::value($filename)
                        . '; delivered="spec removed"; disposition=ordinary manifest row could not be '
                        . 'deterministically separated from the one code-owned stage asset writer',
                ]);
                continue;
            }
            if ($stage) {
                $sawStage = true;
            }
            if (is_string($filename) && $filename !== '') {
                $usedFilenames[$filename] = true;
            }
            $kept[] = $spec;
        }
        if ($removeReservedOrdinaryReferences) {
            $this->removeReservedOrdinaryManifestReferences($project);
        }
        return array_values($kept);
    }

    /** A valid generated theme source's filename, or null for unowned URLs. */
    private static function themeSourceFilename(mixed $source): ?string
    {
        if (!is_string($source)
            || preg_match('~^theme:\./assets/([a-z0-9_-]+\.(?:jpe?g|png))$~i', $source, $match) !== 1
        ) {
            return null;
        }
        return $match[1];
    }

    /**
     * Remove visible ordinary media that lost an ambiguous reserved manifest
     * row, while leaving every exact stage Group untouched.
     */
    private function removeReservedOrdinaryManifestReferences(Project $project): void
    {
        $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
        foreach ($project->markupFiles() as $absolute) {
            $relative = $project->relative($absolute);
            $before = $project->readText($relative);
            [$delivered, $removed, $residual] = self::removeOrdinaryReservedMediaOnly($before, $source);
            if ($delivered !== $before) {
                $project->writeText($relative, $delivered);
            }
            if ($removed || $residual) {
                $project->addWarnings($this->id(), [
                    'file=' . Warnings::value($relative)
                        . "; block='ordinary reserved media owner'; authored source=" . Warnings::value($source)
                        . '; delivered=' . Warnings::value($residual
                            ? 'unsafe media bytes retained and unwired'
                            : 'ordinary media reference removed')
                        . '; disposition=' . ($residual
                            ? 'no safe ordinary media boundary was available; retained bytes need later repair'
                            : 'removed the smallest ordinary media unit after its ambiguous manifest writer was suppressed'),
                ]);
            }
        }
    }

    /** @return array{0:string,1:bool,2:bool} content, removed, residual ordinary media */
    private static function removeOrdinaryReservedMediaOnly(string $markup, string $source): array
    {
        $removed = false;
        try {
            while (true) {
                $document = BlockMarkup::parse($markup);
                $candidates = [];
                foreach ($document->indices() as $index) {
                    if (!in_array($document->name($index), ['image', 'cover', 'media-text'], true)) {
                        continue;
                    }
                    $start = $document->openingOffset($index);
                    $end = $document->endOffset($index);
                    if ($end === null || !$document->isStructurallySafe($index)) {
                        continue;
                    }
                    $block = substr($markup, $start, $end - $start);
                    if (!self::mediaOwnerHasSource($document, $index, $markup, $source)) {
                        continue;
                    }
                    $candidates[] = [
                        'start' => $start,
                        'length' => $end - $start,
                        'block' => $block,
                        'name' => $document->name($index),
                    ];
                }
                usort($candidates, static fn (array $a, array $b): int => $a['length'] <=> $b['length']);
                $changed = false;
                foreach ($candidates as $candidate) {
                    $cleaned = self::removeReservedSourceFromMediaOwner($candidate['block'], $source);
                    if ($cleaned === null || $cleaned === $candidate['block']) {
                        continue;
                    }
                    $markup = substr_replace(
                        $markup,
                        $cleaned,
                        $candidate['start'],
                        $candidate['length'],
                    );
                    $removed = true;
                    $changed = true;
                    break;
                }
                if (!$changed) {
                    break;
                }
            }

            // A generated placeholder may be a bare exact img rather than a
            // block. Remove only tags not enclosed by any parsed media owner;
            // unsafe owners stay intact and are reported as residual below.
            $document = BlockMarkup::parse($markup);
            preg_match_all('/<img\b[^>]*>/is', $markup, $tags, PREG_OFFSET_CAPTURE);
            $tagEdits = [];
            foreach ($tags[0] ?? [] as [$tag, $offset]) {
                if (self::firstMediaSourcePosition($tag, $source) === null) {
                    continue;
                }
                $insideMedia = false;
                foreach ($document->indices() as $index) {
                    if (!in_array($document->name($index), ['image', 'cover', 'media-text'], true)) {
                        continue;
                    }
                    $start = $document->openingOffset($index);
                    $end = $document->endOffset($index) ?? strlen($markup);
                    if ($offset >= $start && $offset < $end) {
                        $insideMedia = true;
                        break;
                    }
                }
                if (!$insideMedia) {
                    $tagEdits[] = ['offset' => $offset, 'length' => strlen($tag)];
                }
            }
            usort($tagEdits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
            foreach ($tagEdits as $edit) {
                $markup = substr_replace($markup, '', $edit['offset'], $edit['length']);
                $removed = true;
            }

            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                if (!in_array($document->name($index), ['image', 'cover', 'media-text'], true)) {
                    continue;
                }
                if (self::mediaOwnerHasSource($document, $index, $markup, $source)) {
                    return [$markup, $removed, true];
                }
            }
            return [$markup, $removed, false];
        } catch (\Throwable) {
            return [$markup, $removed, true];
        }
    }

    /** Whether the media owner's own attrs/rendered layer names the source. */
    private static function mediaOwnerHasSource(
        BlockMarkup $document,
        int $index,
        string $markup,
        string $source,
    ): bool {
        $attrs = $document->attrs($index);
        if (is_array($attrs)) {
            foreach (['url', 'src', 'mediaUrl'] as $key) {
                if (($attrs[$key] ?? null) === $source) {
                    return true;
                }
            }
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
            $background = is_array($style['background'] ?? null) ? $style['background'] : [];
            $image = is_array($background['backgroundImage'] ?? null) ? $background['backgroundImage'] : [];
            if (($image['url'] ?? null) === $source) {
                return true;
            }
        }
        $ownStart = $document->openingOffset($index) + $document->openingLength($index);
        $children = $document->children($index);
        $ownEnd = $children === []
            ? $document->innerEndOffset($index)
            : $document->openingOffset($children[0]);
        if ($ownEnd < $ownStart) {
            return false;
        }
        return self::firstMediaSourcePosition(
            substr($markup, $ownStart, $ownEnd - $ownStart),
            $source,
        ) !== null;
    }

    /** Remove one media owner's own layer without inspecting child blocks. */
    private static function removeReservedSourceFromMediaOwner(string $block, string $source): ?string
    {
        try {
            $document = BlockMarkup::parse($block);
            $root = $document->topLevel();
            if ($root === null || !$document->isStructurallySafe($root)) {
                return null;
            }
            $name = $document->name($root);
            if ($name === 'image') {
                return '';
            }
            if ($name === 'media-text') {
                $children = '';
                foreach ($document->children($root) as $child) {
                    $start = $document->openingOffset($child);
                    $end = $document->endOffset($child);
                    if ($end === null) {
                        return null;
                    }
                    $children .= substr($block, $start, $end - $start);
                }
                return $children;
            }
            if ($name !== 'cover') {
                return null;
            }

            $attrs = $document->attrs($root);
            if (!is_array($attrs)) {
                return null;
            }
            $changed = false;
            foreach (['url', 'src', 'mediaUrl'] as $key) {
                if (($attrs[$key] ?? null) === $source) {
                    unset($attrs[$key]);
                    $changed = true;
                }
            }
            $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
            $background = is_array($style['background'] ?? null) ? $style['background'] : [];
            $image = is_array($background['backgroundImage'] ?? null) ? $background['backgroundImage'] : [];
            if (($image['url'] ?? null) === $source) {
                unset($background['backgroundImage']);
                if ($background === []) {
                    unset($style['background']);
                } else {
                    $style['background'] = $background;
                }
                if ($style === []) {
                    unset($attrs['style']);
                } else {
                    $attrs['style'] = $style;
                }
                $changed = true;
            }
            if ($changed) {
                unset($attrs['id'], $attrs['focalPoint']);
                $document->setAttrs($root, $attrs);
                $block = $document->render();
            }

            $document = BlockMarkup::parse($block);
            $root = $document->topLevel();
            if ($root === null) {
                return null;
            }
            $ownStart = $document->openingOffset($root) + $document->openingLength($root);
            $children = $document->children($root);
            $ownEnd = $children === []
                ? $document->innerEndOffset($root)
                : $document->openingOffset($children[0]);
            $own = substr($block, $ownStart, $ownEnd - $ownStart);
            $cleanedOwn = self::removeMatchingImageTags($own, $source);
            $quotedSource = preg_quote($source, '~');
            $cleanedOwn = (string) preg_replace(
                '~background-image\s*:\s*url\(\s*(["\']?)' . $quotedSource . '\1\s*\)\s*;?~i',
                '',
                $cleanedOwn,
            );
            $cleanedOwn = (string) preg_replace(
                '~background\s*:[^;]*url\(\s*(["\']?)' . $quotedSource . '\1\s*\)[^;]*;?~i',
                '',
                $cleanedOwn,
            );
            if ($cleanedOwn !== $own) {
                $block = substr_replace($block, $cleanedOwn, $ownStart, $ownEnd - $ownStart);
                $changed = true;
            }
            return $changed ? $block : null;
        } catch (\Throwable) {
            return null;
        }
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
        $manifest = $project->readJson('plugin/images.json');
        foreach ((array) ($manifest['images'] ?? []) as $image) {
            $filename = is_array($image) ? (string) ($image['filename'] ?? '') : '';
            if ($filename === '' || !$project->exists('theme/assets/' . $filename)) {
                continue;
            }
            $project->writeText('plugin/images/' . $filename, $project->readText('theme/assets/' . $filename));
        }
    }

    /**
     * Publish the dependency stamp only after every required operation succeeds.
     * A raw AI_IMAGE spec in a final URL/source is a hard postcondition failure:
     * without this gate an unrecognized placeholder shape can silently ship as
     * a blank image even when the manifest was absent or empty.
     */
    private function markComplete(Project $project): void
    {
        $problems = ThemeValidator::unresolvedImageSourceProblems($project);
        if ($problems !== []) {
            throw new \RuntimeException(
                "generate-images: unresolved AI_IMAGE source(s) remain after generation:\n- "
                . implode("\n- ", $problems)
            );
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
    private static function safeSubjectMatter(string $candidate, array $identities): bool
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

    /** @return list<string> markup paths that carry the exact marker + background contract */
    private static function stageTextureSources(Project $project): array
    {
        $sources = [];
        foreach ($project->markupFiles() as $absolute) {
            $relative = $project->relative($absolute);
            if (!self::containsCanonicalStageTextureContract($project->readText($relative))) {
                continue;
            }
            // Existing image specs name theme markup relative to theme/, while
            // assembled plugin pages already use project-relative paths.
            $sources[] = str_starts_with($relative, 'theme/') ? substr($relative, 6) : $relative;
        }
        return array_values(array_unique($sources));
    }

    /** Whether one markup file contains a safe synchronized canonical stage root. */
    private static function containsCanonicalStageTextureContract(string $markup): bool
    {
        try {
            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                $end = $document->endOffset($index);
                if (!$document->isStructurallySafe($index) || $end === null) {
                    continue;
                }
                $block = substr(
                    $markup,
                    $document->openingOffset($index),
                    $end - $document->openingOffset($index),
                );
                if (GeneratedMarkup::hasExactStageTextureContract(
                    $block,
                    GeneratedMarkup::STAGE_TEXTURE_ASSET,
                )) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /**
     * A copied project can retain the previous theme slug in an already-served
     * stage URL. Bring every reserved served alias back to the canonical
     * source before discovery; the ordinary resolved-map pass then writes the
     * current project slug after byte validation.
     */
    private static function canonicalizeStageTextureUrls(Project $project): void
    {
        foreach ($project->markupFiles() as $absolute) {
            $relative = $project->relative($absolute);
            $content = $project->readText($relative);
            try {
                $document = BlockMarkup::parse($content);
                $htmlEdits = [];
                $safeIndices = [];
                foreach ($document->indices() as $index) {
                    $attrs = $document->attrs($index);
                    if (!is_array($attrs) || !CollectImagesStep::isCommittedStageTextureAttrs($attrs)) {
                        continue;
                    }
                    $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                    $background = is_array($style['background'] ?? null) ? $style['background'] : [];
                    $image = is_array($background['backgroundImage'] ?? null)
                        ? $background['backgroundImage']
                        : [];
                    $old = $image['url'] ?? null;
                    if (!is_string($old)) {
                        continue;
                    }
                    if (!$document->isStructurallySafe($index)) {
                        $project->addWarnings('generate-images', [
                            "file='{$relative}'; block='stage-texture block {$index}'; authored source="
                                . Warnings::value($old)
                                . '; delivered="pre-canonicalization bytes retained"; disposition=unsafe '
                                . 'block boundary prevented a transactional theme-slug repair',
                        ]);
                        continue;
                    }
                    $ownHtml = $document->ownHtml($index);
                    if (preg_match(
                        GeneratedMarkup::SAVED_OPENING_TAG,
                        $ownHtml,
                        $opening,
                        PREG_OFFSET_CAPTURE,
                    ) !== 1) {
                        $project->addWarnings('generate-images', [
                            "file='{$relative}'; block='stage-texture block {$index}'; authored source="
                                . Warnings::value($old)
                                . '; delivered="pre-canonicalization bytes retained"; disposition=no isolated '
                                . 'saved wrapper was available for synchronized source repair',
                        ]);
                        continue;
                    }
                    $canonicalTag = $opening['tag'][0];
                    $styleAttributes = array_values(array_filter(
                        MarkupSanitizer::openingTagAttributes($canonicalTag),
                        static fn (array $attribute): bool => $attribute['name'] === 'style'
                            && $attribute['valueStart'] !== null
                            && $attribute['valueEnd'] !== null,
                    ));
                    $classAttributes = array_values(array_filter(
                        MarkupSanitizer::openingTagAttributes($canonicalTag),
                        static fn (array $attribute): bool => $attribute['name'] === 'class'
                            && $attribute['valueStart'] !== null
                            && $attribute['valueEnd'] !== null,
                    ));
                    if (count($styleAttributes) !== 1 || count($classAttributes) !== 1) {
                        $project->addWarnings('generate-images', [
                            "file='{$relative}'; block='stage-texture block {$index}'; authored source="
                                . Warnings::value($old)
                                . '; delivered="pre-canonicalization bytes retained"; disposition=saved wrapper '
                                . 'did not have one unique class and style attribute',
                        ]);
                        continue;
                    }
                    $styleAttribute = $styleAttributes[0];
                    $savedStyle = substr(
                        $canonicalTag,
                        $styleAttribute['valueStart'],
                        $styleAttribute['valueEnd'] - $styleAttribute['valueStart'],
                    );
                    $canonicalStyle = GeneratedMarkup::canonicalizeStageTextureInlineStyle($savedStyle);
                    $savedClass = substr(
                        $canonicalTag,
                        $classAttributes[0]['valueStart'],
                        $classAttributes[0]['valueEnd'] - $classAttributes[0]['valueStart'],
                    );
                    $savedClasses = preg_split(
                        '/\s+/',
                        trim(html_entity_decode($savedClass)),
                        -1,
                        PREG_SPLIT_NO_EMPTY,
                    ) ?: [];
                    if ($canonicalStyle === null
                        || !GeneratedMarkup::hasExactStageTextureInlineStyle(
                            $canonicalStyle,
                            GeneratedMarkup::STAGE_TEXTURE_ASSET,
                        )
                        || !in_array(GeneratedMarkup::STAGE_TEXTURE_CLASS, $savedClasses, true)
                        || ($background['backgroundPosition'] ?? null) !== '0% 0%'
                        || ($background['backgroundSize'] ?? null) !== '420px'
                        || ($background['backgroundRepeat'] ?? null) !== 'repeat'
                        || ($background['backgroundAttachment'] ?? null) !== 'fixed'
                        || array_key_exists('gradient', $attrs)
                        || array_key_exists('customGradient', $attrs)
                        || isset($style['color']['gradient'])
                        || array_key_exists('gradient', $background)
                    ) {
                        $project->addWarnings('generate-images', [
                            "file='{$relative}'; block='stage-texture block {$index}'; authored source="
                                . Warnings::value($old)
                                . '; delivered="pre-canonicalization bytes retained"; disposition=comment and '
                                . 'saved stage paint did not satisfy the exact synchronized contract',
                        ]);
                        continue;
                    }
                    $safeIndices[$index] = true;
                    $canonicalTag = substr_replace(
                        $canonicalTag,
                        $canonicalStyle,
                        $styleAttribute['valueStart'],
                        $styleAttribute['valueEnd'] - $styleAttribute['valueStart'],
                    );
                    if ($canonicalTag !== $opening['tag'][0]) {
                        $htmlEdits[] = [
                            'offset' => $document->openingOffset($index)
                                + $document->openingLength($index)
                                + $opening['tag'][1],
                            'length' => strlen($opening['tag'][0]),
                            'tag' => $canonicalTag,
                        ];
                    }
                }
                usort($htmlEdits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
                $updated = $content;
                foreach ($htmlEdits as $edit) {
                    $updated = substr_replace($updated, $edit['tag'], $edit['offset'], $edit['length']);
                }
                $document = BlockMarkup::parse($updated);
                foreach ($document->indices() as $index) {
                    if (!isset($safeIndices[$index])) {
                        continue;
                    }
                    $attrs = $document->attrs($index);
                    if (!is_array($attrs) || !CollectImagesStep::isCommittedStageTextureAttrs($attrs)) {
                        continue;
                    }
                    $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                    $background = is_array($style['background'] ?? null) ? $style['background'] : [];
                    $currentImage = is_array($background['backgroundImage'] ?? null)
                        ? $background['backgroundImage']
                        : [];
                    if (($currentImage['url'] ?? null) === GeneratedMarkup::STAGE_TEXTURE_ASSET) {
                        continue;
                    }
                    $background['backgroundImage'] = ['url' => GeneratedMarkup::STAGE_TEXTURE_ASSET];
                    $style['background'] = $background;
                    $attrs['style'] = $style;
                    $document->setAttrs($index, $attrs);
                }
                $updated = $document->render();
                $verified = BlockMarkup::parse($updated);
                foreach (array_keys($safeIndices) as $index) {
                    $attrs = $verified->attrs($index);
                    $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                    $background = is_array($style['background'] ?? null) ? $style['background'] : [];
                    $image = is_array($background['backgroundImage'] ?? null)
                        ? $background['backgroundImage']
                        : [];
                    if (!$verified->isStructurallySafe($index)
                        || ($image['url'] ?? null) !== GeneratedMarkup::STAGE_TEXTURE_ASSET
                    ) {
                        throw new \RuntimeException("stage-texture block {$index} failed canonical attr verification");
                    }
                    $ownHtml = $verified->ownHtml($index);
                    if (preg_match(
                        GeneratedMarkup::SAVED_OPENING_TAG,
                        $ownHtml,
                        $opening,
                    ) !== 1) {
                        throw new \RuntimeException("stage-texture block {$index} failed saved-wrapper verification");
                    }
                    $savedStyles = array_values(array_filter(
                        MarkupSanitizer::openingTagAttributes($opening['tag']),
                        static fn (array $attribute): bool => $attribute['name'] === 'style'
                            && $attribute['valueStart'] !== null
                            && $attribute['valueEnd'] !== null,
                    ));
                    if (count($savedStyles) !== 1) {
                        throw new \RuntimeException("stage-texture block {$index} failed saved-style verification");
                    }
                    $savedStyle = substr(
                        $opening['tag'],
                        $savedStyles[0]['valueStart'],
                        $savedStyles[0]['valueEnd'] - $savedStyles[0]['valueStart'],
                    );
                    if (GeneratedMarkup::canonicalizeStageTextureInlineStyle($savedStyle) !== $savedStyle
                        || !GeneratedMarkup::hasStageTextureInlineStyleSource(
                            $savedStyle,
                            GeneratedMarkup::STAGE_TEXTURE_ASSET,
                        )
                    ) {
                        throw new \RuntimeException("stage-texture block {$index} retained a stale saved source");
                    }
                    $end = $verified->endOffset($index);
                    $start = $verified->openingOffset($index);
                    if ($end === null || !GeneratedMarkup::hasExactStageTextureContract(
                        substr($updated, $start, $end - $start),
                        GeneratedMarkup::STAGE_TEXTURE_ASSET,
                    )) {
                        throw new \RuntimeException("stage-texture block {$index} failed exact contract verification");
                    }
                }
            } catch (\Throwable $error) {
                $updated = $content;
                if (str_contains($content, GeneratedMarkup::STAGE_TEXTURE_CLASS)
                    && preg_match(
                        '~(?:theme:\./assets|/wp-content/themes/[a-z0-9_-]+/assets)'
                            . '/stage_backdrop-texture\.jpg~i',
                        $content,
                        $oldSource,
                    ) === 1
                ) {
                    $project->addWarnings('generate-images', [
                        "file='{$relative}'; block='unparsed stage-texture root'; authored source="
                            . json_encode($oldSource[0], JSON_UNESCAPED_SLASHES)
                            . '; delivered=retained in pre-canonicalization bytes; disposition=unsafe generated '
                            . 'markup prevented a transactional theme-slug repair; error=' . $error->getMessage(),
                    ]);
                }
            }
            if ($updated !== $content) {
                $project->writeText($relative, $updated);
            }
        }
    }

    /**
     * Palette colors actually used by text inside roots painted with the
     * texture. These become a post-generation contrast boundary; an opaque
     * tile that cannot carry every delivered foreground degrades to solid.
     *
     * @return list<string> canonical #RRGGBB values
     */
    private static function stageTextureForegroundColors(Project $project): array
    {
        if (!$project->exists('theme/theme.json')) {
            return [];
        }
        $palette = ContrastFixStep::paletteMap($project->readJson('theme/theme.json'));
        $colors = [];
        foreach ($project->markupFiles() as $absolute) {
            $relative = $project->relative($absolute);
            $markup = $project->readText($relative);
            if (!CollectImagesStep::containsCommittedStageTexture($markup)) {
                continue;
            }
            try {
                $document = BlockMarkup::parse($markup);
            } catch (\Throwable) {
                continue;
            }
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index);
                $end = $document->endOffset($index);
                $start = $document->openingOffset($index);
                if (!$document->isStructurallySafe($index)
                    || $end === null
                    || !is_array($attrs)
                    || !GeneratedMarkup::hasExactStageTextureContract(
                        substr($markup, $start, $end - $start),
                        GeneratedMarkup::STAGE_TEXTURE_ASSET,
                    )
                ) {
                    continue;
                }
                $rootStyle = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                $rootColorStyle = is_array($rootStyle['color'] ?? null) ? $rootStyle['color'] : [];
                $inherited = self::resolvePaletteColor($attrs['textColor'] ?? null, $palette)
                    ?? self::resolvePaletteColor($rootColorStyle['text'] ?? null, $palette)
                    ?? (isset($palette['contrast']) ? strtoupper($palette['contrast']) : null);
                if ($inherited !== null) {
                    $colors[$inherited] = true;
                }
                // Collect only the text that actually sits ON the stage
                // texture. A descendant painting its own opaque surface —
                // a button, chip or nested banded group — hosts its text on
                // that surface, so it and its whole subtree are skipped:
                // demanding the light texture contrast against, say, a cream
                // button label on a dark button makes every texture for the
                // palette impossible. The textured root itself always carries
                // the background being replaced and is never pruned.
                $stack = [$index];
                while ($stack !== []) {
                    $candidate = array_pop($stack);
                    $candidateAttrs = $document->attrs($candidate);
                    $candidateAttrs = is_array($candidateAttrs) ? $candidateAttrs : [];
                    $candidateStyle = is_array($candidateAttrs['style'] ?? null)
                        ? $candidateAttrs['style']
                        : [];
                    $candidateColor = is_array($candidateStyle['color'] ?? null)
                        ? $candidateStyle['color']
                        : [];
                    $candidateBackground = is_array($candidateStyle['background'] ?? null)
                        ? $candidateStyle['background']
                        : [];
                    if ($candidate !== $index && (
                        ($candidateAttrs['backgroundColor'] ?? null) !== null
                        || ($candidateAttrs['gradient'] ?? null) !== null
                        || ($candidateColor['background'] ?? null) !== null
                        || ($candidateColor['gradient'] ?? null) !== null
                        || ($candidateBackground['backgroundImage'] ?? null) !== null
                    )) {
                        continue;
                    }
                    array_push($stack, ...$document->children($candidate));
                    $elements = is_array($candidateStyle['elements'] ?? null)
                        ? $candidateStyle['elements']
                        : [];
                    $link = is_array($elements['link'] ?? null) ? $elements['link'] : [];
                    $linkColor = is_array($link['color'] ?? null) ? $link['color'] : [];
                    foreach ([
                        $candidateAttrs['textColor'] ?? null,
                        $candidateColor['text'] ?? null,
                        $linkColor['text'] ?? null,
                    ] as $value) {
                        $resolved = self::resolvePaletteColor($value, $palette);
                        if ($resolved !== null) {
                            $colors[$resolved] = true;
                        }
                    }
                }
            }
        }
        return array_keys($colors);
    }

    /** @param array<string,string> $palette */
    private static function resolvePaletteColor(mixed $value, array $palette): ?string
    {
        return ContrastFixStep::paletteHex($palette, $value);
    }

    /**
     * Bounded regeneration ladder for a failed stage texture (BIGR-776).
     * Both failure modes are stochastic per material — the model's
     * IMAGE_RECITATION filter rejects the most stock-texture-like subjects,
     * and the busyness gate rejects an occasional loud render — so each
     * retry rotates the code-owned subject to the next material instead of
     * resubmitting the same prompt or rewriting it with an LLM. Warnings
     * stay quiet until the ladder's last attempt: earlier failures are
     * ImageLogger-logged but a delivered texture must not leave a stale
     * "texture rejected" warning behind.
     *
     * @param array<int,array<string,mixed>> $specs images.json rows, mutated in place
     * @param list<string> $textureSources markup paths carrying the canonical texture contract
     * @param array<string,string> $resolved theme: src => served URL, mutated in place
     */
    private function retryStageTexture(
        Project $project,
        array &$specs,
        array $textureSources,
        string $siteContext,
        string $imageGrade,
        array &$resolved
    ): void {
        if ($textureSources === []) {
            return;
        }
        foreach ($specs as $i => $spec) {
            if (!is_array($spec) || !CollectImagesStep::isStageTextureSpec($spec)) {
                continue;
            }
            if (($spec['status'] ?? '') === 'completed') {
                return;
            }
            $targetColor = is_string($spec['targetColor'] ?? null) ? $spec['targetColor'] : null;
            if (ContrastMath::hexToRgb((string) $targetColor) === null) {
                return; // already warned as unresolvable; no retry can fix it
            }
            $feasibility = self::stageTextureFeasibility($spec);
            if ($feasibility === 'impossible') {
                return; // already warned by the pre-batch feasibility guard
            }
            $sources = array_values(array_filter(
                array_map('strval', (array) ($spec['sources'] ?? [])),
                static fn (string $value): bool => $value !== '',
            ));
            $modelAttempts = $feasibility === 'generate' && ($spec['status'] ?? '') === 'failed'
                ? self::STAGE_TEXTURE_ATTEMPTS
                : 1; // 'synthesize' skipped the batch; go straight to the tile
            for ($attempt = 1; $attempt < $modelAttempts; $attempt++) {
                $rotated = CollectImagesStep::stageTextureSpec(
                    $sources === [] ? $textureSources : $sources,
                    $targetColor,
                    $attempt,
                );
                $specs[$i]['subject'] = $rotated['subject'];
                $specs[$i]['status'] = 'pending';
                unset($specs[$i]['error']);
                Narrator::write(sprintf(
                    "    retrying stage texture (attempt %d of %d)…\n",
                    $attempt + 1,
                    self::STAGE_TEXTURE_ATTEMPTS,
                ));
                $genSpec = self::generationSpec($specs[$i], $siteContext, $imageGrade);
                $this->drainBatch([$genSpec], function (int $pos, array $result) use (
                    $project, &$specs, $i, $genSpec, &$resolved, $imageGrade
                ): void {
                    $this->finish($project, $specs, $i, $genSpec, $result, $resolved, $imageGrade);
                    $project->writeJsonAtomic('images.json', $specs);
                });
                if (($specs[$i]['status'] ?? '') === 'completed') {
                    return;
                }
            }
            $this->deliverSynthesizedStageTexture($project, $specs, $i, $targetColor, $resolved);
            return;
        }
    }

    /**
     * Last resort after every model generation attempt failed: synthesize
     * the tile procedurally — the delivered surface color carrying faint
     * attenuated grain — so a committed texture blueprint still ships when
     * the image model's recitation filter or output quality won't cooperate
     * (both are stochastic; see retryStageTexture). The synthesized bytes
     * face the SAME delivery gate as generated ones; only if they too are
     * rejected does the texture degrade to the solid-cleanup path, warned
     * with the last recorded failure.
     *
     * @param array<int,array<string,mixed>> $specs images.json rows, mutated in place
     * @param array<string,string> $resolved theme: src => served URL, mutated in place
     */
    private function deliverSynthesizedStageTexture(
        Project $project,
        array &$specs,
        int $i,
        string $targetColor,
        array &$resolved
    ): void {
        $filename = (string) ($specs[$i]['filename'] ?? '');
        $lastError = (string) ($specs[$i]['error'] ?? '');
        $reason = $lastError === ''
            ? 'model generation skipped (palette contrast headroom too small for generated grain)'
            : "every model generation attempt failed (last: {$lastError})";
        $logRequest = [
            'model'             => 'code-synthesized',
            'prompt'            => "procedural tone-on-tone tile at {$targetColor}",
            'aspect_ratio'      => '1:1',
            'sample_image_size' => '',
            'mime'              => 'image/jpeg',
            'subject'           => (string) ($specs[$i]['subject'] ?? ''),
            'page_context'      => '',
            'style'             => '',
            'image_grade'       => '',
        ];
        try {
            $bytes = self::synthesizeStageTextureBytes($targetColor);
            $this->assertDeliveryMime($bytes, 'image/jpeg');
            self::assertStageTextureDelivery($specs[$i], $bytes);
        } catch (\Throwable $error) {
            Narrator::write("    FAILED {$filename}: synthesized fallback rejected: {$error->getMessage()}\n");
            ImageLogger::log($filename, $logRequest, [], $error->getMessage());
            $this->warnFailure($project, $specs[$i], $i, 'image/jpeg', $reason);
            return;
        }
        $project->writeText('theme/assets/' . $filename, $bytes);
        $specs[$i]['status'] = 'completed';
        $specs[$i]['url']    = $this->servedUrl($project, $filename);
        unset($specs[$i]['error']);
        $resolved[$specs[$i]['src']] = $specs[$i]['url'];
        Narrator::write("    synthesized {$filename} procedurally after generation attempts failed\n");
        ImageLogger::log($filename, $logRequest, [
            'path'  => 'theme/assets/' . $filename,
            'bytes' => strlen($bytes),
        ]);
        $project->addWarnings($this->id(), [
            "file='designDirection.json'; path=\"hero_blueprint.stage_backdrop\"; "
                . "block='theme/assets/{$filename}'; authored=\"texture\"; "
                . 'delivered="code-synthesized tone-on-tone tile"; '
                . 'disposition=' . $reason . '; '
                . 'the procedural fallback passed the same stage-texture delivery gate',
        ]);
        $project->writeJsonAtomic('images.json', $specs);
    }

    /**
     * A whisper-quiet procedural tile: the target surface color with faint
     * blurred grain, contrast-compressed hard toward the target so every
     * delivery-gate bound (drift, spread, chroma, foreground contrast where
     * satisfiable) holds by construction.
     */
    private static function synthesizeStageTextureBytes(string $targetColor): string
    {
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('procedural stage texture needs Imagick');
        }
        $target = ContrastMath::hexToRgb($targetColor);
        if ($target === null) {
            throw new \RuntimeException('procedural stage texture needs a resolvable target color');
        }
        $image = new \Imagick();
        $image->newImage(512, 512, new \ImagickPixel($targetColor));
        $image->addNoiseImage(\Imagick::NOISE_GAUSSIAN);
        $image->blurImage(0, 0.7);
        $quantum = (float) (\Imagick::getQuantumRange()['quantumRangeLong'] ?? 65535);
        // Grain amplitude retained around the target tone. Must stay well
        // inside the STAGE_TEXTURE_MAX_* gate bounds above: the full noise
        // range times $keep bounds the tile's spread, and the symmetric
        // noise keeps the mean on target.
        $keep = 0.20;
        $channels = [\Imagick::CHANNEL_RED, \Imagick::CHANNEL_GREEN, \Imagick::CHANNEL_BLUE];
        foreach ($channels as $c => $channel) {
            $image->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $keep, $channel);
            $image->evaluateImage(
                \Imagick::EVALUATE_ADD,
                (float) $target[$c] / 255.0 * $quantum * (1.0 - $keep),
                $channel,
            );
        }
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(92);
        return (string) $image->getImageBlob();
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
        $ratio = GeminiImage::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape'));
        // A .png placeholder is a transparent-background asset: request PNG
        // bytes, prompt for a flat white background (the image model cannot render
        // alpha), and key that background out after generation.
        $mime = GeminiImage::mimeForFilename((string) ($spec['filename'] ?? ''));
        $isStageTexture = CollectImagesStep::isStageTextureSpec($spec);
        // The stage texture's subject is its complete render instruction. The
        // page/site context and grade all describe pictorial SCENES — the
        // composed guidance renders as "editorial photograph … negative
        // space", which fights the flat tone-on-tone tile and trips the
        // busyness gate (assertStageTextureDelivery) — so none of them are
        // sent for the texture spec.
        return [
            'prompt'            => ImagePromptComposer::compose(
                $subject ?? (string) ($spec['subject'] ?? ''),
                $isStageTexture ? '' : (string) ($spec['pageContext'] ?? ''),
                (string) ($spec['style'] ?? ''),
                $isStageTexture ? '' : $siteContext,
                $isStageTexture ? '' : $imageGrade,
                $mime === 'image/png',
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
            'page_context'      => CollectImagesStep::isStageTextureSpec($spec)
                ? ''
                : (string) ($spec['pageContext'] ?? ''),
            'style'             => (string) ($spec['style'] ?? ''),
            'image_grade'       => CollectImagesStep::isStageTextureSpec($spec) ? '' : $imageGrade,
        ];
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
        ?string $subject = null
    ): void {
        $filename = (string) $specs[$i]['filename'];
        $logRequest = $this->requestLog($specs[$i], $genSpec, $imageGrade, $subject);
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
            if (CollectImagesStep::isStageTextureSpec($specs[$i])) {
                $bytes = self::alignStageTextureTone($specs[$i], $bytes);
                self::assertStageTextureDelivery($specs[$i], $bytes);
            }
            if ($genSpec['mime'] === 'image/png') {
                // The image model cannot render real alpha: the prompt asked for a flat
                // solid white background instead, keyed out here so the asset
                // gets the transparency its .png promises.
                $bytes = ImageTransparency::keyOutBackground($bytes);
            }
            // ImageTransparency fails soft by returning its input. Verify the
            // post-processed bytes as well before choosing the file extension.
            $this->assertDeliveryMime($bytes, $genSpec['mime']);
        } catch (\Throwable $e) {
            $specs[$i]['status'] = 'failed';
            $specs[$i]['error']  = $e->getMessage();
            Narrator::write("    FAILED {$filename}: {$e->getMessage()}\n");
            ImageLogger::log($filename, $logRequest, [], $e->getMessage());
            // Stage texture failures are never warned here: the retry ladder
            // and synthesis fallback own the texture's one final warning.
            if (!CollectImagesStep::isStageTextureSpec($specs[$i])) {
                $this->warnFailure($project, $specs[$i], $i, $genSpec['mime'], $e->getMessage());
            }
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
            $regenSpecs[$i] = self::generationSpec($specs[$i], $siteContext, $imageGrade, $subject);
        }
        if ($regenSpecs === []) {
            return;
        }

        // Stream repaired images through the same bounded-memory contract as
        // the original batch. Persist each completion immediately so a large
        // filtered cohort cannot rebuild the all-bytes-in-memory/OOM path or
        // lose every successful repair if a later request is interrupted.
        $indices = array_keys($regenSpecs);
        $batchSpecs = array_values($regenSpecs);
        $this->drainBatch($batchSpecs, function (int $pos, array $result) use (
            $project, &$specs, $indices, $batchSpecs, $imageGrade, &$resolved, $subjects
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

    /**
     * Shift a generated stage tile's mean onto the delivered surface color
     * before gating (BIGR-776). The image model reliably renders the texture
     * CHARACTER but often lands the average tone a hair off the requested
     * hex — near-miss drifts just past assertStageTextureDelivery's bound —
     * and a uniform per-channel shift corrects exactly that without touching
     * the grain. Corrections stay bounded: a mean further than 48/255 per
     * channel means the model ignored the color request, and clipping a
     * large shift could flatten real busyness past the gate, so those bytes
     * are returned unchanged for the gate to judge as delivered. Any
     * inspection problem also returns the original bytes — the gate remains
     * the single authority on acceptance.
     *
     * @param array<string,mixed> $spec one images.json row
     */
    private static function alignStageTextureTone(array $spec, string $bytes): string
    {
        $target = ContrastMath::hexToRgb((string) ($spec['targetColor'] ?? ''));
        if ($target === null || !extension_loaded('imagick')) {
            return $bytes;
        }
        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $quantum = (float) (\Imagick::getQuantumRange()['quantumRangeLong'] ?? 65535);
            $channels = [\Imagick::CHANNEL_RED, \Imagick::CHANNEL_GREEN, \Imagick::CHANNEL_BLUE];
            $deltas = [];
            foreach ($channels as $c => $channel) {
                $mean = $image->getImageChannelMean($channel);
                if (!is_array($mean)) {
                    return $bytes;
                }
                $deltas[$c] = (float) $target[$c] - 255.0 * (float) $mean['mean'] / $quantum;
            }
            $drift = max(array_map('abs', $deltas));
            // Below the gate's own drift bound the tile passes as-is:
            // keep the model's exact bytes rather than re-encoding.
            if ($drift <= self::STAGE_TEXTURE_MAX_DRIFT
                || $drift > self::STAGE_TEXTURE_MAX_ALIGNABLE_DRIFT
            ) {
                return $bytes;
            }
            foreach ($channels as $c => $channel) {
                $image->evaluateImage(\Imagick::EVALUATE_ADD, $deltas[$c] / 255.0 * $quantum, $channel);
            }
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(92);
            $aligned = (string) $image->getImageBlob();
            return $aligned === '' ? $bytes : $aligned;
        } catch (\Throwable) {
            return $bytes;
        }
    }

    /**
     * The texture is opaque paint directly beneath header/hero copy, so a
     * syntactically valid JPEG is not sufficient. Bound its palette drift,
     * tonal spread and contrast against the foregrounds actually delivered in
     * the textured roots. Failure is caught by finish() and degrades to solid.
     *
     * @param array<string,mixed> $spec
     */
    private static function assertStageTextureDelivery(array $spec, string $bytes): void
    {
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException(
                'stage texture validation unavailable because Imagick is not loaded; delivered solid fallback'
            );
        }
        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            $quantum = (float) (\Imagick::getQuantumRange()['quantumRangeLong'] ?? 65535);
            $sourceChannelSpread = 0.0;
            $sourceChannelDeviation = 0.0;
            foreach ([\Imagick::CHANNEL_RED, \Imagick::CHANNEL_GREEN, \Imagick::CHANNEL_BLUE] as $channel) {
                $range = $image->getImageChannelRange($channel);
                $mean = $image->getImageChannelMean($channel);
                if (!is_array($range) || !is_array($mean)) {
                    throw new \RuntimeException('stage texture channel statistics are unavailable');
                }
                $sourceChannelSpread = max(
                    $sourceChannelSpread,
                    ((float) $range['maxima'] - (float) $range['minima']) / $quantum,
                );
                $sourceChannelDeviation = max(
                    $sourceChannelDeviation,
                    (float) $mean['standardDeviation'] / $quantum,
                );
            }
            $image->thumbnailImage(24, 24, true);
            $pixels = [];
            $sum = [0, 0, 0];
            foreach ($image->getPixelIterator() as $row) {
                foreach ($row as $pixel) {
                    $color = $pixel->getColor();
                    $rgb = [(int) $color['r'], (int) $color['g'], (int) $color['b']];
                    $pixels[] = ['rgb' => $rgb, 'luminance' => ContrastMath::luminance($rgb)];
                    $sum[0] += $rgb[0];
                    $sum[1] += $rgb[1];
                    $sum[2] += $rgb[2];
                }
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException('stage texture pixels could not be inspected: ' . $error->getMessage());
        }
        if ($pixels === []) {
            throw new \RuntimeException('stage texture decoded with no inspectable pixels');
        }
        // The full-res max-minus-min spread is a pre-filter for loud tiles;
        // one dark fleck on an otherwise quiet surface spans it, so it sits
        // a step wider than the thumbnail bounds below, which average flecks
        // away and are the perceptual authority on busyness.
        if ($sourceChannelSpread > self::STAGE_TEXTURE_MAX_SOURCE_SPREAD
            || $sourceChannelDeviation > self::STAGE_TEXTURE_MAX_SOURCE_DEVIATION
        ) {
            throw new \RuntimeException(sprintf(
                'stage texture source pixels are too visually busy (channel spread %.3f, deviation %.3f)',
                $sourceChannelSpread,
                $sourceChannelDeviation,
            ));
        }

        usort($pixels, static fn (array $a, array $b): int => $a['luminance'] <=> $b['luminance']);
        $count = count($pixels);
        $mean = [
            (int) round($sum[0] / $count),
            (int) round($sum[1] / $count),
            (int) round($sum[2] / $count),
        ];
        $target = ContrastMath::hexToRgb((string) ($spec['targetColor'] ?? ''));
        if ($target === null) {
            throw new \RuntimeException('stage texture has no single resolvable delivered hero surface color');
        }
        $channelDrift = max(
            abs($mean[0] - $target[0]),
            abs($mean[1] - $target[1]),
            abs($mean[2] - $target[2]),
        );
        if ($channelDrift > self::STAGE_TEXTURE_MAX_DRIFT) {
            throw new \RuntimeException(
                'stage texture mean color drifted too far from target '
                . (string) $spec['targetColor'] . " (max channel drift {$channelDrift})"
            );
        }

        $low = $pixels[(int) floor(($count - 1) * 0.05)];
        $high = $pixels[(int) ceil(($count - 1) * 0.95)];
        $centralSpread = $high['luminance'] - $low['luminance'];
        $fullSpread = $pixels[$count - 1]['luminance'] - $pixels[0]['luminance'];
        if ($centralSpread > self::STAGE_TEXTURE_MAX_CENTRAL_LUMINANCE_SPREAD
            || $fullSpread > self::STAGE_TEXTURE_MAX_FULL_LUMINANCE_SPREAD
        ) {
            throw new \RuntimeException(sprintf(
                'stage texture is too visually busy (central luminance spread %.3f, full spread %.3f)',
                $centralSpread,
                $fullSpread,
            ));
        }

        // Equal-luminance hue changes can be every bit as loud as light/dark
        // contrast. Bound each channel's central and full spread as well, so a
        // red/green checker cannot pass merely because its luminance is flat.
        $channelCentralSpread = 0;
        $channelFullSpread = 0;
        foreach ([0, 1, 2] as $channel) {
            $values = array_column(array_column($pixels, 'rgb'), $channel);
            sort($values, SORT_NUMERIC);
            $channelCentralSpread = max(
                $channelCentralSpread,
                $values[(int) ceil(($count - 1) * 0.95)]
                    - $values[(int) floor(($count - 1) * 0.05)],
            );
            $channelFullSpread = max($channelFullSpread, $values[$count - 1] - $values[0]);
        }
        if ($channelCentralSpread > self::STAGE_TEXTURE_MAX_CENTRAL_CHANNEL_SPREAD
            || $channelFullSpread > self::STAGE_TEXTURE_MAX_FULL_CHANNEL_SPREAD
        ) {
            throw new \RuntimeException(
                "stage texture is too chromatically busy (central channel spread {$channelCentralSpread}, "
                . "full channel spread {$channelFullSpread})"
            );
        }

        foreach ((array) ($spec['foregroundColors'] ?? []) as $foregroundHex) {
            $foreground = ContrastMath::hexToRgb((string) $foregroundHex);
            if ($foreground === null) {
                continue;
            }
            $minimum = INF;
            foreach ($pixels as $pixel) {
                $minimum = min($minimum, ContrastMath::ratio($foreground, $pixel['rgb']));
            }
            if ($minimum < ContrastMath::NORMAL_TEXT) {
                throw new \RuntimeException(sprintf(
                    'stage texture contrast %.2f:1 against delivered foreground %s is below %.1f:1',
                    $minimum,
                    (string) $foregroundHex,
                    ContrastMath::NORMAL_TEXT,
                ));
            }
        }
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
        if (CollectImagesStep::isStageTextureSpec($spec)) {
            $project->addWarnings($this->id(), [
                "file='designDirection.json'; path=\"hero_blueprint.stage_backdrop\"; "
                    . "block='header/hero root groups{$where}'; authored=\"texture\"; "
                    . 'delivered="solid where safely isolated; pre-cleanup bytes otherwise"; '
                    . 'disposition=generated stage texture rejected and code-owned background cleanup requested; '
                    . 'any unsafe retained reference is recorded separately with its file context '
                    . "(asset theme/assets/{$filename}, MIME {$mime}); error: {$error}",
            ]);
            return;
        }
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
            $url = (string) ($spec['url'] ?? '');
            if ($url !== '') {
                $sources[$url] = true;
            }
            if (CollectImagesStep::isStageTextureSpec($spec)) {
                $filename = (string) ($spec['filename'] ?? basename(GeneratedMarkup::STAGE_TEXTURE_ASSET));
                $sources[$this->servedUrl($project, $filename)] = true;
            }
        }
        if ($sources === []) {
            return;
        }

        foreach ($project->markupFiles() as $absolute) {
            $relative = $project->relative($absolute);
            $content = $project->readText($relative);
            $updated = $content;
            foreach (array_keys($sources) as $source) {
                $candidate = self::removeSourceFromMarkup($updated, $source);
                $stageSource = GeneratedMarkup::isStageTextureSource($source);
                $unsafeResidual = $stageSource
                    ? self::containsStageTextureSourceEvidence($candidate, $source)
                    : self::firstMediaSourcePosition($candidate, $source) !== null;
                if ($unsafeResidual) {
                    // The source sits in malformed/unclosed markup without a
                    // safe block span. Keep this source's pre-cleanup bytes and
                    // report the residual instead of half-mutating the file.
                    $project->addWarnings($this->id(), [
                        'file=' . Warnings::value($relative)
                            . '; block=' . Warnings::value($stageSource
                                ? 'unsafe stage-texture root'
                                : 'unsafe generated media owner')
                            . '; authored source=' . Warnings::value($source)
                            . '; delivered=' . Warnings::value($stageSource
                                ? 'safe stage roots solid; unsafe root pre-cleanup bytes retained'
                                : 'pre-cleanup media bytes retained')
                            . '; disposition=' . ($stageSource
                                ? 'safe stage roots were cleaned independently while this unsafe root remained for later repair'
                                : 'no safe media isolation boundary was available'),
                    ]);
                    if ($stageSource) {
                        $updated = $candidate;
                    }
                    continue;
                }
                if ($stageSource && self::firstMediaSourcePosition($candidate, $source) !== null) {
                    $project->addWarnings($this->id(), [
                        'file=' . Warnings::value($relative)
                            . "; block='ordinary non-stage media reference'; authored source="
                            . Warnings::value($source)
                            . '; delivered="retained and unwired"; disposition=the reserved source was not part '
                            . 'of a committed stage root, so stage failure cleanup left its bytes intact and the '
                            . 'scoped URL resolver will not map it',
                    ]);
                }
                $updated = $candidate;
            }
            if ($updated !== $content) {
                $project->writeText($relative, $updated);
            }
        }
    }

    /** Remove every safely isolated occurrence of one failed asset source. */
    private static function removeSourceFromMarkup(string $markup, string $source): string
    {
        if (GeneratedMarkup::isStageTextureSource($source)) {
            return self::removeMatchingBlockBackgroundImages($markup, $source);
        }
        return self::removeOrdinarySourceFromMarkup($markup, $source);
    }

    /** Remove an ordinary failed/colliding media source at its smallest safe owner. */
    private static function removeOrdinarySourceFromMarkup(string $markup, string $source): string
    {
        while (($position = self::firstMediaSourcePosition($markup, $source)) !== null) {
            $withoutBlockBackground = self::removeMatchingBlockBackgroundImages($markup, $source);
            if ($withoutBlockBackground !== $markup) {
                $markup = $withoutBlockBackground;
                continue;
            }
            $document = BlockMarkup::parse($markup);
            $best = null;
            foreach ($document->indices() as $index) {
                if (!in_array($document->name($index), ['image', 'cover', 'media-text'], true)) {
                    continue;
                }
                $start = $document->openingOffset($index);
                $end = $document->endOffset($index);
                if ($end === null || $position < $start || $position >= $end) {
                    continue;
                }
                $length = $end - $start;
                if ($best === null || $length < $best['length']) {
                    $best = ['index' => $index, 'start' => $start, 'length' => $length];
                }
            }
            if ($best !== null) {
                $name = $document->name($best['index']);
                if ($name === 'cover') {
                    // A cover's image is only one visual layer. Strip that
                    // layer while retaining the cover wrapper and every byte
                    // of its headline, copy, buttons, and freeform content.
                    $coverMarkup = substr($markup, $best['start'], $best['length']);
                    $beforeCoverCleanup = $coverMarkup;
                    $coverDocument = BlockMarkup::parse($coverMarkup);
                    $coverIndex = $coverDocument->topLevel();
                    if ($coverIndex === null || $coverDocument->name($coverIndex) !== 'cover') {
                        break;
                    }
                    $attrs = $coverDocument->attrs($coverIndex);
                    if (is_array($attrs)) {
                        $changedAttrs = false;
                        foreach (['url', 'src'] as $key) {
                            if (($attrs[$key] ?? null) === $source) {
                                unset($attrs[$key]);
                                $changedAttrs = true;
                            }
                        }
                        if ($changedAttrs) {
                            unset($attrs['id'], $attrs['focalPoint']);
                            $coverDocument->setAttrs($coverIndex, $attrs);
                            $coverMarkup = $coverDocument->render();
                        }
                    }
                    $coverMarkup = self::removeMatchingImageTags($coverMarkup, $source);
                    $quotedSource = preg_quote($source, '~');
                    $coverMarkup = (string) preg_replace(
                        '~background-image\s*:\s*url\(\s*(["\']?)' . $quotedSource . '\1\s*\)\s*;?~i',
                        '',
                        $coverMarkup,
                    );
                    if ($coverMarkup === $beforeCoverCleanup) {
                        break;
                    }
                    $markup = substr_replace(
                        $markup,
                        $coverMarkup,
                        $best['start'],
                        $best['length'],
                    );
                    continue;
                }

                $replacement = '';
                if ($name === 'media-text') {
                    // Unwrap media-text and retain every authored inner block
                    // byte-for-byte; keeping its grid with no media would leave
                    // a large empty column beside the surviving copy.
                    foreach ($document->children($best['index']) as $child) {
                        $childStart = $document->openingOffset($child);
                        $childEnd = $document->endOffset($child);
                        if ($childEnd === null) {
                            $replacement = null;
                            break;
                        }
                        $replacement .= substr($markup, $childStart, $childEnd - $childStart);
                    }
                }
                if ($replacement === null) {
                    break;
                }
                $markup = substr_replace(
                    $markup,
                    $replacement,
                    $best['start'],
                    $best['length'],
                );
                continue;
            }

            // Recovered placeholders can be bare HTML with no Gutenberg block.
            // Remove only an img whose src attribute is this exact source.
            $withoutImage = self::removeMatchingImageTags($markup, $source);
            if ($withoutImage === $markup) {
                break; // unsafe/unrecognized context: preserve the file bytes
            }
            $markup = $withoutImage;
        }
        return $markup;
    }

    /**
     * Remove a failed source used as block-support background paint while
     * retaining the block, its children, its solid fallback and unrelated
     * style families. The stage texture's code-owned geometry/class leave
     * with its failed image so a later CSS rule cannot treat the root as live.
     */
    private static function removeMatchingBlockBackgroundImages(string $markup, string $source): string
    {
        try {
            $document = BlockMarkup::parse($markup);
            $stageTexture = GeneratedMarkup::isStageTextureSource($source);
            if ($stageTexture) {
                $edits = [];
                foreach ($document->indices() as $index) {
                    $attrs = $document->attrs($index);
                    $end = $document->endOffset($index);
                    if (!$document->isStructurallySafe($index)
                        || $end === null
                        || !is_array($attrs)
                        || !CollectImagesStep::isCommittedStageTextureAttrs($attrs)
                    ) {
                        continue;
                    }
                    $start = $document->openingOffset($index);
                    $block = substr($markup, $start, $end - $start);
                    $cleaned = GeneratedMarkup::withoutStageTextureBackdrop($block);
                    if ($cleaned === null || GeneratedMarkup::hasOwnStageTextureEvidence($cleaned)) {
                        continue;
                    }
                    $beforeBlock = BlockMarkup::parse($block);
                    $afterBlock = BlockMarkup::parse($cleaned);
                    $beforeRoot = $beforeBlock->topLevel();
                    $afterRoot = $afterBlock->topLevel();
                    if ($beforeRoot === null || $afterRoot === null) {
                        continue;
                    }
                    $localEdits = [[
                        'offset' => $beforeBlock->openingOffset($beforeRoot),
                        'length' => $beforeBlock->openingLength($beforeRoot),
                        'replacement' => substr(
                            $cleaned,
                            $afterBlock->openingOffset($afterRoot),
                            $afterBlock->openingLength($afterRoot),
                        ),
                    ]];
                    $beforeOwn = $beforeBlock->ownHtml($beforeRoot);
                    $afterOwn = $afterBlock->ownHtml($afterRoot);
                    $tagPattern = GeneratedMarkup::SAVED_OPENING_TAG;
                    if (preg_match($tagPattern, $beforeOwn, $beforeTag, PREG_OFFSET_CAPTURE) !== 1
                        || preg_match($tagPattern, $afterOwn, $afterTag, PREG_OFFSET_CAPTURE) !== 1
                    ) {
                        continue;
                    }
                    $localEdits[] = [
                        'offset' => $beforeBlock->openingOffset($beforeRoot)
                            + $beforeBlock->openingLength($beforeRoot)
                            + $beforeTag['tag'][1],
                        'length' => strlen($beforeTag['tag'][0]),
                        'replacement' => $afterTag['tag'][0],
                    ];
                    usort($localEdits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
                    $candidate = $block;
                    foreach ($localEdits as $edit) {
                        $candidate = substr_replace(
                            $candidate,
                            $edit['replacement'],
                            $edit['offset'],
                            $edit['length'],
                        );
                    }
                    if ($candidate !== $cleaned) {
                        continue;
                    }
                    foreach ($localEdits as $edit) {
                        $edit['offset'] += $start;
                        $edits[] = $edit;
                    }
                }
                usort($edits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
                foreach ($edits as $edit) {
                    $markup = substr_replace(
                        $markup,
                        $edit['replacement'],
                        $edit['offset'],
                        $edit['length'],
                    );
                }
                return $markup;
            }

            $changed = false;
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index);
                if (!is_array($attrs)) {
                    continue;
                }
                $style = $attrs['style'] ?? null;
                $background = is_array($style) ? ($style['background'] ?? null) : null;
                $image = is_array($background) && is_array($background['backgroundImage'] ?? null)
                    ? $background['backgroundImage']
                    : [];
                $blockStageTexture = false;
                $backgroundMatches = ($image['url'] ?? null) === $source;
                if ($backgroundMatches) {
                    unset($background['backgroundImage']);
                }
                if ($backgroundMatches) {
                    if ($background === []) {
                        unset($style['background']);
                    } else {
                        $style['background'] = $background;
                    }
                    if ($style === []) {
                        unset($attrs['style']);
                    } else {
                        $attrs['style'] = $style;
                    }
                }
                if ($backgroundMatches) {
                    $document->setAttrs($index, $attrs);
                    $changed = true;
                }
            }
            return $changed ? $document->render() : $markup;
        } catch (\Throwable) {
            return $markup;
        }
    }

    /** Root/block-scoped marker evidence, excluding harmless descendant text. */
    private static function containsStageTextureMarkerEvidence(string $markup): bool
    {
        try {
            $document = BlockMarkup::parse($markup);
            $sawBlock = false;
            foreach ($document->indices() as $index) {
                $sawBlock = true;
                $attrs = $document->attrs($index);
                $className = is_array($attrs) && is_string($attrs['className'] ?? null)
                    ? $attrs['className']
                    : '';
                $tokens = preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array(GeneratedMarkup::STAGE_TEXTURE_CLASS, $tokens, true)) {
                    return true;
                }
                $ownHtml = $document->ownHtml($index);
                if (preg_match(
                    GeneratedMarkup::SAVED_OPENING_TAG,
                    $ownHtml,
                    $opening,
                ) !== 1) {
                    continue;
                }
                foreach (MarkupSanitizer::openingTagAttributes($opening['tag']) as $attribute) {
                    if ($attribute['name'] !== 'class'
                        || $attribute['valueStart'] === null
                        || $attribute['valueEnd'] === null
                    ) {
                        continue;
                    }
                    $value = substr(
                        $opening['tag'],
                        $attribute['valueStart'],
                        $attribute['valueEnd'] - $attribute['valueStart'],
                    );
                    $htmlTokens = preg_split('/\s+/', trim(html_entity_decode($value)), -1, PREG_SPLIT_NO_EMPTY)
                        ?: [];
                    if (in_array(GeneratedMarkup::STAGE_TEXTURE_CLASS, $htmlTokens, true)) {
                        return true;
                    }
                }
            }
            return !$sawBlock && str_contains($markup, GeneratedMarkup::STAGE_TEXTURE_CLASS);
        } catch (\Throwable) {
            return str_contains($markup, GeneratedMarkup::STAGE_TEXTURE_CLASS);
        }
    }

    /** Whether one marker-bearing stage root still names this exact source. */
    private static function containsStageTextureSourceEvidence(string $markup, string $source): bool
    {
        try {
            $document = BlockMarkup::parse($markup);
            foreach ($document->indices() as $index) {
                $attrs = $document->attrs($index);
                $attrMarker = false;
                $attrSource = false;
                if (is_array($attrs)) {
                    $classes = is_string($attrs['className'] ?? null)
                        ? preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY)
                        : [];
                    $attrMarker = in_array(GeneratedMarkup::STAGE_TEXTURE_CLASS, $classes ?: [], true);
                    $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                    $background = is_array($style['background'] ?? null) ? $style['background'] : [];
                    $image = is_array($background['backgroundImage'] ?? null)
                        ? $background['backgroundImage']
                        : [];
                    $attrSource = ($image['url'] ?? null) === $source;
                }

                $htmlMarker = false;
                $htmlSource = false;
                $ownHtml = $document->ownHtml($index);
                if (preg_match(
                    GeneratedMarkup::SAVED_OPENING_TAG,
                    $ownHtml,
                    $opening,
                ) === 1) {
                    foreach (MarkupSanitizer::openingTagAttributes($opening['tag']) as $attribute) {
                        if ($attribute['valueStart'] === null || $attribute['valueEnd'] === null) {
                            continue;
                        }
                        $value = substr(
                            $opening['tag'],
                            $attribute['valueStart'],
                            $attribute['valueEnd'] - $attribute['valueStart'],
                        );
                        if ($attribute['name'] === 'class') {
                            $tokens = preg_split(
                                '/\s+/',
                                trim(html_entity_decode($value)),
                                -1,
                                PREG_SPLIT_NO_EMPTY,
                            ) ?: [];
                            $htmlMarker = in_array(GeneratedMarkup::STAGE_TEXTURE_CLASS, $tokens, true);
                        } elseif ($attribute['name'] === 'style') {
                            $htmlSource = GeneratedMarkup::hasStageTextureInlineStyleSource($value, $source);
                        }
                    }
                }
                if (($attrMarker || $htmlMarker) && ($attrSource || $htmlSource)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return str_contains($markup, GeneratedMarkup::STAGE_TEXTURE_CLASS)
                && str_contains($markup, $source);
        }
    }

    /** Remove bare/rendered img tags whose src is this exact failed source. */
    private static function removeMatchingImageTags(string $markup, string $source): string
    {
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/is',
            static function (array $match) use ($source): string {
                if (!preg_match(
                    '/\bsrc\s*=\s*(?:(["\'])' . preg_quote($source, '/') . '\1|'
                    . preg_quote($source, '/') . '(?=\s|\/?>))/i',
                    $match[0],
                )) {
                    return $match[0];
                }
                return '';
            },
            $markup,
        );
    }

    /** Byte position of an exact block-JSON url/src or HTML src value. */
    private static function firstMediaSourcePosition(string $markup, string $source): ?int
    {
        $quoted = preg_quote($source, '/');
        $patterns = [
            '/"(?:url|src)"\s*:\s*"' . $quoted . '"/i',
            '/\bsrc\s*=\s*(?:(["\'])' . $quoted . '\1|' . $quoted . '(?=\s|\/?>))/i',
            '/background-image\s*:\s*url\(\s*(["\']?)' . $quoted . '\1\s*\)/i',
            '/background\s*:[^;]*url\(\s*(["\']?)' . $quoted . '\1\s*\)/i',
        ];
        $positions = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $markup, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$match, $offset]) {
                $within = strpos($match, $source);
                if ($within !== false) {
                    $positions[] = $offset + $within;
                }
            }
        }
        return $positions === [] ? null : min($positions);
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
        $stageServed = $resolved[GeneratedMarkup::STAGE_TEXTURE_ASSET] ?? null;
        unset($resolved[GeneratedMarkup::STAGE_TEXTURE_ASSET]);
        foreach ($project->themeFiles() as $rel) {
            $content = $project->readText('theme/' . $rel);
            $updated = strtr($content, $resolved);
            if (is_string($stageServed)) {
                $updated = $this->rewriteStageTextureContracts(
                    $project,
                    'theme/' . $rel,
                    $updated,
                    $stageServed,
                );
            }
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
            if (is_string($stageServed)) {
                $updated = $this->rewriteStageTextureContracts($project, $rel, $updated, $stageServed);
            }
            if ($updated !== $content) {
                $project->writeText($rel, $updated);
            }
        }
    }

    /** Resolve the code-owned source only inside complete committed stage roots. */
    private function rewriteStageTextureContracts(
        Project $project,
        string $relative,
        string $markup,
        string $served,
    ): string {
        $before = $markup;
        try {
            $document = BlockMarkup::parse($markup);
            $htmlEdits = [];
            $indices = [];
            foreach ($document->indices() as $index) {
                $end = $document->endOffset($index);
                if (!$document->isStructurallySafe($index) || $end === null) {
                    continue;
                }
                $start = $document->openingOffset($index);
                $block = substr($markup, $start, $end - $start);
                if (GeneratedMarkup::hasExactStageTextureContract(
                    $block,
                    GeneratedMarkup::STAGE_TEXTURE_ASSET,
                ) === false) {
                    continue;
                }
                $ownHtml = $document->ownHtml($index);
                if (preg_match(
                    GeneratedMarkup::SAVED_OPENING_TAG,
                    $ownHtml,
                    $opening,
                    PREG_OFFSET_CAPTURE,
                ) !== 1) {
                    throw new \RuntimeException('stage root has no isolated saved wrapper');
                }
                $tag = $opening['tag'][0];
                $styles = array_values(array_filter(
                    MarkupSanitizer::openingTagAttributes($tag),
                    static fn (array $attribute): bool => $attribute['name'] === 'style'
                        && $attribute['valueStart'] !== null
                        && $attribute['valueEnd'] !== null,
                ));
                if (count($styles) !== 1) {
                    throw new \RuntimeException('stage root has no unique saved style');
                }
                $style = substr(
                    $tag,
                    $styles[0]['valueStart'],
                    $styles[0]['valueEnd'] - $styles[0]['valueStart'],
                );
                $servedStyle = GeneratedMarkup::serveStageTextureInlineStyle($style, $served);
                if ($servedStyle === null
                    || !GeneratedMarkup::hasExactStageTextureInlineStyle($servedStyle, $served)
                ) {
                    throw new \RuntimeException('stage root saved source could not be resolved safely');
                }
                $tag = substr_replace(
                    $tag,
                    $servedStyle,
                    $styles[0]['valueStart'],
                    $styles[0]['valueEnd'] - $styles[0]['valueStart'],
                );
                $tagStart = $document->openingOffset($index)
                    + $document->openingLength($index)
                    + $opening['tag'][1];
                $htmlEdits[] = [
                    'offset' => $tagStart,
                    'length' => strlen($opening['tag'][0]),
                    'tag' => $tag,
                ];
                $indices[] = $index;
            }
            usort($htmlEdits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
            foreach ($htmlEdits as $edit) {
                $markup = substr_replace($markup, $edit['tag'], $edit['offset'], $edit['length']);
            }
            $document = BlockMarkup::parse($markup);
            foreach ($indices as $index) {
                $attrs = $document->attrs($index);
                if (!is_array($attrs)) {
                    throw new \RuntimeException('stage root attrs disappeared during URL resolution');
                }
                $styleAttrs = is_array($attrs['style'] ?? null) ? $attrs['style'] : [];
                $background = is_array($styleAttrs['background'] ?? null) ? $styleAttrs['background'] : [];
                $background['backgroundImage'] = ['url' => $served];
                $styleAttrs['background'] = $background;
                $attrs['style'] = $styleAttrs;
                $document->setAttrs($index, $attrs);
            }
            $markup = $document->render();
            $verified = BlockMarkup::parse($markup);
            foreach ($indices as $index) {
                $end = $verified->endOffset($index);
                $start = $verified->openingOffset($index);
                if ($end === null || !GeneratedMarkup::hasExactStageTextureContract(
                    substr($markup, $start, $end - $start),
                    $served,
                )) {
                    throw new \RuntimeException('stage root failed served URL verification');
                }
            }
            return $markup;
        } catch (\Throwable $error) {
            $project->addWarnings($this->id(), [
                "file='{$relative}'; block='stage-texture root'; authored source="
                    . Warnings::value(GeneratedMarkup::STAGE_TEXTURE_ASSET)
                    . '; delivered="pre-resolution bytes retained"; disposition=served stage URL could not '
                    . 'be wired transactionally; error=' . $error->getMessage(),
            ]);
            return $before;
        }
    }
}
