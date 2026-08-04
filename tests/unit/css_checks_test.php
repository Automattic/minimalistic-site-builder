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
