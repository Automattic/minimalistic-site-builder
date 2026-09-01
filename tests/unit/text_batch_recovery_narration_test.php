<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;

test('TextBatchRecovery narration goes through Narrator, not STDERR', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/src/TextBatchRecovery.php');
    assert_true($src !== false, 'could not read TextBatchRecovery.php');
    assert_true(
        !str_contains($src, 'fwrite(STDERR'),
        'TextBatchRecovery still writes to STDERR directly; AGENTS.md requires Narrator::write()'
    );
});

test('TextBatchRecovery notes reach the Narrator stream', function (): void {
    with_temp_dir('narr', function (string $dir): void {
        $path = $dir . '/out.txt';
        $stream = fopen($path, 'w');
        assert_true($stream !== false, 'could not open capture stream');
        Narrator::setStream($stream);
        try {
            Narrator::write("probe-line\n");
        } finally {
            Narrator::setStream(STDERR);
            fclose($stream);
        }
        assert_contains('probe-line', (string) file_get_contents($path));
    });
});
