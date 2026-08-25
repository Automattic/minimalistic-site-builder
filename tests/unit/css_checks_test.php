<?php
declare(strict_types=1);

use Automattic\SiteBuild\CssChecks;

test('braceDepthBalanced walks depth, catching stray closers balanced later', function () {
    assert_true(CssChecks::braceDepthBalanced('.a { color: inherit; }'));
    assert_true(CssChecks::braceDepthBalanced('@media (min-width: 600px) { .a { color: inherit; } }'));
    assert_true(!CssChecks::braceDepthBalanced('.a { color: inherit;'), 'unclosed rule');
    assert_true(
        !CssChecks::braceDepthBalanced("}\n.a { color: inherit; }\n@media screen {"),
        'stray closing brace balanced by a trailing open brace'
    );
});

test('resourceLoadingProblem flags fetching value forms including prefixed ones', function () {
    assert_eq(null, CssChecks::resourceLoadingProblem('.a { background: var(--x); }'));
    foreach ([
        'url(x.png)',
        'image-set("t.png" 1x)',
        '-webkit-image-set("t.png" 1x)',
        'cross-fade(image("a.png"), image("b.png"), 50%)',
    ] as $value) {
        assert_true(CssChecks::resourceLoadingProblem(".a { background: {$value}; }") !== null, $value);
    }
});

test('hiddenContentProblems flags display:none and visibility:hidden anywhere', function () {
    assert_eq([], CssChecks::hiddenContentProblems('.a { display: block; visibility: visible; }'));
    assert_eq(
        ['display:none hides generated content'],
        CssChecks::hiddenContentProblems('.a { display: none }') // even unterminated
    );
    assert_eq(
        ['visibility:hidden hides generated content'],
        CssChecks::hiddenContentProblems('.a { visibility: hidden !important; }')
    );
    assert_eq(
        [],
        CssChecks::hiddenContentProblems(
            '.a[data-example="visibility:hidden"]::before { content: "display:none"; }'
        ),
        'inert selector and string values are not hiding declarations'
    );
});

test('isShapeOwnedRadiusProperty covers shorthand, longhands, and vendor forms without custom-property false positives', function () {
    foreach ([
        'border-radius',
        'border-top-left-radius',
        'border-bottom-right-radius',
        'border-start-start-radius',
        'border-end-end-radius',
        '-webkit-border-radius',
        '-moz-border-top-left-radius',
    ] as $property) {
        assert_true(CssChecks::isShapeOwnedRadiusProperty($property), $property);
    }
    foreach ([
        '--card-border-radius',
        'border-color',
        'outline-radius',
        'transform',
    ] as $property) {
        assert_true(!CssChecks::isShapeOwnedRadiusProperty($property), $property);
    }
});

test('isShapeAffectingDeclaration includes CSS-wide all resets and excludes unrelated all values', function () {
    assert_true(CssChecks::isShapeAffectingDeclaration('border-radius', 'var(--radius)'));
    foreach ([
        'initial',
        'INHERIT !important',
        'unset ! important',
        'revert/**/!important',
        'revert-layer',
        'in\\69tial',
        'var(--shape-reset, initial) !important',
        'env(--shape-reset)',
    ] as $value) {
        assert_true(CssChecks::isShapeAffectingDeclaration('all', $value), $value);
    }
    foreach (['1s ease', 'none', 'initially'] as $value) {
        assert_true(!CssChecks::isShapeAffectingDeclaration('all', $value), $value);
    }
    assert_true(!CssChecks::isShapeAffectingDeclaration('--all', 'initial'));
});

test('selectorTargetsShape recognizes owned and broad selector subjects', function () {
    foreach ([
        '.wp-block-image',
        'figure.wp-block-image:hover',
        '.card .wp-block-image img',
        '& > img.cover',
        'img',
        '.layout > figure',
        '.layout > a',
        '.wp-block-button__link:hover',
        'a.wp-element-button',
        '.custom-motion > button.primary',
        '.card :is(img, .plain)',
        ':where(.wp-element-button)',
        '*',
        '.masonry-3 > *',
        '[class]',
        ':not(.card)',
        ':is(:hover, .card)',
        '.wp-block-cover',
        '.wp-block-cover:not(.alignfull)',
        '.wp-block-cover__image-background',
        '.hero .wp-block-cover__background:hover',
        '.wp-block-cover__video-background',
        '.wp-block-media-text',
        '.section .wp-block-media-text__media',
    ] as $selector) {
        assert_true(CssChecks::selectorTargetsShape($selector), $selector);
    }
    foreach ([
        '.card',
        '.wp-block-group',
        '.masonry-3',
        '.wp-block-image + .card',
        'button > .icon',
        '.card:has(img)',
        '.card:not(.wp-block-image)',
        'div[data-example="img .wp-element-button"]',
        ':is(.card, .wp-block-group)',
        '.wp-block-image::before',
        '.wp-block-cover__inner-container',
        '.wp-block-media-text__content',
        '.wp-block-cover-image',
    ] as $selector) {
        assert_true(!CssChecks::selectorTargetsShape($selector), $selector);
    }
});

