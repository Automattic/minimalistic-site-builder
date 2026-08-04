<?php
declare(strict_types=1);

use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Units\GeneratedMarkup;

test('sanitize removes script elements with their bodies', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:html --><script type="text/javascript">alert(document.cookie)</script><!-- /wp:html -->'
        . '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->'
    );
    assert_true(!str_contains($out, '<script'), 'script tag removed');
    assert_true(!str_contains($out, 'alert('), 'script body removed');
    assert_contains('<p>Kept.</p>', $out);
});

test('sanitize removes style elements so model CSS cannot claim shell positioning', function () {
    // Not script-capable, but one rule overrides the trusted header shell's
    // position-ownership contract in the delivered theme.
    $out = MarkupSanitizer::sanitize(
        '<style>.site-header-shell{position:fixed}</style>'
        . '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->'
    );
    assert_true(!str_contains($out, '<style'), 'style tag removed');
    assert_true(!str_contains($out, 'position:fixed'), 'style body removed');
    assert_contains('<p>Kept.</p>', $out);

    $notes = [];
    MarkupSanitizer::sanitize('<style>.x{color:red}</style>', $notes);
    assert_contains('removed script-capable element markup', implode(' | ', $notes));
});

test('sanitize removes SVG SMIL animation elements that can animate an href to javascript:', function () {
    // <animate>/<set> set the live value of a sibling's attribute, so a link
    // whose own href is inert still executes on click.
    foreach ([
        '<svg><a href="#x"><animate attributeName="href" values="javascript:alert(1)"/><text>Click</text></a></svg>',
        '<svg><a href="#x"><set attributeName="href" to="javascript:alert(1)"/><text>Click</text></a></svg>',
        '<svg><rect><animateTransform attributeName="href" from="javascript:alert(1)"/></rect></svg>',
        '<svg><a href="#x"><animate attributeName="href" values="javascript:alert(1)"></animate>t</a></svg>',
    ] as $payload) {
        $out = MarkupSanitizer::sanitize($payload);
        assert_true(!str_contains($out, 'javascript:'), "animation neutralized: {$payload}");
        assert_true(
            !preg_match('/<(?:animate|animatetransform|animatemotion|set)\b/i', $out),
            "animation element removed: {$payload}",
        );
    }
});

test('sanitize keeps a legitimate SVG that carries no animation', function () {
    $svg = '<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
    assert_eq($svg, MarkupSanitizer::sanitize($svg));
});

test('sanitize removes an unclosed script and its remaining body', function () {
    $out = MarkupSanitizer::sanitize(
        '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->'
        . '<script>const fake = `<!-- wp:group --><!-- /wp:group -->`;'
    );

    assert_eq('<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->', $out);
});

test('sanitize follows script escaped and double-escaped closing states', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    assert_eq(
        '<p>After</p>',
        MarkupSanitizer::sanitize(
            "<script><!--<script></script>{$fake}</script><p>After</p>"
        ),
        'the first double-escaped </script> is script text, not a boundary',
    );
    assert_eq(
        '<p>After</p>',
        MarkupSanitizer::sanitize(
            '<script><!--<script>--></script><p>After</p>'
        ),
        '--> returns double-escaped text to data before the real closer',
    );
});

test('sanitize removes embed containers with their bodies', function () {
    $out = MarkupSanitizer::sanitize(
        '<iframe src="https://evil.test/"></iframe><object data="x"><embed src="y">fallback text</object><base href="https://evil.test/">'
    );
    foreach (['<iframe', '<object', '<embed', '<base'] as $tag) {
        assert_true(!str_contains($out, $tag), "{$tag} removed");
    }
    assert_true(!str_contains($out, 'fallback text'), 'container fallback cannot expose active markup');
});

test('sanitize removes nested container bodies through the outer closer', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $out = MarkupSanitizer::sanitize(
        '<p>Before</p>'
        . "<object><object>inner</object>{$fake}</object>"
        . "<applet><applet>inner</applet>{$fake}</applet>"
        . '<p>After</p>'
    );

    assert_eq('<p>Before</p><p>After</p>', $out);
});

