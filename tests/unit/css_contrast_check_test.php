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

    $root = sys_get_temp_dir() . '/css-contrast-pass-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, '<p class="copy">Readable</p>', $findings));
    assert_true(!$project->exists('warnings.json'), 'passing CSS stays untouched and unwarned');
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

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

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
    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

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

test('malformed CSS degrades to an unverified row without throwing', function () {
    $findings = CssContrastCheck::check(
        '.copy { color: #777777; background: #ffffff;',
        '<p class="copy">Truncated rule</p>',
    );

    assert_eq([[
        'selector' => '.copy',
        'status' => 'unverified',
        'fg' => null,
        'bg' => null,
        'ratio' => null,
        'suggested' => null,
    ]], $findings);
});

test('translucent failing text still receives a passing delivered suggestion', function () {
    $findings = CssContrastCheck::check(
        '.copy { color: rgba(0, 0, 0, 0.1); background: #ffffff; }',
        '<p class="copy">Faint text</p>',
    );

    assert_eq('fail', $findings[0]['status']);
    assert_true(is_string($findings[0]['suggested']));
    $suggested = ContrastMath::hexToRgb($findings[0]['suggested']);
    assert_true($suggested !== null, 'fallback suggestion becomes an opaque passing color');
    assert_true(ContrastMath::ratio($suggested, [255, 255, 255]) >= ContrastMath::NORMAL_TEXT);
});

test('css contrast resolves the rendered cascade across supported matching selectors', function () {
    $css = <<<'CSS'
.copy { color: #000000; background: #ffffff; }
.panel > .copy { color: #777777; }
CSS;
    $markup = '<section class="panel"><p class="copy">Overridden text</p></section>';

    $findings = CssContrastCheck::check($css, $markup);

    assert_eq(1, count($findings));
    assert_eq('.panel > .copy', $findings[0]['selector']);
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#777777', $findings[0]['fg']);
    assert_eq('#ffffff', $findings[0]['bg']);
});

test('css contrast cascade honors importance then specificity and source order', function () {
    $markup = '<p class="copy">Cascade text</p>';

    $earlierImportant = CssContrastCheck::check(
        '.copy { color: #777777 !important; background: #ffffff; } .copy { color: #000000; }',
        $markup,
    );
    assert_eq(1, count($earlierImportant));
    assert_eq('fail', $earlierImportant[0]['status']);
    assert_eq('#777777', $earlierImportant[0]['fg']);

    $laterImportant = CssContrastCheck::check(
        '.copy { color: #777777; background: #ffffff; } .copy { color: #000000 !important; }',
        $markup,
    );
    assert_eq(1, count($laterImportant));
    assert_eq('pass', $laterImportant[0]['status']);
    assert_eq('#000000', $laterImportant[0]['fg']);

    $laterSource = CssContrastCheck::check(
        '.copy { color: #000000; background: #ffffff; } .copy { color: #777777; }',
        $markup,
    );
    assert_eq(1, count($laterSource));
    assert_eq('fail', $laterSource[0]['status']);
    assert_eq('#777777', $laterSource[0]['fg']);
});

test('css contrast ignores an invalid winning declaration and uses the valid fallback', function () {
    $findings = CssContrastCheck::check(
        '.copy { color: #777777; color: not-a-color; background: #ffffff; }',
        '<p class="copy">Fallback text</p>',
    );

    assert_eq(1, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#777777', $findings[0]['fg']);
    assert_eq('#ffffff', $findings[0]['bg']);
});

test('css contrast adjuster repairs only the declaration that wins the cascade', function () {
    $css = ".copy { color: #000000; background: #ffffff; }\n.copy { color: #777777; }\n";
    $markup = '<p class="copy">Duplicate selector</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-target-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_eq(
        ".copy { color: #000000; background: #ffffff; }\n.copy { color: {$findings[0]['suggested']}; }\n",
        $adjusted,
    );
    $after = CssContrastCheck::check($adjusted, $markup);
    assert_eq(1, count($after));
    assert_eq('pass', $after[0]['status']);
    assert_eq($findings[0]['suggested'], $after[0]['fg']);
});

test('css contrast adjuster leaves a shared declaration untouched when rendered contexts need different repairs', function () {
    $css = <<<'CSS'
.copy { color: #777777; }
.light > .copy { background: #ffffff; }
.mid > .copy { background: #888888; }
CSS;
    $markup = '<div class="light"><p class="copy">Light</p></div>'
        . '<div class="mid"><p class="copy">Mid</p></div>';
    $findings = CssContrastCheck::check($css, $markup);
    assert_eq(2, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('fail', $findings[1]['status']);
    assert_true($findings[0]['suggested'] !== $findings[1]['suggested']);
    $root = sys_get_temp_dir() . '/css-contrast-ambiguous-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_eq($css, $adjusted);
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(2, count($warnings));
    foreach ($warnings as $warning) {
        assert_contains('delivered=unchanged', $warning);
        assert_contains('reason=text-color-declaration-target-ambiguous', $warning);
    }
});

test('css contrast warnings scrub invalid UTF-8 before durable JSON writes', function () {
    $css = ".bad\xFF { color: #777777; background: #ffffff; }";
    $markup = '<p class="bad">Bad selector bytes</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-utf8-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings));
    assert_true(is_array(json_decode($project->readText('warnings.json'), true, flags: JSON_THROW_ON_ERROR)));

    $project->addWarnings('later-step', ['later warning']);
    assert_eq(['later warning'], $project->readJson('warnings.json')['later-step'] ?? []);
});

test('css contrast check preserves the caller libxml error queue exactly', function () {
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    try {
        $document = new DOMDocument();
        $document->loadXML('<root><broken></root>');
        $before = array_map(
            static fn (LibXMLError $error): array => [
                $error->level,
                $error->code,
                $error->line,
                $error->column,
                $error->message,
            ],
            libxml_get_errors(),
        );
        assert_true($before !== [], 'control must queue a libxml error');

        CssContrastCheck::check(
            '.copy { color: #111111; background: #ffffff; }',
            '<p class="copy">Queue purity</p>',
        );

        $after = array_map(
            static fn (LibXMLError $error): array => [
                $error->level,
                $error->code,
                $error->line,
                $error->column,
                $error->message,
            ],
            libxml_get_errors(),
        );
        assert_eq($before, $after);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
});
