<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;

/**
 * Step 8 (deterministic): repair block-validation issues in generated markup.
 *
 * Input:  theme/templates/*.html + theme/parts/*.html
 * Output: the same files, re-serialized to match WordPress save() exactly.
 *
 * Runs the LayoutFixer width/rhythm normalization FIRST — it edits only the
 * block-comment JSON attributes, and the Node re-serialization right after is
 * what syncs the authored HTML (align classes) with those attributes.
 *
 * Delegates the effectful Node (or host) repair to an injected BlockFixer.
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
        $project->writeText('logs/' . self::LOG_FILE, $summary . "\n");

        // The fixer can silently migrate a mismatched group through a
        // deprecated block version whose schema predates "layout". Re-assert
        // the header/footer layout contract afterwards regardless.
        foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
            if (!$project->exists('theme/' . $rel)) {
                continue;
            }
            $markup = $project->readText('theme/' . $rel);
            $repaired = SectionsStep::constrainedPart($markup);
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
     * runs before the Node fixer because LayoutFixer rewrites only comment
     * attributes and the following re-serialization syncs the HTML with them.
     * A post-repair pass handles markup that only became parseable afterwards.
     *
     * @return string[]
     */
    public static function normalizeLayouts(Project $project): array
    {
        $notes = [];
        $contentSize = self::themeContentSize($project);
        foreach (self::themeFiles($project) as $rel) {
            $markup = $project->readText('theme/' . $rel);
            $result = LayoutFixer::fix($markup, LayoutFixer::roleFor($rel), $contentSize);
            if ($result['markup'] !== $markup) {
                $project->writeText('theme/' . $rel, $result['markup']);
            }
            foreach ($result['notes'] as $note) {
                $notes[] = "{$rel}: {$note}";
            }
        }
        return $notes;
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