test('selectorTargetsSubject walks balanced subject pseudos without promoting descendants or relational arguments', function () {
    foreach ([
        ['&:is(:hover, :focus-visible)', '&'],
        ['&:not(:has(.excluded))', '&'],
        ['.custom-motion:not(:has(.excluded))', '.custom-motion'],
        [':is(.custom-motion, .another-target):hover', '.custom-motion'],
    ] as [$selector, $subject]) {
        assert_true(CssChecks::selectorTargetsSubject($selector, $subject), $selector);
    }
    foreach ([
        ['& figcaption', '&'],
        ['.custom-motion + .card', '.custom-motion'],
        [':not(.custom-motion)', '.custom-motion'],
        ['.card:has(.custom-motion)', '.custom-motion'],
        ['.custom-motion::before', '.custom-motion'],
        ['[data-example=".custom-motion"]', '.custom-motion'],
    ] as [$selector, $subject]) {
        assert_true(!CssChecks::selectorTargetsSubject($selector, $subject), $selector);
    }
});

test('shared radius declaration repair handles rules, keyframes, and scoped declaration lists', function () {
    $css = '.target { color: inherit; border-radius: 2rem; all: revert-layer !important; } '
        . '@keyframes x { from { border-start-start-radius: 0; transform: none; } }';
    [$repaired, $dropped] = CssChecks::dropShapeOwnedRadiusDeclarations($css);
    assert_eq(3, count($dropped));
    assert_true(!str_contains($repaired, 'border-radius'));
    assert_true(!str_contains($repaired, 'border-start-start-radius'));
    assert_true(!str_contains($repaired, 'all: revert-layer'));
    assert_contains('color: inherit', $repaired);
    assert_contains('transform: none', $repaired);

    [$declarations, $dropped] = CssChecks::dropShapeOwnedRadiusDeclarations(
        'border-radius: 1rem; color: currentColor;',
    );
    assert_eq(['border-radius: 1rem'], $dropped);
    assert_eq(' color: currentColor;', $declarations, 'untouched declaration bytes are preserved');
});

test('declaration scanner ignores fake declarations in strings comments functions and custom-property blocks', function () {
    $css = <<<'CSS'
        .target {
            --quoted: "; border-radius: 90px";
            --function: token("x; border-start-start-radius: 5px", calc(1 + 2));
            --block: { border-radius: 80px; all: revert; };
            content: "}; border-radius: 70px; {";
            /* border-radius: 60px; */
            color: currentColor;
            border-radius: 2rem;
        }
        CSS;

    $declarations = CssChecks::scanDeclarations($css);
    assert_eq(
        ['--quoted', '--function', '--block', 'content', 'color', 'border-radius'],
        array_column($declarations, 'property'),
    );
    assert_eq(['border-radius: 2rem'], CssChecks::shapeOwnedRadiusDeclarations($css));

    [$repaired, $dropped] = CssChecks::dropShapeOwnedRadiusDeclarations($css);
    assert_eq(['border-radius: 2rem'], $dropped);
    assert_eq(str_replace('border-radius: 2rem;', '', $css), $repaired);
    assert_contains('--quoted: "; border-radius: 90px";', $repaired);
    assert_contains('--block: { border-radius: 80px; all: revert; };', $repaired);
    assert_contains('/* border-radius: 60px; */', $repaired);
});

