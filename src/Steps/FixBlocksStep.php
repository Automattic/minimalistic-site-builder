<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Env;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;

/**
 * Step 8 (deterministic): repair block-validation issues in the generated markup.
 *
 * Input:  theme/templates/*.html + theme/parts/*.html (written by the sections
 *         and assemble-landing-page steps)
 * Output: the same files, re-serialized to match WordPress save() exactly.
 *
 * AI-generated block markup frequently carries style/attribute/element-order
 * mismatches that trigger "this block contains unexpected or invalid content"
 * in the editor and WordPress Playground. We shell out to the Node block-fixer
 * (telex's server/scripts/block-fixer plus a comment-attribute overlay — see
 * lib/blockFixer.js), which parses each file with @wordpress/blocks and
 * re-serializes it — the same fix telex applies to model output before saving.
 */
final class FixBlocksStep implements Step
{
    private const LOG_FILE = 'fix-blocks.log';

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
        $script = repo_path('bin/block-fixer/fix-templates.js');
        if (!is_file($script)) {
            throw new \RuntimeException("block-fixer script not found: {$script}");
        }

        $cmd = sprintf(
            '%s %s %s',
            escapeshellarg(self::nodeBinary()),
            escapeshellarg($script),
            escapeshellarg($project->themePath())
        );

        // Route the child's stderr to a temp FILE, not a pipe. @wordpress/blocks
        // can emit a large volume of diagnostic output; reading stdout to EOF
        // while the child blocks on a full stderr pipe buffer would deadlock.
        // A file sink lets the child write freely and never block.
        $errFile = tempnam(sys_get_temp_dir(), 'blockfixer-');
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => $errFile !== false ? ['file', $errFile, 'w'] : ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            if ($errFile !== false) {
                @unlink($errFile);
            }
            throw new \RuntimeException('Could not start block-fixer (proc_open failed)');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($proc);

        $stderr = $errFile !== false ? (string) @file_get_contents($errFile) : '';
        if ($errFile !== false) {
            @unlink($errFile);
        }

        // The fixer's per-file report and the block-validation diffs it emits are
        // verbose (hundreds of lines on a fresh build). Keep the full detail in
        // the project log and show only a one-line summary on the console.
        $log = rtrim($stdout);
        if (trim($stderr) !== '') {
            $log .= "\n\n--- stderr ---\n" . rtrim($stderr);
        }
        file_put_contents($project->logPath(self::LOG_FILE), $log . "\n");

        if ($exit !== 0) {
            throw new \RuntimeException(
                "block-fixer exited with code {$exit}; see logs/" . self::LOG_FILE . "\n" . trim($stderr)
            );
        }

        // The fixer can silently migrate a mismatched group through a
        // deprecated block version whose schema predates "layout" (see the
        // comment-attribute overlay in lib/blockFixer.js for the root fix).
        // Re-assert the header/footer layout contract afterwards regardless,
        // so no fixer path can undo the sections-step repair.
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

        echo '  ' . self::summaryLine($stdout) . ' (details: logs/' . self::LOG_FILE . ")\n";
    }

    /**
     * The single human summary line the fixer prints last (e.g.
     * "[fix-templates] 7/11 file(s) re-serialized, 14 issue(s) fixed …").
     * Pure — unit-testable.
     */
    public static function summaryLine(string $stdout): string
    {
        foreach (array_reverse(preg_split('/\r?\n/', trim($stdout)) ?: []) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[fix-templates]')) {
                return $line;
            }
        }
        return 'block-fixer: no files changed';
    }

    /** Allow overriding the node binary via env; default to PATH lookup. */
    private static function nodeBinary(): string
    {
        $node = Env::get('NODE_BIN', 'node');
        return $node === '' ? 'node' : $node;
    }
}
