<?php
declare(strict_types=1);

use Automattic\SiteBuild\CssScrub;
use Automattic\SiteBuild\ContactFacts;

test('css contact scrub removes only ungrounded generated-content declarations', function (): void {
    $css = '.phone::before{content:"Call +1 212 555 0199";display:block}'
        . '.email::after{content:"fake\\40 example.com";color:red}'
        . '.safe{--example:"fake@example.com";display:grid}';

    $result = CssScrub::scrubContactContent($css, ContactFacts::candidateSetFromSpec([]));

    assert_eq(
        '.phone::before{display:block}.email::after{color:red}'
            . '.safe{--example:"fake@example.com";display:grid}',
        $result['css'],
    );
    assert_eq(2, count($result['removals']));
    assert_eq('removed_ungrounded_contact', $result['removals'][0]['disposition']);

    $allowed = ContactFacts::candidateSetFromSpec(['phone' => '+1 212 555 0199']);
    assert_eq(
        '.x::before{content:"Call +1 212 555 0199"}',
        CssScrub::scrubContactContent('.x::before{content:"Call +1 212 555 0199"}', $allowed)['css'],
    );
});

test('css contact scrub treats browser-invalid typed property math as fallback', function (): void {
    foreach ([
        'calc(1px+1px)',
        'calc(1px +1px)',
        'calc(1px+ 1px)',
        'calc(1px \\2b  1px)',
        'calc(1px + + 1px)',
        'calc(+ 1px)',
        'calc(--1px)',
        'calc(++1px)',
        'calc(+-1px)',
        'calc(-+1px)',
        'calc(1px + ++1px)',
        'calc(1px + --1px)',
        'calc(-(1px))',
        'calc(+(1px))',
        'calc(1px + -(1px))',
        'calc(1px * +(1))',
        'calc(-pi * 1px)',
        'calc(+pi * 1px)',
        'calc(-e * 1px)',
        'calc(+infinity * 1px)',
        'calc(-nan * 1px)',
    ] as $math) {
        $css = '@property --a{syntax:"base-select | <length>";inherits:false;'
            . 'initial-value:base-select}select{--a:' . $math . ';appearance:var(--a)}'
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, '<select></select>');

        assert_true(
            !(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')),
            $css,
        );
        assert_eq(1, count($result['removals']), $css);
        assert_eq(
            [],
            CssScrub::scrubGenerated($result['css'], [], false, '<select></select>')['removals'],
            $css,
        );
    }

    $deepMath = 'calc(' . str_repeat('(', 1000) . '1px' . str_repeat(')', 1000) . ')';
    $css = '@property --a{syntax:"base-select | <length>";inherits:false;'
        . 'initial-value:base-select}select{--a:' . $deepMath . ';appearance:var(--a)}'
        . 'select::before{content:"20755"}select::after{content:"50199"}';
    $result = CssScrub::scrubGenerated($css, [], false, '<select></select>');
    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));
    assert_eq(
        [],
        CssScrub::scrubGenerated($result['css'], [], false, '<select></select>')['removals'],
    );
});

test('css contact scrub treats browser-invalid typed property colors as fallback', function (): void {
    foreach ([
        'color-mix(in srgb,red,notacolor)',
        'color(bogus 1 2 3)',
        'rgb(1 2 3 4)',
        'color-mix(in srgb shorter hue,red,blue)',
        'color-mix(in srgb,red 0%,blue 0%)',
        'color-mix(in srgb,red -10%,blue 110%)',
        'lab(1,2,3)',
        'color(srgb 1,2,3)',
        'rgb(1,2,3/4)',
        'rgb(1 2 3 / +)',
        'color(srgb 1 2 3 / +)',
        'lab(1 2 3 / +)',
        'rgb(1..2 3 4)',
        'rgb(1-2 3 4)',
        'rgb(1..2,3,4)',
        'color(srgb 1..2 3 4)',
        'lab(1..2 3 4)',
        'hsl(1..2 3 4)',
        'hsl(1% 2 3)',
        'hwb(1% 2 3)',
        'rgb(1,2%,3)',
        'rgb(1%,2,3%)',
        'hsl(1,2,3)',
        'hsl(1,2%,3)',
        'rgba(0,0,0,none)',
        'hsla(0,0%,0%,none)',
    ] as $color) {
        $css = '@property --a{syntax:"base-select | <color>";inherits:false;'
            . 'initial-value:base-select}select{--a:' . $color . ';appearance:var(--a)}'
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, '<select></select>');

        assert_true(
            !(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')),
            $css,
        );
        assert_eq(1, count($result['removals']), $css);
        assert_eq(
            [],
            CssScrub::scrubGenerated($result['css'], [], false, '<select></select>')['removals'],
            $css,
        );
    }
});

test('css contact scrub bounds wide three-pseudo condition overflow', function (): void {
    $css = '.x{display:list-item}';
    for ($index = 1; $index <= 100; $index++) {
        $css .= '@supports not (color:' . sprintf('#%06x', $index) . '){'
            . '.x::marker{content:"@"!important}'
            . '.x::before{content:"@"!important}'
            . '.x::after{content:"@"!important}}';
    }
    $css .= '@supports(display:block){.x::marker{content:"207 "}}'
        . '@supports(display:flex){.x::before{content:"555 "}}'
        . '@supports(display:grid){.x::after{content:"0199"}}';
    $result = CssScrub::scrubGenerated($css, [], false, '<ul><li class="x"></li></ul>');

    assert_true(
        !(str_contains($result['css'], '207 ') && str_contains($result['css'], '0199')),
    );
    assert_eq(1, count($result['removals']));
    assert_eq(
        [],
        CssScrub::scrubGenerated($result['css'], [], false, '<ul><li class="x"></li></ul>')['removals'],
    );

    $withoutMarkup = CssScrub::scrubContactContent($css, []);
    assert_eq(303, count($withoutMarkup['removals']));
    assert_eq([], CssScrub::scrubContactContent($withoutMarkup['css'], [])['removals']);
});

test('css contact scrub fails closed when distinct overflow states cannot be exhausted', function (): void {
    $css = '.x{display:list-item}';
    for ($index = 1; $index <= 12; $index++) {
        $css .= '@supports not (color:' . sprintf('#%06x', $index) . '){'
            . '.x::marker{content:"' . $index . '"!important}'
            . '.x::before{content:"' . $index . '"!important}'
            . '.x::after{content:"' . $index . '"!important}}';
    }
    $css .= '@supports(display:block){.x::marker{content:"207 "}}'
        . '@supports(display:flex){.x::before{content:"555 "}}'
        . '@supports(display:grid){.x::after{content:"0199"}}';
    $result = CssScrub::scrubGenerated($css, [], false, '<ul><li class="x"></li></ul>');

    assert_true(
        !(str_contains($result['css'], '207 ') && str_contains($result['css'], '0199')),
    );
    assert_true(count($result['removals']) > 0);
    assert_eq(
        [],
        CssScrub::scrubGenerated($result['css'], [], false, '<ul><li class="x"></li></ul>')['removals'],
    );
});

test('css contact scrub bounds ordered media implication work', function (): void {
    $css = '';
    for ($index = 1; $index <= 100; $index++) {
        $css .= '@media(max-width:' . $index . 'px){.x::before{content:"safe"}}';
    }

    $result = CssScrub::scrubGenerated($css, [], false, '<span class="x"></span>');

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css contact scrub fails closed before quadratic media closure', function (): void {
    $css = '';
    for ($index = 2000; $index >= 1; $index--) {
        $css .= '@media(max-width:' . $index . 'px){.x::before{content:"safe"}}';
    }

    $result = CssScrub::scrubGenerated($css, [], false, '<span class="x"></span>');

    assert_true(count($result['removals']) > 0);
    assert_eq(
        [],
        CssScrub::scrubGenerated($result['css'], [], false, '<span class="x"></span>')['removals'],
    );
});

test('css contact scrub ignores browser-invalid property syntax', function (): void {
    foreach ([
        '<custom-ident> |',
        '| <custom-ident>',
        '<custom-ident> || auto',
        '<custom-ident> | | auto',
        '* | <custom-ident>',
        'auto +',
        'auto #',
    ] as $syntax) {
        $css = '@property --a{syntax:"' . $syntax . '";inherits:false;initial-value:auto}'
            . '.p{--a:base-select}.p select{appearance:var(--a)}'
            . 'select::before{content:"20755"}select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<div class="p"><select></select></div>',
        );

        assert_true(
            !(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')),
            $syntax,
        );
        assert_eq(1, count($result['removals']), $syntax);
    }

    foreach (['auto+', 'auto#'] as $syntax) {
        assert_true(isset(CssScrub::registeredCustomProperties(
            '@property --a{syntax:"' . $syntax . '";inherits:false;initial-value:auto}',
        )['--a']), $syntax);
    }
});

test('css contact scrub bounds the exhaustive fourteen-condition path', function (): void {
    $css = '.x{display:list-item}';
    for ($index = 1; $index <= 8; $index++) {
        $css .= '@supports not (color:' . sprintf('#%06x', $index) . '){'
            . '.x::marker{content:":"}'
            . '.x::before{content:"@"}'
            . '.x::after{content:"."}}';
    }
    $css .= '@supports(color:red){.x::marker{content:"2075"}}'
        . '@supports(display:block){.x::before{content:"550"}}'
        . '@supports(display:grid){.x::after{content:"199"}}'
        . '@supports not (color:red){.x::marker{content:"Call "}}'
        . '@supports not (display:block){.x::before{content:""}}'
        . '@supports not (display:grid){.x::after{content:""}}';
    $result = CssScrub::scrubGenerated($css, [], false, '<ul><li class="x"></li></ul>');

    assert_true(
        !(str_contains($result['css'], '2075') && str_contains($result['css'], '199')),
    );
    assert_eq(1, count($result['removals']));
});

