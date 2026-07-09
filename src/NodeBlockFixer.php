<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Default BlockFixer: shells out to the bundled Node block-fixer
 * (bin/block-fixer/fix-templates.js), which parses each file with
 * @wordpress/blocks and re-serializes it to match WordPress save() exactly.
 */
final class NodeBlockFixer implements BlockFixer
{
    public function __construct(
        private string $script,
        private string $nodeBinary = 'node',
    ) {}

    public static function default(): self
    {
        $node = Env::get('NODE_BIN', 'node');
        return new self(Package::blockFixerScript(), $node === '' ? 'node' : $node);
    }

    public function fix(string $themeDir): string
    {
        if (!is_file($this->script)) {
            throw new \RuntimeException("block-fixer script not found: {$this->script}");
        }

        $cmd = sprintf(
            '%s %s %s',
            escapeshellarg($this->nodeBinary),
            escapeshellarg($this->script),
            escapeshellarg($themeDir)
        );

        // stderr → temp FILE, not a pipe: @wordpress/blocks can emit a large
        // volume of output, and reading stdout to EOF while the child blocks on a
        // full stderr pipe buffer would deadlock. A file sink never blocks.
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

        if ($exit !== 0) {
            throw new \RuntimeException(
                "block-fixer exited with code {$exit}\n" . trim($stderr)
            );
        }

        $summary = self::summaryLine($stdout);
        if (trim($stderr) !== '') {
            return $summary . "\n\n--- stderr ---\n" . rtrim($stderr);
        }
        // Keep full stdout in the returned log body so FixBlocksStep can write
        // the detailed report; prefix is the human summary for the console.
        $log = rtrim($stdout);
        return $log !== '' ? $log : $summary;
    }

    /** The single human summary line the fixer prints last. Pure — unit-testable. */
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
}
