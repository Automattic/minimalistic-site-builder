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
    assert_contains('border-radius: 1.5rem', $css, 'the radius is the committed panel scale');
    assert_contains('overflow: clip', $css);
    assert_contains(':not(.page-opening--section)', $css);

    // The gutter is for a full-bleed band alone. WordPress gives every other
    // constrained child `margin-left/right: auto !important`, so a margin on a
    // wide band is discarded and the rule would promise an inset it never
    // delivers. Every declaration that carries the gutter is `.alignfull`.
    foreach (['margin-inline: var(--wp--preset--spacing--md, 1.5rem)', 'margin-inline: var(--wp--preset--spacing--sm, 0.75rem)'] as $gutter) {
        $rule = substr($css, 0, (int) strpos($css, $gutter));
        $selector = substr($rule, (int) strrpos($rule, '}') + 1);
        assert_contains('.alignfull', $selector, 'the gutter is scoped to a full-bleed band');
    }
    // The radius and the clip are not: a wide band is already inset by the
    // wide measure, and it still has to read as a plate.
    $radiusRule = substr($css, 0, (int) strpos($css, 'border-radius'));
    assert_true(
        !str_contains(substr($radiusRule, (int) strrpos($radiusRule, '*/') + 2), '.alignfull'),
        'a wide band still takes the radius',
    );
    assert_true(!str_contains($css, 'overflow: hidden'));
    assert_contains('margin-inline: var(--wp--preset--spacing--sm, 0.75rem)', $css, 'phones keep a smaller gutter');
    assert_true(!str_contains($css, '!important'), 'the band kit fights nothing');
    assert_contains('inset from the viewport', BandGeometry::meaning('rounded'));
    assert_contains('wide band keeps the wide measure', BandGeometry::meaning('rounded'), 'the promise matches the CSS');
});

test('the band radius answers to the committed corner language (frm W4c)', function () {
    // A `sharp` direction sets every other corner on the page to zero. A 24px
    // plate in the middle of that reads as a rendering accident, not a choice.
    assert_contains('border-radius: 0.5rem', (string) BandGeometry::kitCss('rounded', 'sharp'));
    assert_contains('border-radius: 1.5rem', (string) BandGeometry::kitCss('rounded', 'soft'));
    assert_contains('border-radius: 2.5rem', (string) BandGeometry::kitCss('rounded', 'round'));
    assert_contains('border-radius: 1.5rem', (string) BandGeometry::kitCss('rounded', null), 'an unknown shape takes the soft scale');
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
    $project->writeJson('headerBehavior.json', [
        'behavior' => 'static', 'mode' => 'stacked', 'transition' => 'instant',
        'topSurface' => 'base', 'scrolledSurface' => 'base', 'foreground' => 'contrast',
        'topTreatment' => 'solid', 'scrolledTreatment' => 'solid',
    ]);
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

test('band geometry reads explicit brief phrases and preserves other fields', function () {
    foreach (['Rounded panels', 'rounded near-black panels', 'a dark rounded band'] as $brief) {
        $repairs = [];
        $result = DesignDirectionStep::withStatedBandGeometry(['band_geometry' => 'square', 'canvas' => 'full-bleed'], ['prompt' => $brief], $repairs);
        assert_eq('rounded', $result['band_geometry']);
        assert_eq('full-bleed', $result['canvas']);
        assert_eq(1, count($repairs));
        $repairs = [];
        assert_eq($result, DesignDirectionStep::withStatedBandGeometry($result, ['prompt' => $brief], $repairs));
        assert_eq([], $repairs);
    }
    assert_eq(null, DesignDirectionStep::statedBandGeometry('A hero in a rounded frame'));
    foreach (['sharp' => '0.5rem', 'soft' => '1.5rem', 'round' => '2.5rem'] as $shape => $radius) {
        assert_contains('border-radius: ' . $radius, BandGeometry::kitCss('rounded', $shape));
    }
});

test('a subpage opening has the band exclusion in both block representations', function () {
    $unit = new \Automattic\SiteBuild\Units\SectionUnit(new \Automattic\SiteBuild\Tests\FakeLlm(), new \Automattic\SiteBuild\PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">About</h1><!-- /wp:heading --></div><!-- /wp:group -->';
    $input = ['page' => ['slug' => 'about'], 'section' => ['slug' => 'intro'], 'is_opening' => true];
    $result = $unit->finish($raw, $input);
    $document = \Automattic\SiteBuild\BlockMarkup::parse($result->markup);
    assert_eq('page-opening--section', $document->attrs($document->topLevel())['className']);
    assert_contains('class="wp-block-group page-opening--section"', $result->markup);
    assert_eq($result->markup, $unit->finish($result->markup, $input)->markup);
});