test('css contact scrub bounds conditional custom-property composition', function (): void {
    $css = '.x{display:list-item;list-style-position:inside}'
        . '.x::before{content:var(--at) var(--host)}'
        . '@supports(color:red){.x::marker{content:"fake"}}'
        . '@supports(color:blue){.x{--at:"@"}}'
        . '@supports(color:green){.x{--host:"example"}}'
        . '@supports(color:black){.x::after{content:".com"}}';
    foreach (['block', 'flex', 'grid', 'flow-root', 'inline-block', 'inline-flex',
        'inline-grid', 'list-item', 'table', 'table-cell', 'table-row', 'contents'] as $display
    ) {
        $css .= '@supports not (display:' . $display . '){'
            . '.x::marker{content:"Order 123"!important}}';
    }
    $result = CssScrub::scrubGenerated($css, [], false, '<ul><li class="x"></li></ul>');

    assert_true(!str_contains($result['css'], 'content:var(--at)'));
    assert_eq(2, count($result['removals']));
    assert_eq(
        [],
        CssScrub::scrubGenerated(
            $result['css'],
            [],
            false,
            '<ul><li class="x"></li></ul>',
        )['removals'],
    );
});

test('css resource extraction ignores invalid url syntax and decodes Unicode escapes', function (): void {
    $invalid = 'url (https://invented.example/a.png) '
        . "url('https://invented.example/a\n.png')";
    assert_eq([], CssScrub::resourceUrls($invalid));
    assert_eq(
        ['https://例子.测试/a.png'],
        CssScrub::resourceUrls('url(https://\\4F8B\\5B50.\\6D4B\\8BD5/a.png)'),
    );
});

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