test('declaration scanner reports selector keyframe and at-rule ancestry', function () {
    $css = <<<'CSS'
        @media (min-width: 40rem) {
            .card {
                color: inherit;
                &:hover { border-radius: 1rem; }
            }
        }
        @keyframes custom-motion-shape {
            from { border-start-start-radius: 0; transform: none; }
            to { transform: scale(1); }
        }
        CSS;
    $declarations = CssChecks::scanDeclarations($css);
    $byProperty = [];
    foreach ($declarations as $declaration) {
        $byProperty[$declaration['property']][] = $declaration;
    }

    assert_eq('.card', $byProperty['color'][0]['context']);
    assert_eq(['@media (min-width: 40rem)'], $byProperty['color'][0]['ancestors']);
    assert_eq('&:hover', $byProperty['border-radius'][0]['context']);
    assert_eq(['@media (min-width: 40rem)', '.card'], $byProperty['border-radius'][0]['ancestors']);
    assert_eq('from', $byProperty['border-start-start-radius'][0]['context']);
    assert_eq(['@keyframes custom-motion-shape'], $byProperty['border-start-start-radius'][0]['ancestors']);
    assert_eq('keyframe', $byProperty['border-start-start-radius'][0]['kind']);
    assert_true($byProperty['border-start-start-radius'][0]['structurallySafe']);
});

test('dropDeclarations uses context to remove only a selected shape target', function () {
    $css = '.wp-block-image { border-radius: 1rem; color: inherit; } '
        . '.card { border-radius: 2rem; color: currentColor; }';
    [$repaired, $dropped] = CssChecks::dropDeclarations(
        $css,
        static fn (array $declaration): bool =>
            CssChecks::selectorTargetsShape($declaration['context'])
            && CssChecks::isShapeAffectingDeclaration($declaration['property'], $declaration['value']),
    );

    assert_eq(1, count($dropped));
    assert_eq('.wp-block-image', $dropped[0]['context']);
    assert_eq(
        '.wp-block-image {  color: inherit; } .card { border-radius: 2rem; color: currentColor; }',
        $repaired,
    );
});

test('shapeAffectingDeclarations follows owned animation references without stripping generic-card keyframes', function () {
    $css = <<<'CSS'
        .wp-block-image {
            all: initial;
            animation: image-shape 1s ease both;
        }
        .card {
            border-radius: 2rem;
            animation-name: card-shape;
        }
        @keyframes image-shape {
            from { border-radius: 0; transform: none; }
            to { border-radius: 2rem; transform: scale(1); }
        }
        @keyframes card-shape {
            from { border-radius: 0; }
            to { border-radius: 3rem; }
        }
        CSS;

    $owned = CssChecks::shapeAffectingDeclarations(
        $css,
        static fn (string $selector): bool => CssChecks::selectorTargetsShape($selector),
    );
    assert_eq(
        ['all: initial', 'border-radius: 0', 'border-radius: 2rem'],
        array_column($owned, 'raw'),
        'only the owned rule and its referenced keyframe are shape-owned',
    );
    assert_eq(['.wp-block-image', 'from', 'to'], array_column($owned, 'context'));
});

test('animation shorthand components are not mistaken for keyframe names', function () {
    $shorthand = '.wp-block-image { animation: 1s ease infinite normal both running; } '
        . '@keyframes ease { from { border-radius: 0; } } '
        . '@keyframes infinite { from { border-radius: 1rem; } } '
        . '@keyframes normal { from { border-radius: 2rem; } } '
        . '@keyframes both { from { border-radius: 3rem; } } '
        . '@keyframes running { from { border-radius: 4rem; } }';
    assert_eq([], CssChecks::shapeAffectingDeclarations(
        $shorthand,
        static fn (string $selector): bool => CssChecks::selectorTargetsShape($selector),
    ));

    $explicit = '.wp-block-image { animation-name: ease; } '
        . '@keyframes ease { from { border-radius: 0; } }';
    $owned = CssChecks::shapeAffectingDeclarations(
        $explicit,
        static fn (string $selector): bool => CssChecks::selectorTargetsShape($selector),
    );
    assert_eq(['border-radius: 0'], array_column($owned, 'raw'));
});

test('shapeAffectingDeclarations treats opaque references and selector-less ownership conservatively', function () {
    $css = '.wp-element-button { animation-name: var(--requested-motion); } '
        . '@keyframes one { from { border-radius: 0; } } '
        . '@keyframes two { to { all: revert; } }';
    $owned = CssChecks::shapeAffectingDeclarations(
        $css,
        static fn (string $selector): bool => CssChecks::selectorTargetsShape($selector),
    );
    assert_eq(['border-radius: 0', 'all: revert'], array_column($owned, 'raw'));

    $externallyOwned = CssChecks::shapeAffectingDeclarations(
        'animation-name: owned-shape; '
            . '@keyframes owned-shape { to { border-radius: 50%; } } '
            . '@keyframes caption-shape { to { border-radius: 2rem; } }',
        static fn (string $selector): bool => false,
        true,
        true,
    );
    assert_eq(['border-radius: 50%'], array_column($externallyOwned, 'raw'));

    $bare = CssChecks::shapeAffectingDeclarations(
        'color: inherit; all: unset; border-radius: 1rem;',
        static fn (string $selector): bool => false,
        true,
        true,
    );
    assert_eq(['all: unset', 'border-radius: 1rem'], array_column($bare, 'raw'));
});

