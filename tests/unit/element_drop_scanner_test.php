<?php
declare(strict_types=1);

use Automattic\SiteBuild\ElementDropScanner;

test('element drop scanner distinguishes fallback survival replacement and absence', function (): void {
    $probes = [
        ['selector' => 'main > form', 'tag' => 'form', 'marker' => 'probe-form-one'],
        ['selector' => 'main > aside', 'tag' => 'aside', 'marker' => 'probe-aside-two'],
        ['selector' => 'header > svg', 'tag' => 'svg', 'marker' => 'probe-svg-three'],
        ['selector' => 'main > video', 'tag' => 'video', 'marker' => 'probe-video-four'],
        ['selector' => 'footer > dialog', 'tag' => 'dialog', 'marker' => 'probe-dialog-five'],
        ['selector' => 'main > canvas', 'tag' => 'CANVAS', 'marker' => 'probe-canvas-six'],
    ];
    $fallbacks = [
        ['selector' => 'main > form'],
        ['source_selector' => 'main > aside'],
        ['selector' => 42],
    ];
    $assets = [
        ['selector' => 'header > svg'],
        ['source_selector' => 'main > video'],
        'junk',
    ];
    $markup = '<img class="probe-svg-three">'
        . '<video class="probe-video-four"></video>'
        . '<dialog class="probe-dialog-five">kept</dialog>';

    $dropped = (new ElementDropScanner())->scan($probes, $fallbacks, $assets, $markup);

    assert_eq([
        ['selector' => 'header > svg', 'tag' => 'svg'],
        ['selector' => 'main > canvas', 'tag' => 'canvas'],
    ], $dropped);
});

test('element drop scanner reports a selector-bearing asset as source element replacement', function (): void {
    $probe = [[
        'selector' => 'header > svg',
        'tag' => 'svg',
        'marker' => 'fallback-probe-svg',
    ]];

    $dropped = (new ElementDropScanner())->scan(
        $probe,
        [],
        [['source_selector' => 'header > svg', 'kind' => 'svg']],
        '<img class="fallback-probe-svg">',
    );

    assert_eq([['selector' => 'header > svg', 'tag' => 'svg']], $dropped);
});

test('element drop scanner keeps a marker that survives on the original tag', function (): void {
    $probe = [[
        'selector' => 'header > svg',
        'tag' => 'svg',
        'marker' => 'fallback-probe-svg',
    ]];

    $dropped = (new ElementDropScanner())->scan(
        $probe,
        [],
        [],
        '<svg class="brand-mark fallback-probe-svg"></svg>',
    );

    assert_eq([], $dropped);
});

test('element drop scanner requires an exact marker token', function (): void {
    $probe = [['selector' => 'header > svg', 'tag' => 'svg', 'marker' => 'fallback-probe-svg']];

    $dropped = (new ElementDropScanner())->scan(
        $probe,
        [],
        [],
        '<img class="fallback-probe-svg-copy">',
    );

    assert_eq([['selector' => 'header > svg', 'tag' => 'svg']], $dropped);
});

test('element drop scanner output is sorted deduplicated and deterministic', function (): void {
    $probes = [
        ['selector' => 'z > svg', 'tag' => 'svg', 'marker' => 'probe-z'],
        ['selector' => 'a > form', 'tag' => 'form', 'marker' => 'probe-a'],
        ['selector' => 'z > svg', 'tag' => 'SVG', 'marker' => 'probe-z-again'],
    ];
    $scanner = new ElementDropScanner();

    $first = $scanner->scan($probes, [], [], '');
    $second = $scanner->scan($probes, [], [], '');

    assert_eq([
        ['selector' => 'a > form', 'tag' => 'form'],
        ['selector' => 'z > svg', 'tag' => 'svg'],
    ], $first);
    assert_eq($first, $second);
});

test('element drop scanner rejects incomplete frozen probes', function (): void {
    assert_throws(static fn () => (new ElementDropScanner())->scan(
        [['selector' => 'header > svg', 'tag' => 'svg', 'marker' => '']],
        [],
        [],
        '',
    ));
});