test('css scrub removes only declarations carrying valid external urls', function (): void {
    $css = '.hero { color:red; background:url("https://bad.example/a.png") center/cover;'
        . ' border:1px solid; mask:URL (  //cdn.example/m.svg  ); padding:2rem; }';

    $result = CssScrub::scrub($css);

    assert_eq(
        '.hero { color:red;  border:1px solid; mask:URL (  //cdn.example/m.svg  ); padding:2rem; }',
        $result['css'],
    );
    assert_eq(1, count($result['removals']));
    assert_eq(
        ['background:url("https://bad.example/a.png") center/cover;'],
        array_column($result['removals'], 'authored_value')
    );
    assert_eq(
        ['external_url_declaration'],
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

test('css scrub removes an external image string in an EOF-truncated final declaration', function (): void {
    $prefix = '.x{color:red;';
    $declaration = 'background-image:image-set("http://127.0.0.1:9/eof.png" 1x';
    $css = $prefix . $declaration;

    $result = CssScrub::scrub($css);

    assert_eq(strlen($prefix), strlen($result['css']), 'prior byte length retained');
    assert_eq(hash('sha256', $prefix), hash('sha256', $result['css']), 'prior byte hash retained');
    assert_eq($prefix, $result['css'], 'only harmful final declaration removed through EOF');
    assert_eq(1, count($result['removals']));
    assert_eq($declaration, $result['removals'][0]['authored_value']);
    assert_eq('removed_external_url', $result['removals'][0]['disposition']);
});

test('css scrub preserves a closed rule with an unmatched image function byte for byte', function (): void {
    $css = '.x{background-image:image-set("https://evil.example/x.png" 1x}';

    $result = CssScrub::scrub($css);

    assert_eq(strlen($css), strlen($result['css']));
    assert_eq(hash('sha256', $css), hash('sha256', $result['css']));
    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

foreach (
    [
        'relative' => './local.png',
        'data' => 'data:image/png;base64,AAAA',
        'fragment' => '#paint',
    ] as $label => $value
) {
    test("css scrub preserves an allowed {$label} image string through EOF", function () use ($value): void {
        $css = '.x{color:red;background-image:image-set("' . $value . '" 1x';

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

test('css contact scrub evaluates adjacent content strings as one rendered value', function (): void {
    $css = '.x::before{content:"fake" "\\40 " "example.com";color:red}';

    $result = CssScrub::scrubContactContent($css, []);

    assert_eq('.x::before{color:red}', $result['css']);
    assert_eq('removed_ungrounded_contact', $result['removals'][0]['disposition']);
});

test('css contact scrub rejects generated phone fragments that can compose with markup', function (): void {
    $css = '.x::before{content:"207 555";color:red}'
        . '.split::before{content:"20755"}.split::after{content:"50199"}'
        . '.order::before{content:"Order 123456"}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq(
        '.x::before{color:red}.split::before{content:"20755"}.split::after{}.order::before{content:"Order 123456"}',
        $result['css'],
    );
    assert_eq(2, count($result['removals']));
    assert_eq('removed_ungrounded_contact', $result['removals'][0]['disposition']);
});

test('css contact scrub composes content only for the same selector subject', function (): void {
    $css = '.x::before{content:"20755"}'
        . '.a::before{content:"alpha"}.b::before{content:"beta"}.c::before{content:"gamma"}'
        . '.x::after{content:"50199"}'
        . 'header::before{content:"20755"}footer::before{content:"50199"}';

    $result = CssScrub::scrubContactContent($css, []);

    assert_eq(
        '.x::before{content:"20755"}.a::before{content:"alpha"}.b::before{content:"beta"}.c::before{content:"gamma"}'
            . '.x::after{}header::before{content:"20755"}footer::before{content:"50199"}',
        $result['css'],
    );
    assert_eq(1, count($result['removals']));
});

test('css contact scrub composes compatible selectors active scopes and cascade winners', function (): void {
    $cases = [
        '.x::before{content:"20755"}span.x::after{content:"50199"}',
        '@media all{.x::before{content:"20755"}}.x::after{content:"50199"}',
        '.x::before{content:"alpha"}.x::before{content:"20755"}.x::after{content:"50199"}',
        '.x{&::before{content:"20755"}&::after{content:"50199"}}',
    ];
    foreach ($cases as $css) {
        $result = CssScrub::scrubContactContent($css, []);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub preserves explicit numeric identifiers', function (): void {
    foreach (['Ticket', 'Reference', 'Invoice', 'SKU', 'Case'] as $label) {
        $css = '.x::before{content:"' . $label . ' 123456"}';
        assert_eq($css, CssScrub::scrubContactContent($css, [])['css'], $label);
    }
    assert_eq('.x::before{}', CssScrub::scrubContactContent(
        '.x::before{content:"207 555"}',
        [],
    )['css']);
});

test('css contact scrub preserves a numeric identifier named by matched markup', function (): void {
    $css = '.ticket::before{content:"123456"}';
    $markup = '<span class="ticket">Ticket</span>';

    assert_eq($css, CssScrub::scrubGenerated($css, [], false, $markup)['css']);
    assert_eq('.ticket::before{}', CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<span class="ticket"></span>',
    )['css']);
});

test('css contact scrub composes generated text with matched delivered markup', function (): void {
    $cases = [
        ['.x::before{content:"20755"}', '<span class="x">50199</span>'],
        ['#x::before{content:"20755"}.c::after{content:"50199"}', '<span id="x" class="c"></span>'],
        ['.x::before{content:"20755"}[class~="x"]::after{content:"50199"}', '<span class="x"></span>'],
    ];
    foreach ($cases as [$css, $markup]) {
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub keeps unconditional cascade candidates alongside conditional overrides', function (): void {
    $css = '.x::before{content:"20755"}'
        . '@media(max-width:999px){.x::before{content:"alpha"}}'
        . '.x::after{content:"50199"}';

    $result = CssScrub::scrubGenerated($css, [], false, '<span class="x"></span>');

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));

    $hiding = CssScrub::scrubContactHiding(
        '.gone{display:none}@media(min-width:1000px){.gone{display:block}}',
        '<label class="gone" for="order">Order ID</label>'
            . '<input id="order" type="number" value="2125550199">',
        [],
    );
    assert_true(!str_contains($hiding['css'], 'display:none'));
    assert_eq(1, count($hiding['removals']));

    $exclusive = '@media(min-width:1000px){.x::before{content:"20755"}}'
        . '@media(max-width:999px){.x::after{content:"50199"}}';
    $exclusiveResult = CssScrub::scrubGenerated(
        $exclusive,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_eq($exclusive, $exclusiveResult['css']);
    assert_eq([], $exclusiveResult['removals']);

    $overlapping = '@media(min-width:800px){.x::before{content:"20755"}}'
        . '@media(max-width:1200px){.x::after{content:"50199"}}';
    $overlappingResult = CssScrub::scrubGenerated(
        $overlapping,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_true(!(
        str_contains($overlappingResult['css'], '20755')
        && str_contains($overlappingResult['css'], '50199')
    ));
    assert_eq(1, count($overlappingResult['removals']));

    foreach (
        [
            '@media(orientation:landscape){.x::before{content:"20755"}}'
                . '@media(orientation:portrait){.x::after{content:"50199"}}',
            '@media(min-width:100em){.x::before{content:"20755"}}'
                . '@media(max-width:50em){.x::after{content:"50199"}}',
            '@supports(display:grid){.x::before{content:"20755"}}'
                . '@supports not (display:grid){.x::after{content:"50199"}}',
            '@media(prefers-color-scheme:dark){.x::before{content:"20755"}}'
                . '@media(prefers-color-scheme:light){.x::after{content:"50199"}}',
            '@media(prefers-reduced-motion:reduce){.x::before{content:"20755"}}'
                . '@media(prefers-reduced-motion:no-preference){.x::after{content:"50199"}}',
            '@media screen{.x::before{content:"20755"}}'
                . '@media print{.x::after{content:"50199"}}',
            '@media(prefers-contrast:more){.x::before{content:"20755"}}'
                . '@media(prefers-contrast:less){.x::after{content:"50199"}}',
            '@media(forced-colors:active){.x::before{content:"20755"}}'
                . '@media(forced-colors:none){.x::after{content:"50199"}}',
            '@media(hover:hover){.x::before{content:"20755"}}'
                . '@media(hover:none){.x::after{content:"50199"}}',
            '@media(pointer:fine){.x::before{content:"20755"}}'
                . '@media(pointer:coarse){.x::after{content:"50199"}}',
        ] as $disjoint
    ) {
        $disjointResult = CssScrub::scrubGenerated($disjoint, [], false, '<span class="x"></span>');
        assert_eq($disjoint, $disjointResult['css'], $disjoint);
        assert_eq([], $disjointResult['removals'], $disjoint);
    }
});

test('css contact scrub keeps dynamic state from every selector compound', function (): void {
    foreach ([':hover', ':focus-within', ':has(.ready)'] as $state) {
        $css = '.x::before{content:"20755"}'
            . '.a' . $state . ' .x::before{content:"alpha"}'
            . '.x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<div class="a"><b class="ready"></b><span class="x"></span></div>',
        );
        assert_true(
            !(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')),
            $state,
        );
        assert_eq(1, count($result['removals']), $state);
    }

    $hiding = CssScrub::scrubContactHiding(
        '.gone{display:none}.a:hover .gone{display:block}',
        '<div class="a"><label class="gone" for="order">Order ID</label>'
            . '<input id="order" type="number" value="2125550199"></div>',
        [],
    );
    assert_true(!str_contains($hiding['css'], 'display:none'));
    assert_eq(1, count($hiding['removals']));
});

test('css contact scrub allows simultaneously supported feature queries to compose', function (): void {
    foreach (
        [
            '@supports(display:grid){.x::before{content:"20755"}}'
                . '@supports(display:flex){.x::after{content:"50199"}}',
            '@supports(color:red){.x::before{content:"20755"}}'
                . '@supports(color:blue){.x::after{content:"50199"}}',
        ] as $css
    ) {
        $result = CssScrub::scrubGenerated($css, [], false, '<span class="x"></span>');
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    foreach (
        [
            ['select{appearance:env(msb-missing,base-select)}', '<select></select>'],
            [
                'select{appearance:attr(data-app type(<custom-ident>),auto)}',
                '<select data-app="base-select"></select>',
            ],
            [
                ':root{--a:base-select}select{--a:inherit;appearance:var(--a)}',
                '<select></select>',
            ],
            [
                ':root{--a:base-select}select{--a:unset;appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"*";inherits:false;initial-value:base-select}'
                    . 'select{appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"base-select | auto";inherits:false;initial-value:base-select}'
                    . 'select{appearance:var(--a)}',
                '<select></select>',
            ],
            [
                'select{appearance:attr(data-app type(base-select | auto),auto)}',
                '<select data-app="base-select"></select>',
            ],
            ['select{appearance:env(titlebar-area-width,base-select)}', '<select></select>'],
            ['select{appearance:env(viewport-segment-width,base-select)}', '<select></select>'],
            ['select{appearance:env(SAFE-AREA-INSET-TOP,base-select)}', '<select></select>'],
            ['select{appearance:env(safe-area-inset-top 0,base-select)}', '<select></select>'],
            [
                'select{appearance:attr(data-app type(<custom-ident>+),auto)}',
                '<select data-app="base-select"></select>',
            ],
        ] as [$computedSourceCss, $computedSourceMarkup]
    ) {
        $css = $computedSourceCss
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $computedSourceMarkup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    $cyclicFallback = 'select{--a:var(--b,base-select);--b:var(--a);appearance:var(--a,auto)}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $cyclicFallbackResult = CssScrub::scrubGenerated(
        $cyclicFallback,
        [],
        false,
        '<select></select>',
    );
    assert_eq($cyclicFallback, $cyclicFallbackResult['css']);
    assert_eq([], $cyclicFallbackResult['removals']);

    foreach (
        [
            [
                'select{appearance:attr(data-app type(<custom-ident>),base-select)}',
                '<select data-app="1px"></select>',
            ],
            [
                '@property --a{syntax:"<custom-ident>";inherits:false;initial-value:base-select}'
                    . '.p{--a:auto}select{appearance:var(--a)}',
                '<div class="p"><select></select></div>',
            ],
            [
                'select{appearance:base-select !\69mportant}select{appearance:auto}',
                '<select></select>',
            ],
            [
                '.p{appearance:base-select}.p select{appearance:inherit}',
                '<div class="p"><select></select></div>',
            ],
            [
                '.p{--a:base-select}.p select{--a:revert;appearance:var(--a)}',
                '<div class="p"><select></select></div>',
            ],
            [
                '@property --a{syntax:"<custom-ident>";inherits:false;initial-value:base-select}'
                    . 'select{--a:1px;appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"<length>";inherits:false;initial-value:1em}'
                    . '.p{--a:base-select}.p select{appearance:var(--a,auto)}',
                '<div class="p"><select></select></div>',
            ],
            [
                '@property --a{syntax:"<custom-ident>";inherits:false;initial-value:base-select}'
                    . '.p{--a:auto}.p select{--a:revert;appearance:var(--a)}',
                '<div class="p"><select></select></div>',
            ],
            [
                '@property --a{syntax:"<custom-ident>+";inherits:false;initial-value:base-select}'
                    . 'select{--a:base-select;appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"*";inherits:false;initial-value:var(--x)}'
                    . '.p{--a:base-select}.p select{appearance:var(--a,auto)}',
                '<div class="p"><select></select></div>',
            ],
            [
                '@property --a{syntax:"base-select | <length>";inherits:false;'
                    . 'initial-value:base-select}select{--a:calc(1px 1px);appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"base-select | <length>";inherits:false;'
                    . 'initial-value:base-select}select{--a:calc(1px * 1px);appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"base-select | <color>";inherits:false;'
                    . 'initial-value:base-select}select{--a:#12345;appearance:var(--a)}',
                '<select></select>',
            ],
            [
                '@property --a{syntax:"base-select | <color>";inherits:false;'
                    . 'initial-value:base-select}select{--a:rgb(1,);appearance:var(--a)}',
                '<select></select>',
            ],
        ] as [$unsafeComputedCss, $unsafeComputedMarkup]
    ) {
        $css = $unsafeComputedCss
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $unsafeComputedMarkup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    foreach (
        [
            '@property --a{syntax:"<length>";inherits:false;initial-value:base-select}'
                . 'select{appearance:var(--a,auto)}',
            'select{appearance:env(safe-area-inset-top,base-select)}',
            'select{appearance:attr(data-app type(<number>),base-select)}',
            'select{appearance:attr(data-app type(<length>),base-select)}',
            '@property --a{syntax:"*";inherits:false}'
                . '.p{--a:base-select}.p select{appearance:var(--a,auto)}',
            'select{appearance:env(safe-area-max-inset-top,base-select)}',
            'select{appearance:env(preferred-text-scale,base-select)}',
            '@property --a{syntax:"base-select | <length>+";inherits:false;'
                . 'initial-value:base-select}select{--a:calc(1px + 1px);appearance:var(--a)}',
            '@media n\6ft all{@property --a{syntax:"base-select | auto";inherits:false;'
                . 'initial-value:base-select}}.p{--a:auto}.p select{appearance:var(--a)}',
            '@media n/**/ot all, not all{@property --a{syntax:"base-select | auto";inherits:false;'
                . 'initial-value:base-select}}.p{--a:auto}.p select{appearance:var(--a)}',
            '@property --a{syntax:"<color>";inherits:false;initial-value:red}'
                . '.p{--a:base-select}.p select{appearance:var(--a)}',
            'select{appearance:attr(data-app type(<color>),base-select)}',
            '@property --a{syntax:"base-select | <length>";inherits:false;'
                . 'initial-value:base-select}select{--a:calc(pi * 1px);appearance:var(--a)}',
            '@property --a{syntax:"base-select | <color>";inherits:false;'
                . 'initial-value:base-select}select{--a:rebeccapurple;appearance:var(--a)}',
            '@property --a{syntax:"base-select | <color>";inherits:false;'
                . 'initial-value:base-select}select{--a:CanvasText;appearance:var(--a)}',
            '@property --a{syntax:"base-select | <color>";inherits:false;'
                . 'initial-value:base-select}select{--a:color-mix(in srgb,red,blue);appearance:var(--a)}',
        ] as $safeComputedCss
    ) {
        $css = $safeComputedCss
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $markup = match (true) {
            str_contains($safeComputedCss, 'type(<number>)') => '<select data-app="1"></select>',
            str_contains($safeComputedCss, 'type(<length>)') =>
                '<select data-app="calc(1px + 1px)"></select>',
            str_contains($safeComputedCss, 'type(<color>)') => '<select data-app="red"></select>',
            default => '<select></select>',
        };
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_eq($css, $result['css'], $css);
        assert_eq([], $result['removals'], $css);
    }

    $impliedMedia = '@media(min-width:100px){.x::before{content:"20755"}}'
        . '@media(min-width:300px){.x::after{content:"50199"}}'
        . '@media(min-width:200px){.x::before{content:"Order "}}';
    $impliedMediaResult = CssScrub::scrubGenerated(
        $impliedMedia,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_eq($impliedMedia, $impliedMediaResult['css']);
    assert_eq([], $impliedMediaResult['removals']);

    foreach (['width', 'height'] as $dimension) {
        $rangeMedia = '@media(' . $dimension . ' >= 100px){.x::before{content:"20755"}}'
            . '@media(' . $dimension . ' >= 300px){.x::after{content:"50199"}}'
            . '@media(' . $dimension . ' >= 200px){.x::before{content:"Order "}}';
        $rangeResult = CssScrub::scrubGenerated(
            $rangeMedia,
            [],
            false,
            '<span class="x"></span>',
        );
        assert_eq($rangeMedia, $rangeResult['css'], $dimension);
        assert_eq([], $rangeResult['removals'], $dimension);
    }

    $overflow = 'select{appearance:auto}'
        . '@supports(display:inline){select{appearance:base-select}}';
    foreach (['block', 'flex', 'grid', 'flow-root', 'inline-block', 'inline-flex',
        'inline-grid', 'list-item', 'table', 'table-cell', 'table-row', 'contents'] as $display
    ) {
        $overflow .= '@supports not (display:' . $display . '){select{appearance:auto}}';
    }
    $overflow .= 'select::before{content:"20755"}select::after{content:"50199"}';
    $overflowResult = CssScrub::scrubGenerated($overflow, [], false, '<select></select>');
    assert_true(
        !(str_contains($overflowResult['css'], '20755')
            && str_contains($overflowResult['css'], '50199')),
    );
    assert_eq(1, count($overflowResult['removals']));

    $tripleOverflow = '.x{display:list-item}'
        . '@supports(color:red){.x::marker{content:"207 "}}'
        . '@supports(color:blue){.x::before{content:"555 "}}'
        . '@supports(color:green){.x::after{content:"0199"}}';
    foreach (['block', 'flex', 'grid', 'flow-root', 'inline-block', 'inline-flex',
        'inline-grid', 'list-item', 'table', 'table-cell', 'table-row', 'contents'] as $display
    ) {
        $tripleOverflow .= '@supports not (display:' . $display
            . '){.x::marker{content:"Order "}}';
    }
    $tripleOverflowResult = CssScrub::scrubGenerated(
        $tripleOverflow,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_true(
        !(str_contains($tripleOverflowResult['css'], '207 ')
            && str_contains($tripleOverflowResult['css'], '0199')),
    );
    assert_eq(1, count($tripleOverflowResult['removals']));

    $strictlyDisjoint = '@media(width < 300px){.x::before{content:"20755"}}'
        . '@media(width >= 300px){.x::after{content:"50199"}}';
    $strictResult = CssScrub::scrubGenerated(
        $strictlyDisjoint,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_eq($strictlyDisjoint, $strictResult['css']);
    assert_eq([], $strictResult['removals']);

    $equalityPhone = '@media(width <= 300px){.x::before{content:"20755"}'
        . '.x::after{content:"50199"}}'
        . '@media(width < 300px){.x::before{content:"Order "}}';
    $equalityResult = CssScrub::scrubGenerated(
        $equalityPhone,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_true(
        !(str_contains($equalityResult['css'], '20755')
            && str_contains($equalityResult['css'], '50199')),
    );
    assert_eq(1, count($equalityResult['removals']));

    foreach ([
        '@media(orientation:landscape) and (width <= 300px)'
            . '{.x::before{content:"20755"}}@media(height >= 300px)'
            . '{.x::after{content:"50199"}}',
        '@media(width < 3e2px){.x::before{content:"20755"}}'
            . '@media(width >= 300px){.x::after{content:"50199"}}',
        '@media(width < .5px){.x::before{content:"20755"}}'
            . '@media(width >= +.5px){.x::after{content:"50199"}}',
    ] as $equivalentMedia) {
        $result = CssScrub::scrubGenerated(
            $equivalentMedia,
            [],
            false,
            '<span class="x"></span>',
        );
        assert_eq($equivalentMedia, $result['css'], $equivalentMedia);
        assert_eq([], $result['removals'], $equivalentMedia);
    }

    $lateTriple = '.x{display:list-item}';
    for ($index = 1; $index <= 28; $index++) {
        $lateTriple .= '@supports not (color:#' . str_pad(dechex($index), 6, '0', STR_PAD_LEFT)
            . '){.x::marker{content:"Order "!important}}';
    }
    $lateTriple .= '@supports(display:block){.x::marker{content:"207 "}}'
        . '@supports(display:flex){.x::before{content:"555 "}}'
        . '@supports(display:grid){.x::after{content:"0199"}}';
    $lateTripleResult = CssScrub::scrubGenerated(
        $lateTriple,
        [],
        false,
        '<span class="x"></span>',
    );
    assert_true(
        !(str_contains($lateTripleResult['css'], '207 ')
            && str_contains($lateTripleResult['css'], '0199')),
    );
    assert_eq(1, count($lateTripleResult['removals']));
});

test('css contact scrub canonicalizes equivalent selector state owners', function (): void {
    foreach (
        [
            '.x.a:hover::before{content:"20755"}.a.x:not(:hover)::after{content:"50199"}',
            'span.x:hover::before{content:"20755"}.x:not(:hover)::after{content:"50199"}',
            '*.x:hover::before{content:"20755"}.x:not(:hover)::after{content:"50199"}',
            '[data-a][data-b]:hover::before{content:"20755"}'
                . '[data-b][data-a]:not(:hover)::after{content:"50199"}',
        ] as $css
    ) {
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<span class="x a" data-a data-b></span>',
        );
        assert_eq($css, $result['css'], $css);
        assert_eq([], $result['removals'], $css);
    }
});

test('css contact scrub does not conflate selector state on different elements', function (): void {
    foreach (
        [
            [
                '.control:disabled~.x::before{content:"20755"}'
                    . '.control:enabled~.x::after{content:"50199"}',
                '<button class="control" disabled></button><button class="control"></button>'
                    . '<span class="x"></span>',
            ],
            [
                'button.control:hover~.x::before{content:"20755"}'
                    . 'input.control:not(:hover)~.x::after{content:"50199"}',
                '<button class="control"></button><input class="control"><span class="x"></span>',
            ],
        ] as [$css, $markup]
    ) {
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub does not infer state-owner identity from unsupported attributes', function (): void {
    foreach (
        [
            [
                '[data-x=":hover"]:disabled~.x::before{content:"20755"}'
                    . '[data-x=":hover"]:enabled~.x::after{content:"50199"}',
                '<i data-x=""></i><button data-x=":hover" disabled></button>'
                    . '<button data-x=":hover"></button><span class="x"></span>',
            ],
            [
                '[type=button]:disabled~.x::before{content:"20755"}'
                    . '[type=button]:enabled~.x::after{content:"50199"}',
                '<button type="button" disabled></button><button type="BUTTON"></button>'
                    . '<span class="x"></span>',
            ],
        ] as [$css, $markup]
    ) {
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub broadens unsupported predicates on non-subject compounds', function (): void {
    foreach (
        [
            [
                '.row:not(:last-child) .x::before{content:"20755"}'
                    . '.row:not(:last-child) .x::after{content:"50199"}',
                '<section><div class="row"><span class="x"></span></div>'
                    . '<div class="row"></div></section>',
            ],
            [
                '[data-x=foo i]~.x::before{content:"20755"}'
                    . '[data-x=foo i]~.x::after{content:"50199"}',
                '<i data-x="FOO"></i><span class="x"></span>',
            ],
            [
                '[type=button]~.x::before{content:"20755"}'
                    . '[type=button]~.x::after{content:"50199"}',
                '<button type="BUTTON"></button><span class="x"></span>',
            ],
            [
                '[type=button].x::before{content:"20755"}'
                    . '[type=button].x::after{content:"50199"}',
                '<button type="BUTTON" class="x"></button>',
            ],
        ] as [$css, $markup]
    ) {
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub does not parse pseudo text inside attribute strings', function (): void {
    foreach ([':last-child', ':empty', ':has(+b)'] as $value) {
        $css = '[data-x="' . $value . '"].x::before{content:"20755"}'
            . '[data-x="' . $value . '"].x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<span class="x" data-x="' . $value . '"></span><b></b>',
        );
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $value);
        assert_eq(1, count($result['removals']), $value);
    }
});

test('css contact scrub preserves escaped identifier characters while parsing selectors', function (): void {
    foreach (
        [
            ['#foo\\ bar', 'foo bar'],
            ['#foo\\+bar', 'foo+bar'],
            ['#foo\\:bar', 'foo:bar'],
            ['#foo\\>bar', 'foo>bar'],
        ] as [$selector, $id]
    ) {
        $css = $selector . '::before{content:"20755"}'
            . $selector . '::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<span id="' . htmlspecialchars($id, ENT_QUOTES) . '"></span>',
        );
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $selector);
        assert_eq(1, count($result['removals']), $selector);
    }
});

test('css contact scrub broadens unsupported selector escapes', function (): void {
    foreach (
        [
            ['[data-x=foo\\ bar].x', '<span class="x" data-x="foo bar"></span>'],
            ['[data-x=foo\\]bar].x', '<span class="x" data-x="foo]bar"></span>'],
            ['[data\\+x].x', '<span class="x" data+x></span>'],
            ['[data\\+x]+.x', '<i data+x></i><span class="x"></span>'],
            ['[data-x=foo\\ bar]+.x', '<i data-x="foo bar"></i><span class="x"></span>'],
            [
                '.a>[data\\+x]>.x',
                '<div class="a"><i data+x><span class="x"></span></i></div>',
            ],
            ['foo\\+bar.x', '<foo+bar class="x"></foo+bar>'],
            ['foo\\.bar.x', '<foo.bar class="x"></foo.bar>'],
        ] as [$selector, $markup]
    ) {
        $css = $selector . '::before{content:"20755"}'
            . $selector . '::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $selector);
        assert_eq(1, count($result['removals']), $selector);
    }
});

test('css contact scrub follows html case folding for dir selectors', function (): void {
    foreach (['=', '|=', '^=', '$=', '*='] as $operator) {
        $value = $operator === '$=' ? 'tr' : ($operator === '*=' ? 'lt' : 'ltr');
        $css = '[dir' . $operator . $value . '].x::before{content:"20755"}'
            . '[dir' . $operator . $value . '].x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, '<span class="x" dir="LTR"></span>');

        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    $rel = '[rel=next].x::before{content:"20755"}[rel=next].x::after{content:"50199"}';
    $relResult = CssScrub::scrubGenerated($rel, [], false, '<a class="x" rel="NEXT"></a>');
    assert_true(!(str_contains($relResult['css'], '20755') && str_contains($relResult['css'], '50199')));
    assert_eq(1, count($relResult['removals']));

    foreach (
        [
            ['method', 'post', '<form class="x" method="POST"></form>'],
            ['hreflang', 'en', '<a class="x" hreflang="EN"></a>'],
        ] as [$attribute, $value, $markup]
    ) {
        $css = '[' . $attribute . '=' . $value . '].x::before{content:"20755"}'
            . '[' . $attribute . '=' . $value . '].x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub computes specificity without selector syntax inside tokens', function (): void {
    foreach (
        [
            [
                '*.x::before{content:"alpha"}.x::before{content:"20755"}.x::after{content:"50199"}',
                '<span class="x"></span>',
            ],
            [
                '.foo\\+bar::before{content:"alpha"}'
                    . '[class~="foo+bar"]::before{content:"20755"}'
                    . '[class~="foo+bar"]::after{content:"50199"}',
                '<span class="foo+bar"></span>',
            ],
            [
                '[data-x=":not(#a)"].x::before{content:"alpha"}'
                    . '[data-y].x::before{content:"20755"}'
                    . '.x::after{content:"50199"}',
                '<span class="x" data-x=":not(#a)" data-y></span>',
            ],
        ] as [$css, $markup]
    ) {
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub counts types after sibling combinators in specificity', function (): void {
    foreach (['+', '~'] as $combinator) {
        $css = '.a' . $combinator . 'div.x::before{content:"20755"}'
            . '.a' . $combinator . '.x::before{content:"alpha"}'
            . '.x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<span class="a"></span><div class="x"></div>',
        );
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub includes nth-child of-list specificity', function (): void {
    foreach (
        [':nth-child(1 of #target)', ':nth-child(1 o\\66  #target)', ':n\\74h-child(1 of #target)']
        as $nth
    ) {
        $css = '.x' . $nth . '::before{content:"20755"}'
            . '.x.y.z::before{content:"alpha"}'
            . '.x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<div><span id="target" class="x y z"></span></div>',
        );

        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $nth);
        assert_eq(1, count($result['removals']), $nth);
    }
});

test('css contact scrub respects where zero specificity around nested functions', function (): void {
    $css = '.x:where(:nth-child(1 of #target))::before{content:"20755"}'
        . '.x.y::before{content:"alpha"}'
        . '.x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<div><span id="target" class="x y"></span></div>',
    );

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css contact scrub ignores invalid branches in forgiving selector specificity', function (): void {
    $css = '.x:is(.valid,#target:bogus)::before{content:"20755"}'
        . '.x.y.z::before{content:"alpha"}'
        . '.x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<span id="target" class="x valid y z"></span>',
    );

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css contact scrub attaches is and where subjects to complex branch subjects', function (): void {
    foreach (['is', 'where'] as $function) {
        $selector = '.x:' . $function . '([data-x]>.y)';
        $css = $selector . '::before{content:"20755"}'
            . $selector . '::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<i data-x><span class="x y"></span></i>',
        );
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub intersects nested complex is and where ancestor subjects', function (): void {
    foreach (['is', 'where'] as $function) {
        $selector = '.x:' . $function . '(.a>.y:' . $function . '(.b>.z))';
        $css = $selector . '::before{content:"20755"}'
            . $selector . '::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<i class="a b"><span class="x y z"></span></i>',
        );
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub intersects divergent nested complex selector ancestry', function (): void {
    $selector = '.x:is(.a>.y:is(.b .z))';
    $css = $selector . '::before{content:"20755"}'
        . '.x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<section class="b"><i class="a"><span class="x y z"></span></i></section>',
    );

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));
});

test('css contact scrub intersects functional selectors on ancestor compounds', function (): void {
    $selector = '.p>.a:is(.b .c)>.x';
    $css = $selector . '::before{content:"20755"}'
        . '.x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<section class="b"><i class="p"><div class="a c"><span class="x"></span></div></i></section>',
    );

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));

    foreach (
        [
            ['.a:not(:is(.blocked))>.x', '<div class="a"><span class="x"></span></div>'],
            ['.a:has(:is(.child))>.x', '<div class="a"><i class="child"></i><span class="x"></span></div>'],
            [
                '.a:has(.p>.q:is(.b .c))>.x',
                '<div class="a"><section class="b"><i class="p"><span class="q c"></span></i></section>'
                    . '<span class="x"></span></div>',
            ],
        ] as [$nestedSelector, $markup]
    ) {
        $nestedCss = $nestedSelector . '::before{content:"20755"}'
            . '.x::after{content:"50199"}';
        $nestedResult = CssScrub::scrubGenerated($nestedCss, [], false, $markup);
        assert_true(!(
            str_contains($nestedResult['css'], '20755')
            && str_contains($nestedResult['css'], '50199')
        ), $nestedSelector);
        assert_eq(1, count($nestedResult['removals']), $nestedSelector);
    }
});

test('css contact scrub treats comments as nth-child grammar whitespace', function (): void {
    $css = '.x:nth-child(1/**/of/**/#target)::before{content:"20755"}'
        . '.x.y.z::before{content:"alpha"}'
        . '.x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<div><span id="target" class="x y z"></span></div>',
    );

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));
});

test('css contact scrub uses current browser pseudo support in forgiving specificity', function (): void {
    foreach (
        [
            'popover-open', 'active-view-transition', 'interest-source', 'interest-target',
            'current', 'future', 'past', 'target-current',
        ] as $supported
    ) {
        $css = '.x:is(.valid,#target:' . $supported . ')::before{content:"20755"}'
            . '.x.y.z::before{content:"alpha"}'
            . '.x::after{content:"50199"}';
        $result = CssScrub::scrubGenerated(
            $css,
            [],
            false,
            '<span id="target" class="x valid y z"></span>',
        );
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $supported);
        assert_eq(1, count($result['removals']), $supported);
    }

    foreach (['target-within', 'nth-col(1)', 'left'] as $unsupportedPseudo) {
        $unsupported = '.x:is(.valid,#target:' . $unsupportedPseudo . ')::before{content:"20755"}'
            . '.x.y.z::before{content:"alpha"}'
            . '.x::after{content:"50199"}';
        $unsupportedResult = CssScrub::scrubGenerated(
            $unsupported,
            [],
            false,
            '<span id="target" class="x valid y z"></span>',
        );
        assert_eq($unsupported, $unsupportedResult['css'], $unsupportedPseudo);
        assert_eq([], $unsupportedResult['removals'], $unsupportedPseudo);
    }
});

test('css contact scrub follows html case folding for boolean attributes', function (): void {
    $css = '[checked=checked].x::before{content:"20755"}'
        . '[checked=checked].x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated(
        $css,
        [],
        false,
        '<input type="checkbox" class="x" checked="CHECKED">',
    );

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));
});