test('sanitize ignores fake container closers in attributes and comments', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    $out = MarkupSanitizer::sanitize(
        '<p>Before</p>'
        . "<script data-x=\"</script>\">{$fake}</script>"
        . "<iframe data-x=\"</iframe>\">{$fake}</iframe>"
        . "<object><div data-x=\"</object>\">{$fake}</div></object>"
        . "<applet><!-- </applet> -->{$fake}</applet>"
        . '<p>After</p>'
    );

    assert_eq('<p>Before</p><p>After</p>', $out);
});

test('sanitize does not enter quote state from a malformed unquoted attribute', function () {
    $out = MarkupSanitizer::sanitize(
        '<p>Before</p><script data-x=foo"><span x=">'
        . 'malformed_attribute_body()</script><p>After</p>'
    );

    assert_eq(
        '<p>Before</p><p>After</p>',
        $out,
        'the malformed attribute cannot expose the container body',
    );
});

test('sanitize removes noscript fallback content', function () {
    $out = MarkupSanitizer::sanitize(
        '<p>Before</p><noscript>fallback marker</noscript><p>After</p>'
    );
    assert_eq('<p>Before</p><p>After</p>', $out);
});

test('sanitize removes slash-terminated non-void containers with their bodies', function () {
    $fake = '<!-- wp:paragraph --><p>Fake</p><!-- /wp:paragraph -->';
    foreach (['script', 'iframe', 'object', 'applet'] as $tag) {
        assert_eq(
            '<p>Before</p><p>After</p>',
            MarkupSanitizer::sanitize(
                "<p>Before</p><{$tag}/>{$fake}</{$tag}><p>After</p>"
            ),
        );
    }
});

test('sanitize strips inline event handlers but never prose', function () {
    $out = MarkupSanitizer::sanitize(
        '<div class="wp-block-group" onclick="alert(1)" onmouseover=alert(2) data-x="k">'
        . '<p data-x=">" onclick="alert(3)">Carry on writing = fine, even on days like this.</p></div>'
    );
    assert_true(!str_contains($out, 'onclick'), 'quoted handler removed');
    assert_true(!str_contains($out, 'onmouseover'), 'bare handler removed');
    assert_true(!str_contains($out, 'alert(3)'), 'quoted > cannot cut the tag scan short');
    assert_contains('data-x="k"', $out, 'other attributes kept');
    assert_contains('Carry on writing = fine, even on days like this.', $out);
});

test('sanitize strips handlers after malformed unquoted attribute values', function () {
    $out = MarkupSanitizer::sanitize(
        '<img src=x" onerror=malformed_handler()><p>Kept</p>'
    );
    assert_true(!str_contains($out, 'onerror'), 'handler name removed');
    assert_true(!str_contains($out, 'malformed_handler'), 'handler body removed');
    assert_contains('<p>Kept</p>', $out, 'following content survives');
});

test('sanitize follows browser attribute boundaries for event handlers', function () {
    $cases = [
        '<img src=x onerror="E()">' => '<img src=x>',
        '<svg onload=E()>' => '<svg>',
        '<svg/onload=E()>' => '<svg>',
        '<svg id="x"/onload=\'E()\'>' => '<svg id="x">',
        "<svg\tOnLoAd = E()>" => '<svg>',
        "<svg\nOnLoAd = E()>" => '<svg>',
        "<svg\fOnLoAd = E()>" => '<svg>',
        "<svg\rOnLoAd = E()>" => '<svg>',
        '<img title=">" onerror=E()>after' => '<img title=">">after',
        '<div class="x"onclick=E()>' => '<div class="x">',
        '<div id=a onload="x"class=y>t</div>' => '<div id=a class=y>t</div>',
        '<div id=a onload="x"onclick="y"class=z>t</div>' => '<div id=a class=z>t</div>',
        '<svg =" /onload=E()>' => '<svg =">',
        '<svg x=""=" /onload=E()>' => '<svg x=""=">',
    ];
    foreach ($cases as $input => $expected) {
        assert_eq($expected, MarkupSanitizer::sanitize($input), $input);
    }

    foreach ([
        '<svg id=x/onload=not-an-attr>',
        '<div data-onload=x>onload=prose</div>',
    ] as $safe) {
        assert_eq($safe, MarkupSanitizer::sanitize($safe), $safe);
    }
});

