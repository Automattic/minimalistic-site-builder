<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockFixerOutcome;
use Automattic\SiteBuild\BlockSerializer\AlignmentClassLoss;
use Automattic\SiteBuild\BlockSerializer\AlignmentClassLossDetector;
use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
use Automattic\SiteBuild\BlockSerializer\JsString;
use Automattic\SiteBuild\BlockSerializer\Parser\BlockNode;
use Automattic\SiteBuild\BlockSerializer\Parser\DefaultParser;
use Automattic\SiteBuild\BlockSerializer\Parser\FreeformNode;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\Project;
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

    public function __construct(private BlockFixer $fixer) {}

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
            // Templates are only scanned when they exist; in the default graph
            // they are written by assemble-pages, which runs after this step.
            reads: ['designDirection.json', 'theme/theme.json', 'theme/parts/*'],
            writes: ['theme/parts/*', 'warnings.json'],
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
        try {
            $layoutNotes = self::normalizeLayouts($project);
            $shapeChanges = self::normalizeShapes($project, $shape);
            $alignmentBaselines[] = self::snapshotThemeFiles($project);
            $initialOutcome = BlockFixerOutcome::run($this->fixer, $project->themePath());
            $outcomes[] = $initialOutcome;
            $summary = $initialOutcome->formatted;
            self::appendFailures($failedFiles, $initialOutcome);
            self::restoreFailedThemeFiles($project, $beforeInitialPass, self::failurePaths($failedFiles));
            $layoutNotes = self::withoutFailedLayoutNotes($layoutNotes, self::failurePaths($failedFiles));
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
            $postRepairLayoutNotes = self::normalizeLayouts($project, self::failurePaths($failedFiles));
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
            $repaired = GeneratedMarkup::constrainedPart($markup);
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
        // A dropped has-text-align-* class on reading copy is deterministic
        // to repair: the direction is known from the authored class, and
        // style.typography.textAlign is the canonical shape every pinned
        // save() derives the class from. Attempt the repair before warning —
        // only losses the repair could not heal stay durable.
        if ($alignmentLosses !== []) {
            $alignmentRepairRows = self::repairAlignmentLosses($project, $alignmentLosses);
            if ($alignmentRepairRows !== []) {
                $summary .= "\n[alignment] REPAIR: dropped text alignment re-expressed as "
                    . "style.typography.textAlign:\n  " . implode("\n  ", $alignmentRepairRows);
                echo '  [alignment] ' . count($alignmentRepairRows)
                    . " dropped text alignment(s) repaired\n";
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
     * Apply LayoutFixer to every part/template, writing changed files back.
     * Returns one "file: note" line per fix for the step log. The primary pass
     * runs before the block fixer because LayoutFixer rewrites only comment
     * attributes and the following re-serialization syncs the HTML with them.
     * A post-repair pass handles markup that only became parseable afterwards.
     *
     * @param list<string> $excluded fixer-relative paths that have already
     *        failed this step and must remain at their step-entry bytes
     * @return string[]
     */
    public static function normalizeLayouts(Project $project, array $excluded = []): array
    {
        $notes = [];
        $excluded = array_fill_keys($excluded, true);
        $contentSize = self::themeContentSize($project);
        $spacingSlugs = self::themeSpacingSlugs($project);
        foreach ($project->themeFiles() as $rel) {
            if (isset($excluded[$rel])) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $result = LayoutFixer::fix($markup, LayoutFixer::roleFor($rel), $contentSize, $spacingSlugs);
            if ($result['markup'] !== $markup) {
                $project->writeText('theme/' . $rel, $result['markup']);
            }
            foreach ($result['notes'] as $note) {
                $notes[] = "{$rel}: {$note}";
            }
        }
        return $notes;
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
        foreach ($baselines as $baseline) {
            foreach ($baseline as $file => $before) {
                if (isset($failed[$file])) {
                    continue;
                }
                $delivered[$file] ??= $project->readText('theme/' . $file);
                foreach ($detector->detect($before, $delivered[$file]) as $loss) {
                    $key = implode("\0", [
                        $file,
                        $loss->blockPath,
                        $loss->blockName,
                        $loss->authoredClass,
                    ]);
                    $lossesByKey[$key] = [$file, $loss];
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

    /**
     * Repair dropped has-text-align-* classes on reading-copy blocks by
     * folding the authored direction into style.typography.textAlign in the
     * delivered comment JSON and re-serializing the file. The pinned
     * adapters already preserve most authored alignment shapes; this pass
     * only fires on the residue the whole transaction still lost, so no
     * reviewed byte contract changes for cases that already worked. A block
     * whose delivered family kept a DIFFERENT alignment, whose containers
     * are malformed, or that already carries a typography.textAlign is left
     * alone — those stay durable warnings. Serializer::transform is
     * idempotent on delivered output, so untouched sibling blocks keep
     * their exact bytes.
     *
     * @param list<array{0:string,1:AlignmentClassLoss}> $losses
     * @return list<string> one human log row per repaired block
     */
    private static function repairAlignmentLosses(Project $project, array $losses): array
    {
        $byFile = [];
        foreach ($losses as [$file, $loss]) {
            if (!in_array($loss->blockName, ['core/paragraph', 'core/heading'], true)
                || $loss->deliveredClasses !== []
                || preg_match('/^has-text-align-(left|center|right)$/', $loss->authoredClass, $match) !== 1
            ) {
                continue;
            }
            $byFile[$file][$loss->blockPath] = $match[1];
        }

        $rows = [];
        foreach ($byFile as $file => $targets) {
            $delivered = $project->readText('theme/' . $file);
            $patch = self::textAlignPatchedMarkup($delivered, $targets);
            if ($patch === null) {
                continue;
            }
            [$patched, $patchedPaths] = $patch;
            try {
                $transformed = (new Serializer())->transform($patched)->html;
            } catch (\Throwable) {
                // Deliver the pre-repair bytes and keep the durable warning.
                continue;
            }
            $project->writeText('theme/' . $file, $transformed);
            foreach ($patchedPaths as $blockPath => $direction) {
                $rows[] = "{$file} block {$blockPath}: \"has-text-align-{$direction}\" "
                    . 're-expressed as style.typography.textAlign';
            }
        }
        return $rows;
    }

    /**
     * Splice style.typography.textAlign into the comment delimiters of the
     * targeted blocks. Paths mirror AlignmentClassLossDetector: top-level
     * indices count blocks and nonblank freeform, child indices are local to
     * innerBlocks.
     *
     * @param array<string,string> $targets blockPath => direction
     * @return array{0:string,1:array<string,string>}|null patched bytes and
     *     the targets actually patched, or null when nothing was patchable
     */
    private static function textAlignPatchedMarkup(string $markup, array $targets): ?array
    {
        try {
            $document = DefaultParser::parse($markup);
        } catch (\InvalidArgumentException | \RuntimeException) {
            return null;
        }

        $byPath = [];
        $index = 0;
        foreach ($document->nodes() as $node) {
            if ($node instanceof FreeformNode) {
                if (JsString::trim($node->content) !== '') {
                    $index++;
                }
                continue;
            }
            if ($node instanceof BlockNode) {
                self::collectBlocksByPath($node, (string) $index, $byPath);
                $index++;
            }
        }

        $splices = [];
        $patchedPaths = [];
        foreach ($targets as $blockPath => $direction) {
            $block = $byPath[$blockPath] ?? null;
            if ($block === null) {
                continue;
            }
            $attributes = $block->attributes ?? new JsonObject();
            $style = $attributes->get('style');
            if ($style !== null && !$style instanceof JsonObject) {
                continue;
            }
            $typography = $style?->get('typography');
            if ($typography !== null && !$typography instanceof JsonObject) {
                continue;
            }
            if ($typography?->has('textAlign') === true) {
                continue;
            }
            if ($style === null) {
                $style = new JsonObject();
                $attributes->set('style', $style);
            }
            if ($typography === null) {
                $typography = new JsonObject();
                $style->set('typography', $typography);
            }
            $typography->set('textAlign', new JsonString($direction));

            $shortName = str_starts_with($block->name, 'core/')
                ? substr($block->name, strlen('core/')) : $block->name;
            $encoded = JsJsonEncoder::stringify($attributes);
            if ($encoded === null) {
                continue;
            }
            $splices[] = [
                $block->openingStart,
                $block->openingEnd,
                '<!-- wp:' . $shortName . ' ' . $encoded . ' -->',
            ];
            $patchedPaths[$blockPath] = $direction;
        }
        if ($splices === []) {
            return null;
        }

        usort($splices, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($splices as [$start, $end, $replacement]) {
            $markup = substr($markup, 0, $start) . $replacement . substr($markup, $end);
        }
        return [$markup, $patchedPaths];
    }

    /** @param array<string,BlockNode> $byPath */
    private static function collectBlocksByPath(BlockNode $block, string $path, array &$byPath): void
    {
        $byPath[$path] = $block;
        foreach ($block->innerBlocks as $index => $child) {
            self::collectBlocksByPath($child, $path . '/' . $index, $byPath);
        }
    }

    /** @param array<string,true> $paragraphAlignmentRepairs */
    private static function alignmentLossAlreadyWarned(
        string $file,
        AlignmentClassLoss $loss,
        array $paragraphAlignmentRepairs,
    ): bool {
        return $loss->blockName === 'core/paragraph'
            && str_starts_with($loss->authoredClass, 'has-text-align-')
            && isset($paragraphAlignmentRepairs[$file . "\0" . $loss->blockPath]);
    }

    private static function alignmentWarning(string $file, AlignmentClassLoss $loss): string
    {
        $authored = self::quoted($loss->authoredClass);
        $delivered = self::deliveredAlignmentValue($loss);
        $disposition = $loss->deliveredClasses === []
            ? 'authored class removed; block uses its default alignment'
            : 'authored class removed; final block keeps other alignment in the same family';
        return "{$file} block {$loss->blockPath} ({$loss->blockName}): alignment class {$authored} "
            . "could not be preserved (authored {$authored}; delivered {$delivered}; "
            . "disposition: {$disposition}); "
            . 'deterministic final output delivered — see logs/' . self::LOG_FILE;
    }

    private static function alignmentSummary(string $file, AlignmentClassLoss $loss): string
    {
        return "{$file} block {$loss->blockPath} ({$loss->blockName}): "
            . self::quoted($loss->authoredClass) . ' -> ' . self::deliveredAlignmentValue($loss);
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
        foreach ($project->themeFiles() as $relative) {
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