test('bare declaration-list scanning is quote function escape and block aware', function () {
    $css = 'content: "; border-radius: 9px"; '
        . '--payload: fn("x; all: revert", { border-radius: 8px; }); '
        . 'border\\2d radius: 1rem; color: currentColor;';
    $declarations = CssChecks::scanDeclarations($css, true);
    assert_eq(
        ['content', '--payload', 'border-radius', 'color'],
        array_column($declarations, 'property'),
    );

    [$repaired, $dropped] = CssChecks::dropDeclarations(
        $css,
        static fn (array $declaration): bool => CssChecks::isShapeAffectingDeclaration(
            $declaration['property'],
            $declaration['value'],
        ),
        true,
    );
    assert_eq(['border-radius'], array_column($dropped, 'property'));
    assert_eq(str_replace('border\\2d radius: 1rem;', '', $css), $repaired);
});

test('declaration priority splitting recognizes comments and escaped important identifiers', function () {
    assert_eq(
        ['value' => 'right', 'important' => true],
        CssChecks::splitDeclarationPriority(' right ! /* priority */ \\69mportant '),
    );
    assert_eq(
        ['value' => 'center', 'important' => true],
        CssChecks::splitDeclarationPriority('center!important'),
    );
    assert_eq(
        ['value' => 'right!urgent', 'important' => false],
        CssChecks::splitDeclarationPriority('right!urgent'),
    );
});

test('dropDeclarations is byte-identical when its predicate selects nothing', function () {
    $css = "\n/* keep { ; } */\n.target { content: \"; border-radius: 2rem\"; color: inherit; }\n";
    [$repaired, $dropped] = CssChecks::dropDeclarations($css, static fn (array $declaration): bool => false);
    assert_eq([], $dropped);
    assert_eq($css, $repaired);
});

test('declaration scanner recovers an implicit EOF rule close and marks its rows unsafe', function () {
    foreach ([
        '.wp-block-image img { border-radius:99px' => ['border-radius', '99px', '.wp-block-image img'],
        '& img { all:initial!important' => ['all', 'initial!important', '& img'],
    ] as $css => [$property, $value, $context]) {
        $declarations = CssChecks::scanDeclarations($css);
        assert_eq(1, count($declarations), $css);
        assert_eq($property, $declarations[0]['property']);
        assert_eq($value, $declarations[0]['value']);
        assert_eq($context, $declarations[0]['context']);
        assert_true(!$declarations[0]['structurallySafe'], $css);

        $owned = CssChecks::shapeAffectingDeclarations(
            $css,
            static fn (string $selector): bool => CssChecks::selectorTargetsShape($selector),
        );
        assert_eq(1, count($owned), $css);
        assert_true(!$owned[0]['structurallySafe'], $css);
    }
});

test('an unclosed custom-property block stays opaque during implicit EOF recovery', function () {
    $css = '.wp-block-image { --payload: { border-radius:99px; all:initial';
    assert_eq([], CssChecks::shapeOwnedRadiusDeclarations($css));
    assert_eq([], CssChecks::shapeAffectingDeclarations(
        $css,
        static fn (string $selector): bool => CssChecks::selectorTargetsShape($selector),
    ));
});

test('disallowedAtRules honors the per-caller allowlist', function () {
    $css = "@media (min-width: 600px) { .a { color: inherit; } }\n"
        . "@keyframes spin { to { transform: rotate(1turn); } }\n"
        . "@import 'x.css';";
    assert_eq(
        ['disallowed at-rule: @keyframes', 'disallowed at-rule: @import'],
        CssChecks::disallowedAtRules($css, ['media'])
    );
    assert_eq(['disallowed at-rule: @import'], CssChecks::disallowedAtRules($css, ['media', 'keyframes']));
});

