<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\CssContrastAdjuster;
use Automattic\SiteBuild\CssContrastCheck;
use Automattic\SiteBuild\Project;

test('css contrast check returns an exact pass row for a resolved same-element class', function () {
    $css = '.copy { color: #111111; background-color: #ffffff; }';

    $findings = CssContrastCheck::check($css, '<p class="copy">Readable</p>');

    assert_eq([[
        'selector' => '.copy',
        'status' => 'pass',
        'fg' => '#111111',
        'bg' => '#ffffff',
        'ratio' => ContrastMath::ratio([17, 17, 17], [255, 255, 255]),
        'suggested' => null,
    ]], $findings);
});

test('css contrast check suggests the smallest passing black-or-white nudge for a failing direct child', function () {
    $css = '.panel > .copy { color: #777777; background: #ffffff; }';

    $findings = CssContrastCheck::check(
        $css,
        '<section class="panel"><p class="copy">Low contrast</p></section>',
    );

    assert_eq(1, count($findings));
    assert_eq('.panel > .copy', $findings[0]['selector']);
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#777777', $findings[0]['fg']);
    assert_eq('#ffffff', $findings[0]['bg']);
    assert_true(is_float($findings[0]['ratio']) && $findings[0]['ratio'] < ContrastMath::NORMAL_TEXT);
    assert_true(is_string($findings[0]['suggested']));
    $suggested = ContrastMath::hexToRgb($findings[0]['suggested']);
    assert_true($suggested !== null);
    assert_true(ContrastMath::ratio($suggested, [255, 255, 255]) >= ContrastMath::NORMAL_TEXT);

    $oneStepCloser = array_map(static fn (int $channel): int => min(255, $channel + 1), $suggested);
    assert_true(
        ContrastMath::ratio($oneStepCloser, [255, 255, 255]) < ContrastMath::NORMAL_TEXT,
        'one RGB step back toward the authored foreground must fail',
    );
});

test('css contrast adjuster rewrites only the failing color value and records actionable warning', function () {
    $css = ".panel > .copy {\n  color: #777777;\n  background: #ffffff;\n  padding: 1rem;\n}\n";
    $markup = '<section class="panel"><p class="copy">Low contrast</p></section>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-adjuster-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $findings);

    assert_eq(
        str_replace('#777777', $findings[0]['suggested'], $css),
        $adjusted,
        'only the authored text color value changes',
    );
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('file=theme/style.css', $warnings[0]);
    assert_contains('selector=.panel > .copy', $warnings[0]);
    assert_contains('authored=#777777', $warnings[0]);
    assert_contains('delivered=' . $findings[0]['suggested'], $warnings[0]);
    assert_contains('disposition=adjusted', $warnings[0]);
});

test('complex inherited contrast stays unverified and adjustment preserves CSS bytes', function () {
    $css = ".panel { background: #ffffff; }\n.panel .copy { color: #777777; }\n";
    $markup = '<section class="panel"><p class="copy">Inherited background</p></section>';

    $findings = CssContrastCheck::check($css, $markup);

    assert_eq([
        'selector' => '.panel',
        'status' => 'unverified',
        'fg' => null,
        'bg' => null,
        'ratio' => null,
        'suggested' => null,
    ], $findings[0]);
    assert_eq([
        'selector' => '.panel .copy',
        'status' => 'unverified',
        'fg' => null,
        'bg' => null,
        'ratio' => null,
        'suggested' => null,
    ], $findings[1]);

    $root = sys_get_temp_dir() . '/css-contrast-unverified-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $findings);

    assert_eq($css, $adjusted, 'unverified CSS stays byte-identical');
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(2, count($warnings));
    foreach ($warnings as $warning) {
        assert_contains('file=theme/style.css', $warning);
        assert_contains('authored=unresolved', $warning);
        assert_contains('delivered=unchanged', $warning);
        assert_contains('disposition=unverified', $warning);
    }
});
