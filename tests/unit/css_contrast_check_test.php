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

test('css contrast adjuster preserves sunny ember white text and moves its orange background', function () {
    $css = '.eyebrow { color: #fff; background: #F26522; }';
    $markup = '<p class="eyebrow">Confidence coaching</p>';
    $findings = CssContrastCheck::check($css, $markup);
    assert_eq(1, count($findings));
    assert_eq('fail', $findings[0]['status'], 'control pair must begin below the threshold');
    assert_eq('3.1531', number_format($findings[0]['ratio'] ?? 0.0, 4, '.', ''));

    $root = sys_get_temp_dir() . '/css-contrast-background-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_true($adjusted !== $css, 'repair must be non-vacuous');
    assert_contains('color: #fff', $adjusted, 'authored text colour must survive unchanged');
    assert_true(!str_contains($adjusted, 'background: #F26522'), 'background must move');
    $delivered = CssContrastCheck::check($adjusted, $markup);
    assert_eq(1, count($delivered));
    assert_eq('pass', $delivered[0]['status']);
    assert_eq('#fff', $delivered[0]['fg']);
    assert_true(($delivered[0]['ratio'] ?? 0.0) >= ContrastMath::NORMAL_TEXT);
});

test('css contrast adjuster repairs every duplicate selector occurrence identically', function () {
    $rule = '.copy { color: #fff; background: #F26522; }';
    $css = $rule . "\n" . $rule . "\n";
    $markup = '<p class="copy">Repeated selector</p>';
    $findings = CssContrastCheck::check($css, $markup);
    assert_eq(1, count($findings), 'identical failing findings are intentionally deduplicated');
    assert_eq('fail', $findings[0]['status']);
    $root = sys_get_temp_dir() . '/css-contrast-duplicate-selector-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_eq(2, substr_count($adjusted, 'color: #fff'), 'both authored foregrounds survive');
    assert_eq(0, substr_count($adjusted, 'background: #F26522'), 'both failing backgrounds move');
    preg_match_all('/\.copy \{ color: #fff; background: (#[0-9A-F]{6}); \}/', $adjusted, $rules);
    assert_eq(2, count($rules[1] ?? []), 'both selector copies remain present');
    assert_eq($rules[1][0], $rules[1][1], 'both selector copies receive the same background');
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(1, count($warnings), 'one selector emits one background disposition');
    assert_contains('disposition=adjusted', $warnings[0]);
    assert_true(!str_contains($warnings[0], 'authored=#fff delivered='));
});

test('css contrast adjuster rewrites only the failing background and records actionable warning', function () {
    $css = ".panel > .copy {\n  color: #fff;\n  background: #F26522;\n  padding: 1rem;\n}\n";
    $markup = '<section class="panel"><p class="copy">Low contrast</p></section>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-adjuster-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    $delivered = CssContrastCheck::check($adjusted, $markup);
    assert_eq('pass', $delivered[0]['status']);
    assert_eq(str_replace('#F26522', $delivered[0]['bg'], $css), $adjusted);
    assert_contains('color: #fff', $adjusted, 'authored foreground stays byte-identical');
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('file=theme/style.css', $warnings[0]);
    assert_contains('selector=.panel > .copy', $warnings[0]);
    assert_contains('authored_fg=#fff', $warnings[0]);
    assert_contains('authored_bg=#F26522', $warnings[0]);
    assert_contains('delivered_bg=' . $delivered[0]['bg'], $warnings[0]);
    assert_contains('ratio_before=3.1531', $warnings[0]);
    assert_contains('ratio_after=', $warnings[0]);
    assert_contains('disposition=adjusted', $warnings[0]);
    assert_contains('reason=background-moved-within-perceptual-cap', $warnings[0]);
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
        assert_contains('authored_fg=', $warning);
        assert_contains('authored_bg=', $warning);
        assert_contains('delivered_bg=', $warning);
        assert_contains('ratio_before=unresolved', $warning);
        assert_contains('ratio_after=unresolved', $warning);
        assert_contains('disposition=unverified', $warning);
        assert_contains('reason=selector-or-color-context-unresolved', $warning);
    }
});

test('css contrast adjuster leaves image backgrounds and authored colours unchanged', function () {
    $css = '.copy { color: #fff; background: #222 url("texture.png"); }';
    $markup = '<p class="copy">Image surface</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-image-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings));
    $warning = ($project->readJson('warnings.json')['css_contrast'] ?? [])[0] ?? '';
    assert_contains('authored_fg=#fff', $warning);
    assert_contains('authored_bg=#222 url("texture.png")', $warning);
    assert_contains('disposition=unverified', $warning);
    assert_contains('reason=background-image', $warning);
});

