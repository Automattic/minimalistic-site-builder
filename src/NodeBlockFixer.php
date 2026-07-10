<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Default BlockFixer: shells out to the bundled Node block-fixer
 * (bin/block-fixer/fix-templates.js). Needs Node 18+ and the script's npm
 * deps (`npm install` at the package root). Hosts without Node should inject
 * their own BlockFixer.
 */
final class NodeBlockFixer implements BlockFixer
{
    public function __construct(
        private string $script,
        private string $nodeBinary = 'node',
    ) {}

    /** Bundled fixer; node binary from NODE_BIN (default: PATH). */
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

        // stderr → file (or null device), not a pipe: large @wordpress/blocks
        // output can deadlock if stderr fills a pipe nobody drains. stdin from
        // null so the child never waits on the host's stdin. Array form proc_open
        // skips the shell so paths reach Node byte-for-byte.
        $errFile = tempnam(sys_get_temp_dir(), 'blockfixer-');
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errFile !== false ? $errFile : $nullDevice, 'w'],
        ];

        $proc = proc_open([$this->nodeBinary, $this->script, $themeDir], $descriptors, $pipes);
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

        // First line = human summary for the console; rest = full report for the log.
        $summary = self::summaryLine($stdout);
        $log = rtrim($stdout);
        if (trim($stderr) !== '') {
            $log .= ($log !== '' ? "\n\n" : '') . "--- stderr ---\n" . rtrim($stderr);
        }
        return $log !== '' ? $summary . "\n" . $log : $summary;
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
