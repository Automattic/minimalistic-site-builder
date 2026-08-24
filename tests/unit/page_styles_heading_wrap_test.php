<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PageStylesStep;

/** Grab the CSS rule block whose selector list matches $needle. */
function css_block_for_selector(string $css, string $needle): ?string
{
    if (preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER) === false) {
        return null;
    }
    foreach ($m as $rule) {
        if (str_contains($rule[1], $needle)) {
            return $rule[2];
        }
    }
    return null;
}

test('WORD_WRAP_CSS never grants break-word to display headings', function () {
    $css = PageStylesStep::WORD_WRAP_CSS;
    // Any rule block that mentions a heading selector must not set break-word.
    if (preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER) !== false) {
        foreach ($m as $rule) {
            $selectors = $rule[1];
            $body = $rule[2];
            $touchesHeading = preg_match('/\bh[1-3]\b|\.wp-block-heading\b/', $selectors) === 1;
            if ($touchesHeading && str_contains($body, 'break-word')) {
                throw new RuntimeException(
                    'heading selector still granted overflow-wrap:break-word: ' . trim($selectors)
                );
            }
        }
    }
});

test('WORD_WRAP_CSS keeps headings at normal wrap with no hyphens or word-break splitting', function () {
    $css = PageStylesStep::WORD_WRAP_CSS;
    $body = css_block_for_selector($css, 'h1, h2, h3');
    assert_true($body !== null, 'the normal-wrap block for headings exists');
    assert_contains('overflow-wrap: normal', $body, 'headings keep overflow-wrap:normal');
    assert_contains('word-break: normal', $body, 'headings keep word-break:normal');
    assert_contains('hyphens: none', $body, 'headings forbid hyphenation');
    assert_contains('text-wrap: pretty', $body, 'headings and copy avoid a dangling last word');
    assert_true(!str_contains($body, 'break-all'), 'headings never word-break:break-all');
});

test('WORD_WRAP_CSS names the hero heading subjects so it can beat scaffold specificity', function () {
    $css = PageStylesStep::WORD_WRAP_CSS;
    $body = css_block_for_selector($css, '.hero-composition__copy .wp-block-heading');
    assert_true($body !== null, 'wrap policy includes the hero copy heading subject');
    assert_contains('overflow-wrap: normal', $body);
    $layered = css_block_for_selector($css, '.hero-composition--layered-poster .wp-block-heading');
    assert_true($layered !== null, 'wrap policy includes the layered-poster heading subject');
});