test('css contrast adjuster leaves gradient backgrounds and authored colours unchanged', function () {
    $css = '.copy { color: #fff; background: linear-gradient(#777, #999); }';
    $markup = '<p class="copy">Gradient surface</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-gradient-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings));
    $warning = ($project->readJson('warnings.json')['css_contrast'] ?? [])[0] ?? '';
    assert_contains('authored_fg=#fff', $warning);
    assert_contains('authored_bg=linear-gradient(#777, #999)', $warning);
    assert_contains('disposition=unverified', $warning);
    assert_contains('reason=background-gradient', $warning);
});

test('css contrast adjuster leaves unresolved variable backgrounds and authored colours unchanged', function () {
    $css = '.copy { color: #fff; background: var(--missing-surface); }';
    $markup = '<p class="copy">Unknown surface</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-variable-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings));
    $warning = ($project->readJson('warnings.json')['css_contrast'] ?? [])[0] ?? '';
    assert_contains('authored_fg=#fff', $warning);
    assert_contains('authored_bg=var(--missing-surface)', $warning);
    assert_contains('disposition=unverified', $warning);
    assert_contains('reason=background-unresolved-variable', $warning);
});

test('css contrast adjuster leaves authored colours unchanged when the perceptual cap is hit', function () {
    $css = '.copy { color: #777; background: #888; }';
    $markup = '<p class="copy">Close greys</p>';
    $findings = CssContrastCheck::check($css, $markup);
    assert_eq('fail', $findings[0]['status']);
    $root = sys_get_temp_dir() . '/css-contrast-cap-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings));
    $warning = ($project->readJson('warnings.json')['css_contrast'] ?? [])[0] ?? '';
    assert_contains('authored_fg=#777', $warning);
    assert_contains('authored_bg=#888', $warning);
    assert_contains('delivered_bg=#888', $warning);
    assert_contains('disposition=unchanged', $warning);
    assert_contains('reason=perceptual-shift-cap-exceeded', $warning);
});