test('css contact scrub follows browser pseudo painting for form controls', function (): void {
    foreach (
        [
            ['input', '<input type="text">'],
            ['input', '<input type="number">'],
            ['input', '<input type="email">'],
            ['input', '<input type="color">'],
            ['input', '<input type="button">'],
            ['input', '<input type="submit">'],
            ['input', '<input type="image">'],
            ['textarea', '<textarea></textarea>'],
            ['select', '<select><option>Choice</option></select>'],
        ] as [$selector, $markup]
    ) {
        $css = $selector . '::before{content:"20755"}'
            . $selector . '::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_eq($css, $result['css'], $markup);
        assert_eq([], $result['removals'], $markup);
    }

    foreach (
        [
            ['input', '<input type="checkbox">'],
            ['input', '<input type="radio">'],
            ['input', '<input type="range">'],
            ['button', '<button></button>'],
        ] as [$selector, $markup]
    ) {
        $css = $selector . '::before{content:"20755"}'
            . $selector . '::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $markup);
        assert_eq(1, count($result['removals']), $markup);
    }

    $customSelectCss = 'select{appearance:base-select}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $customSelect = CssScrub::scrubGenerated(
        $customSelectCss,
        [],
        false,
        '<select></select>',
    );
    assert_true(!(
        str_contains($customSelect['css'], '20755')
        && str_contains($customSelect['css'], '50199')
    ));
    assert_eq(1, count($customSelect['removals']));

    foreach (
        [
            'select{appearance:var(--missing,base-select)}',
            'select{appearance:var(--missing,var(--also-missing,base-select))}',
            'select{--mode:base-select;appearance:var(--mode)}',
            'select{--mode:base-select;appearance:var(/*x*/--mode)}',
            'select{appearance:base\\2d select}',
        ] as $appearance
    ) {
        $css = $appearance
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, '<select></select>');
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    $overriddenSelect = 'select{appearance:base-select;appearance:auto}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $overriddenSelectResult = CssScrub::scrubGenerated(
        $overriddenSelect,
        [],
        false,
        '<select></select>',
    );
    assert_eq($overriddenSelect, $overriddenSelectResult['css']);
    assert_eq([], $overriddenSelectResult['removals']);

    $conditionalVariables = 'select{appearance:var(--a)}'
        . '@supports(display:grid){select{--a:var(--b)}}'
        . '@supports(display:flex){select{--b:var(--c)}}'
        . '@supports(display:block){select{--c:base-select}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $conditionalResult = CssScrub::scrubGenerated(
        $conditionalVariables,
        [],
        false,
        '<select></select>',
    );
    assert_true(!(
        str_contains($conditionalResult['css'], '20755')
        && str_contains($conditionalResult['css'], '50199')
    ));
    assert_eq(1, count($conditionalResult['removals']));

    $impossibleVariables = 'select{appearance:var(--a,auto)}'
        . '@media(orientation:portrait){select{--a:var(--b)}}'
        . '@media(min-width:1000px){select{--b:var(--c)}}'
        . '@media(max-height:500px){select{--c:base-select}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $impossibleResult = CssScrub::scrubGenerated(
        $impossibleVariables,
        [],
        false,
        '<select></select>',
    );
    assert_eq($impossibleVariables, $impossibleResult['css']);
    assert_eq([], $impossibleResult['removals']);

    $landscapeBoundary = 'select{appearance:var(--a,auto)}'
        . '@media(orientation:landscape){select{--a:var(--b)}}'
        . '@media(min-height:1000px){select{--b:var(--c)}}'
        . '@media(max-width:1000px){select{--c:base-select}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $landscapeResult = CssScrub::scrubGenerated(
        $landscapeBoundary,
        [],
        false,
        '<select></select>',
    );
    assert_eq($landscapeBoundary, $landscapeResult['css']);
    assert_eq([], $landscapeResult['removals']);

    $fourConditions = 'select{appearance:var(--a)}'
        . '@supports not (display:grid){select{--pre0:x}}'
        . '@supports not (display:flex){select{--pre1:x}}'
        . '@supports not (display:block){select{--pre2:x}}'
        . '@supports not (width:1px){select{--pre3:x}}'
        . '@supports(display:grid){select{--a:var(--b)}}'
        . '@supports(display:flex){select{--b:var(--c)}}'
        . '@supports(display:block){select{--c:var(--d)}}'
        . '@supports(width:1px){select{--d:base-select}}'
        . '@supports not ((display:grid)){select{--post0:x}}'
        . '@supports not ((display:flex)){select{--post1:x}}'
        . '@supports not ((display:block)){select{--post2:x}}'
        . '@supports not ((width:1px)){select{--post3:x}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $fourConditionResult = CssScrub::scrubGenerated(
        $fourConditions,
        [],
        false,
        '<select></select>',
    );
    assert_true(!(
        str_contains($fourConditionResult['css'], '20755')
        && str_contains($fourConditionResult['css'], '50199')
    ));
    assert_eq(1, count($fourConditionResult['removals']));

    $inlineImportantCss = '@layer base{select{appearance:auto!important}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $inlineImportantResult = CssScrub::scrubGenerated(
        $inlineImportantCss,
        [],
        false,
        '<select style="appearance:base-select!important"></select>',
    );
    assert_true(!(
        str_contains($inlineImportantResult['css'], '20755')
        && str_contains($inlineImportantResult['css'], '50199')
    ));
    assert_eq(1, count($inlineImportantResult['removals']));

    foreach (
        [
            'select{appearance:var(--A);--A:base-select;--a:auto}',
            'select{--a:var(--a);appearance:var(--a,base-select)}',
            'select{--a:initial;appearance:var(--a,base-select)}',
        ] as $customPropertyCss
    ) {
        $css = $customPropertyCss
            . 'select::before{content:"20755"}'
            . 'select::after{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, '<select></select>');
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    $relevantDecoys = 'select{appearance:var(--a)}'
        . '@supports not (display:grid){select{--a:auto}}'
        . '@supports not (display:flex){select{--b:auto}}'
        . '@supports not (display:block){select{--c:auto}}'
        . '@supports not (width:1px){select{--d:auto}}'
        . '@supports(display:grid){select{--a:var(--b)}}'
        . '@supports(display:flex){select{--b:var(--c)}}'
        . '@supports(display:block){select{--c:var(--d)}}'
        . '@supports(width:1px){select{--d:base-select}}'
        . '@supports not ((display:grid)){select{--a:auto}}'
        . '@supports not ((display:flex)){select{--b:auto}}'
        . '@supports not ((display:block)){select{--c:auto}}'
        . '@supports not ((width:1px)){select{--d:auto}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $relevantDecoyResult = CssScrub::scrubGenerated(
        $relevantDecoys,
        [],
        false,
        '<select></select>',
    );
    assert_true(!(
        str_contains($relevantDecoyResult['css'], '20755')
        && str_contains($relevantDecoyResult['css'], '50199')
    ));
    assert_eq(1, count($relevantDecoyResult['removals']));

    $mediaAlternatives = 'select{appearance:var(--a)}'
        . '@media (orientation:landscape),(min-width:0px){select{--a:var(--b)}}'
        . '@media (orientation:portrait),(min-width:0px){select{--b:base-select}}'
        . 'select::before{content:"20755"}'
        . 'select::after{content:"50199"}';
    $mediaAlternativeResult = CssScrub::scrubGenerated(
        $mediaAlternatives,
        [],
        false,
        '<select></select>',
    );
    assert_true(!(
        str_contains($mediaAlternativeResult['css'], '20755')
        && str_contains($mediaAlternativeResult['css'], '50199')
    ));
    assert_eq(1, count($mediaAlternativeResult['removals']));
});

