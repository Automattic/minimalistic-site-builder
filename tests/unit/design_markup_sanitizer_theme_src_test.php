<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignMarkupSanitizer;

test('DesignMarkupSanitizer keeps pipeline theme: src and href', function () {
    $warnings = [];
    $out = DesignMarkupSanitizer::sanitize(
        '<img src="theme:./assets/x.jpg" alt="a"><a href="theme:./assets/y.jpg">y</a>',
        'design/home.html',
        'section',
        $warnings,
    );
    assert_contains('src="theme:./assets/x.jpg"', $out, 'assign-image-sources theme: src must reach collect-images');
    assert_contains('href="theme:./assets/y.jpg"', $out, 'theme: href is the same internal scheme');
    assert_contains('alt="a"', $out);
    assert_eq([], $warnings, 'a pipeline-authored theme: URL is not a defect');
});

test('DesignMarkupSanitizer still strips javascript, data, and vbscript URLs', function () {
    foreach ([
        '<img src="javascript:alert(1)" alt="a">',
        '<img src="data:image/gif;base64,xx" alt="a">',
        '<img src="vbscript:msgbox(1)" alt="a">',
        '<a href="javascript:alert(1)">x</a>',
        '<a href="data:text/html,x">x</a>',
        '<a href="vbscript:x">x</a>',
    ] as $input) {
        $warnings = [];
        $out = DesignMarkupSanitizer::sanitize($input, 'design/home.html', 'section', $warnings);
        assert_true(!preg_match('/javascript:|data:|vbscript:/i', $out), "hostile scheme survived in {$input} => {$out}");
        assert_true($warnings !== [], "stripping {$input} must warn");
    }
});

test('DesignMarkupSanitizer still keeps a relative src', function () {
    $warnings = [];
    $out = DesignMarkupSanitizer::sanitize(
        '<img src="./assets/x.jpg" alt="r">',
        'design/home.html',
        'section',
        $warnings,
    );
    assert_contains('src="./assets/x.jpg"', $out);
    assert_eq([], $warnings, 'a same-origin relative src is not a defect');
});

test('DesignMarkupSanitizer neutralizes a media src on a foreign host', function () {
    // This assertion used to require the https src to SURVIVE. BIGR-975
    // deliberately reversed that: a hot-linked media source is removed at the
    // write. The element and its alt stay, so only the fetch is dropped.
    $warnings = [];
    $out = DesignMarkupSanitizer::sanitize(
        '<img src="https://ex.com/x.jpg" alt="h">',
        'design/home.html',
        'section',
        $warnings,
    );
    assert_true(!str_contains($out, 'ex.com'), "the foreign host must not survive: {$out}");
    assert_contains('alt="h"', $out, 'only the source is dropped, not the element');
    assert_eq(1, count($warnings), 'the removal is named, never silent');
    assert_contains('media source on a foreign host', $warnings[0]);
});
