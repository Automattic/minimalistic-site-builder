<?php
declare(strict_types=1);

use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\CssContrastAdjuster;
use Automattic\SiteBuild\CssContrastCheck;
use Automattic\SiteBuild\Project;

/** Return the single finding whose selector matches, or null. */
function var_finding(array $findings, string $selector): ?array
{
    foreach ($findings as $finding) {
        if ($finding['selector'] === $selector) {
            return $finding;
        }
    }
    return null;
}

test('css contrast resolves :root custom properties and detects a failing var() pair', function () {
    $css = ':root{--fg:#777777;--bg:#ffffff}.x{color:var(--fg);background:var(--bg)}';

    $findings = CssContrastCheck::check($css, '<p class="x">Token colors</p>');

    $finding = var_finding($findings, '.x');
    assert_true($finding !== null, 'the .x pair must be evaluated, not skipped');
    assert_eq('fail', $finding['status']);
    assert_eq('var(--fg)', $finding['fg']);
    assert_eq('var(--bg)', $finding['bg']);
    assert_true(is_float($finding['ratio']) && $finding['ratio'] < ContrastMath::NORMAL_TEXT);
    assert_true(is_string($finding['suggested']));
});

test('css contrast uses a var() fallback when the custom property is undefined', function () {
    $css = '.x{color:var(--fg, #777777);background:#ffffff}';

    $findings = CssContrastCheck::check($css, '<p class="x">Fallback color</p>');

    $finding = var_finding($findings, '.x');
    assert_true($finding !== null);
    assert_eq('fail', $finding['status'], 'fallback #777777 on white is a failing pair');
    assert_eq('var(--fg, #777777)', $finding['fg']);
});

test('css contrast resolves a transitive custom property chain', function () {
    $css = ':root{--a:var(--b);--b:#111111}.x{color:var(--a);background:#ffffff}';

    $findings = CssContrastCheck::check($css, '<p class="x">Chained token</p>');

    $finding = var_finding($findings, '.x');
    assert_true($finding !== null);
    assert_eq('pass', $finding['status'], '--a -> --b -> #111111 resolves to a passing color');
    assert_true(is_float($finding['ratio']) && $finding['ratio'] >= ContrastMath::NORMAL_TEXT);
});

test('css contrast skips an undefined var() with no fallback without error', function () {
    $css = '.x{color:var(--missing);background:#ffffff}';

    $findings = CssContrastCheck::check($css, '<p class="x">Undefined token</p>');

    $finding = var_finding($findings, '.x');
    assert_true($finding !== null);
    assert_eq('unverified', $finding['status']);
    assert_eq(null, $finding['fg']);
});

test('css contrast leaves a self-referential var() cycle unverified without hanging', function () {
    $css = ':root{--a:var(--b);--b:var(--a)}.x{color:var(--a);background:#ffffff}';

    $findings = CssContrastCheck::check($css, '<p class="x">Cyclic token</p>');

    $finding = var_finding($findings, '.x');
    assert_true($finding !== null);
    assert_eq('unverified', $finding['status']);
});

test('css contrast keeps a passing literal pair unchanged when unrelated tokens exist', function () {
    $css = ':root{--brand:#ff2e88}.x{color:#111111;background:#ffffff}';

    $findings = CssContrastCheck::check($css, '<p class="x">Literal pair</p>');

    $finding = var_finding($findings, '.x');
    assert_true($finding !== null);
    assert_eq('pass', $finding['status']);
    assert_eq('#111111', $finding['fg']);
    assert_eq('#ffffff', $finding['bg']);
});

test('css contrast adjuster repairs a var()-expressed failing pair end to end', function () {
    $css = ":root{--ink:#777777}\n.x{color:var(--ink);background:#ffffff}\n";
    $markup = '<p class="x">Token repair</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $finding = var_finding($findings, '.x');
    assert_true($finding !== null && $finding['status'] === 'fail');

    $root = sys_get_temp_dir() . '/css-contrast-var-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    // Only the var(--ink) reference at the point of use is rewritten.
    assert_eq(
        ":root{--ink:#777777}\n.x{color:{$finding['suggested']};background:#ffffff}\n",
        $adjusted,
    );
    $after = var_finding(CssContrastCheck::check($adjusted, $markup), '.x');
    assert_true($after !== null && $after['status'] === 'pass', 'repaired pair now passes');
});

test('css contrast judges a token pair against its own rule, not the page fill', function () {
    $css = ':root{--ink:#0C0C0E;--paper:#F2EBDC;--mustard:#E4A11B}'
        . 'body{background:var(--ink);color:var(--paper)}'
        . '.panel--mustard{background:var(--mustard);color:var(--ink)}';
    $markup = '<body><div class="panel panel--mustard"><p>Receba a semana</p></div></body>';

    $finding = var_finding(CssContrastCheck::check($css, $markup), '.panel--mustard');
    assert_true($finding !== null, 'the mustard panel must be evaluated');
    assert_eq('pass', $finding['status'], 'ink on mustard is the authored pair');
    assert_eq('var(--ink)', $finding['fg']);
    assert_eq('var(--mustard)', $finding['bg']);
    assert_true(is_float($finding['ratio']) && $finding['ratio'] >= ContrastMath::NORMAL_TEXT);
});
