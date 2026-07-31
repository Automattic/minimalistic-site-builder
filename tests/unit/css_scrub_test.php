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