test('unscopedSelectors drops @media preludes and splits selector lists', function () {
    $isAllowed = static fn (string $selector): bool => str_starts_with($selector, '.ok');
    $css = "@media (max-width: 600px) {\n    .ok { columns: 1; }\n}\n.ok > *, body { margin: 0; }";
    assert_eq(['body'], CssChecks::unscopedSelectors($css, $isAllowed));
    assert_eq([], CssChecks::unscopedSelectors('.ok:hover { color: inherit; }', $isAllowed));
});

test('declarationScopeAtViewport honours width media queries true at the render viewport', function () {
    assert_eq('apply', CssChecks::declarationScopeAtViewport([], 1366.0), 'an unscoped declaration always applies');
    assert_eq('apply', CssChecks::declarationScopeAtViewport(['@media (min-width:1000px)'], 1366.0));
    assert_eq('apply', CssChecks::declarationScopeAtViewport(['@media (min-width:56rem)'], 1366.0), '56rem is 896px');
    assert_eq('apply', CssChecks::declarationScopeAtViewport(['@media (min-width:0)'], 1366.0), 'a unitless zero is a length');
    assert_eq(
        'apply',
        CssChecks::declarationScopeAtViewport(['@media screen and (min-width:600px) and (max-width:1400px)'], 1366.0),
        'a media type and a conjunction of width features stay decidable',
    );
});

test('declarationScopeAtViewport reports a width media query false at the render viewport as inert', function () {
    assert_eq('inert', CssChecks::declarationScopeAtViewport(['@media (max-width:899px)'], 1366.0));
    assert_eq('inert', CssChecks::declarationScopeAtViewport(['@media (min-width:100rem)'], 1366.0), '1600px');
    assert_eq(
        'inert',
        CssChecks::declarationScopeAtViewport(['@media (min-width:600px) and (max-width:1200px)'], 1366.0),
        'one false arm of a conjunction is enough',
    );
    assert_eq(
        'inert',
        CssChecks::declarationScopeAtViewport(['@media (max-width:600px)', '.wrapper'], 1366.0),
        'a proven-false ancestor outranks an undecidable one',
    );
});

test('selectorTargetsHeading matches heading subjects and ignores descendant combinators', function () {
    foreach ([
        'h1',
        'h2.hero',
        '.wp-block-heading',
        'h1.wp-block-heading',
        '.hero .wp-block-heading',
        '.wp-block-post-title',
        ':is(h1, h2)',
        ':where(.wp-block-heading)',
        'section > h3',
    ] as $selector) {
        assert_true(CssChecks::selectorTargetsHeading($selector), $selector);
    }
    foreach ([
        'h1 + p',
        'h1 > span',
        '.card',
        '.wp-block-heading-wrapper',
        '.wp-block-heading::before',
        'p',
        'h1 + .wp-block-group',
        '[data-example="h1 .wp-block-heading"]',
    ] as $selector) {
        assert_true(!CssChecks::selectorTargetsHeading($selector), $selector);
    }
});

test('dropHeadingWordSplitDeclarations removes wrap/hyphen properties only from heading subjects', function () {
    $css = '.hero h1 { overflow-wrap: anywhere; word-break: break-all; hyphens: auto; text-wrap: wrap; font-size: 5rem; } '
        . 'h1 + p { overflow-wrap: break-word; color: inherit; } '
        . 'p { -webkit-hyphens: auto; text-wrap: pretty; }';
    [$repaired, $dropped] = CssChecks::dropHeadingWordSplitDeclarations($css);
    assert_eq(3, count($dropped), 'three heading word-splitting declarations dropped');
    assert_contains('font-size: 5rem', $repaired);
    assert_contains('h1 + p { overflow-wrap: break-word; color: inherit; }', $repaired);
    assert_contains('p { -webkit-hyphens: auto; text-wrap: pretty; }', $repaired);
    assert_true(!str_contains($repaired, 'anywhere'));
    assert_true(!str_contains($repaired, 'break-all'));
    assert_true(!str_contains($repaired, 'hyphens: auto; font-size'));
    assert_contains('text-wrap: wrap', $repaired, 'text-wrap ownership is a separate global repair');
});

