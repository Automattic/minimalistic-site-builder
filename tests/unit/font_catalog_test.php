<?php
declare(strict_types=1);

use Automattic\SiteBuild\FontCatalog;

/**
 * FontCatalog: resolution of the family references generated themes actually
 * contain, face selection against the scanned weights/italics, and the
 * consistency of the vendored artifact with the invariants the distiller
 * enforces.
 */

$fontCatalogFixture = static fn (): FontCatalog => with_temp_dir('font-catalog-', static function (string $dir): FontCatalog {
    $path = $dir . '/font-catalog.json';
    file_put_contents($path, json_encode([
        'font_families' => [
            [
                'name'       => 'Inter',
                'slug'       => 'inter',
                'fontFamily' => 'Inter, sans-serif',
                'fontFace'   => [
                    ['fontWeight' => '300', 'fontStyle' => 'normal', 'src' => 'https://fonts.gstatic.com/s/inter/300.woff2'],
                    ['fontWeight' => '400', 'fontStyle' => 'normal', 'src' => 'https://fonts.gstatic.com/s/inter/400.woff2'],
                    ['fontWeight' => '700', 'fontStyle' => 'normal', 'src' => 'https://fonts.gstatic.com/s/inter/700.woff2'],
                    ['fontWeight' => '400', 'fontStyle' => 'italic', 'src' => 'https://fonts.gstatic.com/s/inter/400i.woff2'],
                ],
            ],
            [
                'name'       => 'Playfair Display',
                'slug'       => 'playfair-display',
                'fontFamily' => '"Playfair Display", serif',
                'fontFace'   => [
                    ['fontWeight' => '400', 'fontStyle' => 'normal', 'src' => 'https://fonts.gstatic.com/s/playfair/400.woff2'],
                    ['fontWeight' => '900', 'fontStyle' => 'normal', 'src' => 'https://fonts.gstatic.com/s/playfair/900.woff2'],
                ],
            ],
        ],
    ]));
    return FontCatalog::load($path);
});

test('FontCatalog resolves names, slugs, and CSS stacks case-insensitively', function () use ($fontCatalogFixture) {
    $catalog = $fontCatalogFixture();

    assert_eq('Inter', $catalog->resolve('Inter')['name']);
    assert_eq('Inter', $catalog->resolve('inter')['name']);
    assert_eq('Inter', $catalog->resolve('INTER')['name']);
    assert_eq('Playfair Display', $catalog->resolve('PLAYFAIR-DISPLAY')['name']);
    assert_eq('Inter', $catalog->resolve('Inter, sans-serif')['name']);
    assert_eq('Playfair Display', $catalog->resolve('"Playfair Display", serif')['name']);
    assert_eq('Playfair Display', $catalog->resolve("'Playfair Display'")['name']);
    assert_eq(null, $catalog->resolve('No Such Family'));
    assert_eq(null, $catalog->resolve(''));
    assert_eq(null, $catalog->resolve('sans-serif, Inter'), 'only the first stack segment names the family');
});

test('FontCatalog selects exact faces and falls back to the nearest weight', function () use ($fontCatalogFixture) {
    $catalog = $fontCatalogFixture();
    $inter = $catalog->resolve('Inter');

    $srcs = array_column($catalog->faces($inter, [400, 700], false), 'src');
    assert_eq([
        'https://fonts.gstatic.com/s/inter/400.woff2',
        'https://fonts.gstatic.com/s/inter/700.woff2',
    ], $srcs);

    // 500 does not exist in the fixture; the nearest real face (400) is bundled
    // once, not synthesized and not duplicated.
    $srcs = array_column($catalog->faces($inter, [400, 500], false), 'src');
    assert_eq(['https://fonts.gstatic.com/s/inter/400.woff2'], $srcs);

    // Playfair has no 700; 900 is nearer to 700 than 400 is.
    $playfair = $catalog->resolve('Playfair Display');
    $srcs = array_column($catalog->faces($playfair, [700], false), 'src');
    assert_eq(['https://fonts.gstatic.com/s/playfair/900.woff2'], $srcs);
});

test('FontCatalog includes italic faces only when the scan saw italics', function () use ($fontCatalogFixture) {
    $catalog = $fontCatalogFixture();
    $inter = $catalog->resolve('Inter');

    $styles = array_column($catalog->faces($inter, [400], true), 'fontStyle');
    assert_eq(['normal', 'italic'], $styles);

    $styles = array_column($catalog->faces($inter, [400], false), 'fontStyle');
    assert_eq(['normal'], $styles);

    // A family with no italic faces degrades to normal-only rather than failing.
    $playfair = $catalog->resolve('Playfair Display');
    $styles = array_column($catalog->faces($playfair, [400], true), 'fontStyle');
    assert_eq(['normal'], $styles);
});

test('the vendored catalog honors the distiller invariants', function () {
    $catalog = FontCatalog::load();
    assert_true($catalog->count() > 1900, 'the wp-7.1 release carries ~1949 families');
    assert_true($catalog->resolve('Inter') !== null);

    // Security invariant: every downloadable src is fonts.gstatic.com. The
    // distiller refuses anything else at build time; this re-checks the
    // committed artifact so a hand edit cannot widen the origin set.
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/google-fonts/catalog.json'), true);
    $faceCount = 0;
    foreach ($decoded['font_families'] as $family) {
        assert_true($family['fontFace'] !== [], "family {$family['name']} has no faces");
        foreach ($family['fontFace'] as $face) {
            ++$faceCount;
            assert_eq('fonts.gstatic.com', parse_url($face['src'], PHP_URL_HOST));
            assert_eq('https', parse_url($face['src'], PHP_URL_SCHEME));
        }
    }

    // Provenance: the manifest's catalog hash matches the committed bytes.
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/google-fonts/catalog-manifest.json'), true);
    assert_eq(
        $manifest['catalog']['sha256'],
        hash('sha256', (string) file_get_contents(dirname(__DIR__, 2) . '/data/google-fonts/catalog.json')),
        'catalog.json was edited without re-running the distiller'
    );
    assert_eq(
        $manifest['distiller']['sha256'],
        hash_file('sha256', dirname(__DIR__, 2) . '/bin/distill-google-fonts-catalog.php'),
        'distiller changed without regenerating catalog-manifest.json'
    );
    assert_eq(
        $manifest['catalog']['families'],
        count($decoded['font_families']),
        'catalog-manifest.json records a stale family count'
    );
    assert_eq(
        $manifest['catalog']['faces'],
        $faceCount,
        'catalog-manifest.json records a stale face count'
    );
});