test('css contact scrub composes visible marker text with element pseudos', function (): void {
    $css = '.x{list-style-position:inside}'
        . '.x::marker{content:"20755"}'
        . '.x::before{content:"50199"}';
    $result = CssScrub::scrubGenerated($css, [], false, '<ul><li class="x"></li></ul>');

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));

    foreach (['inline list-item', 'var(--missing,inline list-item)', 'list\\2d item'] as $display) {
        $css = '.x{display:' . $display . ';list-style-position:inside}'
            . '.x::marker{content:"20755"}'
            . '.x::before{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, '<div class="x"></div>');
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }

    foreach (
        [
            ['.x{display:list-item;display:block}', '<div class="x"></div>'],
            ['li{display:block}', '<ul><li></li></ul>'],
        ] as [$displayCss, $markup]
    ) {
        $css = $displayCss
            . ($markup === '<ul><li></li></ul>' ? 'li' : '.x') . '::marker{content:"20755"}'
            . ($markup === '<ul><li></li></ul>' ? 'li' : '.x') . '::before{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_eq($css, $result['css'], $markup);
        assert_eq([], $result['removals'], $markup);
    }

    $wideKeywordCases = [
        ['li{display:revert;list-style-position:inside}', '<ul><li></li></ul>', 'li'],
        [
            '.parent{display:list-item}.x{display:inherit;list-style-position:inside}',
            '<div class="parent"><div class="x"></div></div>',
            '.x',
        ],
    ];
    foreach ($wideKeywordCases as [$displayCss, $markup, $selector]) {
        $css = $displayCss
            . $selector . '::marker{content:"20755"}'
            . $selector . '::before{content:"50199"}';
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub treats cascade layers as simultaneously active', function (): void {
    $css = '@layer base,override;'
        . '@layer base{.x::before{content:"20755"}}'
        . '@layer override{.x::before{content:"safe"}}'
        . '.x::after{content:"50199"}';
    $result = CssScrub::scrubGenerated($css, [], false, '<span class="x"></span>');

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);

    foreach (
        [
            '@layer base,override;'
                . '@layer override{.x::before{content:"20755"}}'
                . '@layer base{.x::before{content:"safe"}}'
                . '.x::after{content:"50199"}',
            '@layer safe;'
                . '.x::before{content:"20755"}'
                . '@layer safe{.x::before{content:"safe"}}'
                . '.x::after{content:"50199"}',
        ] as $unsafeLayerCss
    ) {
        $unsafeLayerResult = CssScrub::scrubGenerated(
            $unsafeLayerCss,
            [],
            false,
            '<span class="x"></span>',
        );
        assert_true(!(
            str_contains($unsafeLayerResult['css'], '20755')
            && str_contains($unsafeLayerResult['css'], '50199')
        ), $unsafeLayerCss);
        assert_eq(1, count($unsafeLayerResult['removals']), $unsafeLayerCss);
    }

    foreach (
        [
            '.d{--note:"@layer override,base;"}'
                . '@layer base,override;'
                . '@layer override{.x::before{content:"20755"}}'
                . '@layer base{.x::before{content:"safe"}}'
                . '.x::after{content:"50199"}',
            '.x::before{content:"20755"}'
                . '@layer{.x::before{content:"safe"}}'
                . '.x::after{content:"50199"}',
            '@layer framework{@layer base,override;'
                . '@layer override{.x::before{content:"20755"}}'
                . '@layer base{.x::before{content:"safe"}}}'
                . '.x::after{content:"50199"}',
            '@layer override{.x::before{content:"safe"}}'
                . '@layer base,override;'
                . '@layer base{.x::before{content:"20755"}}'
                . '.x::after{content:"50199"}',
            '@layer framework{.x::before{content:"20755"}'
                . '@layer child{.x::before{content:"safe"}}}'
                . '.x::after{content:"50199"}',
            '@layer foo,foo\2e bar;'
                . '@layer foo\2e bar{.x::before{content:"20755"}}'
                . '@layer foo{@layer bar{.x::before{content:"safe"}}}'
                . '.x::after{content:"50199"}',
            ".d{--x:\"bad\n}"
                . '@layer base,override;'
                . '@layer override{.x::before{content:"20755"}}'
                . '@layer base{.x::before{content:"safe"}}'
                . '.x::after{content:"50199"}',
        ] as $tokenizedLayerCss
    ) {
        $tokenizedResult = CssScrub::scrubGenerated(
            $tokenizedLayerCss,
            [],
            false,
            '<span class="x"></span>',
        );
        assert_true(!(
            str_contains($tokenizedResult['css'], '20755')
            && str_contains($tokenizedResult['css'], '50199')
        ), $tokenizedLayerCss);
        assert_eq(1, count($tokenizedResult['removals']), $tokenizedLayerCss);
    }

    $revertLayer = '@layer base,override;'
        . '@layer base{.x{display:list-item}}'
        . '@layer override{.x{display:revert-layer}}'
        . '.x::marker{content:"20755"}'
        . '.x::before{content:"50199"}';
    $revertLayerResult = CssScrub::scrubGenerated(
        $revertLayer,
        [],
        false,
        '<div class="x"></div>',
    );
    assert_true(!(
        str_contains($revertLayerResult['css'], '20755')
        && str_contains($revertLayerResult['css'], '50199')
    ));
    assert_eq(1, count($revertLayerResult['removals']));
});

