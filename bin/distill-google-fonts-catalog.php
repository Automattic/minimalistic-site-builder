<?php
declare(strict_types=1);

/**
 * Distill the WordPress google-fonts-to-wordpress-collection release JSON into
 * the vendored catalog the pipeline resolves font families against.
 *
 * Deliberately offline: it takes a path to an already-downloaded collection
 * file, so regenerating the catalog is reviewable (the download and the
 * transform are separate, and the manifest records the source hash).
 *
 * Usage:
 *   curl -sL -o /tmp/google-fonts.json \
 *     https://raw.githubusercontent.com/WordPress/google-fonts-to-wordpress-collection/refs/heads/trunk/releases/wp-7.1/collections/google-fonts.json
 *   php bin/distill-google-fonts-catalog.php /tmp/google-fonts.json wp-7.1
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/distill-google-fonts-catalog.php <google-fonts.json> <release-tag>\n");
    exit(2);
}

[, $sourcePath, $release] = $argv;
$root = dirname(__DIR__);

$raw = file_get_contents($sourcePath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$sourcePath}\n");
    exit(1);
}
$source = json_decode($raw, true);
if (!is_array($source) || !isset($source['font_families']) || !is_array($source['font_families'])) {
    fwrite(STDERR, "Unexpected collection shape: no font_families array\n");
    exit(1);
}

$families = [];
$faceCount = 0;
foreach ($source['font_families'] as $entry) {
    $settings = $entry['font_family_settings'] ?? null;
    if (!is_array($settings) || !isset($settings['name'], $settings['slug'], $settings['fontFamily'])) {
        fwrite(STDERR, 'Skipping malformed entry: ' . json_encode($entry) . "\n");
        continue;
    }
    $faces = [];
    foreach ((array) ($settings['fontFace'] ?? []) as $face) {
        if (!is_array($face) || !isset($face['src'], $face['fontWeight'], $face['fontStyle'])) {
            continue;
        }
        $host = parse_url((string) $face['src'], PHP_URL_HOST);
        if ($host !== 'fonts.gstatic.com') {
            fwrite(STDERR, "Refusing non-gstatic face src for {$settings['name']}: {$face['src']}\n");
            exit(1);
        }
        $faces[] = [
            'fontWeight' => (string) $face['fontWeight'],
            'fontStyle'  => (string) $face['fontStyle'],
            'src'        => (string) $face['src'],
        ];
        ++$faceCount;
    }
    // A family with no downloadable faces cannot be bundled; keep it out so a
    // resolver hit always means faces exist.
    if ($faces === []) {
        continue;
    }
    $families[] = [
        'name'       => (string) $settings['name'],
        'slug'       => (string) $settings['slug'],
        'fontFamily' => (string) $settings['fontFamily'],
        'fontFace'   => $faces,
    ];
}

$catalogPath = $root . '/data/google-fonts/catalog.json';
$manifestPath = $root . '/data/google-fonts/catalog-manifest.json';
if (!is_dir(dirname($catalogPath)) && !mkdir(dirname($catalogPath), 0777, true)) {
    fwrite(STDERR, "Cannot create data/google-fonts\n");
    exit(1);
}

$catalogJson = json_encode(['font_families' => $families], JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($catalogPath, $catalogJson);

$manifest = [
    'source'  => [
        'repository' => 'WordPress/google-fonts-to-wordpress-collection',
        'release'    => $release,
        'path'       => 'collections/google-fonts.json',
        'sha256'     => hash('sha256', $raw),
    ],
    'distiller' => [
        'script' => 'bin/distill-google-fonts-catalog.php',
        'sha256' => hash_file('sha256', __FILE__),
    ],
    'catalog' => [
        'sha256'   => hash('sha256', $catalogJson),
        'families' => count($families),
        'faces'    => $faceCount,
    ],
];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo '[distill-google-fonts] ' . count($families) . " family/families, {$faceCount} faces → data/google-fonts/catalog.json\n";
