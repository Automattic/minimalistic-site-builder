<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BareListItemLift;
use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockFixerOutcome;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\BlockSerializer\AlignmentClassLoss;
use Automattic\SiteBuild\BlockSerializer\AlignmentClassLossDetector;
use Automattic\SiteBuild\BlockSerializer\Repair;
use Automattic\SiteBuild\ImageCaptions;
use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\PhotographySite;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\StaggeredChildren;
use Automattic\SiteBuild\ShapeMarkup;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Step 8 (deterministic): repair block-validation issues in generated markup.
 *
 * Input:  theme/templates/*.html + theme/parts/*.html
 * Output: the same files, re-serialized to match WordPress save() exactly.
 *
 * Runs the LayoutFixer width/rhythm and design-direction shape normalization
 * FIRST. Both edit block-comment JSON attributes, and the block
 * re-serialization right after syncs saved HTML with those attributes.
 *
 * Delegates the effectful repair to an injected BlockFixer.
 */
final class FixBlocksStep implements Step
{
    private const LOG_FILE = 'fix-blocks.log';

    public function __construct(private BlockFixer $fixer, private bool $htmlFirst = false) {}

    public function id(): string
    {
        return 'fix-blocks';
    }

    public function label(): string
    {
        return 'Fix block validation';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            // Templates/pages are only scanned when they already exist; in the
            // default graph assemble-pages writes them after this step, so an
            // optional page cannot be declared as a required upstream read.
            reads: [
                'designDirection.json',
                ...($this->htmlFirst ? ['design/site.css'] : []),
                'meta.json',
                'siteSpec.json',
                'theme/theme.json',
                'theme/parts/*',
            ],
            writes: ['theme/parts/*', 'theme/pages/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $beforeInitialPass = self::snapshotThemeFiles($project);
        $alignmentBaselines = [];
        $outcomes = [];
        $failedFiles = [];
        $shape = DesignDirectionStep::shapeFor($project);
        // A failed file is restored to these exact step-entry bytes. Keep the
        // shape defects observable in that snapshot separate from changes
        // exposed by LayoutFixer or a successful first fixer pass: only the
        // former can truthfully describe the file ultimately delivered after
        // rollback.
        $entryShapeChanges = self::shapeChangesInSnapshot($beforeInitialPass, $shape);
        $shapeChanges = [];
        $designCss = $this->htmlFirst && $project->exists('design/site.css')
            ? $project->readText('design/site.css')
            : null;
        $wideMeasureRootClasses = $designCss === null
            ? []
            : GeneratedMarkup::wideMeasureSubjectClasses($designCss);
        $wideClassTokens = $designCss === null
            ? []
            : SectionLayoutStep::wideClassTokens($designCss);
        try {
            $listReport = self::liftBareListItems($project);
            $listNotes = $listReport['notes'];
            $listWarnings = $listReport['warnings'];
            $listBlocked = $listReport['blocked'];
            $captionReport = self::stripImageCaptions($project, self::failurePaths($failedFiles));
            $captionNotes = $captionReport['notes'];
            $captionWarnings = $captionReport['warnings'];
            $layoutNotes = self::normalizeLayouts(
                $project,
                [],
                $this->htmlFirst,
                $wideMeasureRootClasses,
                $wideClassTokens,
            );
            $shapeChanges = self::normalizeShapes($project, $shape);
            $alignmentBaselines[] = self::snapshotThemeFiles($project);
            $blockedFailures = [];
            foreach ($listBlocked as $blocked) {
                $blockedFailures[$blocked['file']] = isset($blockedFailures[$blocked['file']])
                    ? $blockedFailures[$blocked['file']] . '; ' . $blocked['reason']
                    : $blocked['reason'];
            }
            $initialOutcome = BlockFixerOutcome::run($this->fixer, $project->themePath())
                ->withFailures($blockedFailures);
            $outcomes[] = $initialOutcome;
            $summary = $initialOutcome->formatted;
            self::appendFailures($failedFiles, $initialOutcome);
            // A legacy fixer exposes only a formatted string, so retain the
            // pre-pass abandonments in the step's own transaction ledger.
            if ($initialOutcome->typed === null) {
                foreach ($blockedFailures as $file => $reason) {
                    $failedFiles[$file] = [$file, $reason];
                }
            }
            self::restoreFailedThemeFiles($project, $beforeInitialPass, self::failurePaths($failedFiles));
            $layoutNotes = self::withoutFailedLayoutNotes($layoutNotes, self::failurePaths($failedFiles));
            $listNotes = self::withoutFailedLayoutNotes($listNotes, self::failurePaths($failedFiles));
            $listWarnings = self::withoutFailedLayoutNotes($listWarnings, self::failurePaths($failedFiles));
            $captionNotes = self::withoutFailedLayoutNotes($captionNotes, self::failurePaths($failedFiles));
            $captionWarnings = self::withoutFailedLayoutNotes($captionWarnings, self::failurePaths($failedFiles));
        } catch (\RuntimeException $e) {
            self::restoreThemeFiles($project, $beforeInitialPass);
            $project->writeText('logs/' . self::LOG_FILE, $e->getMessage() . "\n");
            throw $e;
        }

        // Structural repair can make previously unparseable markup safe for
        // LayoutFixer or expose image/button attributes to ShapeMarkup (for
        // example, by balancing a wp:group). Give both contracts one more
        // chance, then re-serialize only when that pass actually changed
        // comment attributes so the saved HTML stays in sync with them.
        try {
            $postRepairLayoutNotes = self::normalizeLayouts(
                $project,
                self::failurePaths($failedFiles),
                $this->htmlFirst,
                $wideMeasureRootClasses,
                $wideClassTokens,
            );
            $postRepairShapeChanges = self::normalizeShapes(
                $project,
                $shape,
                self::failurePaths($failedFiles),
            );
            $layoutNotes = array_merge($layoutNotes, $postRepairLayoutNotes);
            $shapeChanges = array_merge($shapeChanges, $postRepairShapeChanges);
            if ($postRepairLayoutNotes !== [] || $postRepairShapeChanges !== []) {
                $alignmentBaselines[] = self::snapshotThemeFiles($project);
                $followUpOutcome = BlockFixerOutcome::run($this->fixer, $project->themePath());
                $outcomes[] = $followUpOutcome;
                $followUpSummary = $followUpOutcome->formatted;
                $summary .= "\n[layout/shape] post-repair normalization required a second block-fixer pass:\n  "
                    . str_replace("\n", "\n  ", $followUpSummary);
                self::appendFailures($failedFiles, $followUpOutcome);
                self::restoreFailedThemeFiles($project, $beforeInitialPass, self::failurePaths($failedFiles));
                $layoutNotes = self::withoutFailedLayoutNotes($layoutNotes, self::failurePaths($failedFiles));
                $listNotes = self::withoutFailedLayoutNotes($listNotes, self::failurePaths($failedFiles));
                $listWarnings = self::withoutFailedLayoutNotes($listWarnings, self::failurePaths($failedFiles));
                $captionNotes = self::withoutFailedLayoutNotes($captionNotes, self::failurePaths($failedFiles));
                $captionWarnings = self::withoutFailedLayoutNotes($captionWarnings, self::failurePaths($failedFiles));
            }
        } catch (\RuntimeException $e) {
            // The public step is one transaction even though structural
            // repair can require two fixer passes. A failed follow-up must not
            // leave the first pass committed as a partial step result.
            self::restoreThemeFiles($project, $beforeInitialPass);
            $summary .= "\n[layout/shape] post-repair normalization required a second block-fixer pass, which failed:\n  "
                . str_replace("\n", "\n  ", $e->getMessage());
            if ($layoutNotes !== []) {
                $summary .= "\n[layout] " . count($layoutNotes) . " width/rhythm fix(es):\n  "
                    . implode("\n  ", $layoutNotes);
            }
            $project->writeText('logs/' . self::LOG_FILE, $summary . "\n");
            throw $e;
        }
        if ($listNotes !== []) {
            $summary .= "\n[list] " . count($listNotes) . " bare list-item lift(s):\n  " . implode("\n  ", $listNotes);
        }
        if ($listWarnings !== []) {
            $summary .= "\n[list] WARNING: " . count($listWarnings)
                . " empty bare list item(s) removed (recorded in warnings.json):\n  "
                . implode("\n  ", $listWarnings);
        }
        if ($captionNotes !== []) {
            $summary .= "\n[captions] " . count($captionNotes)
                . " image caption(s) removed outside galleries (recorded in warnings.json):\n  "
                . implode("\n  ", $captionNotes);
        }
        if ($layoutNotes !== []) {
            $summary .= "\n[layout] " . count($layoutNotes) . " width/rhythm fix(es):\n  " . implode("\n  ", $layoutNotes);
        }

        $failedPaths = self::failurePaths($failedFiles);
        $shapeChanges = self::uniqueShapeChanges($shapeChanges);
        $failedShapeChanges = self::onlyFailedShapeChanges($entryShapeChanges, $failedPaths);
        $shapeChanges = self::uniqueShapeChanges(
            self::withoutFailedShapeChanges($shapeChanges, $failedPaths),
        );
        // Every retained shape change is fully resolved in delivered markup,
        // so it belongs in the fixer report rather than warnings.json (the
        // future repair queue). A rolled-back file is filtered from that
        // success report and gets exact unresolved-value evidence below.
        if ($shapeChanges !== []) {
            $summary .= "\n[shape] " . count($shapeChanges) . " corner-language normalization(s):\n  "
                . implode("\n  ", array_map(self::shapeChangeSummary(...), $shapeChanges));
        }
        $shapeDeliveryWarnings = array_map(
            self::shapeDeliveryWarning(...),
            array_values(array_filter(
                $shapeChanges,
                static fn (array $change): bool => ($change['warning'] ?? false) === true,
            )),
        );
        if ($shapeDeliveryWarnings !== []) {
            $summary .= "\n[shape] WARNING: " . count($shapeDeliveryWarnings)
                . " lossy corner repair(s) recorded in warnings.json:\n  "
                . implode("\n  ", $shapeDeliveryWarnings);
        }
        $shapeRollbackWarnings = array_map(
            static fn (array $change): string => self::shapeRollbackWarning(
                $change,
                $failedFiles[$change['file']][1] ?? 'unknown transformation failure',
            ),
            $failedShapeChanges,
        );
        if ($shapeRollbackWarnings !== []) {
            $summary .= "\n[shape] WARNING: " . count($shapeRollbackWarnings)
                . " corner-language normalization(s) rolled back with failed file transactions:\n  "
                . implode("\n  ", $shapeRollbackWarnings);
        }

        // The fixer can silently migrate a mismatched group through a
        // deprecated block version whose schema predates "layout". Re-assert
        // the header/footer layout contract on files whose transaction
        // succeeded; a failed file must remain at its exact step-entry bytes.
        // Do this before class-loss inspection so warnings describe the bytes
        // the step actually delivers, not an intermediate fixer pass.
        foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
            if (!$project->exists('theme/' . $rel) || in_array($rel, $failedPaths, true)) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $repaired = GeneratedMarkup::constrainedPart($markup, $wideMeasureRootClasses);
            if ($repaired !== $markup) {
                $project->writeText('theme/' . $rel, $repaired);
            }
        }

        // Re-serialization is allowed to discard incidental HTML-only styling.
        // Losing vertical spacing changes the page's authored rhythm, which is
        // worth flagging loudly — the block fixer reports every such loss as
        // `DROPPED style `prop:value``. But it is a cosmetic regression in one
        // theme, and discarding the whole build over it means the user gets no
        // site at all rather than one whose spacing is slightly off. Record it
        // as a prominent warning and let the build continue.
        $deliveredSummary = implode("\n", array_map(
            static fn (BlockFixerOutcome $outcome): string => $outcome->formattedExcluding($failedPaths),
            $outcomes,
        ));
        $rhythmDrops = self::droppedVerticalRhythmStyles($deliveredSummary);
        if ($rhythmDrops !== []) {
            $summary .= "\n[rhythm] WARNING: block re-serialization dropped vertical rhythm CSS:\n  "
                . implode("\n  ", $rhythmDrops);
            echo '  [rhythm] warning: dropped ' . implode(', ', $rhythmDrops) . "\n";
        }

        // A residual paragraph style reaches this report only after current
        // validation, compatibility repair, and reviewed deprecation adapters
        // all had a chance to preserve it. The current-schema save is the
        // deterministic fallback; keep that usable output, but make every loss
        // durable for the later repair pass rather than hiding it in this log.
        $paragraphStyleWarnings = self::degradedParagraphStyles($deliveredSummary);
        $warnings = array_merge(
            $listWarnings,
            $captionWarnings,
            $paragraphStyleWarnings,
            $shapeDeliveryWarnings,
            $shapeRollbackWarnings,
        );
        foreach ($rhythmDrops as $drop) {
            $warnings[] = "block re-serialization dropped vertical rhythm CSS `{$drop}`; "
                . 'see logs/' . self::LOG_FILE;
        }
        $paragraphAlignmentRepairs = self::reviewedParagraphAlignmentRepairs($outcomes, $failedPaths);
        $alignmentLosses = array_values(array_filter(
            self::alignmentLosses($project, $alignmentBaselines, $failedPaths),
            static fn (array $entry): bool => !self::alignmentLossAlreadyWarned(
                $entry[0],
                $entry[1],
                $paragraphAlignmentRepairs,
            ),
        ));
        // The built-in fixer's frozen serializer intentionally mirrors the
        // pinned runtime, including a few HTML-only paragraph/heading classes
        // that its final save strands. Repair only those proven final losses,
        // and only when the built-in fixer owns the preceding transaction.
        // TextAlignmentRepairer serializes each target block in isolation,
        // verifies that alignment is the sole change, then atomically replaces
        // the containing file. Custom fixers keep their own output untouched
        // and any loss stays in warnings.json.
        if ($alignmentLosses !== [] && $this->fixer instanceof PhpBlockFixer) {
            try {
                $alignmentRepairRows = $this->fixer->repairTextAlignmentLosses(
                    $project->themePath(),
                    $alignmentLosses,
                );
            } catch (\RuntimeException $error) {
                // The optional repair is still part of this public step's
                // transaction. Its writer keeps each replacement atomic, but
                // a later I/O failure must not strand earlier layout/fixer or
                // alignment writes as a partially committed step.
                self::restoreThemeFiles($project, $beforeInitialPass);
                $summary .= "\n[alignment] text-alignment repair transaction failed; "
                    . "restored step-entry bytes:\n  "
                    . str_replace("\n", "\n  ", $error->getMessage());
                $project->writeText('logs/' . self::LOG_FILE, $summary . "\n");
                throw $error;
            }
            if ($alignmentRepairRows !== []) {
                $summary .= "\n[alignment] REPAIR: dropped text alignment re-expressed as "
                    . "style.typography.textAlign:\n  " . implode("\n  ", $alignmentRepairRows);
                $alignmentLosses = array_values(array_filter(
                    self::alignmentLosses($project, $alignmentBaselines, $failedPaths),
                    static fn (array $entry): bool => !self::alignmentLossAlreadyWarned(
                        $entry[0],
                        $entry[1],
                        $paragraphAlignmentRepairs,
                    ),
                ));
            }
        }
        foreach ($alignmentLosses as [$file, $loss]) {
            $warnings[] = self::alignmentWarning($file, $loss);
        }
        if ($alignmentLosses !== []) {
            $summary .= "\n[alignment] WARNING: block re-serialization dropped alignment classes:\n  "
                . implode("\n  ", array_map(
                    static fn (array $entry): string => self::alignmentSummary($entry[0], $entry[1]),
                    $alignmentLosses,
                ));
            echo '  [alignment] warning: ' . count($alignmentLosses)
                . " alignment class(es) dropped; see warnings.json\n";
        }
        // Blocks the serializer preserved verbatim (unsupported or
        // irreparable): the smallest affected unit gets a durable record
        // while its siblings were still normalized.
        $preservedBlocks = self::preservedBlockRepairs($outcomes, $failedPaths);
        foreach ($preservedBlocks as [$file, $repair]) {
            $warnings[] = "block re-serialization {$repair->code} at {$file} block {$repair->blockPath}; "
                . 'its authored bytes were delivered while sibling blocks were still normalized — '
                . 'see logs/' . self::LOG_FILE;
        }
        if ($preservedBlocks !== []) {
            echo '  [fix-blocks] warning: ' . count($preservedBlocks)
                . " block(s) preserved verbatim (unsupported or irreparable); see warnings.json\n";
        }
        // Files whose transformation the fixer abandoned (unsupported block,
        // unreviewed signature, non-convergence): their pre-fixer bytes were
        // delivered untouched — an isolated loss worth a durable record.
        foreach ($failedFiles as [$file, $why]) {
            $warnings[] = "block re-serialization left {$file} unmodified ({$why}); "
                . 'pre-step markup delivered byte-for-byte — see logs/' . self::LOG_FILE;
        }
        if ($failedFiles !== []) {
            echo '  [fix-blocks] warning: ' . count($failedFiles)
                . " file(s) left unmodified after a failed transformation; see warnings.json\n";
        }
        $project->addWarnings($this->id(), $warnings);
        if ($paragraphStyleWarnings !== []) {
            $summary .= "\n[paragraph-styles] WARNING: " . count($paragraphStyleWarnings)
                . " unsupported style(s) degraded (recorded in warnings.json):\n  "
                . implode("\n  ", $paragraphStyleWarnings);
            echo '  [paragraph-styles] warning: ' . count($paragraphStyleWarnings)
                . " unsupported style(s) degraded; see warnings.json\n";
        }
        $project->writeText('logs/' . self::LOG_FILE, $summary . "\n");

        $firstLine = (string) strtok($summary, "\n");
        echo '  ' . $firstLine . ' (details: logs/' . self::LOG_FILE . ")\n";
        if ($layoutNotes !== []) {
            echo '  layout: ' . count($layoutNotes) . " width/rhythm fix(es) applied\n";
        }
    }