test('css contact scrub preserves non-painting svg pseudos', function (): void {
    $css = 'svg{overflow:visible;width:0;height:0}'
        . 'svg::before{content:"20755";position:absolute}'
        . 'svg::after{content:"50199";position:absolute}';
    $result = CssScrub::scrubGenerated($css, [], false, '<svg></svg>');

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('css contact scrub treats media query lists as alternatives', function (): void {
    foreach (
        [
            '@media print,screen{.x::before{content:"20755"}}'
                . '@media screen{.x::after{content:"50199"}}',
            '@media (prefers-color-scheme:dark),(hover:hover){.x::before{content:"20755"}}'
                . '@media (prefers-color-scheme:light),(hover:none){.x::after{content:"50199"}}',
            '@media not print{.x::before{content:"20755"}}'
                . '@media screen{.x::after{content:"50199"}}',
            '@media \\6e ot (hover:hover){.x::before{content:"20755"}}'
                . '@media (hover:none){.x::after{content:"50199"}}',
        ] as $css
    ) {
        $result = CssScrub::scrubGenerated($css, [], false, '<span class="x"></span>');
        assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')), $css);
        assert_eq(1, count($result['removals']), $css);
    }
});

test('css contact scrub composes pseudo label text with a descendant control value', function (): void {
    $css = '.p::before{content:"20755"}';
    $markup = '<label class="p"><input type="number" value="50199"></label>';

    $result = CssScrub::scrubGenerated($css, [], false, $markup);

    assert_eq('.p::before{}', $result['css']);
    assert_eq(1, count($result['removals']));

    $email = CssScrub::scrubGenerated(
        '.p::before{content:"fake@"}',
        [],
        false,
        '<label class="p"><input value="photo.png"></label>',
    );
    assert_eq('.p::before{}', $email['css']);
    assert_eq(1, count($email['removals']));

    foreach (
        [
            '<label class="p" for="external-input"></label>'
                . '<input id="external-input" value="photo.png">',
            '<span class="p" id="external-label"></span>'
                . '<input aria-labelledby="external-label" value="photo.png">',
            '<span class="p" id=external-label></span>'
                . '<input aria-labelledby=external-label value="photo.png">',
        ] as $associatedMarkup
    ) {
        $associated = CssScrub::scrubGenerated(
            '.p::before{content:"fake@"}',
            [],
            false,
            $associatedMarkup,
        );
        assert_eq('.p::before{}', $associated['css'], $associatedMarkup);
        assert_eq(1, count($associated['removals']), $associatedMarkup);
    }
});

test('css contact scrub does not let a nonmatching last-child rule hide a harmful fallback', function (): void {
    $css = '.x::before{content:"20755"}'
        . '.x:last-child::before{content:"alpha"}'
        . '.x::after{content:"50199"}';
    $markup = '<div><span class="x"></span><b>later</b></div>';

    $result = CssScrub::scrubGenerated($css, [], false, $markup);

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));
});

