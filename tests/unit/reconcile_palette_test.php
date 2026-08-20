<?php
declare(strict_types=1);

use Automattic\SiteBuild\PaletteReconciliation;
use Automattic\SiteBuild\Steps\ReconcilePaletteStep;

/** theme.json shape carrying only the palette the reconciler reads. */
function palette_theme(array $slugToHex): array
{
    $palette = [];
    foreach ($slugToHex as $slug => $hex) {
        $palette[] = ['slug' => $slug, 'color' => $hex, 'name' => ucfirst((string) $slug)];
    }
    return ['version' => 3, 'settings' => ['color' => ['palette' => $palette]]];
}

/** designDirection.json whose prose and hero blueprint both quote the proposed colors. */
function palette_direction(array $palette): array
{
    return test_design_direction('cinematic-safe-zone', [
        'palette' => $palette,
        'description' => 'Terracotta ' . $palette['primary'] . ' carries the brand, '
            . 'supported by ' . $palette['secondary'] . ' bands on ' . $palette['base'] . '.',
        'motion_note' => 'Buttons settle into ' . $palette['primary'] . ' on hover.',
    ]);
}

function palette_pages(array $palette): array
{
    return ['pages' => [[
        'slug' => 'home',
        'title' => 'Home',
        'front' => true,
        'sections' => [[
            'slug' => 'hero',
            'title' => 'Hero',
            'content_notes' => 'A call-to-action button in ' . $palette['primary']
                . ' over a ' . $palette['base'] . ' field.',
            'vertical_density' => 'standard',
        ]],
    ]]];
}

test('drifted planning colors are resynced across the direction and the page plan', function () {
    with_project('builder_palette_', function ($project): void {
        $proposed = [
            'base' => '#1E1714',
            'contrast' => '#EDE0CC',
            'primary' => '#A6432A',
            'secondary' => '#6E6A45',
            'accent' => '#C79A3E',
        ];
        $project->writeJson('designDirection.json', palette_direction($proposed));
        $project->writeJson('pages.json', palette_pages($proposed));
        $project->writeJson('theme/theme.json', palette_theme([
            'base' => '#1E1714',
            'contrast' => '#EDE0CC',
            // theme-json authored its own primary/secondary — the exact drift
            // seen in the tbilisi build that produced BIGR-850.
            'primary' => '#C9542F',
            'secondary' => '#A7A277',
            'accent' => '#C79A3E',
        ]));

        quietly(fn () => (new ReconcilePaletteStep())->run($project));

        $direction = $project->readJson('designDirection.json');
        assert_eq('#C9542F', $direction['palette']['primary'], 'the palette map follows the theme');
        assert_eq('#A7A277', $direction['palette']['secondary']);
        assert_contains('Terracotta #C9542F carries the brand', $direction['description']);
        assert_contains('supported by #A7A277 bands', $direction['description']);
        assert_contains('settle into #C9542F on hover', $direction['motion_note']);
        assert_true(
            !str_contains(json_encode($direction, JSON_UNESCAPED_SLASHES), '#A6432A'),
            'no proposed hex survives anywhere in the direction',
        );

        $notes = $project->readJson('pages.json')['pages'][0]['sections'][0]['content_notes'];
        assert_contains('button in #C9542F', $notes, 'the concurrent page plan is resynced too');
        assert_contains('a #1E1714 field', $notes, 'an undrifted color is left exactly as authored');
        assert_true(!$project->exists('warnings.json'), 'a lossless deterministic resync is not a warning');
    });
});

test('reconciling twice changes nothing the second time', function () {
    with_project('builder_palette_fixed_point_', function ($project): void {
        $proposed = [
            'base' => '#FFFFFF',
            'contrast' => '#111111',
            'primary' => '#AA0000',
            'secondary' => '#00AA00',
            'accent' => '#0000AA',
        ];
        $project->writeJson('designDirection.json', palette_direction($proposed));
        $project->writeJson('pages.json', palette_pages($proposed));
        $project->writeJson('theme/theme.json', palette_theme([
            'base' => '#FFFFFF',
            'contrast' => '#111111',
            'primary' => '#BB1111',
            'secondary' => '#00AA00',
            'accent' => '#0000AA',
        ]));

        quietly(fn () => (new ReconcilePaletteStep())->run($project));
        $afterFirst = [
            $project->readJson('designDirection.json'),
            $project->readJson('pages.json'),
        ];
        quietly(fn () => (new ReconcilePaletteStep())->run($project));

        assert_eq($afterFirst, [
            $project->readJson('designDirection.json'),
            $project->readJson('pages.json'),
        ], 'the resync reaches a fixed point');
    });
});

