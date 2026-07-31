<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\NativeStagedFileWriter;
use Automattic\SiteBuild\BlockSerializer\StagedFileWriter;
use Automattic\SiteBuild\Narrator;

require_once dirname(__DIR__) . '/autoload.php';

// A real Google Fonts release cannot plausibly shrink below these floors. They
// keep a truncated download from replacing the known 1,949-family artifact.
const DISTILL_GOOGLE_FONTS_MIN_FAMILIES = 1000;
const DISTILL_GOOGLE_FONTS_MIN_FACES = 1000;

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

/** Require one collection field to be a non-empty string. */
function distill_google_fonts_string(mixed $value, string $path): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Collection field {$path} must be a non-empty string");
    }
    return $value;
}

/**
 * Validate and transform the downloaded collection without touching disk.
 *
 * @return array{catalogJson:string,manifestJson:string,families:int,faces:int}
 */
function distill_google_fonts_catalog(
    string $raw,
    string $release,
    int $minimumFamilies = DISTILL_GOOGLE_FONTS_MIN_FAMILIES,
    int $minimumFaces = DISTILL_GOOGLE_FONTS_MIN_FACES,
): array {
    if (trim($release) === '') {
        throw new RuntimeException('Release tag must be a non-empty string');
    }
    if ($minimumFamilies < 1 || $minimumFaces < 1) {
        throw new InvalidArgumentException('Catalog viability floors must be positive');
    }

    try {
        $source = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Collection is not valid JSON: ' . $error->getMessage(), 0, $error);
    }
    if (
        !is_array($source)
        || !isset($source['font_families'])
        || !is_array($source['font_families'])
        || !array_is_list($source['font_families'])
        || $source['font_families'] === []
    ) {
        throw new RuntimeException('Unexpected collection shape: font_families must be a non-empty list');
    }

    $families = [];
    $faceCount = 0;
    foreach ($source['font_families'] as $familyIndex => $entry) {
        $familyPath = "font_families[{$familyIndex}]";
        $settingsPath = "{$familyPath}.font_family_settings";
        if (!is_array($entry) || !is_array($entry['font_family_settings'] ?? null)) {
            throw new RuntimeException("Collection field {$settingsPath} must be an object");
        }
        $settings = $entry['font_family_settings'];
        $name = distill_google_fonts_string($settings['name'] ?? null, "{$settingsPath}.name");
        $slug = distill_google_fonts_string($settings['slug'] ?? null, "{$settingsPath}.slug");
        $fontFamily = distill_google_fonts_string(
            $settings['fontFamily'] ?? null,
            "{$settingsPath}.fontFamily"
        );

        if (
            !array_key_exists('fontFace', $settings)
            || !is_array($settings['fontFace'])
            || !array_is_list($settings['fontFace'])
        ) {
            throw new RuntimeException("Collection field {$settingsPath}.fontFace must be a list");
        }
        $sourceFaces = $settings['fontFace'];

        $faces = [];
        foreach ($sourceFaces as $faceIndex => $face) {
            $facePath = "{$settingsPath}.fontFace[{$faceIndex}]";
            if (!is_array($face)) {
                throw new RuntimeException("Collection field {$facePath} must be an object");
            }
            $src = distill_google_fonts_string($face['src'] ?? null, "{$facePath}.src");
            $fontWeight = distill_google_fonts_string(
                $face['fontWeight'] ?? null,
                "{$facePath}.fontWeight"
            );
            $fontStyle = distill_google_fonts_string(
                $face['fontStyle'] ?? null,
                "{$facePath}.fontStyle"
            );

            $url = parse_url($src);
            if (
                !is_array($url)
                || ($url['scheme'] ?? null) !== 'https'
                || ($url['host'] ?? null) !== 'fonts.gstatic.com'
            ) {
                throw new RuntimeException(
                    "Refusing face src outside https://fonts.gstatic.com for {$name}: {$src}"
                );
            }
            if (preg_match('/^[1-9]00$/D', $fontWeight) !== 1) {
                throw new RuntimeException("Collection field {$facePath}.fontWeight is unsupported: {$fontWeight}");
            }
            if (!in_array($fontStyle, ['normal', 'italic'], true)) {
                throw new RuntimeException("Collection field {$facePath}.fontStyle is unsupported: {$fontStyle}");
            }

            $faces[] = [
                'fontWeight' => $fontWeight,
                'fontStyle'  => $fontStyle,
                'src'        => $src,
            ];
            ++$faceCount;
        }

        // A family with no downloadable faces cannot be bundled; keep it out
        // so a resolver hit always means faces exist.
        if ($faces === []) {
            continue;
        }
        $families[] = [
            'name'       => $name,
            'slug'       => $slug,
            'fontFamily' => $fontFamily,
            'fontFace'   => $faces,
        ];
    }

    if (count($families) < $minimumFamilies || $faceCount < $minimumFaces) {
        throw new RuntimeException(
            'Collection is implausibly small: produced ' . count($families) . " families/{$faceCount} faces; "
            . "minimum is {$minimumFamilies} families/{$minimumFaces} faces"
        );
    }

    $catalogJson = json_encode(
        ['font_families' => $families],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    $scriptHash = hash_file('sha256', __FILE__);
    if ($scriptHash === false) {
        throw new RuntimeException('Cannot hash bin/distill-google-fonts-catalog.php');
    }
    $manifest = [
        'source'  => [
            'repository' => 'WordPress/google-fonts-to-wordpress-collection',
            'release'    => $release,
            'path'       => 'collections/google-fonts.json',
            'sha256'     => hash('sha256', $raw),
        ],
        'distiller' => [
            'script' => 'bin/distill-google-fonts-catalog.php',
            'sha256' => $scriptHash,
        ],
        'catalog' => [
            'sha256'   => hash('sha256', $catalogJson),
            'families' => count($families),
            'faces'    => $faceCount,
        ],
    ];
    $manifestJson = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";

    return [
        'catalogJson' => $catalogJson,
        'manifestJson' => $manifestJson,
        'families' => count($families),
        'faces' => $faceCount,
    ];
}

/**
 * Stage both artifacts before replacing either, and restore prior targets if
 * a later atomic replacement fails.
 *
 * @param array{catalogJson:string,manifestJson:string,families:int,faces:int} $distilled
 */
function distill_google_fonts_write(
    string $root,
    array $distilled,
    ?StagedFileWriter $writer = null,
): void {
    $directory = rtrim($root, '/\\') . '/data/google-fonts';
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create data/google-fonts');
    }

    $artifacts = [
        ['target' => $directory . '/catalog.json', 'content' => $distilled['catalogJson']],
        ['target' => $directory . '/catalog-manifest.json', 'content' => $distilled['manifestJson']],
    ];
    foreach ($artifacts as $artifact) {
        if (file_exists($artifact['target']) && !is_file($artifact['target'])) {
            throw new RuntimeException("Output target is not a regular file: {$artifact['target']}");
        }
    }

    $writer ??= new NativeStagedFileWriter();
    $staged = [];
    $backups = [];
    try {
        foreach ($artifacts as $index => $artifact) {
            $original = null;
            if (is_file($artifact['target'])) {
                $original = @file_get_contents($artifact['target']);
                if ($original === false) {
                    throw new RuntimeException("Cannot read existing output: {$artifact['target']}");
                }
            }
            $artifacts[$index]['original'] = $original;
            $staged[$index] = $writer->stage($artifact['target'], $artifact['content']);
        }
        foreach ($artifacts as $index => $artifact) {
            if ($artifact['original'] !== null) {
                $backups[$index] = $writer->stage($artifact['target'], $artifact['original']);
            }
        }
    } catch (Throwable $error) {
        foreach (array_merge($staged, $backups) as $temporary) {
            $writer->discard($temporary);
        }
        throw new RuntimeException('Could not stage font catalog artifacts: ' . $error->getMessage(), 0, $error);
    }

    $committed = [];
    try {
        foreach ($artifacts as $index => $artifact) {
            $writer->replace($staged[$index], $artifact['target']);
            $committed[] = $index;
            unset($staged[$index]);
        }
    } catch (Throwable $error) {
        foreach ($staged as $temporary) {
            $writer->discard($temporary);
        }

        $rollbackErrors = [];
        $preservedBackups = [];
        foreach (array_reverse($committed) as $index) {
            try {
                if (isset($backups[$index])) {
                    $writer->replace($backups[$index], $artifacts[$index]['target']);
                    unset($backups[$index]);
                } elseif (is_file($artifacts[$index]['target']) && !@unlink($artifacts[$index]['target'])) {
                    throw new RuntimeException("Cannot remove new output {$artifacts[$index]['target']}");
                }
            } catch (Throwable $rollbackError) {
                $message = $rollbackError->getMessage();
                if (isset($backups[$index])) {
                    $preservedBackups[$index] = $backups[$index];
                    unset($backups[$index]);
                    $message .= "; prior bytes remain staged at {$preservedBackups[$index]}";
                }
                $rollbackErrors[] = $message;
            }
        }
        foreach ($backups as $temporary) {
            $writer->discard($temporary);
        }

        $message = 'Could not commit font catalog artifacts: ' . $error->getMessage();
        if ($rollbackErrors !== []) {
            $message .= '; rollback failed: ' . implode('; ', $rollbackErrors);
        }
        throw new RuntimeException($message, 0, $error);
    }

    foreach ($backups as $temporary) {
        $writer->discard($temporary);
    }
}

