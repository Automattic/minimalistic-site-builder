<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\Narrator;

require_once dirname(__DIR__, 2) . '/bin/distill-google-fonts-catalog.php';

/** Writer that fails the manifest commit after atomically replacing catalog.json. */
final class FontCatalogFailSecondReplaceWriter implements StagedFileWriter
{
    private NativeStagedFileWriter $inner;
    private int $replaceCount = 0;

    public function __construct(private bool $failRollback = false)
    {
        $this->inner = new NativeStagedFileWriter();
    }

    public function stage(string $target, string $content): string
    {
        return $this->inner->stage($target, $content);
    }

    public function replace(string $staged, string $target): void
    {
        ++$this->replaceCount;
        if ($this->replaceCount === 2 || ($this->failRollback && $this->replaceCount === 3)) {
            throw new RuntimeException(
                $this->replaceCount === 2
                    ? 'injected manifest replace failure'
                    : 'injected catalog rollback failure'
            );
        }
        $this->inner->replace($staged, $target);
    }

    public function discard(string $staged): void
    {
        $this->inner->discard($staged);
    }
}

function font_catalog_distiller_root(): string
{
    $root = sys_get_temp_dir() . '/font-catalog-distiller-' . bin2hex(random_bytes(8));
    if (!mkdir($root . '/data/google-fonts', 0775, true)) {
        throw new RuntimeException("Could not create test directory: {$root}");
    }
    return $root;
}

function font_catalog_distiller_remove(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($root);
}

/** @return array<string,mixed> */
function font_catalog_distiller_collection(array $settings = []): array
{
    return [
        'font_families' => [[
            'font_family_settings' => array_replace([
                'name'       => 'Inter',
                'slug'       => 'inter',
                'fontFamily' => 'Inter, sans-serif',
                'fontFace'   => [[
                    'fontWeight' => '400',
                    'fontStyle'  => 'normal',
                    'src'        => 'https://fonts.gstatic.com/s/inter/400.woff2',
                ]],
            ], $settings),
        ]],
    ];
}

function font_catalog_distiller_seed(string $root): void
{
    file_put_contents($root . '/data/google-fonts/catalog.json', 'catalog-before');
    file_put_contents($root . '/data/google-fonts/catalog-manifest.json', 'manifest-before');
}

/**
 * @param array<string,mixed> $source
 * @return array{exit:int,stdout:string,stderr:string}
 */
function font_catalog_distiller_run(
    string $root,
    array $source,
    ?StagedFileWriter $writer = null,
    int $minimumFamilies = DISTILL_GOOGLE_FONTS_MIN_FAMILIES,
    int $minimumFaces = DISTILL_GOOGLE_FONTS_MIN_FACES,
): array {
    $sourcePath = $root . '/source.json';
    file_put_contents($sourcePath, json_encode($source, JSON_THROW_ON_ERROR));

    $sink = fopen('php://memory', 'w+');
    if ($sink === false) {
        throw new RuntimeException('Could not create narration sink');
    }
    Narrator::setStream($sink);
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        $exit = distill_google_fonts_catalog_main(
            ['distill-google-fonts-catalog.php', $sourcePath, 'test-release'],
            $root,
            $writer,
            $minimumFamilies,
            $minimumFaces,
        );
        $stdout = (string) ob_get_clean();
        rewind($sink);
        $stderr = (string) stream_get_contents($sink);
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        Narrator::reset();
        fclose($sink);
    }

    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