test('a proposed color the theme moved to another slug is kept and warned about', function () {
    with_project('builder_palette_ambiguous_', function ($project): void {
        $proposed = [
            'base' => '#FFFFFF',
            'contrast' => '#111111',
            'primary' => '#C79A3E',
            'secondary' => '#445566',
            'accent' => '#778899',
        ];
        $project->writeJson('designDirection.json', palette_direction($proposed));
        $project->writeJson('pages.json', palette_pages($proposed));
        // primary drifted, but its proposed hex is now the accent: any prose
        // naming it still names a real theme color, with a different role.
        $project->writeJson('theme/theme.json', palette_theme([
            'base' => '#FFFFFF',
            'contrast' => '#111111',
            'primary' => '#D2691E',
            'secondary' => '#445566',
            'accent' => '#C79A3E',
        ]));

        quietly(fn () => (new ReconcilePaletteStep())->run($project));

        $direction = $project->readJson('designDirection.json');
        assert_eq('#C79A3E', $direction['palette']['primary'], 'an ambiguous color is not rewritten');
        assert_contains('Terracotta #C79A3E carries the brand', $direction['description']);

        $warnings = $project->readJson('warnings.json')['reconcile-palette'] ?? [];
        assert_eq(1, count($warnings));
        assert_contains("block=\"palette.primary\"", $warnings[0]);
        assert_contains('authored="#C79A3E"', $warnings[0]);
        assert_contains('delivered="#D2691E"', $warnings[0]);
        assert_contains('still uses it for another slug', $warnings[0]);
    });
});

test('a matching palette leaves both artifacts byte-for-byte alone', function () {
    with_project('builder_palette_noop_', function ($project): void {
        $palette = [
            'base' => '#FFFFFF',
            'contrast' => '#111111',
            'primary' => '#AA0000',
            'secondary' => '#00AA00',
            'accent' => '#0000AA',
        ];
        $project->writeJson('designDirection.json', palette_direction($palette));
        $project->writeJson('pages.json', palette_pages($palette));
        $project->writeJson('theme/theme.json', palette_theme($palette));
        $before = [
            $project->readText('designDirection.json'),
            $project->readText('pages.json'),
        ];

        quietly(fn () => (new ReconcilePaletteStep())->run($project));

        assert_eq($before, [
            $project->readText('designDirection.json'),
            $project->readText('pages.json'),
        ]);
        assert_true(!$project->exists('warnings.json'));
    });
});

test('rewriting is single-pass and respects color token boundaries', function () {
    // primary now holds the color secondary proposed. Rewriting primary is
    // still correct; secondary is skipped because its proposed hex is a real
    // delivered color. Chaining the two rules would land primary on #123456.
    $plan = PaletteReconciliation::plan(
        ['primary' => '#AABBCC', 'secondary' => '#DDEEFF'],
        ['primary' => '#DDEEFF', 'secondary' => '#123456'],
    );
    assert_eq(['#AABBCC' => '#DDEEFF'], $plan['substitutions']);
    assert_eq(['secondary'], $plan['ambiguous']);
    assert_eq(
        'primary #DDEEFF, secondary #DDEEFF',
        PaletteReconciliation::rewriteText('primary #AABBCC, secondary #DDEEFF', $plan['substitutions']),
        'one pass: a just-rewritten color is never rewritten again',
    );

    // Two slugs that proposed the same color and drifted apart have no single
    // answer, so the text naming that color is left alone.
    $collision = PaletteReconciliation::plan(
        ['primary' => '#AABBCC', 'secondary' => '#AABBCC'],
        ['primary' => '#111111', 'secondary' => '#222222'],
    );
    assert_eq([], $collision['substitutions']);
    assert_eq(['primary', 'secondary'], $collision['ambiguous']);

    $simple = ['#AABBCC' => '#001122'];
    assert_eq('#001122', PaletteReconciliation::rewriteText('#AABBCC', $simple));
    assert_eq('#001122', PaletteReconciliation::rewriteText('#aabbcc', $simple), 'matching is case-insensitive');
    assert_eq(
        '#AABBCCFF',
        PaletteReconciliation::rewriteText('#AABBCCFF', $simple),
        'an eight-digit color is a different token',
    );
    assert_eq(
        'gradient(#001122, #001122 40%)',
        PaletteReconciliation::rewriteText('gradient(#AABBCC, #aabbcc 40%)', $simple),
    );
});

test('the reconciler ignores malformed palette entries on both sides', function () {
    $theme = palette_theme(['primary' => '#AABBCC']);
    $theme['settings']['color']['palette'][] = ['slug' => 'broken', 'color' => 'rgb(1,2,3)'];
    $theme['settings']['color']['palette'][] = ['color' => '#123456'];
    $theme['settings']['color']['palette'][] = 'not-an-entry';

    assert_eq(['primary' => '#AABBCC'], PaletteReconciliation::themePalette($theme));
    assert_eq([], PaletteReconciliation::themePalette(['version' => 3]));
    assert_eq(
        ['primary' => '#A6432A'],
        PaletteReconciliation::directionPalette(['palette' => ['primary' => '#A6432A', 'secondary' => 'walnut']]),
    );
    assert_eq([], PaletteReconciliation::plan(['primary' => '#A6432A'], [])['substitutions']);
});

test('the reconcile step declares the artifacts it rewrites', function () {
    $declaration = (new ReconcilePaletteStep())->declaration();
    assert_eq('reconcile-palette', $declaration->id);
    assert_eq(['theme/theme.json', 'designDirection.json', 'pages.json'], $declaration->reads);
    assert_eq(['designDirection.json', 'pages.json', 'warnings.json'], $declaration->writes);
    assert_true(!$declaration->concurrent);
});