test('dropTextWrapDeclarations removes build-owned wrap properties from every style selector', function () {
    $css = 'p { text-wrap: nowrap !important; color: inherit; } '
        . '.hero > * { text-wrap-style: balance; display: block; } '
        . 'body { text-wrap-mode: nowrap; hyphens: auto; } '
        . '@keyframes settle { from { text-wrap-mode: nowrap; opacity: 0; } }';

    [$repaired, $dropped] = CssChecks::dropTextWrapDeclarations($css);

    assert_eq(4, count($dropped), 'all authored text-wrap declarations are dropped');
    assert_true(!str_contains($repaired, 'p { text-wrap'), 'direct paragraph override removed');
    assert_true(!str_contains($repaired, 'text-wrap-style'), 'shorthand companion removed');
    assert_contains('body {  hyphens: auto; }', $repaired, 'unrelated body wrap behavior survives');
    assert_contains('from {  opacity: 0; }', $repaired, 'unrelated keyframe declarations survive');
});

test('declarationScopeAtViewport refuses to decide anything a width comparison cannot settle', function () {
    // The only place a @keyframes ancestor can be pinned. Asserting it through
    // SectionLayoutStep instead is vacuous: a keyframe declaration's context is
    // its step prelude, so it matches no selector however the classifier breaks.
    foreach ([
        ['@media (prefers-reduced-motion: reduce)'],
        ['@media (prefers-reduced-motion:no-preference)'],
        ['@keyframes settle'],
        ['@supports (display:grid)'],
        ['@layer components'],
        ['@media print'],
        ['@media (width >= 1000px)'],
        ['@media (max-width:620px), print'],
        ['@media not all and (min-width:1000px)'],
        ['@media (min-width:calc(50rem + 2px))'],
        ['@media (min-width:2vw)'],
        ['@container (min-width:1000px)'],
        // Nested CSS: the caller's selector test only saw the inner selector.
        ['.wrapper'],
        ['@media (min-width:1000px)', '.wrapper'],
    ] as $ancestors) {
        assert_eq(
            'unprovable',
            CssChecks::declarationScopeAtViewport($ancestors, 1366.0),
            implode(' / ', $ancestors),
        );
    }
});

test('hiddenContentProblems catches a clip-path that leaves no visible area', function () {
    // Regression (BIGR-881): pulso2's hero copy shipped
    // `clip-path: inset(0 0 100% 0)`. Every visibility check we had read the
    // element as visible; on screen it was clipped to nothing.
    foreach ([
        'inset(0 0 100% 0)',
        'inset(100% 0 0 0)',
        'inset(0 100% 0 0)',
        'inset(60% 0 40% 0)',
        'inset(100%)',
        'inset(0 0 100% 0 round 4px)',
        'circle(0)',
        'circle(0%)',
        'ellipse(0 0 at center)',
    ] as $value) {
        assert_eq(
            ['clip-path clips generated content away entirely: ' . $value],
            CssChecks::hiddenContentProblems(".a { clip-path: {$value}; }"),
            "hides: {$value}"
        );
    }
});

test('hiddenContentProblems leaves a clip-path that still shows something', function () {
    foreach ([
        'inset(0 0 0 0)',
        'inset(0)',
        'inset(50% 0 0 0)',
        'inset(0 0 99.9% 0)',
        'inset(0 0 4rem 0)',
        'circle(50%)',
        'circle(12px at center)',
        'polygon(0 0, 100% 0, 100% 100%)',
        'var(--clip)',
        'none',
    ] as $value) {
        assert_eq(
            [],
            CssChecks::hiddenContentProblems(".a { clip-path: {$value}; }"),
            "visible: {$value}"
        );
    }
});

test('selectorNamesMotionClass reads the whole selector, not just its subject', function () {
    foreach ([
        '.reveal-up',
        '.stagger-children > *',
        '.stagger-children > *:nth-child(2)',
        '.gradient-shift',
        '.hero-entrance .card',
        '.wp-block-group:is(.reveal-fade, .card)',
        '.reveal-left',       // an invented variant the kit ships no CSS for
        '.is-visible',        // the JS-owned state class
    ] as $selector) {
        assert_true(CssChecks::selectorNamesMotionClass($selector), "kit selector: {$selector}");
    }

    foreach ([
        '.text-measure',
        '.overlap-up',
        '.wp-block-cover__inner-container',
        '.card[data-motion=".reveal-up"]',   // an attribute value is not a class
        '.card::after',
        'h1',
    ] as $selector) {
        assert_true(!CssChecks::selectorNamesMotionClass($selector), "ordinary selector: {$selector}");
    }
});