    /**
     * Mirror bare `<li>` children of authored wp:list blocks into
     * wp:list-item inner blocks before the fixer regenerates save output
     * from block structure alone (which would drop every bare item).
     *
     * @param list<string> $excluded fixer-relative paths whose step
     *        transaction has already been abandoned
     * @return array{
     *     notes:list<string>,
     *     warnings:list<string>,
     *     blocked:list<array{file:string,reason:string}>
     * }
     */
    public static function liftBareListItems(Project $project, array $excluded = []): array
    {
        $notes = [];
        $warnings = [];
        $blocked = [];
        $excluded = array_fill_keys($excluded, true);
        foreach ($project->themeFiles() as $rel) {
            if (isset($excluded[$rel])) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $result = BareListItemLift::fix($markup);
            if ($result['markup'] !== $markup) {
                $project->writeText('theme/' . $rel, $result['markup']);
            }
            foreach ($result['notes'] as $note) {
                $notes[] = "{$rel}: {$note}";
            }
            foreach ($result['warnings'] as $warning) {
                $warnings[] = "{$rel}: {$warning}";
            }
            foreach ($result['blocked'] as $reason) {
                $blocked[] = ['file' => $rel, 'reason' => $reason];
            }
        }
        return ['notes' => $notes, 'warnings' => $warnings, 'blocked' => $blocked];
    }

