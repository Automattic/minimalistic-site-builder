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