test('sanitize neutralizes executable URL schemes and keeps real links', function () {
    $out = MarkupSanitizer::sanitize(
        '<a href="javascript:alert(1)">a</a>'
        . '<a href=\'JAVASCRIPT : alert(2)\'>b</a>'
        . '<img src="data:text/html;base64,PHNjcmlwdD4=">'
        . '<a href="/menu/#breads">menu</a><a href="https://example.com">ext</a>'
    );
    assert_true(stripos($out, 'javascript') === false, 'javascript: URLs neutralized');
    assert_true(!str_contains($out, 'data:text/html'), 'data: URLs neutralized');
    assert_contains('href="/menu/#breads"', $out);
    assert_contains('href="https://example.com"', $out);
});

test('sanitize neutralizes browser-decoded and unquoted executable URLs', function () {
    $cases = [
        '<a href=javascript:alert(1)>x</a>' => '<a href=#>x</a>',
        '<a/href=javascript:alert(1)>x</a>' => '<a/href=#>x</a>',
        '<a href="java&#x73;cript:alert(1)">x</a>' => '<a href="#">x</a>',
        '<a href=jav&#97;script:alert(1)>x</a>' => '<a href=#>x</a>',
        '<a href=javascript&#58;alert(1)>x</a>' => '<a href=#>x</a>',
        '<a href=javascript&colon;alert(1)>x</a>' => '<a href=#>x</a>',
        "<a href=\"java\tscript:alert(1)\">x</a>" => '<a href="#">x</a>',
        '<a href="java&#9;script:alert(1)">x</a>' => '<a href="#">x</a>',
        '<img src=data:text/html,x>' => '<img src=#>',
        '<form action=vbscript:x></form>' => '<form action=#></form>',
        '<button formaction="javascript:x">x</button>' => '<button formaction="#">x</button>',
        '<svg><a xlink:href=data:text/html,x>x</a></svg>' => '<svg><a xlink:href=#>x</a></svg>',
        '<a =" /href=javascript:E()>x</a>' => '<a =" /href=#>x</a>',
    ];
    foreach ($cases as $input => $expected) {
        assert_eq($expected, MarkupSanitizer::sanitize($input), $input);
        assert_eq(
            $expected,
            MarkupSanitizer::sanitize(MarkupSanitizer::sanitize($input)),
            "idempotent: {$input}",
        );
    }

    foreach ([
        '<img src=x/onerror=y>',
        '<a href="/relative/#anchor">relative</a>',
        '<a href="https://example.com">https</a>',
        '<a href="mailto:a@example.com">mail</a>',
        '<a href="&amp;#106;avascript:x">one decode only</a>',
        '<div data-href="javascript:x">safe data</div>',
        '<!-- href="javascript:x" --> prose href="javascript:x"',
    ] as $safe) {
        assert_eq($safe, MarkupSanitizer::sanitize($safe), $safe);
    }
});

test('sanitize leaves well-formed block markup byte-identical', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50"}}}} -->'
        . '<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50)">'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/visit/">Visit us</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group -->';
    assert_eq($markup, MarkupSanitizer::sanitize($markup));
});

test('GeneratedMarkup::normalize sanitizes the part at intake', function () {
    $out = GeneratedMarkup::normalize(
        '<!-- wp:html --><script>alert(1)</script><!-- /wp:html --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        'section-test'
    );
    assert_true(!str_contains($out, '<script'), 'script stripped at the intake choke point');
    assert_contains('<!-- wp:paragraph -->', $out);
});

