<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockFixerOutcome;
use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Step 8 (deterministic): repair block-validation issues in generated markup.
 *
 * Input:  theme/templates/*.html + theme/parts/*.html
 * Output: the same files, re-serialized to match WordPress save() exactly.
 *
 * Runs the LayoutFixer width/rhythm normalization FIRST — it edits only the
 * block-comment JSON attributes, and the block re-serialization right after is
 * what syncs the authored HTML (align classes) with those attributes.
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
            reads: ['theme/theme.json', 'theme/parts/*'],
            writes: ['theme/parts/*', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $beforeInitialPass = self::snapshotThemeFiles($project);
        $outcomes = [];
        $failedFiles = [];
        try {
            $layoutNotes = self::normalizeLayouts($project);
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
        // LayoutFixer (for example, by balancing a wp:group). Give the layout
        // contract one more chance, then re-serialize only when that pass
        // actually changed comment attributes so the authored HTML stays in
        // sync with them.
        try {
            $postRepairLayoutNotes = self::normalizeLayouts($project, self::failurePaths($failedFiles));
            $layoutNotes = array_merge($layoutNotes, $postRepairLayoutNotes);
            if ($postRepairLayoutNotes !== []) {
                $followUpOutcome = BlockFixerOutcome::run($this->fixer, $project->themePath());
                $outcomes[] = $followUpOutcome;
                $followUpSummary = $followUpOutcome->formatted;
                $summary .= "\n[layout] post-repair normalization required a second block-fixer pass:\n  "
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
            $summary .= "\n[layout] post-repair normalization required a second block-fixer pass, which failed:\n  "
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

        // Re-serialization is allowed to discard incidental HTML-only styling.
        // Losing vertical spacing changes the page's authored rhythm, which is
        // worth flagging loudly — the block fixer reports every such loss as
        // `DROPPED style `prop:value``. But it is a cosmetic regression in one
        // theme, and discarding the whole build over it means the user gets no
        // site at all rather than one whose spacing is slightly off. Record it
        // as a prominent warning and let the build continue.
        $failedPaths = self::failurePaths($failedFiles);
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
        $warnings = $paragraphStyleWarnings;
        foreach ($rhythmDrops as $drop) {
            $warnings[] = "block re-serialization dropped vertical rhythm CSS `{$drop}`; "
                . 'see logs/' . self::LOG_FILE;
        }
        // An alignment class is not the incidental HTML-only styling the fixer
        // may discard: WordPress generates these tokens from block supports, so
        // a heading that was centred renders left-aligned and the visitor sees
        // it. Skip files whose loss is already recorded by the reviewed
        // paragraph path — there the alignment was resolved, not lost, and a
        // second row for one defect is noise rather than signal.
        $alignmentDrops = array_values(array_filter(
            self::droppedAlignmentClasses($deliveredSummary),
            static fn (array $drop): bool => !in_array($drop[0], self::warnedFiles($paragraphStyleWarnings), true),
        ));
        foreach ($alignmentDrops as [$file, $class]) {
            $warnings[] = "block re-serialization dropped alignment class `{$class}` in {$file}; "
                . 'the block renders with the default alignment — see logs/' . self::LOG_FILE;
        }
        if ($alignmentDrops !== []) {
            $summary .= "\n[alignment] WARNING: block re-serialization dropped alignment classes:\n  "
                . implode("\n  ", array_map(
                    static fn (array $drop): string => $drop[0] . ': ' . $drop[1],
                    $alignmentDrops,
                ));
            echo '  [alignment] warning: ' . count($alignmentDrops)
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

        // The fixer can silently migrate a mismatched group through a
        // deprecated block version whose schema predates "layout". Re-assert
        // the header/footer layout contract on files whose transaction
        // succeeded; a failed file must remain at its exact step-entry bytes.
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
        foreach (self::themeFiles($project) as $rel) {
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
     * Extract DROPPED class rows for support-generated alignment classes.
     *
     * The sibling rhythm helper above exists because losing spacing changes the
     * page's authored rhythm. Alignment is the same kind of loss and not the
     * "incidental HTML-only styling" the fixer is allowed to discard: a heading
     * that was centred renders left-aligned, which the visitor sees. WordPress
     * generates these tokens from block supports, so their visual meaning is
     * fixed and does not depend on whether the theme happens to style them.
     *
     * @return list<string>
     */
    public static function droppedAlignmentClasses(string $report): array
    {
        $dropped = [];
        $file = 'unknown file';
        foreach (preg_split('/\r?\n/', $report) ?: [] as $line) {
            if (preg_match('/^\s+(?:FIXED|ok|skip)\s+(.+?)\s*$/', $line, $match) === 1) {
                $file = trim($match[1]);
                continue;
            }
            if (preg_match('/\bDROPPED\s+class\s+`([^`\r\n]+)`/i', $line, $match) !== 1) {
                continue;
            }
            $token = trim($match[1]);
            if (preg_match('/^(?:has-text-align-(?:left|center|right)|align(?:full|wide|left|right|center|none))$/', $token) !== 1) {
                continue;
            }
            $entry = [$file, $token];
            if (!in_array($entry, $dropped, true)) {
                $dropped[] = $entry;
            }
        }
        return $dropped;
    }

    /**
     * Files already named by another warning, so one defect is not recorded
     * twice in two vocabularies.
     *
     * @param list<string> $warnings
     * @return list<string>
     */
    private static function warnedFiles(array $warnings): array
    {
        $files = [];
        foreach ($warnings as $warning) {
            if (preg_match('/(\S+\.html)\b/', $warning, $match) === 1
                && !in_array($match[1], $files, true)) {
                $files[] = $match[1];
            }
        }
        return $files;
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
        foreach (self::themeFiles($project) as $relative) {
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

    /** @return string[] parts/*.html and templates/*.html, theme-relative */
    public static function themeFiles(Project $project): array
    {
        $rels = [];
        foreach (['parts', 'templates'] as $sub) {
            foreach (glob($project->themePath($sub) . '/*.html') ?: [] as $file) {
                $rels[] = $sub . '/' . basename($file);
            }
        }
        return $rels;
    }

    /**
     * The theme's spacing-scale slugs (settings.spacing.spacingSizes), for
     * LayoutFixer's bare-slug spacing repair. Empty when unknown.
     *
     * @return string[]
     */
    public static function themeSpacingSlugs(Project $project): array
    {
        if (!$project->exists('theme/theme.json')) {
            return [];
        }
        $theme = json_decode($project->readText('theme/theme.json'), true);
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
        if (!$project->exists('theme/theme.json')) {
            return null;
        }
        $theme = json_decode($project->readText('theme/theme.json'), true);
        $size = $theme['settings']['layout']['contentSize'] ?? null;
        return is_string($size) && preg_match('/^([0-9.]+)px$/', $size, $m) === 1
            ? (float) $m[1]
            : null;
    }
}