test('css contrast adjuster scopes resolved variables without rewriting root tokens', function () {
    $css = ":root { --surface: #F26522; }\n.copy { color: #fff; background: var(--surface); }";
    $markup = '<p class="copy">Scoped token</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-scoped-var-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_contains(':root { --surface: #F26522; }', $adjusted);
    assert_contains('.copy { color: #fff; background: #', $adjusted);
    assert_true(!str_contains($adjusted, '.copy { color: #fff; background: var(--surface); }'));
    $delivered = array_values(array_filter(
        CssContrastCheck::check($adjusted, $markup),
        static fn (array $finding): bool => $finding['selector'] === '.copy',
    ));
    assert_eq('pass', $delivered[0]['status']);
    assert_eq('#fff', $delivered[0]['fg']);
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
    $css = ".copy { color: #fff; background: #000000; }\n.copy { background: #F26522; }\n";
    $markup = '<p class="copy">Duplicate selector</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-target-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    $after = CssContrastCheck::check($adjusted, $markup);
    assert_eq(1, count($after));
    assert_eq('pass', $after[0]['status']);
    assert_eq('#fff', $after[0]['fg']);
    assert_eq(
        ".copy { color: #fff; background: #000000; }\n.copy { background: {$after[0]['bg']}; }\n",
        $adjusted,
    );
});

test('css contrast adjuster rejects a background move that breaks another authored foreground', function () {
    $css = <<<'CSS'
.surface { background: #777777; }
.light { color: rgba(255, 255, 255, 0.95); }
.dark { color: #000000; }
CSS;
    $markup = '<p class="surface light">Light</p><p class="surface dark">Dark</p>';
    $findings = CssContrastCheck::check($css, $markup);
    assert_eq(2, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('pass', $findings[1]['status']);
    $root = sys_get_temp_dir() . '/css-contrast-shared-bg-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_eq($css, $adjusted);
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('authored_fg=rgba(255, 255, 255, 0.95)', $warnings[0]);
    assert_contains('authored_bg=#777777', $warnings[0]);
    assert_contains('delivered_bg=#777777', $warnings[0]);
    assert_contains('disposition=unchanged', $warnings[0]);
    assert_contains('reason=shared-background-conflict', $warnings[0]);
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

test('css contrast cascade ignores comments between declarations and repairs the real winner', function () {
    $css = '.copy{color:#fff;background:#000000;/* generated */ background:#F26522}';
    $markup = '<p class="copy">Commented cascade</p>';

    $findings = CssContrastCheck::check($css, $markup);

    assert_eq(1, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#fff', $findings[0]['fg']);
    $root = sys_get_temp_dir() . '/css-contrast-comments-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);
    $after = CssContrastCheck::check($adjusted, $markup);
    assert_eq('pass', $after[0]['status']);
    assert_eq('.copy{color:#fff;background:#000000;/* generated */ background:' . $after[0]['bg'] . '}', $adjusted);
});

test('css contrast cascade recognizes important split by a comment', function () {
    $css = '.copy{color:#fff;background:#F26522 !/**/important;background:#000000}';
    $markup = '<p class="copy">Commented important</p>';

    $findings = CssContrastCheck::check($css, $markup);

    assert_eq(1, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#fff', $findings[0]['fg']);
    $root = sys_get_temp_dir() . '/css-contrast-important-comment-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);
    $after = CssContrastCheck::check($adjusted, $markup);
    assert_eq('pass', $after[0]['status']);
    assert_eq('.copy{color:#fff;background:' . $after[0]['bg'] . ' !/**/important;background:#000000}', $adjusted);
});

test('css contrast comment handling keeps comment tokens inside strings byte-identical', function () {
    $css = '.copy{--note:"literal /* generated */ token";color:#fff;background:#F26522}';
    $markup = '<p class="copy">String token</p>';
    $findings = CssContrastCheck::check($css, $markup);
    $root = sys_get_temp_dir() . '/css-contrast-string-comment-' . bin2hex(random_bytes(8));
    $project = new Project($root);

    $adjusted = CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings);

    assert_contains('--note:"literal /* generated */ token"', $adjusted);
    $after = CssContrastCheck::check($adjusted, $markup);
    assert_eq('pass', $after[0]['status']);
    assert_eq(str_replace('background:#F26522', 'background:' . $after[0]['bg'], $css), $adjusted);
});

test('css contrast resolves modern space rgb and opaque alpha hex instead of stale fallbacks', function () {
    $markup = '<p class="copy">Modern colors</p>';
    foreach ([
        'rgb(119 119 119)',
        '#777777ff',
    ] as $authored) {
        $findings = CssContrastCheck::check(
            ".copy{color:#000000;color:{$authored};background:#ffffff}",
            $markup,
        );

        assert_eq(1, count($findings), $authored);
        assert_eq('fail', $findings[0]['status'], $authored);
        assert_eq($authored, $findings[0]['fg'], $authored);
        assert_true(is_float($findings[0]['ratio']) && $findings[0]['ratio'] < ContrastMath::NORMAL_TEXT);
        assert_true(is_string($findings[0]['suggested']));
    }
});

test('valid unresolved background shorthand wins cascade and stays untouched with warning', function () {
    $css = '.copy{color:#777777;background-color:#000000;background:#ffffff none}';
    $markup = '<p class="copy">Shorthand background</p>';

    $findings = CssContrastCheck::check($css, $markup);

    assert_eq([[
        'selector' => '.copy',
        'status' => 'unverified',
        'fg' => null,
        'bg' => null,
        'ratio' => null,
        'suggested' => null,
    ]], $findings);
    $root = sys_get_temp_dir() . '/css-contrast-shorthand-' . bin2hex(random_bytes(8));
    $project = new Project($root);
    assert_eq($css, CssContrastAdjuster::apply($project, 'theme/style.css', $css, $markup, $findings));
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('disposition=unverified', $warnings[0]);
    assert_contains('delivered_bg=#ffffff none', $warnings[0]);
    assert_contains('reason=selector-or-color-context-unresolved', $warnings[0]);
});

test('position-only background shorthands win over stale background colors as unverified', function () {
    $markup = '<p class="copy">Position shorthand</p>';
    foreach (['center center', 'left top', '0 0'] as $shorthand) {
        $findings = CssContrastCheck::check(
            ".copy{color:#777777;background-color:#000000;background:{$shorthand}}",
            $markup,
        );

        assert_eq([[
            'selector' => '.copy',
            'status' => 'unverified',
            'fg' => null,
            'bg' => null,
            'ratio' => null,
            'suggested' => null,
        ]], $findings, $shorthand);
    }
});

test('later resolved background longhand wins over an earlier unresolved shorthand', function () {
    $findings = CssContrastCheck::check(
        '.copy{color:#777777;background:center center;background-color:#ffffff}',
        '<p class="copy">Later longhand</p>',
    );

    assert_eq(1, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#777777', $findings[0]['fg']);
    assert_eq('#ffffff', $findings[0]['bg']);
});

test('invalid atomic color declarations still fall back to earlier valid declarations', function () {
    $findings = CssContrastCheck::check(
        '.copy{color:#777777;color:not-a-color;background-color:#ffffff;background-color:not-a-color}',
        '<p class="copy">Invalid atomic fallback</p>',
    );

    assert_eq(1, count($findings));
    assert_eq('fail', $findings[0]['status']);
    assert_eq('#777777', $findings[0]['fg']);
    assert_eq('#ffffff', $findings[0]['bg']);
});