test('dropMotionKitDeclarations removes only kit rules and leaves every other byte', function () {
    // The exact shape pulso2 shipped, trimmed to two rules plus a neighbour.
    $css = 'body{-webkit-font-smoothing:antialiased}'
        . '.device--hairline-rule{border-top:1px solid currentColor}'
        . '@media (prefers-reduced-motion: no-preference){'
        . '.reveal-up{opacity:0;transform:translate3d(0,2.75rem,0);clip-path:inset(0 0 100% 0);'
        . 'animation:nn-reveal-up 1.15s ease both;animation-timeline:view()}'
        . '.stagger-children>*:nth-child(2){animation-delay:.09s}'
        . '.text-measure{max-width:38rem}'
        . '}'
        . '@keyframes nn-reveal-up{to{opacity:1;clip-path:inset(0 0 0 0)}}';

    [$repaired, $dropped] = CssChecks::dropMotionKitDeclarations($css);

    assert_eq([
        'opacity:0',
        'transform:translate3d(0,2.75rem,0)',
        'clip-path:inset(0 0 100% 0)',
        'animation:nn-reveal-up 1.15s ease both',
        'animation-timeline:view()',
        'animation-delay:.09s',
    ], $dropped);

    // Emptied kit rules stay as inert bytes; everything else is untouched,
    // including the now-unreferenced keyframe, which renders nothing.
    assert_eq(
        'body{-webkit-font-smoothing:antialiased}'
        . '.device--hairline-rule{border-top:1px solid currentColor}'
        . '@media (prefers-reduced-motion: no-preference){'
        . '.reveal-up{}'
        . '.stagger-children>*:nth-child(2){}'
        . '.text-measure{max-width:38rem}'
        . '}'
        . '@keyframes nn-reveal-up{to{opacity:1;clip-path:inset(0 0 0 0)}}',
        $repaired
    );

    // Idempotent: a repair pass must reach a fixed point.
    [$again, $droppedAgain] = CssChecks::dropMotionKitDeclarations($repaired);
    assert_eq($repaired, $again);
    assert_eq([], $droppedAgain);
});

test('dropMotionKitDeclarations leaves CSS that names no motion class alone', function () {
    $css = '.text-measure{max-width:38rem}.overlap-up{margin-top:-3.5rem}';
    [$repaired, $dropped] = CssChecks::dropMotionKitDeclarations($css);
    assert_eq($css, $repaired, 'byte-for-byte');
    assert_eq([], $dropped);
});

test('a clip-path entrance inside @keyframes is legal, a hidden resting state is not', function () {
    // Regression (BIGR-887): the check scanned the whole stylesheet with no
    // context, so a wipe-in and an iris-in — the two canonical clip-path
    // entrances — were reported as hidden content. The opacity check the two
    // callers run alongside this one is keyframe-aware for exactly this
    // reason: a hidden START state is legal, a hidden REST state is not.
    foreach ([
        '@keyframes custom-motion-wipe{from{clip-path:inset(0 0 100% 0)}to{clip-path:inset(0 0 0 0)}}',
        '@keyframes custom-motion-iris{from{clip-path:circle(0)}to{clip-path:circle(75%)}}',
        '@keyframes custom-motion-x{0%{clip-path:inset(100% 0 0 0)}100%{clip-path:inset(0)}}',
    ] as $css) {
        assert_eq([], CssChecks::hiddenContentProblems($css), $css);
    }

    // Outside keyframes it is still a defect.
    assert_eq(
        ['clip-path clips generated content away entirely: inset(0 0 100% 0)'],
        CssChecks::hiddenContentProblems('.reveal-up{clip-path:inset(0 0 100% 0)}')
    );
    // And a rule beside a legal keyframe is still judged.
    assert_eq(
        ['clip-path clips generated content away entirely: circle(0)'],
        CssChecks::hiddenContentProblems(
            '@keyframes custom-motion-iris{from{clip-path:circle(0)}to{clip-path:circle(75%)}}'
            . '.custom-motion{clip-path:circle(0)}'
        )
    );
});

test('one repeated clip-path value is reported once', function () {
    assert_eq(
        1,
        count(CssChecks::hiddenContentProblems(
            '.a{clip-path:inset(0 0 100% 0)}.b{clip-path:inset(0 0 100% 0)}'
        ))
    );
});