    /**
     * Strip image captions outside galleries from every part/template.
     *
     * Mirrors liftBareListItems(): the removed caption is authored
     * visitor-facing text, so it earns a durable warnings.json row rather than
     * a log line (AGENTS.md rung 3 -> 4). Runs before the block fixer, which
     * round-trips a core/image caption between its element and its attribute.
     *
     * @param list<string> $excluded fixer-relative paths whose step
     *        transaction has already been abandoned
     * @return array{notes:list<string>, warnings:list<string>}
     */
    public static function stripImageCaptions(Project $project, array $excluded = []): array
    {
        $notes = [];
        $warnings = [];
        $excluded = array_fill_keys($excluded, true);
        foreach ($project->themeFiles() as $rel) {
            if (isset($excluded[$rel])) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $result = ImageCaptions::stripOutsideGalleries($markup);
            if ($result['markup'] !== $markup) {
                $project->writeText('theme/' . $rel, $result['markup']);
            }
            foreach ($result['notes'] as $note) {
                $notes[] = "{$rel}: {$note}";
            }
            foreach ($result['warnings'] as $warning) {
                $warnings[] = "{$rel}: {$warning}";
            }
        }
        return ['notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * Apply LayoutFixer to every part/template, writing changed files back.
     * Returns one "file: note" line per fix for the step log. The primary pass
     * runs before the block fixer because LayoutFixer rewrites only comment
     * attributes and the following re-serialization syncs the HTML with them.
     * A post-repair pass handles markup that only became parseable afterwards.
     *
     * @param list<string> $excluded fixer-relative paths that have already
     *        failed this step and must remain at their step-entry bytes
     * @param bool $htmlFirst carried design CSS owns section width — see
     *        LayoutFixer::fix()
     * Both class lists come from design/site.css, so every caller must declare
     * that read, but they answer different questions and are not
     * interchangeable. A root must keep its own width only when its OWN rule
     * gives it the measure; the carrier search wants every class named in such
     * a rule, because it is looking for a wrapper anywhere inside the part.
     *
     * @param list<string> $wideMeasureRootClasses classes whose own rule gives
     *        them the wide measure — see Units\GeneratedMarkup::wideMeasureSubjectClasses
     * @param list<string> $widePartCarrierClasses classes named anywhere in such
     *        a rule — see SectionLayoutStep::wideClassTokens. Empty means "do
     *        not promote", which is how normalize-layout leaves align:wide
     *        promotion to fix-blocks alone.
     * @return string[]
     */
    public static function normalizeLayouts(
        Project $project,
        array $excluded = [],
        bool $htmlFirst = false,
        array $wideMeasureRootClasses = [],
        array $widePartCarrierClasses = [],
    ): array
    {
        $notes = [];
        $excluded = array_fill_keys($excluded, true);
        $contentSize = self::themeContentSize($project);
        $spacingSlugs = self::themeSpacingSlugs($project);
        $siteSpec = $project->exists('siteSpec.json') ? $project->readJson('siteSpec.json') : [];
        $meta = $project->exists('meta.json') ? $project->readJson('meta.json') : [];
        $flattenStaggeredChildren = !PhotographySite::matches(
            is_array($siteSpec) ? $siteSpec : [],
            (string) ($meta['prompt'] ?? ''),
        );
        foreach ($project->themeFiles() as $rel) {
            if (isset($excluded[$rel])) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $role = LayoutFixer::roleFor($rel);
            $result = LayoutFixer::fix(
                $markup,
                $role,
                $contentSize,
                $spacingSlugs,
                $htmlFirst,
                $wideMeasureRootClasses,
            );
            $normalized = $result['markup'];
            if ($flattenStaggeredChildren && $role === LayoutFixer::ROLE_SECTION) {
                $flat = StaggeredChildren::flatten($normalized);
                $normalized = $flat['markup'];
                foreach ($flat['notes'] as $note) {
                    $notes[] = "{$rel}: {$note}";
                }
            }
            if ($rel === 'parts/footer.html' && $widePartCarrierClasses !== []) {
                $wide = self::promoteOutermostWidePartCarrier($normalized, $widePartCarrierClasses);
                if ($wide !== $normalized) {
                    $notes[] = "{$rel}: promoted outermost wide-size carrier to align:wide";
                    $normalized = $wide;
                }
            }
            if ($normalized !== $markup) {
                $project->writeText('theme/' . $rel, $normalized);
            }
            foreach ($result['notes'] as $note) {
                $notes[] = "{$rel}: {$note}";
            }
        }
        return $notes;
    }

    /**
     * Add align:wide to the first block whose own element carries a class that
     * design CSS measures against var(--wide-size). Explicit alignment wins.
     *
     * @param list<string> $wideClassTokens
     */
    private static function promoteOutermostWidePartCarrier(string $markup, array $wideClassTokens): string
    {
        $wideClasses = array_fill_keys($wideClassTokens, true);
        $doc = BlockMarkup::parse($markup);
        if ($doc->hasMalformedDelimiters()
            || $doc->hasMismatchedDelimiters()
            || $doc->unclosedIndices() !== []
        ) {
            return $markup;
        }
        foreach ($doc->indices() as $index) {
            foreach (self::partElementClassTokens($doc, $index) as $token) {
                if (!isset($wideClasses[$token])) {
                    continue;
                }
                $attrs = $doc->attrs($index) ?? [];
                if (!isset($attrs['align'])) {
                    $attrs['align'] = 'wide';
                    $doc->setAttrs($index, $attrs);
                }
                return $doc->render();
            }
        }
        return $markup;
    }

    /** @return list<string> class tokens on a block's own wrapper, never descendants */
    private static function partElementClassTokens(BlockMarkup $doc, int $index): array
    {
        $tokens = [];
        $className = ($doc->attrs($index) ?? [])['className'] ?? null;
        if (is_string($className)) {
            $tokens = preg_split('/[\x20\t\r\n\f]+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (preg_match('/<[a-zA-Z][a-zA-Z0-9-]*\b[^>]*>/s', $doc->ownHtml($index), $tag) === 1) {
            $class = self::partTagAttribute($tag[0], 'class');
            if ($class !== null && trim($class) !== '') {
                $tokens = array_merge(
                    $tokens,
                    preg_split('/[\x20\t\r\n\f]+/', trim($class), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                );
            }
        }
        return array_values(array_unique($tokens));
    }

    private static function partTagAttribute(string $tagHtml, string $name): ?string
    {
        $pattern = '/[\x20\t\r\n\f]' . preg_quote($name, '/')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if (preg_match($pattern, $tagHtml, $match) !== 1) {
            return null;
        }
        return array_key_exists(1, $match) && $match[1] !== '' ? $match[1] : ($match[2] ?? '');
    }

    /**
     * Normalize generated core/image and core/button corner overrides in every
     * successful file transaction. A null shape is the backwards-compatible
     * no-op for design directions persisted before the commitment existed.
     *
     * @param list<string> $excluded fixer-relative paths whose step
     *        transaction has already been abandoned
     * @return list<array{
     *     file:string,
     *     blockPath:string,
     *     blockName:string,
     *     property:string,
     *     authored:mixed,
     *     delivered:mixed,
     *     disposition:string
     * }>
     */
    public static function normalizeShapes(
        Project $project,
        ?string $shape,
        array $excluded = [],
    ): array {
        if ($shape === null) {
            return [];
        }

        $changes = [];
        $excluded = array_fill_keys($excluded, true);
        foreach ($project->themeFiles() as $rel) {
            if (isset($excluded[$rel])) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $result = ShapeMarkup::normalize($markup, $shape);
            if ($result['markup'] !== $markup) {
                $project->writeText('theme/' . $rel, $result['markup']);
            }
            foreach ($result['changes'] as $change) {
                $changes[] = ['file' => $rel] + $change;
            }
        }
        return $changes;
    }

    /**
     * Inspect exact step-entry bytes without mutating them. A later fixer pass
     * can expose or synthesize additional shape state, but a failed file is
     * restored from this snapshot, so intermediate state must never be
     * reported as delivered rollback evidence.
     *
     * @param array<string,string> $snapshot theme-relative path => exact bytes
     * @return list<array{
     *     file:string,
     *     blockPath:string,
     *     blockName:string,
     *     property:string,
     *     authored:mixed,
     *     delivered:mixed,
     *     disposition:string
     * }>
     */
    private static function shapeChangesInSnapshot(array $snapshot, ?string $shape): array
    {
        if ($shape === null) {
            return [];
        }

        $changes = [];
        foreach ($snapshot as $rel => $markup) {
            if (!str_starts_with($rel, 'parts/') && !str_starts_with($rel, 'templates/')) {
                // Optional pages participate in the block-fixer transaction,
                // but normalizeShapes intentionally follows themeFiles() and
                // never touches them. Do not claim a page shape repair was
                // rolled back when that repair was never attempted.
                continue;
            }
            foreach (ShapeMarkup::normalize($markup, $shape)['changes'] as $change) {
                $changes[] = ['file' => $rel] + $change;
            }
        }
        return self::uniqueShapeChanges($changes);
    }

    /**
     * @param list<array{file:string,blockPath:string,blockName:string,property:string,
     *        authored:mixed,delivered:mixed,disposition:string}> $changes
     * @param list<string> $failedPaths
     * @return list<array{file:string,blockPath:string,blockName:string,property:string,
     *         authored:mixed,delivered:mixed,disposition:string}>
     */
    private static function withoutFailedShapeChanges(array $changes, array $failedPaths): array
    {
        $failed = array_fill_keys($failedPaths, true);
        return array_values(array_filter(
            $changes,
            static fn (array $change): bool => !isset($failed[$change['file']]),
        ));
    }

    /**
     * @param list<array{file:string,blockPath:string,blockName:string,property:string,
     *        authored:mixed,delivered:mixed,disposition:string}> $changes
     * @param list<string> $failedPaths
     * @return list<array{file:string,blockPath:string,blockName:string,property:string,
     *         authored:mixed,delivered:mixed,disposition:string}>
     */
    private static function onlyFailedShapeChanges(array $changes, array $failedPaths): array
    {
        $failed = array_fill_keys($failedPaths, true);
        return array_values(array_filter(
            $changes,
            static fn (array $change): bool => isset($failed[$change['file']]),
        ));
    }

    /**
     * @param list<array{file:string,blockPath:string,blockName:string,property:string,
     *        authored:mixed,delivered:mixed,disposition:string}> $changes
     * @return list<array{file:string,blockPath:string,blockName:string,property:string,
     *         authored:mixed,delivered:mixed,disposition:string}>
     */
    private static function uniqueShapeChanges(array $changes): array
    {
        $unique = [];
        foreach ($changes as $change) {
            $key = json_encode($change, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($key === false) {
                $key = serialize($change);
            }
            $unique[$key] = $change;
        }
        return array_values($unique);
    }

    /**
     * @param array{file:string,blockPath:string,blockName:string,property:string,
     *        authored:mixed,delivered:mixed,disposition:string} $change
     */
    private static function shapeChangeSummary(array $change): string
    {
        $authored = $change['authored'] === null
            && str_starts_with($change['disposition'], 'added deterministic')
            ? 'missing'
            : self::shapeValue($change['authored']);
        return sprintf(
            '%s block %s (%s): %s %s -> %s (%s)',
            $change['file'],
            $change['blockPath'],
            $change['blockName'],
            $change['property'],
            $authored,
            $change['delivered'] === null ? 'removed' : self::shapeValue($change['delivered']),
            $change['disposition'],
        );
    }

    /**
     * A successful transform can still lose unrelated authored CSS when the
     * generated declaration container is structurally unsafe. Keep that loss
     * actionable for the future repair pass instead of hiding it in the log.
     *
     * @param array{file:string,blockPath:string,blockName:string,property:string,
     *        authored:mixed,delivered:mixed,disposition:string,warning?:bool} $change
     */
    private static function shapeDeliveryWarning(array $change): string
    {
        return sprintf(
            '%s block %s (%s): corner property %s; authored %s; delivered %s; '
                . 'disposition %s; see logs/%s',
            $change['file'],
            $change['blockPath'],
            $change['blockName'],
            $change['property'],
            self::shapeValue($change['authored']),
            $change['delivered'] === null ? 'removed' : self::shapeValue($change['delivered']),
            $change['disposition'],
            self::LOG_FILE,
        );
    }

    /**
     * Describe the delivered pre-step value after a file-level transaction
     * rolls back. Unlike a successful normalization, this is unresolved work
     * for the future repair pass and therefore belongs in warnings.json.
     *
     * @param array{file:string,blockPath:string,blockName:string,property:string,
     *        authored:mixed,delivered:mixed,disposition:string} $change
     */
    private static function shapeRollbackWarning(array $change, string $failure): string
    {
        $authored = $change['authored'] === null
            && str_starts_with($change['disposition'], 'added deterministic')
            ? 'missing'
            : self::shapeValue($change['authored']);
        return sprintf(
            '%s block %s (%s): corner property %s; authored %s; delivered %s '
                . '(pre-step value restored); disposition shape normalization rolled back '
                . 'because block re-serialization abandoned this file (%s); see logs/%s',
            $change['file'],
            $change['blockPath'],
            $change['blockName'],
            $change['property'],
            $authored,
            $authored,
            $failure,
            self::LOG_FILE,
        );
    }

    private static function shapeValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? 'unencodable value' : $encoded;
    }

    /**
     * Extract DROPPED inline-style declarations that affect vertical rhythm.
     *
     * The fixer report encloses each lost declaration in backticks. Match that
     * stable boundary, then classify the CSS property exactly so similarly
     * named but unrelated properties cannot make a build fail accidentally.
     * Logical block-axis properties and shorthands count because they include a
     * vertical component; left/right-only padding and margin do not.
     *
     * @return string[] unique `property:value` declarations, in report order
     */
    public static function droppedVerticalRhythmStyles(string $report): array
    {
        if (preg_match_all('/\bDROPPED\s+style\s+`([^`\r\n]+)`/i', $report, $matches) < 1) {
            return [];
        }

        $rhythmProperties = [
            'padding',
            'padding-top',
            'padding-bottom',
            'padding-block',
            'padding-block-start',
            'padding-block-end',
            'margin',
            'margin-top',
            'margin-bottom',
            'margin-block',
            'margin-block-start',
            'margin-block-end',
            'gap',
            'row-gap',
            'column-gap',
        ];

        $dropped = [];
        foreach ($matches[1] as $declaration) {
            $property = strtolower(trim((string) strtok($declaration, ':')));
            if (!in_array($property, $rhythmProperties, true)) {
                continue;
            }
            // A bare identifier that isn't a CSS keyword (e.g. a preset slug
            // the model left unexpanded, `margin-top:sm`) never rendered in
            // the first place — losing it cannot change the page's rhythm.
            $colon = strpos($declaration, ':');
            $value = $colon === false ? '' : trim(substr($declaration, $colon + 1));
            if (preg_match('/^[a-z][a-z0-9_-]*$/i', $value) === 1
                && !in_array(strtolower($value), ['auto', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'none', 'normal'], true)) {
                continue;
            }
            $declaration = trim($declaration);
            if (!in_array($declaration, $dropped, true)) {
                $dropped[] = $declaration;
            }
        }
        return $dropped;
    }

    /**
     * Compare every pre-serialization baseline with the complete final
     * transaction output. This preserves a loss that survives either fixer
     * pass, while a class restored by the final pass produces no warning.
     * This deliberately does not consume DroppedValue: that DTO is a whole-file
     * occurrence delta and cannot locate a visitor-visible loss on one block.
     *
     * @param list<array<string,string>> $baselines theme-relative path => bytes
     * @param list<string> $failedPaths
     * @return list<array{0:string,1:AlignmentClassLoss}>
     */
    private static function alignmentLosses(
        Project $project,
        array $baselines,
        array $failedPaths,
    ): array {
        $detector = new AlignmentClassLossDetector();
        $failed = array_fill_keys($failedPaths, true);
        $delivered = [];
        $lossesByKey = [];
        $keysByProvenance = [];
        foreach ($baselines as $baselineIndex => $baseline) {
            foreach ($baseline as $file => $before) {
                if (isset($failed[$file])) {
                    continue;
                }
                $delivered[$file] ??= $project->readText('theme/' . $file);
                foreach ($detector->detect($before, $delivered[$file]) as $loss) {
                    $provenanceKey = implode("\0", [
                        $file,
                        $loss->blockPath,
                        $loss->blockName,
                        $loss->authoredClass,
                    ]);
                    $key = implode("\0", [
                        $provenanceKey,
                        $loss->authoredClassOnSavedRoot ? 'root' : 'owned-descendant',
                        $loss->authoredElementPath ?? 'saved-root',
                    ]);
                    $previous = $lossesByKey[$key][1] ?? null;
                    $sameScope = $previous instanceof AlignmentClassLoss;
                    if (!$sameScope && $baselineIndex !== 0) {
                        // A scope that appears only after the first baseline
                        // is an intermediate transformation artifact, not a
                        // second authored defect. Fold it into the earliest
                        // row so warnings.json does not invite a later repair
                        // to promote that temporary root/descendant state.
                        // The element path remains part of the primary key so
                        // two authored descendant occurrences are never
                        // collapsed merely because they carry the same class.
                        foreach ($keysByProvenance[$provenanceKey] ?? [] as $candidateKey) {
                            $candidate = $lossesByKey[$candidateKey][1] ?? null;
                            if ($candidate instanceof AlignmentClassLoss
                                && $candidate->authoredClassOnSavedRoot
                                    !== $loss->authoredClassOnSavedRoot
                            ) {
                                $key = $candidateKey;
                                $previous = $candidate;
                                break;
                            }
                        }
                    }
                    if ($previous instanceof AlignmentClassLoss) {
                        // A later fixer baseline must not upgrade evidence
                        // that was unsafe in an earlier authored snapshot.
                        // In particular, a descendant class temporarily moved
                        // onto a root is still not ours to promote to block
                        // typography. Replacement evidence is also combined
                        // conservatively so any observed alternative keeps the
                        // target out of the deterministic-repair path.
                        $loss = new AlignmentClassLoss(
                            blockPath: $loss->blockPath,
                            blockName: $loss->blockName,
                            authoredClass: $loss->authoredClass,
                            deliveredClasses: $sameScope
                                ? array_values(array_unique(array_merge(
                                    $previous->deliveredClasses,
                                    $loss->deliveredClasses,
                                )))
                                : $previous->deliveredClasses,
                            authoredClassOnSavedRoot: $previous->authoredClassOnSavedRoot,
                            authoredClassIsSafeRootTextAlignment:
                                $previous->authoredClassIsSafeRootTextAlignment
                                && $loss->authoredClassIsSafeRootTextAlignment
                                && $sameScope,
                            deliveredBlockPath: $sameScope
                                ? $loss->deliveredBlockPath
                                : $previous->deliveredBlockPath,
                            authoredElementPath: $previous->authoredElementPath,
                        );
                    } elseif ($baselineIndex !== 0 && $loss->authoredClassOnSavedRoot) {
                        // Repair eligibility requires positive root proof from
                        // the earliest authored baseline. A structural rewrite
                        // can make that snapshot non-comparable, or move a
                        // descendant token onto a later root; absence of
                        // contrary evidence is not proof that promotion is
                        // semantics-safe.
                        $loss = new AlignmentClassLoss(
                            blockPath: $loss->blockPath,
                            blockName: $loss->blockName,
                            authoredClass: $loss->authoredClass,
                            deliveredClasses: $loss->deliveredClasses,
                            authoredClassOnSavedRoot: true,
                            authoredClassIsSafeRootTextAlignment: false,
                            deliveredBlockPath: $loss->deliveredBlockPath,
                            authoredElementPath: $loss->authoredElementPath,
                        );
                    }
                    $lossesByKey[$key] = [$file, $loss];
                    if (!isset($keysByProvenance[$provenanceKey])) {
                        $keysByProvenance[$provenanceKey] = [];
                    }
                    if (!in_array($key, $keysByProvenance[$provenanceKey], true)) {
                        $keysByProvenance[$provenanceKey][] = $key;
                    }
                }
            }
        }
        return array_values($lossesByKey);
    }

    /**
     * Exact paragraph blocks whose text alignment already has an actionable
     * warning in the reviewed paragraph-style vocabulary.
     *
     * @param list<BlockFixerOutcome> $outcomes
     * @param list<string> $failedPaths
     * @return array<string,true> `file\0block-path` keys
     */
    private static function reviewedParagraphAlignmentRepairs(array $outcomes, array $failedPaths): array
    {
        $failed = array_fill_keys($failedPaths, true);
        $covered = [];
        $prefix = 'paragraph-style-degraded:';
        foreach ($outcomes as $outcome) {
            foreach ($outcome->typed?->files ?? [] as $file) {
                if (isset($failed[$file->path])) {
                    continue;
                }
                foreach ($file->repairs as $repair) {
                    if (!str_starts_with($repair->code, $prefix)) {
                        continue;
                    }
                    $payload = json_decode(substr($repair->code, strlen($prefix)), true);
                    if (!is_array($payload) || ($payload['property'] ?? null) !== 'text-align') {
                        continue;
                    }
                    $covered[$file->path . "\0" . $repair->blockPath] = true;
                }
            }
        }
        return $covered;
    }

    /** @param array<string,true> $paragraphAlignmentRepairs */
    private static function alignmentLossAlreadyWarned(
        string $file,
        AlignmentClassLoss $loss,
        array $paragraphAlignmentRepairs,
    ): bool {
        return $loss->blockName === 'core/paragraph'
            && $loss->authoredClassOnSavedRoot
            && str_starts_with($loss->authoredClass, 'has-text-align-')
            && isset($paragraphAlignmentRepairs[$file . "\0" . $loss->blockPath]);
    }

    private static function alignmentWarning(string $file, AlignmentClassLoss $loss): string
    {
        $authored = self::quoted($loss->authoredClass);
        $delivered = self::deliveredAlignmentValue($loss);
        $scope = $loss->authoredClassOnSavedRoot
            ? 'the saved root'
            : 'owned descendant element ' . ($loss->authoredElementPath ?? 'unknown');
        $deliveredPath = $loss->deliveredBlockPath !== null
            && $loss->deliveredBlockPath !== $loss->blockPath
            ? "; semantic match moved to delivered block {$loss->deliveredBlockPath}"
            : '';
        $disposition = $loss->deliveredClasses === []
            ? 'authored class removed; no same-scope alignment class remains'
            : 'authored class removed; same scope keeps other alignment in the same family';
        return "{$file} block {$loss->blockPath} ({$loss->blockName}): alignment class {$authored} "
            . "could not be preserved on {$scope}{$deliveredPath} (authored {$authored}; delivered {$delivered}; "
            . "disposition: {$disposition}); "
            . 'deterministic final output delivered — see logs/' . self::LOG_FILE;
    }

    private static function alignmentSummary(string $file, AlignmentClassLoss $loss): string
    {
        $scope = $loss->authoredClassOnSavedRoot
            ? 'root'
            : 'owned descendant ' . ($loss->authoredElementPath ?? 'unknown');
        $deliveredPath = $loss->deliveredBlockPath !== null
            && $loss->deliveredBlockPath !== $loss->blockPath
            ? " (moved to {$loss->deliveredBlockPath})"
            : '';
        return "{$file} block {$loss->blockPath} ({$loss->blockName}): "
            . self::quoted($loss->authoredClass) . " [{$scope}]{$deliveredPath} -> "
            . self::deliveredAlignmentValue($loss);
    }

    private static function deliveredAlignmentValue(AlignmentClassLoss $loss): string
    {
        if ($loss->deliveredClasses === []) {
            return 'removed';
        }
        $classes = array_map(self::quoted(...), $loss->deliveredClasses);
        return count($classes) === 1 ? $classes[0] : '[' . implode(', ', $classes) . ']';
    }

    private static function quoted(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '""' : $encoded;
    }

    /**
     * Decode paragraph-style degradation repair rows with their file and block
     * path so warnings.json is actionable for a later repair pass.
     *
     * @return string[]
     */
    public static function degradedParagraphStyles(string $report): array
    {
        $warnings = [];
        $file = 'unknown file';
        $marker = '- REPAIR paragraph-style-degraded:';
        foreach (preg_split('/\r?\n/', $report) ?: [] as $line) {
            if (preg_match('/^\s+(?:FIXED|ok|skip)\s+(.+?)\s*$/', $line, $match) === 1) {
                $file = trim($match[1]);
                continue;
            }
            $offset = strpos($line, $marker);
            if ($offset === false) {
                continue;
            }
            $rest = substr($line, $offset + strlen($marker));
            $split = strrpos($rest, ' at ');
            if ($split === false) {
                continue;
            }
            $payload = json_decode(substr($rest, 0, $split), true);
            if (!is_array($payload)) {
                continue;
            }
            $property = json_encode(
                (string) ($payload['property'] ?? ''),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $authored = json_encode(
                (string) ($payload['authored'] ?? ''),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $delivered = array_key_exists('delivered', $payload) && $payload['delivered'] !== null
                ? json_encode(
                    (string) $payload['delivered'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )
                : null;
            $disposition = is_string($payload['disposition'] ?? null)
                ? $payload['disposition']
                : ($delivered === null
                    ? 'removed by the reviewed deterministic fallback'
                    : 'delivered as ' . $delivered);
            $warnings[] = sprintf(
                '%s block %s: core/paragraph style %s could not be preserved '
                    . '(authored %s; %s); deterministic current-schema output delivered',
                $file,
                trim(substr($rest, $split + 4)),
                $property === false ? '""' : $property,
                $authored === false ? '""' : $authored,
                $disposition,
            );
        }
        return array_values(array_unique($warnings));
    }

    /** @param array<string,array{0:string,1:string}> $failures */
    private static function appendFailures(array &$failures, BlockFixerOutcome $outcome): void
    {
        foreach ($outcome->failures() as $failure) {
            $reason = str_replace(["\r", "\n"], ' ', $failure->error ?? 'unknown transformation failure');
            $failures[$failure->path] = [$failure->path, $reason];
        }
    }

    /** @param array<string,array{0:string,1:string}> $failures @return list<string> */
    private static function failurePaths(array $failures): array
    {
        return array_keys($failures);
    }

    /**
     * Preservation rows from every delivered file, deduplicated across fixer
     * passes. Files whose transaction failed are excluded — they already get
     * their own file-level warning.
     *
     * @param list<BlockFixerOutcome> $outcomes
     * @param list<string> $failedPaths
     * @return list<array{0:string,1:Repair}>
     */
    private static function preservedBlockRepairs(array $outcomes, array $failedPaths): array
    {
        $excluded = array_fill_keys($failedPaths, true);
        $rows = [];
        $seen = [];
        foreach ($outcomes as $outcome) {
            foreach ($outcome->typed?->files ?? [] as $file) {
                if (isset($excluded[$file->path])) {
                    continue;
                }
                foreach ($file->repairs as $repair) {
                    if (!str_starts_with($repair->code, Repair::PRESERVED_PREFIX)) {
                        continue;
                    }
                    $key = $repair->key($file->path);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $rows[] = [$file->path, $repair];
                }
            }
        }
        return $rows;
    }

    /**
     * A fixer failure abandons this step's complete transaction for that file,
     * including LayoutFixer mutations made before either fixer pass.
     *
     * @param array<string,string> $snapshot
     * @param list<string> $failedPaths
     */
    private static function restoreFailedThemeFiles(Project $project, array $snapshot, array $failedPaths): void
    {
        foreach ($failedPaths as $relative) {
            if (!array_key_exists($relative, $snapshot)) {
                throw new \LogicException("Block fixer reported an unknown theme file: {$relative}");
            }
            $project->writeText('theme/' . $relative, $snapshot[$relative]);
        }
    }

    /** @param list<string> $notes @param list<string> $failedPaths @return list<string> */
    private static function withoutFailedLayoutNotes(array $notes, array $failedPaths): array
    {
        return array_values(array_filter($notes, static function (string $note) use ($failedPaths): bool {
            foreach ($failedPaths as $relative) {
                if (str_starts_with($note, $relative . ': ')) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** @return array<string,string> theme-relative path => exact bytes */
    private static function snapshotThemeFiles(Project $project): array
    {
        $snapshot = [];
        $files = $project->themeFiles();
        foreach (glob($project->themePath('pages/*.html')) ?: [] as $absolute) {
            $files[] = 'pages/' . basename($absolute);
        }
        foreach (array_values(array_unique($files)) as $relative) {
            $snapshot[$relative] = $project->readText('theme/' . $relative);
        }
        return $snapshot;
    }

    /** @param array<string,string> $snapshot */
    private static function restoreThemeFiles(Project $project, array $snapshot): void
    {
        foreach ($snapshot as $relative => $markup) {
            $project->writeText('theme/' . $relative, $markup);
        }
    }

    /**
     * The theme's spacing-scale slugs (settings.spacing.spacingSizes), for
     * LayoutFixer's bare-slug spacing repair. Empty when unknown.
     *
     * @return string[]
     */
    public static function themeSpacingSlugs(Project $project): array
    {
        $theme = self::readThemeJson($project);
        if ($theme === null) {
            return [];
        }
        $sizes = $theme['settings']['spacing']['spacingSizes'] ?? [];
        if (!is_array($sizes)) {
            return [];
        }
        $slugs = [];
        foreach ($sizes as $size) {
            if (is_array($size) && is_string($size['slug'] ?? null) && $size['slug'] !== '') {
                $slugs[] = $size['slug'];
            }
        }
        return $slugs;
    }

    /** theme.json settings.layout.contentSize in px, when parseable. */
    public static function themeContentSize(Project $project): ?float
    {
        $theme = self::readThemeJson($project);
        if ($theme === null) {
            return null;
        }
        $size = $theme['settings']['layout']['contentSize'] ?? null;
        return is_string($size) && preg_match('/^([0-9.]+)px$/', $size, $m) === 1
            ? (float) $m[1]
            : null;
    }

    /**
     * theme.json decoded fail-open: null when missing or malformed, so the
     * theme helpers degrade to their defaults instead of throwing.
     *
     * @return array<mixed>|null
     */
    private static function readThemeJson(Project $project): ?array
    {
        if (!$project->exists('theme/theme.json')) {
            return null;
        }
        $theme = json_decode($project->readText('theme/theme.json'), true);
        return is_array($theme) ? $theme : null;
    }
}