/** Run the CLI command; injectable root/writer keep its failure paths testable. */
function distill_google_fonts_catalog_main(
    array $argv,
    ?string $root = null,
    ?StagedFileWriter $writer = null,
    int $minimumFamilies = DISTILL_GOOGLE_FONTS_MIN_FAMILIES,
    int $minimumFaces = DISTILL_GOOGLE_FONTS_MIN_FACES,
): int {
    if (count($argv) < 3) {
        Narrator::write(
            "Usage: php bin/distill-google-fonts-catalog.php <google-fonts.json> <release-tag>\n"
        );
        return 2;
    }

    try {
        $sourcePath = distill_google_fonts_string($argv[1] ?? null, 'source path');
        $release = distill_google_fonts_string($argv[2] ?? null, 'release tag');
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException("Cannot read local collection file: {$sourcePath}");
        }
        $raw = @file_get_contents($sourcePath);
        if ($raw === false) {
            throw new RuntimeException("Cannot read local collection file: {$sourcePath}");
        }

        $distilled = distill_google_fonts_catalog($raw, $release, $minimumFamilies, $minimumFaces);
        distill_google_fonts_write($root ?? dirname(__DIR__), $distilled, $writer);
        echo '[distill-google-fonts] ' . $distilled['families']
            . " family/families, {$distilled['faces']} faces → data/google-fonts/catalog.json\n";
        return 0;
    } catch (Throwable $error) {
        Narrator::write('[distill-google-fonts] ' . $error->getMessage() . "\n");
        return 1;
    }
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    exit(distill_google_fonts_catalog_main($argv));
}
