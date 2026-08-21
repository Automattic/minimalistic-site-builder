<?php
declare(strict_types=1);

use Automattic\SiteBuild\CssTokenExtractor;

function css_token_fixture(string $name): string
{
    $css = file_get_contents(repo_path("tests/fixtures/design/tokens-{$name}.css"));
    if (!is_string($css)) {
        throw new RuntimeException("Missing CSS token fixture: {$name}");
    }
    return $css;
}

test('css-token-extractor ranks palette colors by usage with a stable tie-break', function () {
    $tokens = CssTokenExtractor::extract(css_token_fixture('rich'));

    assert_eq([
        ['color' => '#123456', 'count' => 4],
        ['color' => '#AABBCC', 'count' => 1],
        ['color' => '#FFFFFF', 'count' => 1],
    ], $tokens['palette']);
    assert_eq([
        '"Source Sans 3", Arial, sans-serif',
        '"Fraunces", Georgia, serif',
    ], $tokens['fonts']);
    assert_eq(['2rem', '1.5rem'], $tokens['spacing']);
});

test('css-token-extractor resolves custom properties exactly one level', function () {
    $tokens = CssTokenExtractor::extract(css_token_fixture('rich'));

    assert_eq(4, $tokens['palette'][0]['count']);
    assert_true(
        !str_contains(json_encode($tokens, JSON_THROW_ON_ERROR), 'var('),
        'a custom property whose value is another var remains unresolved',
    );
});

test('css-token-extractor normalizes supported colors and drops translucent alpha', function () {
    $tokens = CssTokenExtractor::extract(<<<'CSS'
body {
    font-family: serif;
    color: #abc;
    background: rgb(18 52 86);
    border-color: rgba(18, 52, 86, 1);
    outline-color: hsl(210, 65.384615%, 20.392157%);
    text-decoration-color: oklch(50% 0 0);
    box-shadow: 0 0 0 1px rgba(18, 52, 86, .5);
    caret-color: oklch(70% 0.4 30);
}
CSS);

    assert_eq('#123456', $tokens['palette'][0]['color']);
    assert_eq(3, $tokens['palette'][0]['count']);
    assert_true(in_array(['color' => '#AABBCC', 'count' => 1], $tokens['palette'], true));
    assert_true(in_array(['color' => '#636363', 'count' => 1], $tokens['palette'], true));
    assert_true(in_array(['color' => 'oklch(70% 0.4 30)', 'count' => 1], $tokens['palette'], true));
    assert_true(
        !str_contains(json_encode($tokens['palette'], JSON_THROW_ON_ERROR), 'rgba'),
        'translucent colors cannot become opaque theme tokens',
    );
});

test('css-token-extractor returns empty lists for sparse stylesheets', function () {
    assert_eq([
        'palette' => [],
        'fonts' => [],
        'spacing' => [],
    ], CssTokenExtractor::extract(css_token_fixture('sparse')));
});

test('css-token-extractor caps one-off-heavy palettes with a stable top ten', function () {
    $tokens = CssTokenExtractor::extract(css_token_fixture('one-off-heavy'));

    assert_eq(10, count($tokens['palette']));
    assert_eq(
        ['#111111', '#222222', '#333333', '#444444', '#555555',
            '#666666', '#777777', '#888888', '#999999', '#AAAAAA'],
        array_column($tokens['palette'], 'color'),
    );
    assert_eq(3, $tokens['palette'][0]['count']);
});

test('css-token-extractor resolves defined vars without scanning nested fallbacks', function () {
    $tokens = CssTokenExtractor::extract(<<<'CSS'
:root {
    --brand: #112233;
    --other: #445566;
}
body {
    color: var(--brand, rgb(255, 255, 255));
    background: var(--brand, var(--other));
    font-family: serif;
}
CSS);

    assert_eq([
        ['color' => '#112233', 'count' => 2],
    ], $tokens['palette']);
});

test('css-token-extractor degrades invalid UTF-8 font tokens to sparse', function () {
    $tokens = CssTokenExtractor::extract(
        "body { color: #112233; font-family: \"Bad\xC3\", serif; padding: 1rem; }",
    );

    assert_eq([
        'palette' => [],
        'fonts' => [],
        'spacing' => [],
    ], $tokens);
});

test('css-token-extractor preserves commas and escapes inside quoted font families', function () {
    $tokens = CssTokenExtractor::extract(<<<'CSS'
body {
    color: #112233;
    font-family: "A,B", serif;
}
.quote {
    font-family: "Quote\",Comma", monospace;
}
CSS);

    assert_eq([
        '"A,B", serif',
        '"Quote\\",Comma", monospace',
    ], $tokens['fonts']);
});

test('css-token-extractor requires color token boundaries and keeps adjacent controls', function () {
    $tokens = CssTokenExtractor::extract(<<<'CSS'
body {
    font-family: serif;
    color: myrgb(1 2 3);
    border-color: #123456xyz;
    background: linear-gradient(#ABCDEF,rgb(4 5 6));
}
CSS);

    assert_eq([
        ['color' => '#ABCDEF', 'count' => 1],
        ['color' => '#040506', 'count' => 1],
    ], $tokens['palette']);
});

test('css-token-extractor keeps unresolved nested var fallbacks opaque after one level', function () {
    $tokens = CssTokenExtractor::extract(<<<'CSS'
:root {
    --brand: #112233;
    --nested: var(--brand, rgb(255, 255, 255));
    --undefined-chain: var(--missing, var(--other, #445566));
}
body {
    color: var(--nested);
    background: var(--undefined-chain);
    font-family: serif;
}
CSS);

    assert_eq([
        'palette' => [],
        'fonts' => [],
        'spacing' => [],
    ], $tokens);
});

test('css-token-extractor treats Unicode and CSS escapes as color name boundaries', function () {
    $tokens = CssTokenExtractor::extract(<<<'CSS'
body {
    font-family: serif;
    color: érgb(1 2 3);
    border-color: #123456é;
    outline-color: #123456\65;
    text-decoration-color: \rgb(7 8 9);
    background: linear-gradient(#ABCDEF,rgb(4 5 6));
}
CSS);

    assert_eq([
        ['color' => '#ABCDEF', 'count' => 1],
        ['color' => '#040506', 'count' => 1],
    ], $tokens['palette']);
});
