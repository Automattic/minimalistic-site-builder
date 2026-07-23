<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;

function native_staged_writer_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/staged-writer-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0775, true)) {
        throw new RuntimeException("Could not create test directory: {$dir}");
    }
    return $dir;
}

function native_staged_writer_remove(string $dir): void
{
    @chmod($dir, 0775);
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            @unlink($dir . '/' . $name);
        }
    }
    @rmdir($dir);
}

test('NativeStagedFileWriter stages beside the target and replaces atomically', function (): void {
    $dir = native_staged_writer_temp_dir();
    try {
        $target = $dir . '/part.html';
        file_put_contents($target, 'before');

        $writer = new NativeStagedFileWriter();
        $staged = $writer->stage($target, 'after');
        assert_eq(realpath($dir), realpath(dirname($staged)), 'staged file must live beside the target');
        assert_eq('before', file_get_contents($target), 'target must be untouched while staged');

        $writer->replace($staged, $target);
        assert_eq('after', file_get_contents($target));
        assert_true(!file_exists($staged), 'staged file must be consumed by replace');
    } finally {
        native_staged_writer_remove($dir);
    }
});

test('NativeStagedFileWriter preserves the target file mode on replace', function (): void {
    $dir = native_staged_writer_temp_dir();
    try {
        $target = $dir . '/part.html';
        file_put_contents($target, 'before');
        chmod($target, 0644);

        $writer = new NativeStagedFileWriter();
        $writer->replace($writer->stage($target, 'after'), $target);
        clearstatcache();
        assert_eq(0644, fileperms($target) & 0777, 'replaced file must keep the original mode');
    } finally {
        native_staged_writer_remove($dir);
    }
});

test('NativeStagedFileWriter refuses to stage when tempnam falls back to the system tmp dir', function (): void {
    $dir = native_staged_writer_temp_dir();
    try {
        $target = $dir . '/part.html';
        file_put_contents($target, 'before');
        if (!chmod($dir, 0555) || is_writable($dir)) {
            skip_test('cannot make the target directory read-only on this platform');
        }

        // A read-only parent makes tempnam silently create the staged file
        // under the system tmp dir; rename from there may cross filesystems
        // and lose atomicity, so stage() must throw instead.
        $writer = new NativeStagedFileWriter();
        assert_throws(
            fn() => $writer->stage($target, 'after'),
            'stage() must refuse a staged file outside the target directory'
        );
        assert_eq('before', file_get_contents($target), 'target must be untouched');
    } finally {
        native_staged_writer_remove($dir);
    }
});
