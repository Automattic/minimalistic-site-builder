<?php
declare(strict_types=1);

use Automattic\SiteBuild\InlineStyleHoister;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\PhpBlockFixer;

test('inline style hoister moves styles to deterministic CSS and preserves block attributes', function (): void {
    $style = 'color:red; min-height: 4rem';
    $className = 'se-' . hash('sha256', $style);
    $markup = '<!-- wp:group {"className":"existing","style":{"dimensions":{"minHeight":"4rem"}}} -->'
        . '<div class="wp-block-group existing" data-note="keep" style="' . $style . '">x</div>'
        . '<!-- /wp:group -->';

    $result = (new InlineStyleHoister())->hoist(['part.html' => $markup]);

    assert_eq(1, $result['style_count']);
    assert_eq('.' . $className . '{' . $style . "}\n", $result['css']);
    assert_contains('"className":"existing ' . $className . '"', $result['parts']['part.html']);
    assert_contains('"style":{"dimensions":{"minHeight":"4rem"}}', $result['parts']['part.html']);
    assert_contains('class="wp-block-group existing ' . $className . '"', $result['parts']['part.html']);
    assert_contains('data-note="keep"', $result['parts']['part.html']);
    assert_true(!str_contains($result['parts']['part.html'], ' style="' . $style . '"'));
});

test('inline style hoister emits one sorted rule per unique declaration', function (): void {
    $first = 'z-index:2';
    $second = 'color:blue';
    $firstClass = 'se-' . hash('sha256', $first);
    $secondClass = 'se-' . hash('sha256', $second);
    $parts = [
        'one.html' => '<div style="' . $first . '"></div><span style="' . $first . '"></span>',
        'two.html' => '<p style="' . $second . '">x</p>',
    ];

    $result = (new InlineStyleHoister())->hoist($parts);
    $rules = [
        $firstClass => $first,
        $secondClass => $second,
    ];
    ksort($rules);
    $expectedCss = '';
    foreach ($rules as $className => $declarations) {
        $expectedCss .= '.' . $className . '{' . $declarations . "}\n";
    }

    assert_eq(3, $result['style_count']);
    assert_eq($expectedCss, $result['css']);
    assert_eq(2, substr_count($result['parts']['one.html'], $firstClass));
    assert_contains('class="' . $secondClass . '"', $result['parts']['two.html']);
});

test('inline style hoister targets a nested element through a durable owner-root carrier', function (): void {
    $style = 'border-radius:999px';
    $descendant = '> a.wp-block-button__link';
    $className = 'se-' . hash('sha256', $style . "\0" . $descendant);
    $markup = '<!-- wp:button {"text":"Go","url":"/"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" style="' . $style . '">Go</a></div>'
        . '<!-- /wp:button -->';

    $result = (new InlineStyleHoister())->hoist(['button.html' => $markup]);

    assert_contains('"className":"' . $className . '"', $result['parts']['button.html']);
    assert_contains('class="wp-block-button ' . $className . '"', $result['parts']['button.html']);
    assert_true(!str_contains($result['parts']['button.html'], 'wp-block-button__link ' . $className));
    assert_contains('.' . $className . ' ' . $descendant . '{' . $style . '}', $result['css']);
    assert_true(!str_contains($result['parts']['button.html'], ' style="' . $style . '"'));
});

test('inline style hoister leaves style-free markup byte-identical', function (): void {
    $markup = '<!-- wp:paragraph {"content":"Hello"} --><p>Hello</p><!-- /wp:paragraph -->';

    $result = (new InlineStyleHoister())->hoist(['part.html' => $markup]);

    assert_eq($markup, $result['parts']['part.html']);
    assert_eq('', $result['css']);
    assert_eq(0, $result['style_count']);
});

test('inline style hoister is deterministic for repeated pristine transformer output', function (): void {
    $parts = [
        'header.html' => '<!-- wp:group {"className":"header"} -->'
            . '<div class="wp-block-group header" style="min-height:5rem">Header</div>'
            . '<!-- /wp:group -->',
        'page.html' => '<!-- wp:paragraph {"content":"Hello"} -->'
            . '<p style="color:#123456">Hello</p><!-- /wp:paragraph -->',
    ];
    $hoister = new InlineStyleHoister();

    $first = $hoister->hoist($parts);
    $second = $hoister->hoist($parts);

    assert_eq($first, $second);
});

test('inline style carrier survives PhpBlockFixer reserialization', function (): void {
    $style = 'margin-top:2rem';
    $className = 'se-' . hash('sha256', $style);
    $markup = '<!-- wp:paragraph {"content":"Hello","style":{"spacing":{"margin":{"top":"2rem"}}}} -->'
        . '<p style="' . $style . '">Hello</p><!-- /wp:paragraph -->';
    $result = (new InlineStyleHoister())->hoist(['part.html' => $markup]);
    $root = sys_get_temp_dir() . '/inline-style-hoister-' . bin2hex(random_bytes(8));
    $theme = $root . '/parts';
    if (!mkdir($theme, 0775, true) && !is_dir($theme)) {
        throw new RuntimeException('Could not create inline-style hoister test directory.');
    }
    $path = $theme . '/part.html';
    try {
        file_put_contents($path, $result['parts']['part.html']);
        (new PhpBlockFixer())->fix($root);
        $fixed = (string) file_get_contents($path);
        assert_contains('"className":"' . $className . '"', $fixed);
        assert_contains($className, $fixed);
    } finally {
        @unlink($path);
        @rmdir($theme);
        @rmdir($root);
    }
});

test('nested inline style selector still matches only its original descendant after PhpBlockFixer', function (): void {
    $style = 'border-radius:999px';
    $descendant = '> a.wp-block-button__link';
    $className = 'se-' . hash('sha256', $style . "\0" . $descendant);
    $markup = '<!-- wp:button {"text":"Go","url":"/","style":{"border":{"radius":"999px"}}} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" style="' . $style . '">Go</a></div>'
        . '<!-- /wp:button -->';
    $result = (new InlineStyleHoister())->hoist(['part.html' => $markup]);
    $root = sys_get_temp_dir() . '/nested-inline-style-hoister-' . bin2hex(random_bytes(8));
    $theme = $root . '/parts';
    if (!mkdir($theme, 0775, true) && !is_dir($theme)) {
        throw new RuntimeException('Could not create nested inline-style hoister test directory.');
    }
    $path = $theme . '/part.html';
    try {
        file_put_contents($path, $result['parts']['part.html']);
        (new PhpBlockFixer())->fix($root);
        $fixed = (string) file_get_contents($path);
        $fragment = HtmlFragment::parse($fixed);
        $targets = $fragment->querySelectorAll('.' . $className . ' > a.wp-block-button__link');

        assert_eq(1, count($targets), 'carrier selector must keep matching one descendant');
        assert_eq('a', $targets[0]->tagName());
        assert_eq('Go', $targets[0]->textContent());
        assert_true(!in_array(
            $className,
            preg_split('/\s+/', trim((string) $targets[0]->attribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            true,
        ), 'carrier must remain on owner root, not shift to styled descendant');
        assert_contains('.' . $className . ' ' . $descendant . '{' . $style . '}', $result['css']);
    } finally {
        @unlink($path);
        @rmdir($theme);
        @rmdir($root);
    }
});
