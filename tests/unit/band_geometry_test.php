<?php
declare(strict_types=1);

use Automattic\SiteBuild\BandGeometry;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;

test('the rounded band kit insets contrast and band surfaces with the panel radius and spares the hero (frm W4c)', function () {
    assert_eq(['square', 'rounded'], BandGeometry::ALL);
    assert_eq(null, BandGeometry::kitCss('square'));
    assert_eq(null, BandGeometry::kitCss(null));
    $css = (string) BandGeometry::kitCss(' Rounded ');
    assert_contains('.has-contrast-background-color, .has-band-background-color', $css);
    assert_contains(':not([class*="hero-composition--"])', $css, 'the page opening keeps its edges');
    assert_contains(':not(.section-composition--full-bleed-cover)', $css, 'an image cover keeps its edges');
    assert_contains('margin-inline: var(--wp--preset--spacing--md, 1.5rem)', $css);
    assert_contains('border-radius: var(--shape-radius-panel, 1.5rem)', $css, 'the radius is the committed panel scale');
    assert_contains('overflow: hidden', $css);
    assert_contains('margin-inline: var(--wp--preset--spacing--sm, 0.75rem)', $css, 'phones keep a smaller gutter');
    assert_true(!str_contains($css, '!important'), 'the band kit fights nothing');
    assert_contains('inset from the viewport', BandGeometry::meaning('rounded'));
});

test('the direction normalizes, persists, formats and reads band_geometry (frm W4c)', function () {
    $warnings = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'x', 'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'), 'band_geometry' => 'ROUNDED'],
        'cinematic-safe-zone',
        '',
        warnings: $warnings,
    );
    assert_eq('rounded', $direction['band_geometry']);
    assert_eq([], $warnings);
    $rendered = DesignDirectionStep::format(['description' => 'x', 'band_geometry' => 'rounded']);
    assert_contains('**Band geometry**: rounded', $rendered);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'band_geometry' => 'square']), 'Band geometry'), 'square is the silent default');
    $warnings = [];
    $odd = DesignDirectionStep::normalize(
        ['description' => 'x', 'hero_blueprint' => \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone'), 'band_geometry' => 'pillowy'],
        'cinematic-safe-zone',
        '',
        warnings: $warnings,
    );
    assert_eq('square', $odd['band_geometry']);
    assert_eq(1, count(array_filter($warnings, fn (string $w): bool => str_contains($w, 'band_geometry'))));

    $tmp = sys_get_temp_dir() . '/builder_band_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Luzia');
    assert_eq('square', DesignDirectionStep::bandGeometryFor($project), 'no direction, square');
    $project->writeJson('designDirection.json', ['description' => 'x', 'band_geometry' => 'rounded']);
    assert_eq('rounded', DesignDirectionStep::bandGeometryFor($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme ships the band kit for rounded and prunes it for square (frm W4c)', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_band_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeJson('designDirection.json', ['description' => 'x', 'band_geometry' => 'rounded']);
    finalize_static_header($project);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_contains('.has-contrast-background-color, .has-band-background-color', $project->readText('theme/assets/band/band.css'));
    $php = $project->readText('theme/functions.php');
    assert_contains("wp_enqueue_style('forno-vero-band', get_theme_file_uri('assets/band/band.css'), array('forno-vero-style'), \$ver);", $php);

    $project->writeJson('designDirection.json', ['description' => 'x', 'band_geometry' => 'square']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/band/band.css'), 'stale band kit pruned');
    assert_true(!str_contains($project->readText('theme/functions.php'), 'forno-vero-band'), 'stale band enqueue pruned');
    exec('rm -rf ' . escapeshellarg($tmp));
});
