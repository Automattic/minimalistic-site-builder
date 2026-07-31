<?php
declare(strict_types=1);

use Automattic\SiteBuild\FontFetcher;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\BundleFontsStep;
use Automattic\SiteBuild\Steps\FontsPhpStep;

/**
 * BundleFontsStep: fonts ship as theme assets declared in theme.json, and every
 * failure degrades one family to the fonts.php link path — never the build.
 */

final class BundleFontsRecordingFetcher implements FontFetcher
{
    /** @var string[] */
    public array $fetched = [];

    /** @param string[] $failing substrings of URLs that must throw */
    public function __construct(private array $failing = [])
    {
    }

    public function fetch(string $url): string
    {
        foreach ($this->failing as $needle) {
            if (str_contains($url, $needle)) {
                throw new \RuntimeException('simulated download failure');
            }
        }
        $this->fetched[] = $url;
        return 'FONTBYTES:' . basename((string) parse_url($url, PHP_URL_PATH));
    }
}

$bundleFontsProject = static function (array $familySlugs = ['inter']): Project {
    $known = [
        'inter'    => ['name' => 'Inter', 'stack' => 'Inter, sans-serif'],
        'lora'     => ['name' => 'Lora', 'stack' => 'Lora, serif'],
        'made-up'  => ['name' => 'Totally Made Up Font', 'stack' => '"Totally Made Up Font", serif'],
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
    $project = $bundleFontsProject(['inter']);
    $fetcher = new BundleFontsRecordingFetcher();

    try {
        (new BundleFontsStep($fetcher))->run($project);

        // The scan floor is 400+700 for the body family; only those download.
        assert_eq(2, count($fetcher->fetched));

        $theme = $project->readJson('theme/theme.json');
        $faces = $theme['settings']['typography']['fontFamilies'][0]['fontFace'];
        assert_eq(2, count($faces));
        assert_eq('Inter', $faces[0]['fontFamily']);
        assert_eq(['file:./assets/fonts/body-400.woff2'], $faces[0]['src']);
        assert_eq(['file:./assets/fonts/body-700.woff2'], $faces[1]['src']);
        foreach (['body-400.woff2', 'body-700.woff2'] as $file) {
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
    $fetcher = new BundleFontsRecordingFetcher();

    try {
        (new BundleFontsStep($fetcher))->run($project);

        assert_eq([], $fetcher->fetched, 'nothing downloads for an unknown family');
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
    $fetcher = new BundleFontsRecordingFetcher(['/lora/']);

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
        (new BundleFontsStep(new BundleFontsRecordingFetcher()))->run($project);
        (new FontsPhpStep())->run($project);
        assert_true(!$project->exists('theme/fonts.php'), 'nothing left to enqueue');
    } finally {
        exec('rm -rf ' . escapeshellarg($project->root));
    }
});
