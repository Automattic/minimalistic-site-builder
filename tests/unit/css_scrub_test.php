<?php
declare(strict_types=1);

use Automattic\SiteBuild\CssScrub;

test('css scrub removes complete import statements with every supported source form', function (): void {
    $css = <<<'CSS'
@import url("https://fonts.example/a.css") screen and (min-width: 40rem);
@IMPORT 'theme:local.css' layer(tokens);
@import url(//cdn.example/b.css) print;
.safe { color: red; }
CSS;

    $result = CssScrub::scrub($css);

    assert_eq("\n\n\n.safe { color: red; }", $result['css']);
    assert_eq(3, count($result['removals']));
    assert_eq(['import', 'import', 'import'], array_column($result['removals'], 'kind'));
    assert_eq('removed', $result['removals'][0]['delivered_value']);
    assert_eq('removed_import', $result['removals'][0]['disposition']);
});

test('css scrub removes nested imports without consuming sibling rules', function (): void {
    $css = '@media screen { @import url("https://bad.example/x.css") supports(display:grid);'
        . ' .safe { display:grid; } }';

    $result = CssScrub::scrub($css);

    assert_eq('@media screen {  .safe { display:grid; } }', $result['css']);
    assert_eq(1, count($result['removals']));
});

test('css scrub removes a balanced terminal import without a semicolon', function (): void {
    $css = '@import url(https://evil.example/x.css)';

    $result = CssScrub::scrub($css);

    assert_eq('', $result['css']);
    assert_eq('@import url(https://evil.example/x.css)', $result['removals'][0]['authored_value']);
});

test('css scrub removes recoverably malformed terminal imports through EOF', function (): void {
    foreach ([
        '@import url(https://evil.example/x.css',
        '@import "https://evil.example/x.css',
        '@import url(https://evil.example/x.css) /* unclosed',
    ] as $css) {
        $result = CssScrub::scrub($css);
        assert_eq('', $result['css']);
        assert_eq($css, $result['removals'][0]['authored_value']);
        assert_eq('import', $result['removals'][0]['kind']);
    }
});

test('css scrub retains malformed import bytes when EOF recovery could swallow a later rule', function (): void {
    foreach ([
        "@import url(https://evil.example/x.css\n.safe{display:block}",
        "@import \"https://evil.example/x.css\n.safe{display:block}",
    ] as $css) {
        $result = CssScrub::scrub($css);
        assert_eq($css, $result['css']);
        assert_eq([], $result['removals']);
    }
});

test('css scrub removes an unclosed terminal import comment including brace lookalikes', function (): void {
    $css = "@import url(https://evil.example/x.css) /* unclosed\n.safe{display:block}";

    $result = CssScrub::scrub($css);

    assert_eq('', $result['css']);
    assert_eq($css, $result['removals'][0]['authored_value']);
    assert_eq('import', $result['removals'][0]['kind']);
});

test('css scrub removes only declarations carrying external urls', function (): void {
    $css = '.hero { color:red; background:url("https://bad.example/a.png") center/cover;'
        . ' border:1px solid; mask:URL (  //cdn.example/m.svg  ); padding:2rem; }';

    $result = CssScrub::scrub($css);

    assert_eq('.hero { color:red;  border:1px solid;  padding:2rem; }', $result['css']);
    assert_eq(2, count($result['removals']));
    assert_eq(
        ['background:url("https://bad.example/a.png") center/cover;', 'mask:URL (  //cdn.example/m.svg  );'],
        array_column($result['removals'], 'authored_value')
    );
    assert_eq(
        ['external_url_declaration', 'external_url_declaration'],
        array_column($result['removals'], 'kind')
    );
});

test('css scrub emits one removal for multiple external urls in one declaration', function (): void {
    $css = '.hero{background-image:image-set(url(https://bad.example/a.png) 1x,'
        . 'url("HTTP://bad.example/b.png") 2x);color:blue}';

    $result = CssScrub::scrub($css);

    assert_eq('.hero{color:blue}', $result['css']);
    assert_eq(1, count($result['removals']));
});

test('css scrub removes harmful font face src and preserves sibling descriptors', function (): void {
    $css = '@font-face{font-family:"Local";font-display:swap;'
        . 'src:local("Local"), url(HTTPS://fonts.example/font.woff2) format("woff2");'
        . 'font-weight:400}';

    $result = CssScrub::scrub($css);

    assert_eq('@font-face{font-family:"Local";font-display:swap;font-weight:400}', $result['css']);
    assert_eq('external_url_declaration', $result['removals'][0]['kind']);
});

test('css scrub removes declarations with CSS-escaped external url schemes', function (): void {
    $css = <<<'CSS'
.a{background:url(h\74 tps://evil.example/a.png);color:red}
.b{mask:url(\2f \2f evil.example/b.svg);display:block}
CSS;

    $result = CssScrub::scrub($css);

    assert_eq(".a{color:red}\n.b{display:block}", $result['css']);
    assert_eq(2, count($result['removals']));
});

test('css scrub recognizes CSS-escaped import and url identifiers', function (): void {
    $css = <<<'CSS'
@\69mport url(https://evil.example/x.css);
.a{background:u\72l(https://evil.example/x.png);color:red}
.b{mask:\75 rl(//evil.example/x.svg);display:block}
CSS;

    $result = CssScrub::scrub($css);

    assert_eq("\n.a{color:red}\n.b{display:block}", $result['css']);
    assert_eq(
        ['import', 'external_url_declaration', 'external_url_declaration'],
        array_column($result['removals'], 'kind')
    );
});

test('css scrub does not match a url suffix inside a longer escaped identifier', function (): void {
    $css = '.safe{background:\\78 url(https://evil.example/x.png);color:red}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub leaves malformed CSS url escapes unchanged without losing safe siblings', function (): void {
    $css = '.bad{background:url(h\\);color:red}.safe{display:block}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub preserves every allowed url scheme and path form', function (): void {
    $css = <<<'CSS'
.a{background:url(./asset.png)}
.b{background:url("../asset.png")}
.c{background:url(/theme/asset.png)}
.d{background:url(theme:images/asset.png)}
.e{background:url(assets/generated/asset.png)}
.f{background:url(data:image/svg+xml,%3Csvg%20viewBox='0;0;1;1'%3E%3C/svg%3E)}
.g{filter:url(#shadow)}
CSS;

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub ignores import and url lookalikes in comments and strings', function (): void {
    $css = <<<'CSS'
/* @import url(https://bad.example/comment.css); */
.note::before{content:"@import url(https://bad.example/string.css);"}
.note::after{content:'url("//bad.example/string.png")'}
.safe{background:url(./safe.png)}
CSS;

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub leaves malformed url boundary unchanged and does not lose safe siblings', function (): void {
    $css = '.bad{background:url("https://bad.example/x.png";color:red}.safe{display:block}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub leaves malformed import boundary unchanged and does not lose safe rules', function (): void {
    $css = '@import url("https://bad.example/x.css") .safe{display:block}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub removes remote bare strings from every supported image function context', function (): void {
    $cases = [
        'image-set' => 'image-set("https://evil.example/image-set.png" 1x)',
        '-webkit-image-set' => '-webkit-image-set("//evil.example/webkit.png" 2x)',
        'image' => 'image("HTTP://evil.example/image.png")',
        'cross-fade' => 'cross-fade("https://evil.example/cross-fade.png", transparent, 50%)',
        'src' => 'src("https://evil.example/font.woff2")',
    ];

    foreach ($cases as $function => $value) {
        $css = ".bad{background-image:{$value};color:red}";
        $result = CssScrub::scrub($css);

        assert_eq('.bad{color:red}', $result['css'], "{$function} remote string removed");
        assert_eq(1, count($result['removals']), "{$function} reports one removal");
        assert_eq('external_url_declaration', $result['removals'][0]['kind'], $function);
        assert_eq("background-image:{$value};", $result['removals'][0]['authored_value'], $function);
        assert_eq('removed', $result['removals'][0]['delivered_value'], $function);
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $function);
    }
});

test('css scrub matches image function names case insensitively', function (): void {
    $css = '.bad{background-image:ImAgE-SeT("HTTPS://evil.example/case.png" 1x);color:red}'
        . '.also-bad{background-image:CrOsS-FaDe("//evil.example/case-2.png", transparent, 50%);display:block}';

    $result = CssScrub::scrub($css);

    assert_eq('.bad{color:red}.also-bad{display:block}', $result['css']);
    assert_eq(2, count($result['removals']));
});

test('css scrub removes image function bare strings with CSS-escaped external schemes', function (): void {
    $css = <<<'CSS'
.bad{background-image:image-set("https\3a //evil.example/escaped.png" 1x);color:red}
CSS;

    $result = CssScrub::scrub($css);

    assert_eq('.bad{color:red}', $result['css']);
    assert_eq(1, count($result['removals']));
    assert_eq('removed_external_url', $result['removals'][0]['disposition']);
});

test('css scrub removes bare remote strings after raw or escaped leading whitespace', function (): void {
    $cases = [
        'raw leading whitespace' => ' http://127.0.0.1:9/px.png',
        'CSS-escaped leading whitespace' => '\20 https://evil.example/escaped-space.png',
    ];

    foreach ($cases as $label => $value) {
        $css = '.bad{background-image:image-set("' . $value . '" 1x);color:red}';
        $result = CssScrub::scrub($css);

        assert_eq('.bad{color:red}', $result['css'], $label);
        assert_eq(1, count($result['removals']), $label);
        assert_eq(
            'background-image:image-set("' . $value . '" 1x);',
            $result['removals'][0]['authored_value'],
            $label
        );
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $label);
    }
});

test('css scrub removes bare remote strings after CSS-escaped leading C0 controls', function (): void {
    $cases = [
        'vertical tab' => '\b http://127.0.0.1:9/vtab.png',
        'unit separator' => '\1f https://evil.example/unit-separator.png',
    ];

    foreach ($cases as $label => $value) {
        $css = '.bad{background-image:image-set("' . $value . '" 1x);color:red}';
        $result = CssScrub::scrub($css);

        assert_eq('.bad{color:red}', $result['css'], $label);
        assert_eq(1, count($result['removals']), $label);
        assert_eq(
            'background-image:image-set("' . $value . '" 1x);',
            $result['removals'][0]['authored_value'],
            $label
        );
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $label);
    }
});

test('css scrub removes bare remote strings with embedded CSS-escaped URL whitespace', function (): void {
    $cases = [
        'tab' => 'h\9 ttp://127.0.0.1:9/tab.png',
        'line feed' => 'h\a ttp://evil.example/line-feed.png',
        'carriage return' => 'h\d ttp://evil.example/carriage-return.png',
    ];

    foreach ($cases as $label => $value) {
        $css = '.bad{background-image:image-set("' . $value . '" 1x);color:red}';
        $result = CssScrub::scrub($css);

        assert_eq('.bad{color:red}', $result['css'], $label);
        assert_eq(1, count($result['removals']), $label);
        assert_eq(
            'background-image:image-set("' . $value . '" 1x);',
            $result['removals'][0]['authored_value'],
            $label
        );
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $label);
    }
});

test('css scrub preserves allowed bare string urls in image functions byte for byte on one line', function (): void {
    $css = '.relative{background-image:image-set("./asset.png" 1x)}'
        . '.data{background-image:-webkit-image-set("data:image/png;base64,AAAA" 2x)}'
        . '.fragment{background-image:image("#local-paint")}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub preserves allowed bare string urls after CSS-escaped leading C0 controls', function (): void {
    $css = '.relative{background-image:image-set("\b ./asset.png" 1x)}'
        . '.data{background-image:image-set("\1f data:image/png;base64,AAAA" 2x)}'
        . '.fragment{background-image:image("\b #local-paint")}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub preserves allowed urls with embedded CSS-escaped URL whitespace', function (): void {
    $css = '.relative{background-image:image-set("./as\9 set.png" 1x)}'
        . '.data{background-image:image-set("da\a ta:image/png;base64,AAAA" 2x)}'
        . '.fragment{background-image:image("#lo\d cal-paint")}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub preserves raw and CSS-escaped NUL image strings byte for byte', function (): void {
    $nul = chr(0);
    $rawRemote = 'http://127.0.0.1:9/raw-nul.png';
    $rawCss = '.raw{background-image:image-set("' . $nul . $rawRemote . '" 1x);color:red}';
    $escapedCss = '.escaped{background-image:image-set("\0 http://127.0.0.1:9/escaped-nul.png" 1x);color:blue}';

    foreach ([$rawCss, $escapedCss] as $css) {
        $result = CssScrub::scrub($css);

        assert_eq(strlen($css), strlen($result['css']), 'binary length preserved');
        assert_eq(hash('sha256', $css), hash('sha256', $result['css']), 'binary hash preserved');
        assert_eq($css, $result['css']);
        assert_eq([], $result['removals']);
    }
    assert_eq(1, substr_count($rawCss, $nul), 'probe contains one actual NUL byte');
    assert_contains($rawRemote, CssScrub::scrub($rawCss)['css'], 'remote-like suffix remains inert');
});

test('css scrub removes every decoded slash-backslash authority pair', function (): void {
    $backslash = '\\';
    $cases = [
        'slash slash' => '//evil.example/slash-slash.png',
        'backslash backslash' => str_repeat($backslash, 4) . 'evil.example/backslash-backslash.png',
        'slash backslash' => '/' . str_repeat($backslash, 2) . 'evil.example/slash-backslash.png',
        'backslash slash' => str_repeat($backslash, 2) . '/evil.example/backslash-slash.png',
    ];

    foreach ($cases as $label => $value) {
        $declaration = 'background-image:image-set("' . $value . '" 1x);';
        $css = '.bad{' . $declaration . 'color:red}';
        $result = CssScrub::scrub($css);

        assert_eq('.bad{color:red}', $result['css'], $label);
        assert_eq(1, count($result['removals']), $label);
        assert_eq($declaration, $result['removals'][0]['authored_value'], $label);
        assert_eq(hash('sha256', $declaration), hash('sha256', $result['removals'][0]['authored_value']), $label);
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $label);
    }
});

test('css scrub preserves a single decoded backslash relative path byte for byte', function (): void {
    $value = str_repeat('\\', 2) . 'assets/local.png';
    $css = '.safe{background-image:image-set("' . $value . '" 1x);color:red}';

    $result = CssScrub::scrub($css);

    assert_eq(strlen($css), strlen($result['css']));
    assert_eq(hash('sha256', $css), hash('sha256', $result['css']));
    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css scrub preserves strings outside immediate image function url context', function (): void {
    $css = '.note::before{content:"https://x"}'
        . '.tokens{--asset:"https://x"}'
        . '.custom{value:not-an-image("https://x")}'
        . '.typed{background-image:image-set("./hero.avif" type("image/avif") 1x)}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

foreach (['hash' => '#', 'at-keyword' => '@'] as $label => $prefix) {
    test("css scrub preserves {$label} image-set lookalikes byte for byte", function () use ($label, $prefix): void {
        $css = '.tokens{--' . $label . ':' . $prefix
            . 'image-set("https://evil.example/not-a-function.png");color:red}';

        $result = CssScrub::scrub($css);

        assert_eq(strlen($css), strlen($result['css']));
        assert_eq(hash('sha256', $css), hash('sha256', $result['css']));
        assert_eq($css, $result['css']);
        assert_eq([], $result['removals']);
    });
}

foreach (
    [
        'whitespace-gap' => 'image-set ("https://evil.example/x.png")',
        'bracket-nested' => 'image-set(["https://evil.example/x.png"])',
    ] as $label => $value
) {
    test("css scrub preserves {$label} image-set lookalikes byte for byte", function () use ($value): void {
        $css = '.tokens{--fake:' . $value . ';color:red}';

        $result = CssScrub::scrub($css);

        assert_eq(strlen($css), strlen($result['css']));
        assert_eq(hash('sha256', $css), hash('sha256', $result['css']));
        assert_eq($css, $result['css']);
        assert_eq([], $result['removals']);
    });
}

foreach (
    [
        'immediate' => 'image-set("https://evil.example/x.png" 1x)',
        'comment-gap' => 'image-set/**/("https://evil.example/x.png" 1x)',
        'bracket-nested-real' => 'image-set([image("https://evil.example/x.png")])',
    ] as $label => $value
) {
    test("css scrub removes {$label} real image function control", function () use ($label, $value): void {
        $declaration = 'background-image:' . $value . ';';
        $css = '.actual{' . $declaration . 'color:red}';

        $result = CssScrub::scrub($css);

        assert_eq('.actual{color:red}', $result['css'], $label);
        assert_eq(1, count($result['removals']), $label);
        assert_eq($declaration, $result['removals'][0]['authored_value'], $label);
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $label);
    });
}

test('css scrub still removes a real image-set function beside token lookalikes', function (): void {
    $declaration = 'background-image:image-set("https://evil.example/not-a-function.png" 1x);';
    $css = '.actual{' . $declaration . 'color:red}';

    $result = CssScrub::scrub($css);

    assert_eq('.actual{color:red}', $result['css']);
    assert_eq(1, count($result['removals']));
    assert_eq($declaration, $result['removals'][0]['authored_value']);
    assert_eq('removed_external_url', $result['removals'][0]['disposition']);
});

test('css scrub recovers function context at a rule boundary before a later remote image string', function (): void {
    $css = '.broken{color:foo(1}.later{background-image:image-set("https://evil/px.png" 1x);display:block}';

    $result = CssScrub::scrub($css);

    assert_eq('.broken{color:foo(1}.later{display:block}', $result['css']);
    assert_eq(1, count($result['removals']));
    assert_eq(
        'background-image:image-set("https://evil/px.png" 1x);',
        $result['removals'][0]['authored_value']
    );
    assert_eq('removed_external_url', $result['removals'][0]['disposition']);
});

test('css scrub never carries an unclosed image function across a rule boundary', function (): void {
    $css = '.broken{background-image:image-set(foo}.later{content:"https://x");display:block}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

foreach (['LF' => "\n", 'CR' => "\r", 'FF' => "\f"] as $label => $terminator) {
    test("css scrub recovers after raw {$label} in a string before a later remote image string", function () use ($label, $terminator): void {
        $malformed = '.broken{--token:"unterminated' . $terminator . ';color:red}';
        $declaration = 'background-image:image-set("https://evil.example/after-bad-string.png" 1x);';
        $safe = '.note::before{content:"https://x";display:block}';
        $css = $malformed . '.later{' . $declaration . 'display:grid}' . $safe;
        $expected = $malformed . '.later{display:grid}' . $safe;

        $result = CssScrub::scrub($css);

        assert_eq(strlen($expected), strlen($result['css']), "{$label} retained byte length");
        assert_eq(hash('sha256', $expected), hash('sha256', $result['css']), "{$label} retained byte hash");
        assert_eq($expected, $result['css'], "{$label} malformed bytes and safe siblings retained");
        assert_eq(1, count($result['removals']), $label);
        assert_eq($declaration, $result['removals'][0]['authored_value'], $label);
        assert_eq('removed_external_url', $result['removals'][0]['disposition'], $label);
    });
}

foreach (['LF' => "\n", 'CR' => "\r", 'FF' => "\f"] as $label => $terminator) {
    test("css scrub does not carry raw {$label} in a malformed string into later safe content", function () use ($terminator): void {
        $css = '.broken{--token:"unterminated' . $terminator . ';color:red}'
            . '.note::before{content:"https://x";display:block}';

        $result = CssScrub::scrub($css);

        assert_eq(strlen($css), strlen($result['css']));
        assert_eq(hash('sha256', $css), hash('sha256', $result['css']));
        assert_eq($css, $result['css']);
        assert_eq([], $result['removals']);
    });
}

test('css scrub output and report are idempotent and byte stable', function (): void {
    $css = '@import "https://bad.example/x.css";'
        . '.hero{background:url(https://bad.example/x.png);color:red}'
        . '.safe{background:url(theme:hero.png)}';

    $first = CssScrub::scrub($css);
    $second = CssScrub::scrub($first['css']);
    $repeat = CssScrub::scrub($css);

    assert_eq($first, $repeat);
    assert_eq($first['css'], $second['css']);
    assert_eq([], $second['removals']);
});