test('a kit class as an ANCESTOR removes only what can hide or animate', function () {
    // Regression (BIGR-887): the scrub deleted the whole declaration set of
    // any rule with a kit class anywhere in its selector, so ordinary design
    // intent on an element that merely lives inside a kit element was
    // silently removed. Rung 3 asks for the smallest cut.
    [$out, $dropped] = CssChecks::dropMotionKitDeclarations(
        '.hero-entrance h1{letter-spacing:-0.03em;max-width:18ch;opacity:0;transform:translateY(2rem)}'
    );
    assert_eq(['opacity:0', 'transform:translateY(2rem)'], $dropped, 'only the motion-capable half');
    assert_contains('letter-spacing:-0.03em', $out, 'the type survives');
    assert_contains('max-width:18ch', $out, 'and the layout');

    // The rule's SUBJECT being a kit class still removes everything: it is
    // styling the kit element itself.
    [, $all] = CssChecks::dropMotionKitDeclarations('.reveal-up{opacity:0;color:red}');
    assert_eq(['opacity:0', 'color:red'], $all);

    // motion.js registers `.stagger-children > *` itself, so that IS the kit.
    [, $stagger] = CssChecks::dropMotionKitDeclarations('.stagger-children>*{opacity:0;margin-block:0}');
    assert_eq(['opacity:0', 'margin-block:0'], $stagger);
});

test('excluded and relational kit references do not make the selected element kit-owned', function () {
    // `.card:not(.reveal-up)` deliberately excludes kit elements.
    [$out, $dropped] = CssChecks::dropMotionKitDeclarations('.card:not(.reveal-up){border:1px solid}');
    assert_eq([], $dropped);
    assert_eq('.card:not(.reveal-up){border:1px solid}', $out, 'byte-for-byte');

    $excluded = '.card:not(.reveal-up){opacity:0}';
    [$excludedOut, $excludedDrops] = CssChecks::dropMotionKitDeclarations($excluded);
    assert_eq([], $excludedDrops, ':not() names what this selector cannot target');
    assert_eq($excluded, $excludedOut, 'byte-for-byte');

    $relational = '.shell:has(.reveal-up) .card{opacity:0}';
    [$relationalOut, $relationalDrops] = CssChecks::dropMotionKitDeclarations($relational);
    assert_eq([], $relationalDrops, ':has() selects a container, not the kit descendant');
    assert_eq($relational, $relationalOut, 'byte-for-byte');
});

test('a kit ancestor loses a declaration that hides, judged by value not by name', function () {
    // The scrub's only caller is ThemeJsonStep, whose custom CSS never reaches
    // hiddenContentProblems(). Narrowing the ancestor cut to motion-capable
    // properties must not drop `display: none` out of every guard at once.
    foreach (['display:none', 'visibility:hidden', 'clip-path:inset(0 0 100% 0)'] as $declaration) {
        [$out, $dropped] = CssChecks::dropMotionKitDeclarations(
            '.hero-entrance h1{letter-spacing:-0.03em;' . $declaration . '}'
        );
        assert_eq([$declaration], $dropped, $declaration);
        assert_contains('letter-spacing:-0.03em', $out, 'the type still survives');
    }

    // And `display` is judged by VALUE: naming the property outright would
    // delete ordinary layout under any kit ancestor, which is the over-reach
    // this branch exists to stop.
    foreach ([
        '.hero-entrance .row{display:flex;gap:1rem}',
        '.hero-entrance .grid{display:grid}',
        '.hero-entrance h1{display:block}',
        '.hero-entrance .perf{content-visibility:auto}',
    ] as $css) {
        [$kept, $none] = CssChecks::dropMotionKitDeclarations($css);
        assert_eq($css, $kept, 'byte-for-byte: ' . $css);
        assert_eq([], $none, $css);
    }
});

test('isMotionCapableProperty separates choreography from ordinary design', function () {
    foreach ([
        'opacity', 'visibility', 'clip-path', 'filter', 'backdrop-filter', 'will-change',
        'transform', 'transform-origin', 'translate', 'rotate', 'scale',
        'animation', 'animation-name', 'animation-timeline', 'transition', 'transition-delay',
        '-webkit-transform',
    ] as $property) {
        assert_true(CssChecks::isMotionCapableProperty($property), $property);
    }
    // `display` belongs here and not above: the ancestor cut judges it by
    // value, so `display: flex` survives and `display: none` does not.
    foreach ([
        'color', 'background-color', 'letter-spacing', 'max-width', 'margin-block',
        'border', 'font-size', 'padding', 'gap', 'transformation', 'displays',
        'display', 'content-visibility',
    ] as $property) {
        assert_true(!CssChecks::isMotionCapableProperty($property), $property);
    }
});
