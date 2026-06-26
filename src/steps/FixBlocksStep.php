<?php
declare(strict_types=1);

/**
 * Step 8 (deterministic): repair block-validation issues in the generated markup.
 *
 * Input:  theme/templates/*.html + theme/parts/*.html (written by landing-page)
 * Output: the same files, re-serialized to match WordPress save() exactly.
 *
 * AI-generated block markup frequently carries style/attribute/element-order
 * mismatches that trigger "this block contains unexpected or invalid content"
 * in the editor and WordPress Playground. We shell out to the Node block-fixer
 * (a verbatim copy of telex's server/scripts/block-fixer), which parses each
 * file with @wordpress/blocks and re-serializes it — the same fix telex applies
 * to model output before saving.
 */
final class FixBlocksStep implements Step
{
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
            throw new RuntimeException("block-fixer script not found: {$script}");
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
            throw new RuntimeException('Could not start block-fixer (proc_open failed)');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($proc);

        $stderr = $errFile !== false ? (string) @file_get_contents($errFile) : '';
        if ($errFile !== false) {
            @unlink($errFile);
        }

        if ($exit !== 0) {
            throw new RuntimeException(
                "block-fixer exited with code {$exit}.\n" . trim($stderr)
            );
        }

        // The fixer prints a concise per-file report on stdout; surface it.
        echo rtrim((string) $stdout) . "\n";
    }

    /** Allow overriding the node binary via env; default to PATH lookup. */
    private static function nodeBinary(): string
    {
        $node = Env::get('NODE_BIN', 'node');
        return $node === '' ? 'node' : $node;
    }
}