test('css contact scrub evaluates structural predicates inside not with inverted semantics', function (): void {
    $css = '.x::before{content:"20755"}'
        . '.x:not(:last-child)::before{content:"alpha"}'
        . '.x::after{content:"50199"}';
    $markup = '<div><span class="x"></span></div>';

    $result = CssScrub::scrubGenerated($css, [], false, $markup);

    assert_true(!(str_contains($result['css'], '20755') && str_contains($result['css'], '50199')));
    assert_eq(1, count($result['removals']));

    $hiding = CssScrub::scrubContactHiding(
        '.gone{display:none}.gone:not(:last-child){display:block}',
        '<div><input id="order" type="number" value="2125550199">'
            . '<label class="gone" for="order">Order ID</label></div>',
        [],
    );
    assert_true(!str_contains($hiding['css'], 'display:none'));
    assert_eq(1, count($hiding['removals']));

    $cases = [
        ['.x:not(#x)::before{content:"alpha"}', '<span id="x" class="x"></span>'],
        ['.x:not(:first-of-type)::before{content:"alpha"}', '<span class="x"></span>'],
        ['.x:not(:empty)::before{content:"alpha"}', '<span class="x"></span>'],
        [
            '.x:not(:has(+ input))::before{content:"alpha"}',
            '<span class="x"></span><input>',
        ],
    ];
    foreach ($cases as [$override, $caseMarkup]) {
        $caseCss = '.x::before{content:"20755"}' . $override . '.x::after{content:"50199"}';
        $caseResult = CssScrub::scrubGenerated($caseCss, [], false, $caseMarkup);
        assert_true(!(
            str_contains($caseResult['css'], '20755')
            && str_contains($caseResult['css'], '50199')
        ), $override);
        assert_eq(1, count($caseResult['removals']), $override);
    }

    $statefulCases = [
        ['.x:hover::before{content:"alpha"}', '<span class="x"></span>'],
        ['.x:focus::before{content:"alpha"}', '<span class="x" tabindex="0"></span>'],
        ['.x:has(.child)::before{content:"alpha"}', '<span class="x"><b class="child"></b></span>'],
        ['.x:nth-child(odd)::before{content:"alpha"}', '<span class="x"></span>'],
        ['.x:disabled::before{content:"alpha"}', '<button class="x" disabled></button>'],
    ];
    foreach ($statefulCases as [$override, $caseMarkup]) {
        $caseCss = '.x::before{content:"20755"}' . $override . '.x::after{content:"50199"}';
        $caseResult = CssScrub::scrubGenerated($caseCss, [], false, $caseMarkup);
        assert_true(!(
            str_contains($caseResult['css'], '20755')
            && str_contains($caseResult['css'], '50199')
        ), $override);
        assert_eq(1, count($caseResult['removals']), $override);
    }
    $statefulHiding = CssScrub::scrubContactHiding(
        '.gone{display:none}.gone:hover{display:block}',
        '<label class="gone" for="order-state">Order ID</label>'
            . '<input id="order-state" type="number" value="2125550199">',
        [],
    );
    assert_true(!str_contains($statefulHiding['css'], 'display:none'));
    assert_eq(1, count($statefulHiding['removals']));

    $negatedState = CssScrub::scrubGenerated(
        '.x:not(:hover)::before{content:"20755"}.x::after{content:"50199"}',
        [],
        false,
        '<span class="x"></span>',
    );
    assert_true(!(
        str_contains($negatedState['css'], '20755')
        && str_contains($negatedState['css'], '50199')
    ));
    assert_eq(1, count($negatedState['removals']));

    $negatedStateHiding = CssScrub::scrubContactHiding(
        '.gone:not(:hover){display:none}',
        '<label class="gone" for="order-negated">Order ID</label>'
            . '<input id="order-negated" type="number" value="2125550199">',
        [],
    );
    assert_true(!str_contains($negatedStateHiding['css'], 'display:none'));
    assert_eq(1, count($negatedStateHiding['removals']));

    $separateOwners = CssScrub::scrubGenerated(
        'button:disabled~.x::before{content:"20755"}'
            . 'input:enabled~.x::after{content:"50199"}',
        [],
        false,
        '<button disabled></button><input><span class="x"></span>',
    );
    assert_true(!(
        str_contains($separateOwners['css'], '20755')
        && str_contains($separateOwners['css'], '50199')
    ));
    assert_eq(1, count($separateOwners['removals']));

    foreach (
        [
            [
                '.x:hover::before{content:"20755"}.x:not(:hover)::after{content:"50199"}',
                '<span class="x"></span>',
            ],
            [
                '.x:disabled::before{content:"20755"}.x:enabled::after{content:"50199"}',
                '<button class="x"></button>',
            ],
        ] as [$disjointStateCss, $disjointStateMarkup]
    ) {
        $disjointState = CssScrub::scrubGenerated(
            $disjointStateCss,
            [],
            false,
            $disjointStateMarkup,
        );
        assert_eq($disjointStateCss, $disjointState['css']);
        assert_eq([], $disjointState['removals']);
    }
});