test('sanitize does not splice a live script out of inert neighbours', function () {
    // Removing a tag joins the bytes on either side of it. `<` + `script>`
    // spells a script element a browser would never have parsed from the
    // input, so removal has to repeat until the markup stops changing.
    foreach ([
        '<<base>script>alert(1)<</base>/script>',
        '<</script>script>alert(1)<</base>/script>',
        '<<embed>script>alert(1)<</embed>/script>',
        '<<meta>script>alert(1)<</meta>/script>',
    ] as $input) {
        $out = MarkupSanitizer::sanitize($input);
        assert_true(!str_contains($out, '<script'), "no script spliced from: {$input}");
        assert_true(!str_contains($out, 'alert('), "no script body left from: {$input}");
    }
});

test('normalize does not publish a spliced script', function () {
    $out = GeneratedMarkup::normalize(
        "<!-- wp:paragraph -->\n<p>Hi<</base>script>alert(1)<</base>/script></p>\n<!-- /wp:paragraph -->",
        'section-test'
    );
    assert_true(!str_contains($out, '<script'), 'no script reaches the theme part');
    assert_contains('<p>Hi</p>', $out);
});

test('sanitize removes meta so http-equiv refresh cannot redirect visitors', function () {
    $out = MarkupSanitizer::sanitize(
        '<meta http-equiv="refresh" content="0;url=https://evil.example">'
        . '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->'
    );
    assert_true(!str_contains($out, '<meta'), 'meta tag removed');
    assert_contains('<p>Kept.</p>', $out);
});

test('sanitize treats a spaced closer as text, not a raw-text boundary', function () {
    // `</ style>` / `</ iframe>` are bogus comments to a browser, so each
    // element runs on unclosed and the whole stripped container consumes to
    // EOF — nothing after it can leak out live. The seeder's copy of this
    // scanner must agree.
    assert_eq('', MarkupSanitizer::sanitize('<style>a</ style><img src=x onerror=alert(1)>'));
    assert_eq('', MarkupSanitizer::sanitize('<iframe>x</ iframe><img src=x onerror=alert(1)>'));
});

test('sanitize and normalize report their removals through the notes out-param', function () {
    $notes = [];
    MarkupSanitizer::sanitize(
        '<script>alert(1)</script>'
        . '<!-- wp:paragraph --><p onclick="alert(2)"><a href="javascript:alert(3)">x</a></p><!-- /wp:paragraph -->',
        $notes
    );
    $joined = implode(' | ', $notes);
    assert_contains('removed script-capable element markup', $joined);
    assert_contains('removed 1 inline event handler attribute(s)', $joined);
    assert_contains('neutralized 1 executable URL value(s)', $joined);

    // A clean part produces no notes — warnings.json stays actionable.
    $clean = [];
    MarkupSanitizer::sanitize('<!-- wp:paragraph --><p>Safe.</p><!-- /wp:paragraph -->', $clean);
    assert_eq([], $clean);

    // The intake normalize prefixes each note with its part key, so the
    // caller can route them into warnings.json verbatim.
    $intake = [];
    GeneratedMarkup::normalize(
        '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->'
        . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
        'section-test',
        $intake
    );
    assert_contains("part 'section-test': sanitized script-capable markup", implode(' | ', $intake));
});

test('normalize reports truncation salvage through the notes out-param', function () {
    $kept = '<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->';
    $notes = [];
    $out = GeneratedMarkup::normalize(
        $kept . "\n<!-- wp:group -->\n<div class=\"wp-block-group\"><p>cut off mid-",
        'section-test',
        $notes
    );
    assert_eq($kept, $out);
    assert_true($notes !== [], 'salvage produces a durable note');
    assert_contains("part 'section-test':", $notes[0]);
});
