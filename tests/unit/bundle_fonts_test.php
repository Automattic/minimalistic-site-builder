<?php
declare(strict_types=1);

use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Tests\FakeFontFetcher;
use Automattic\SiteBuild\Steps\BundleFontsStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;

require_once __DIR__ . '/../FakeFontFetcher.php';

/**
 * BundleFontsStep: fonts ship as theme assets declared in theme.json, and every
 * failure degrades one family to the fonts.php link path — never the build.
 */

$bundleFontsProject = static function (array $familySlugs = ['inter']): Project {
    $known = [
        'inter'            => ['name' => 'Inter', 'stack' => 'Inter, sans-serif'],
        'lora'             => ['name' => 'Lora', 'stack' => 'Lora, serif'],
        'playfair-display' => ['name' => 'Playfair Display', 'stack' => '"Playfair Display", serif'],
        'made-up'          => ['name' => 'Totally Made Up Font', 'stack' => '"Totally Made Up Font", serif'],
    ];
    $families = [];
    foreach ($familySlugs as $i => $slug) {
        $families[] = [
            'slug'       => $i === 0 ? 'body' : $slug,
            'name'       => $known[$slug]['name'],
            'fontFamily' => $known[$slug]['stack'],
        ];
    }
    $tmp = sys_get_temp_dir() . '/bundle-fonts-' . uniqid();
    $project = new Project($tmp);
    $project->writeJson('designDirection.json', []);
    $project->writeJson('theme/theme.json', [
        'settings' => ['typography' => ['fontFamilies' => $families]],
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:paragraph --><p><strong>Body copy</strong></p><!-- /wp:paragraph -->',
    );
    return $project;
};

test('BundleFontsStep writes assets and declares fontFace with file sources', function () use ($bundleFontsProject) {
    $project = $bundleFontsProject(['playfair-display']);
    $fetcher = new FakeFontFetcher();

    try {
        (new BundleFontsStep($fetcher))->run($project);

        // The scan floor is 400+700 for the body family; only those download.
        assert_eq(2, count($fetcher->calls));

        $theme = $project->readJson('theme/theme.json');
        $faces = $theme['settings']['typography']['fontFamilies'][0]['fontFace'];
        assert_eq(2, count($faces));
        assert_eq('Playfair Display', $faces[0]['fontFamily']);
        assert_eq(['file:./assets/fonts/playfair-display-400.woff2'], $faces[0]['src']);
        assert_eq(['file:./assets/fonts/playfair-display-700.woff2'], $faces[1]['src']);
        foreach (['playfair-display-400.woff2', 'playfair-display-700.woff2'] as $file) {
            assert_true(
                str_starts_with($project->readText('theme/assets/fonts/' . $file), 'FONTBYTES:'),
                "{$file} carries the downloaded bytes"
            );
        }
        assert_true(!$project->exists('warnings.json'), 'a clean bundle warns about nothing');
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('BundleFontsStep degrades a family the catalog does not know', function () use ($bundleFontsProject) {
    $project = $bundleFontsProject(['made-up']);
    $fetcher = new FakeFontFetcher();

    try {
        (new BundleFontsStep($fetcher))->run($project);

        assert_eq([], $fetcher->calls, 'nothing downloads for an unknown family');
        $theme = $project->readJson('theme/theme.json');
        assert_true(
            !isset($theme['settings']['typography']['fontFamilies'][0]['fontFace']),
            'no fontFace is invented'
        );
        $warnings = $project->readJson('warnings.json')['bundle-fonts'];
        assert_contains('Totally Made Up Font', implode("\n", $warnings));
        assert_contains('link path', implode("\n", $warnings));

        // The link path actually engages: fonts.php still hotlinks the family.
        (new FontsPhpStep())->run($project);
        assert_contains('Totally+Made+Up+Font', $project->readText('theme/fonts.php'));
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('BundleFontsStep is all-or-nothing per family and independent across families', function () use ($bundleFontsProject) {
    $project = $bundleFontsProject(['inter', 'lora']);
    // Every Lora face fails; Inter downloads normally.
    $fetcher = new FakeFontFetcher(['/lora/']);

    try {
        (new BundleFontsStep($fetcher))->run($project);

        $theme = $project->readJson('theme/theme.json');
        $families = $theme['settings']['typography']['fontFamilies'];
        assert_true(($families[0]['fontFace'] ?? []) !== [], 'Inter bundled');
        assert_true(!isset($families[1]['fontFace']), 'Lora left whole, not half-bundled');
        assert_true(!$project->exists('theme/assets/fonts/lora-400.woff2'), 'no partial Lora files');
        $warnings = $project->readJson('warnings.json')['bundle-fonts'];
        assert_contains('Lora', implode("\n", $warnings));

        // fonts.php covers exactly the degraded family.
        (new FontsPhpStep())->run($project);
        $fonts = $project->readText('theme/fonts.php');
        assert_contains('Lora', $fonts);
        assert_true(!str_contains($fonts, 'Inter'), 'bundled families are not hotlinked');
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('FontsPhpStep skips fonts.php entirely when every family is bundled', function () use ($bundleFontsProject) {
    $project = $bundleFontsProject(['inter']);

    try {
        (new BundleFontsStep(new FakeFontFetcher()))->run($project);
        (new FontsPhpStep())->run($project);
        assert_true(!$project->exists('theme/fonts.php'), 'nothing left to enqueue');
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('BundleFontsStep degrades a family whose scan selects no faces', function () use ($bundleFontsProject) {
    $project = $bundleFontsProject(['inter']);
    $catalogPath = sys_get_temp_dir() . '/font-catalog-italic-only-' . uniqid() . '.json';
    file_put_contents($catalogPath, json_encode([
        'font_families' => [[
            'name'       => 'Inter',
            'slug'       => 'inter',
            'fontFamily' => 'Inter, sans-serif',
            'fontFace'   => [
                ['fontWeight' => '400', 'fontStyle' => 'italic', 'src' => 'https://fonts.gstatic.com/s/inter/400i.woff2'],
            ],
        ]],
    ]));
    $fetcher = new FakeFontFetcher();

    try {
        (new BundleFontsStep($fetcher, FontCatalog::load($catalogPath)))->run($project);

        assert_eq([], $fetcher->calls, 'nothing downloads when no face matches the scan');
        $theme = $project->readJson('theme/theme.json');
        assert_true(
            !isset($theme['settings']['typography']['fontFamilies'][0]['fontFace']),
            'no empty fontFace is written — that would read as bundled'
        );
        $warnings = $project->readJson('warnings.json')['bundle-fonts'];
        assert_contains('no faces for the scanned use', implode("\n", $warnings));

        // The family stays on the link path.
        (new FontsPhpStep())->run($project);
        assert_contains('Inter', $project->readText('theme/fonts.php'));
    } finally {
        unlink($catalogPath);
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('BundleFontsStep names assets from the catalog family, not the theme role slug', function () {
    // fontFamilies slugs are model-authored and identify theme roles. Even a
    // traversal-shaped role must never become part of an asset filename; the
    // resolved catalog family provides the canonical filesystem identity.
    $tmp = sys_get_temp_dir() . '/bundle-fonts-traversal-' . uniqid();
    $project = new Project($tmp);
    $project->writeJson('designDirection.json', []);
    $project->writeJson('theme/theme.json', [
        'settings' => ['typography' => ['fontFamilies' => [[
            'slug'       => '../../evil',
            'name'       => 'Inter',
            'fontFamily' => 'Inter, sans-serif',
        ]]]],
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:paragraph --><p><strong>Body copy</strong></p><!-- /wp:paragraph -->',
    );

    try {
        (new BundleFontsStep(new FakeFontFetcher()))->run($project);

        assert_true(
            !file_exists($project->path('theme/evil-400.woff2')),
            'nothing is written outside theme/assets/fonts/'
        );
        $faces = $project->readJson('theme/theme.json')['settings']['typography']['fontFamilies'][0]['fontFace'];
        foreach ($faces as $face) {
            assert_true(
                !str_contains($face['src'][0], '..'),
                'no declared src escapes the fonts directory: ' . $face['src'][0]
            );
        }
        assert_eq(['file:./assets/fonts/inter-400.woff2'], $faces[0]['src']);
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});

test('font steps run on their own without a design direction', function () use ($bundleFontsProject) {
    // Run on their own, outside the full graph, neither step finds a
    // designDirection.json — both threw "Missing file" before dataFor().
    $project = $bundleFontsProject(['inter']);
    unlink($project->path('designDirection.json'));

    try {
        (new BundleFontsStep(new FakeFontFetcher()))->run($project);
        (new FontsPhpStep())->run($project);

        $faces = $project->readJson('theme/theme.json')['settings']['typography']['fontFamilies'][0]['fontFace'];
        assert_true($faces !== [], 'the family still bundles from scanned usage alone');
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});