test('css contact scrub excludes browser-inert descendants from generated text composition', function (): void {
    $css = '.x::before{content:"20755"}';
    $markups = [
        '<span class="x"><span hidden>50199</span></span>',
        '<span class="x"><span style="display:none">50199</span></span>',
        '<span class="x"><template>50199</template></span>',
        '<span class="x"><style>.safe{color:red}50199</style></span>',
        '<span class="x"><script>50199</script></span>',
        '<label class="x"><input hidden value="50199"></label>',
    ];
    foreach ($markups as $markup) {
        $result = CssScrub::scrubGenerated($css, [], false, $markup);
        assert_eq($css, $result['css'], $markup);
        assert_eq([], $result['removals'], $markup);
    }

    foreach (
        [
            ['.x::before{content:"20755"}', '<span class="x" style="display:none;display:block">50199</span>'],
            [
                '.x::before{content:"20755"}',
                '<div style="visibility:hidden"><span class="x" style="visibility:visible">50199</span></div>',
            ],
            ['.x::before{content:"20755"}', '<span class="x" hidden style="display:block">50199</span>'],
            [
                '@layer low,high;@layer high{.x{display:block}}'
                    . '@layer low{.x{display:none}}.x::before{content:"20755"}',
                '<span class="x">50199</span>',
            ],
            [
                '.x{display:none;all:initial}.x::before{content:"20755"}',
                '<span class="x">50199</span>',
            ],
        ] as [$visibleCss, $visibleMarkup]
    ) {
        $visibleResult = CssScrub::scrubGenerated($visibleCss, [], false, $visibleMarkup);
        assert_true(!str_contains($visibleResult['css'], '20755'), $visibleMarkup);
        assert_eq(1, count($visibleResult['removals']), $visibleMarkup);
    }

    $inheritedHiddenCss = '.p{visibility:hidden}.x{all:unset}.x::before{content:"20755"}';
    $inheritedHiddenResult = CssScrub::scrubGenerated(
        $inheritedHiddenCss,
        [],
        false,
        '<div class="p"><span class="x">50199</span></div>',
    );
    assert_eq($inheritedHiddenCss, $inheritedHiddenResult['css']);
    assert_eq([], $inheritedHiddenResult['removals']);

    $cssHidden = '.p::before{content:"20755"}.hidden{display:none}';
    $cssHiddenMarkup = '<label class="p"><input class="hidden" value="50199"></label>';
    assert_eq(
        $cssHidden,
        CssScrub::scrubGenerated($cssHidden, [], false, $cssHiddenMarkup)['css'],
    );

    foreach (
        [
            '<label class="p"><details><input value="50199"></details></label>',
            '<label class="p"><select multiple><option hidden>50199</option>'
                . '<option>Order</option></select></label>',
            '<label class="p"><select multiple><option hidden>50199</option></select></label>',
            '<label class="p"><select multiple><optgroup hidden><option>50199</option></optgroup>'
                . '<option>Order</option></select></label>',
        ] as $inertMarkup
    ) {
        $inertCss = '.p::before{content:"20755"}';
        assert_eq(
            $inertCss,
            CssScrub::scrubGenerated($inertCss, [], false, $inertMarkup)['css'],
            $inertMarkup,
        );
    }
});

test('css contact scrub preserves inert element content image payloads and disjoint selectors', function (): void {
    $cases = [
        '.ticket{content:"2075550199"}',
        '.x{content:url("/assets/call-207-555-0199.png")}',
        '.x:not(.a)::before{content:"20755"}.x.a::after{content:"50199"}',
    ];
    $markups = [
        '<span class="ticket">Order ID</span>',
        '<span class="x">Safe</span>',
        '<span class="x a"></span>',
    ];
    foreach ($cases as $index => $css) {
        assert_eq($css, CssScrub::scrubGenerated($css, [], false, $markups[$index])['css'], $css);
    }
});

test('css scrub recognizes CRLF continuations in quoted remote URLs', function (): void {
    $css = ".x{background:url(\"https://evil.example/a\\\r\n.png\");color:red}";

    $result = CssScrub::scrub($css);

    assert_eq('.x{color:red}', $result['css']);
    assert_eq(1, count($result['removals']));
});

test('css scrub preserves invalid unquoted URLs containing raw spaces', function (): void {
    $css = '.x{background:url(https://evil.example/a b.png);color:red}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('generated css cannot reveal hidden markup', function (): void {
    $css = '[hidden]{display:block;color:red}[hidden="until-found"]{display:grid;all:unset}'
        . '.safe{display:block}';

    $result = CssScrub::scrubHiddenReveals($css);

    assert_eq('[hidden]{color:red}[hidden="until-found"]{}.safe{display:block}', $result['css']);
    assert_eq(3, count($result['removals']));
    assert_eq('removed_hidden_state_reveal', $result['removals'][0]['disposition']);
    assert_eq([], CssScrub::scrubHiddenReveals($result['css'])['removals']);
});

test('generated css removes indirect content values it cannot ground', function (): void {
    $css = '.x{--a:"fake@example.com"}.x::before{content:var(--a);color:red}'
        . '.x::after{content:attr(data-contact);display:block}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq('.x{--a:"fake@example.com"}.x::before{color:red}.x::after{display:block}', $result['css']);
    assert_eq(2, count($result['removals']));
});

test('generated css removes indirect resource declarations it cannot inspect', function (): void {
    $css = '.x{--u:"https://invented.example/p.png";background-image:image-set(var(--u) 1x);color:red}'
        . '.y{width:var(--measure);background-image:attr(data-image type(<url>))}'
        . '.z{background-image:image-set(env(--missing-image,"https://invented.example/fallback.png") 1x)}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq('.x{--u:"https://invented.example/p.png";color:red}.y{width:var(--measure);}.z{}', $result['css']);
    assert_eq(3, count($result['removals']));
    assert_eq([], CssScrub::scrubGenerated($result['css'], [])['removals']);
});

test('generated css grounds every visible generated-text property', function (): void {
    $css = '.x{list-style-type:symbols(cyclic "fake@example.com");color:red}'
        . '.x::before{quotes:"fake@example.com" "";content:open-quote;display:block}'
        . '@counter-style invented{system:cyclic;symbols:"https://invented.example";suffix:". "}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq(
        '.x{color:red}.x::before{content:open-quote;display:block}'
            . '@counter-style invented{system:cyclic;suffix:". "}',
        $result['css'],
    );
    assert_eq(3, count($result['removals']));
});

test('generated css removes conditional resource values it cannot inspect', function (): void {
    $css = '.x{--flag:yes;background-image:image-set(if(style(--flag:yes):'
        . '"https://invented.example/if.png";else:"./safe.png") 1x);color:red}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq('.x{--flag:yes;color:red}', $result['css']);
    assert_eq(1, count($result['removals']));
    assert_eq('external_url_declaration', $result['removals'][0]['kind']);
    assert_eq([], CssScrub::scrubGenerated($result['css'], [])['removals']);
});

test('generated css preserves conditional resource values whose branches are local', function (): void {
    $css = '.x{background-image:if(style(--flag: yes): linear-gradient(red, blue); else: none);color:red}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});

test('generated css removes counter text whose rendered composition cannot be grounded locally', function (): void {
    $css = '.x{counter-reset:a 212 b 555 c 199}'
        . '.x::before{content:"+1 " counter(a) " " counter(b) " 0" counter(c);color:red}'
        . '@counter-style invented{system:cyclic;prefix:"fake@";symbols:"example";suffix:".com"}'
        . '.x{list-style:invented;margin:0}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq(
        '.x{counter-reset:a 212 b 555 c 199}.x::before{color:red}'
            . '@counter-style invented{system:cyclic;prefix:"fake@";symbols:"example";suffix:".com"}'
            . '.x{margin:0}',
        $result['css'],
    );
    assert_eq(2, count($result['removals']));
});

test('generated css rejects a data SVG that paints invented contact copy', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>fake@example.com</text></svg>';
    $data = 'data:image/svg+xml;base64,' . base64_encode($svg);
    $css = '.x{width:300px;background-image:url(' . $data . ');height:50px}';

    $result = CssScrub::scrubGenerated($css, []);

    assert_eq('.x{width:300px;height:50px}', $result['css']);
    assert_eq(1, count($result['removals']));
    assert_eq('removed_ungrounded_contact', $result['removals'][0]['disposition']);

    $grounded = CssScrub::scrubGenerated(
        $css,
        ContactFacts::candidateSetFromSpec(['email' => 'fake@example.com']),
    );
    assert_eq($css, $grounded['css']);
    assert_eq([], $grounded['removals']);
});

test('generated css scrubs a bare declaration list', function (): void {
    $css = 'background-image:url(https://invented.example/p.png);content:"fake@example.com";color:red';

    $result = CssScrub::scrubGenerated($css, [], true);

    assert_eq('content:"fake@example.com";color:red', $result['css']);
    assert_eq(1, count($result['removals']));
});

test('css scrub preserves malformed quoted url syntax and its safe sibling', function (): void {
    $css = '.x{background-image:url(https://invented.example/"x);color:red}';

    $result = CssScrub::scrub($css);

    assert_eq($css, $result['css']);
    assert_eq([], $result['removals']);
});
