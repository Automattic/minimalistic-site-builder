<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
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
        $layoutNotes = self::normalizeLayouts($project);

        try {
            $summary = $this->fixer->fix($project->themePath());
        } catch (\RuntimeException $e) {
            $project->writeText('logs/' . self::LOG_FILE, $e->getMessage() . "\n");
            throw $e;
        }

        // Structural repair can make previously unparseable markup safe for
        // LayoutFixer (for example, by balancing a wp:group). Give the layout
        // contract one more chance, then re-serialize only when that pass
        // actually changed comment attributes so the authored HTML stays in
        // sync with them.
        $postRepairLayoutNotes = self::normalizeLayouts($project);
        $layoutNotes = array_merge($layoutNotes, $postRepairLayoutNotes);
        if ($postRepairLayoutNotes !== []) {
            try {
                $followUpSummary = $this->fixer->fix($project->themePath());
            } catch (\RuntimeException $e) {
                $summary .= "\n[layout] post-repair normalization required a second block-fixer pass, which failed:\n  "
                    . str_replace("\n", "\n  ", $e->getMessage());
                if ($layoutNotes !== []) {
                    $summary .= "\n[layout] " . count($layoutNotes) . " width/rhythm fix(es):\n  "
                        . implode("\n  ", $layoutNotes);
                }
                $project->writeText('logs/' . self::LOG_FILE, $summary . "\n");
                throw $e;
            }
            $summary .= "\n[layout] post-repair normalization required a second block-fixer pass:\n  "
                . str_replace("\n", "\n  ", $followUpSummary);
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
        $rhythmDrops = self::droppedVerticalRhythmStyles($summary);
        if ($rhythmDrops !== []) {
            $summary .= "\n[rhythm] WARNING: block re-serialization dropped vertical rhythm CSS:\n  "
                . implode("\n  ", $rhythmDrops);
            echo '  [rhythm] warning: dropped ' . implode(', ', $rhythmDrops) . "\n";
        }

        // An attribute the block does not register is dropped rather than
        // failing the build, so warnings.json is the only durable record that
        // authored intent went missing — the repair pass reads it. Renames are
        // deliberately not recorded there: the attribute survived under its
        // correct name, so there is no defect left to repair.
        $renames = self::renamedAttributes($summary);
        $drops = self::droppedAttributes($summary);
        if ($drops !== []) {
            $project->addWarnings($this->id(), $drops);
        }
        if ($renames !== []) {
            $summary .= "\n[attributes] " . count($renames) . " misnamed attribute(s) renamed:\n  "
                . implode("\n  ", $renames);
        }
        if ($drops !== []) {
            $summary .= "\n[attributes] WARNING: " . count($drops)
                . " unregistered attribute(s) dropped (recorded in warnings.json):\n  "
                . implode("\n  ", $drops);
        }
        $project->writeText('logs/' . self::LOG_FILE, $summary . "\n");

        if ($renames !== []) {
            echo '  attributes: ' . count($renames) . " misnamed attribute(s) renamed\n";
        }
        if ($drops !== []) {
            echo '  [attributes] warning: ' . count($drops)
                . " unregistered attribute(s) dropped; see warnings.json\n";
        }

        // The fixer can silently migrate a mismatched group through a
        // deprecated block version whose schema predates "layout". Re-assert
        // the header/footer layout contract afterwards regardless.
        foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
            if (!$project->exists('theme/' . $rel)) {
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
     * @return string[]
     */
    public static function normalizeLayouts(Project $project): array
    {
        $notes = [];
        $contentSize = self::themeContentSize($project);
        $spacingSlugs = self::themeSpacingSlugs($project);
        foreach (self::themeFiles($project) as $rel) {
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

    /** Most warning lines emitted for one distinct attribute before they are summarized. */
    private const MAX_WARNING_LINES_PER_ATTRIBUTE = 20;

    /**
     * Attributes the normalizer renamed onto the registered name whose shape
     * they varied, one line per distinct rename with its occurrence count.
     * These stay out of warnings.json: the value survived under its correct
     * name, so there is no defect for the repair pass to act on.
     *
     * @return string[]
     */
    public static function renamedAttributes(string $report): array
    {
        $counts = [];
        foreach (self::attributeRepairs($report, 'attribute-renamed') as $row) {
            $key = ($row['from'] ?? '') . "\0" . ($row['to'] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $lines = [];
        foreach ($counts as $key => $count) {
            [$from, $to] = explode("\0", (string) $key, 2);
            $lines[] = sprintf("renamed '%s' to '%s' on %d block(s)", $from, $to, $count);
        }
        return $lines;
    }

    /**
     * Attributes dropped because the block does not register them and no
     * registered name matched. Each line names the file and block path so the
     * repair pass documented on Project::addWarnings can find the block again,
     * and carries the dropped value so it can restore it. Structural drops —
     * where the block ended up without what it needs to render at all — lead,
     * and are labelled so they are not lost among alignment nits.
     *
     * @return string[]
     */
    public static function droppedAttributes(string $report): array
    {
        $lines = [];
        foreach (['structural-attribute-dropped', 'unknown-attribute-dropped'] as $code) {
            $structural = $code === 'structural-attribute-dropped';
            $seen = [];
            foreach (self::attributeRepairs($report, $code) as $row) {
                $key = (string) ($row['key'] ?? '');
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                if ($seen[$key] > self::MAX_WARNING_LINES_PER_ATTRIBUTE) {
                    continue;
                }
                $lines[] = sprintf(
                    "%s%s block %s: dropped unregistered attribute '%s' (value %s) from %s%s",
                    $structural ? 'BROKEN: ' : '',
                    $row['file'],
                    $row['blockPath'],
                    $key,
                    json_encode((string) ($row['value'] ?? ''), JSON_UNESCAPED_SLASHES),
                    (string) ($row['block'] ?? 'unknown block'),
                    $structural
                        ? ' — the block cannot render without it'
                        : '; the block does not implement it',
                );
            }
            foreach ($seen as $key => $count) {
                if ($count > self::MAX_WARNING_LINES_PER_ATTRIBUTE) {
                    $lines[] = sprintf(
                        "...and %d further block(s) with '%s' dropped (listed in logs/%s)",
                        $count - self::MAX_WARNING_LINES_PER_ATTRIBUTE,
                        $key,
                        self::LOG_FILE,
                    );
                }
            }
        }
        return $lines;
    }

    /**
     * Every `REPAIR <code>:<json> at <path>` row in the report, decoded, with
     * the file it appeared under attached.
     *
     * The payload is JSON rather than delimiter-packed text because the fields
     * are model-authored and can contain any character a delimiter might use.
     * Rows whose payload does not decode are skipped rather than guessed at.
     *
     * @return list<array<string,mixed>>
     */
    private static function attributeRepairs(string $report, string $code): array
    {
        $rows = [];
        $file = 'unknown file';
        $marker = '- REPAIR ' . $code . ':';
        foreach (preg_split('/\r?\n/', $report) ?: [] as $line) {
            if (preg_match('/^\s{2}(?:FIXED|ok|skip)\s+(\S+)$/', $line, $m) === 1) {
                $file = $m[1];
                continue;
            }
            $at = strpos($line, $marker);
            if ($at === false) {
                continue;
            }
            $rest = substr($line, $at + strlen($marker));
            $split = strrpos($rest, ' at ');
            if ($split === false) {
                continue;
            }
            $decoded = json_decode(substr($rest, 0, $split), true);
            if (!is_array($decoded)) {
                continue;
            }
            $decoded['file'] = $file;
            $decoded['blockPath'] = trim(substr($rest, $split + 4));
            $rows[] = $decoded;
        }
        return $rows;
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