test('font catalog distiller writes validated artifacts and provenance', function (): void {
    $root = font_catalog_distiller_root();
    try {
        $result = font_catalog_distiller_run($root, font_catalog_distiller_collection(), null, 1, 1);
        assert_eq(0, $result['exit'], $result['stderr']);
        assert_contains('1 family/families, 1 faces', $result['stdout']);

        $catalogBytes = (string) file_get_contents($root . '/data/google-fonts/catalog.json');
        $catalog = json_decode($catalogBytes, true, 512, JSON_THROW_ON_ERROR);
        assert_eq('https://fonts.gstatic.com/s/inter/400.woff2', $catalog['font_families'][0]['fontFace'][0]['src']);

        $manifest = json_decode(
            (string) file_get_contents($root . '/data/google-fonts/catalog-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        assert_eq(1, $manifest['catalog']['families']);
        assert_eq(1, $manifest['catalog']['faces']);
        assert_eq(hash('sha256', $catalogBytes), $manifest['catalog']['sha256']);
        assert_eq(
            hash_file('sha256', dirname(__DIR__, 2) . '/bin/distill-google-fonts-catalog.php'),
            $manifest['distiller']['sha256'],
        );
    } finally {
        font_catalog_distiller_remove($root);
    }
});

test('font catalog distiller rejects every source outside the HTTPS gstatic origin', function (): void {
    foreach ([
        'http://fonts.gstatic.com/s/inter/400.woff2',
        'https://example.com/s/inter/400.woff2',
    ] as $src) {
        $root = font_catalog_distiller_root();
        try {
            font_catalog_distiller_seed($root);
            $source = font_catalog_distiller_collection([
                'fontFace' => [[
                    'fontWeight' => '400',
                    'fontStyle' => 'normal',
                    'src' => $src,
                ]],
            ]);
            $result = font_catalog_distiller_run($root, $source);

            assert_eq(1, $result['exit']);
            assert_contains('outside https://fonts.gstatic.com', $result['stderr']);
            assert_eq('', $result['stdout'], 'a rejected source must not print the success banner');
            assert_eq('catalog-before', file_get_contents($root . '/data/google-fonts/catalog.json'));
            assert_eq('manifest-before', file_get_contents($root . '/data/google-fonts/catalog-manifest.json'));
        } finally {
            font_catalog_distiller_remove($root);
        }
    }
});

test('font catalog distiller rejects empty and malformed collections before writing', function (): void {
    $missingFaces = font_catalog_distiller_collection();
    unset($missingFaces['font_families'][0]['font_family_settings']['fontFace']);
    $cases = [
        'empty collection' => ['font_families' => []],
        'implausibly truncated collection' => font_catalog_distiller_collection(),
        'non-string family field' => font_catalog_distiller_collection(['name' => []]),
        'missing face list' => $missingFaces,
        'non-string face field' => font_catalog_distiller_collection([
            'fontFace' => [[
                'fontWeight' => '400',
                'fontStyle' => 'normal',
                'src' => [],
            ]],
        ]),
    ];

    foreach ($cases as $label => $source) {
        $root = font_catalog_distiller_root();
        try {
            font_catalog_distiller_seed($root);
            $result = font_catalog_distiller_run($root, $source);

            assert_eq(1, $result['exit'], $label);
            assert_eq('', $result['stdout'], "{$label} must not print the success banner");
            assert_eq('catalog-before', file_get_contents($root . '/data/google-fonts/catalog.json'), $label);
            assert_eq(
                'manifest-before',
                file_get_contents($root . '/data/google-fonts/catalog-manifest.json'),
                $label,
            );
        } finally {
            font_catalog_distiller_remove($root);
        }
    }
});

test('font catalog distiller fails before replacing an invalid output target', function (): void {
    $root = font_catalog_distiller_root();
    try {
        file_put_contents($root . '/data/google-fonts/catalog.json', 'catalog-before');
        mkdir($root . '/data/google-fonts/catalog-manifest.json');

        $result = font_catalog_distiller_run($root, font_catalog_distiller_collection(), null, 1, 1);
        assert_eq(1, $result['exit']);
        assert_contains('Output target is not a regular file', $result['stderr']);
        assert_eq('', $result['stdout']);
        assert_eq('catalog-before', file_get_contents($root . '/data/google-fonts/catalog.json'));
        assert_true(is_dir($root . '/data/google-fonts/catalog-manifest.json'));
    } finally {
        font_catalog_distiller_remove($root);
    }
});

test('font catalog distiller rolls back the first artifact when the second commit fails', function (): void {
    $root = font_catalog_distiller_root();
    try {
        font_catalog_distiller_seed($root);
        $result = font_catalog_distiller_run(
            $root,
            font_catalog_distiller_collection(),
            new FontCatalogFailSecondReplaceWriter(),
            1,
            1,
        );

        assert_eq(1, $result['exit']);
        assert_contains('injected manifest replace failure', $result['stderr']);
        assert_eq('', $result['stdout']);
        assert_eq('catalog-before', file_get_contents($root . '/data/google-fonts/catalog.json'));
        assert_eq('manifest-before', file_get_contents($root . '/data/google-fonts/catalog-manifest.json'));
        assert_eq([], glob($root . '/data/google-fonts/.block-fixer-*') ?: [], 'staged files must be cleaned');
    } finally {
        font_catalog_distiller_remove($root);
    }
});

test('font catalog distiller preserves the prior bytes when rollback also fails', function (): void {
    $root = font_catalog_distiller_root();
    try {
        font_catalog_distiller_seed($root);
        $result = font_catalog_distiller_run(
            $root,
            font_catalog_distiller_collection(),
            new FontCatalogFailSecondReplaceWriter(true),
            1,
            1,
        );

        assert_eq(1, $result['exit']);
        assert_contains('injected catalog rollback failure', $result['stderr']);
        assert_contains('prior bytes remain staged at', $result['stderr']);
        assert_eq('', $result['stdout']);
        assert_true(
            file_get_contents($root . '/data/google-fonts/catalog.json') !== 'catalog-before',
            'the injected rollback failure must leave the new target in place',
        );
        assert_eq('manifest-before', file_get_contents($root . '/data/google-fonts/catalog-manifest.json'));

        $backups = glob($root . '/data/google-fonts/.block-fixer-*') ?: [];
        assert_eq(1, count($backups), 'the one needed recovery backup must survive cleanup');
        assert_eq('catalog-before', file_get_contents($backups[0]));
    } finally {
        font_catalog_distiller_remove($root);
    }
});
