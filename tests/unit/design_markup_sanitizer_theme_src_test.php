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

test('DesignMarkupSanitizer still keeps relative and https srcs', function () {
    $warnings = [];
    $out = DesignMarkupSanitizer::sanitize(
        '<img src="./assets/x.jpg" alt="r"><img src="https://ex.com/x.jpg" alt="h">',
        'design/home.html',
        'section',
        $warnings,
    );
    assert_contains('src="./assets/x.jpg"', $out);
    assert_contains('src="https://ex.com/x.jpg"', $out);
    assert_eq([], $warnings);
});
