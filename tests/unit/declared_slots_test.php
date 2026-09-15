<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\DeclaredSlots;

/**
 * A supplied page as a Blueprint author writes it: a group holding a
 * paragraph, a heading, a paragraph the author protected, an image and a
 * button. Paths are what `block_path` counts.
 */
function declared_markup(): string
{
    return '<!-- wp:group {"className":"approved-hero"} --><div class="wp-block-group approved-hero">'
        . '<!-- wp:paragraph {"className":"eyebrow"} --><p class="eyebrow">Example eyebrow</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Example heading</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"className":"ai-ignore"} --><p class="ai-ignore">Legal line</p><!-- /wp:paragraph -->'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/placeholder.png" alt="Example image"/></figure><!-- /wp:image -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.test/">Example action</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->';
}

function declared_slots(): array
{
    return [
        ['id' => 'headline', 'block_path' => [0, 1], 'field' => 'text', 'fallback' => 'Example heading', 'instruction' => 'A headline', 'max_words' => 6],
        ['id' => 'hero-src', 'block_path' => [0, 3], 'field' => 'url', 'fallback' => 'https://example.test/placeholder.png', 'binding' => 'hero_image_url'],
        ['id' => 'hero-alt', 'block_path' => [0, 3], 'field' => 'alt', 'fallback' => 'Example image', 'instruction' => 'Describe the image'],
        ['id' => 'action', 'block_path' => [0, 4, 0], 'field' => 'text', 'fallback' => 'Example action', 'instruction' => 'A short action', 'max_words' => 3],
        ['id' => 'action-url', 'block_path' => [0, 4, 0], 'field' => 'url', 'fallback' => 'https://example.test/', 'binding' => 'contact_url'],
    ];
}

/**
 * Only the declared bytes move. Everything around them, including the
 * protected paragraph and the eyebrow nobody declared, is byte for byte what
 * the author wrote, and serializing the result again reaches a fixed point.
 */
test('declared slots edit only their text, image and link bytes', function () {
    $pattern = new DeclaredSlots(declared_markup(), declared_slots());

    $values = [
        'headline' => 'Make room for good work',
        'hero-src' => 'https://example.test/media/studio.png',
        'hero-alt' => 'A sunlit studio',
        'action' => 'Visit our studio',
        'action-url' => 'https://example.test/contact/',
    ];
    $expected = strtr(declared_markup(), [
        'Example heading' => 'Make room for good work',
        'https://example.test/placeholder.png' => 'https://example.test/media/studio.png',
        'Example image' => 'A sunlit studio',
        'Example action' => 'Visit our studio',
        'href="https://example.test/"' => 'href="https://example.test/contact/"',
    ]);

    assert_eq($expected, $pattern->serialize($values));
    assert_eq($expected, (new DeclaredSlots($expected, declared_slots()))->serialize($values), 'a fixed point');
    assert_eq(declared_markup(), $pattern->serialize([]), 'no values, no change');
});

test('the inventory carries the current text as each slot example', function () {
    $inventory = (new DeclaredSlots(declared_markup(), declared_slots()))->inventory();

    assert_eq(['headline', 'hero-src', 'hero-alt', 'action', 'action-url'], array_column($inventory, 'id'));
    assert_eq('Example heading', $inventory[0]['example']);
    assert_eq('core/heading', $inventory[0]['block_name']);
});

/**
 * A block that mirrors its text in the comment JSON keeps the mirror in
 * step, with Gutenberg's own comment escaping, and no other key touched.
 */
test('a sourced comment string is kept in step without normalizing other JSON', function () {
    $markup = '<!-- wp:paragraph { "content" : "Old", "style": {"color":{"text":"#abcdef"}}, "className" : "custom" } --><p class="custom" style="color:#abcdef">Old</p><!-- /wp:paragraph -->';
    $slot = ['id' => 'copy', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Fallback'];

    $result = (new DeclaredSlots($markup, [$slot]))->serialize(['copy' => 'New & clear']);

    $expected = str_replace('"Old"', '"New \\u0026amp; clear"', $markup);
    $expected = str_replace('>Old<', '>New &amp; clear<', $expected);
    assert_eq($expected, $result);
});

test('a slot on protected content, or under a protected ancestor, is refused', function () {
    $onProtected = ['id' => 'legal', 'block_path' => [0, 2], 'field' => 'text', 'fallback' => 'x'];
    assert_contains('protected', assert_throws(static fn () => new DeclaredSlots(declared_markup(), [$onProtected]))->getMessage());

    $underProtected = str_replace('approved-hero', 'ai-ignore', declared_markup());
    assert_contains('protected', assert_throws(static fn () => new DeclaredSlots($underProtected, declared_slots()))->getMessage());
});

/**
 * Replacing the whole of a rich-text value with one string would flatten the
 * markup inside it. That is refused rather than done quietly.
 */
test('whole rich-text replacement is refused instead of flattening inline markup', function () {
    $markup = '<!-- wp:paragraph --><p>Keep <strong>this</strong> formatting</p><!-- /wp:paragraph -->';

    $thrown = assert_throws(static fn () => new DeclaredSlots($markup, [
        ['id' => 'copy', 'block_path' => [0], 'field' => 'text', 'fallback' => 'Fallback'],
    ]));

    assert_contains('inline markup', $thrown->getMessage());
});

test('an unknown path, an overlapping slot, a structural field and broken source are refused', function () {
    $slots = declared_slots();

    $nowhere = $slots[0];
    $nowhere['block_path'] = [99];
    assert_contains('unknown block_path', assert_throws(static fn () => new DeclaredSlots(declared_markup(), [$nowhere]))->getMessage());

    $twice = $slots[0];
    $twice['id'] = 'same-target';
    assert_contains('overlap', assert_throws(static fn () => new DeclaredSlots(declared_markup(), [$slots[0], $twice]))->getMessage());

    $structural = $slots[0];
    $structural['field'] = 'className';
    assert_contains('Unsupported slot', assert_throws(static fn () => new DeclaredSlots(declared_markup(), [$structural]))->getMessage());

    $urlWithoutBinding = $slots[4];
    unset($urlWithoutBinding['binding']);
    assert_contains('binding', assert_throws(static fn () => new DeclaredSlots(declared_markup(), [$urlWithoutBinding]))->getMessage());

    $broken = str_replace('<!-- /wp:heading -->', '<!-- /wp:paragraph -->', declared_markup());
    assert_throws(static fn () => new DeclaredSlots($broken, $slots));
});

test('a value is checked against its slot before it is written', function () {
    $text = ['field' => 'text', 'max_words' => 2];
    assert_eq(null, DeclaredSlots::valueError($text, 'Two words'));
    assert_eq('word limit exceeded', DeclaredSlots::valueError($text, 'Three whole words'));
    assert_eq('expected a scalar value, not HTML', DeclaredSlots::valueError($text, '<b>no</b>'));
    assert_eq('empty content', DeclaredSlots::valueError($text, '   '));
    assert_eq(null, DeclaredSlots::valueError(['field' => 'alt'], ''));
    assert_eq('unsupported URL scheme', DeclaredSlots::valueError(['field' => 'url'], 'javascript:alert(1)'));
    assert_eq(null, DeclaredSlots::valueError(['field' => 'url'], '/contact/'));
});

test('blocks nobody declared keep their bytes, foreign ones included', function () {
    $custom = "\n<!-- wp:acme/card {\"style\":{\"color\":\"red\"}} --><aside data-x='a'>Custom</aside><!-- /wp:acme/card -->";
    $pattern = new DeclaredSlots(declared_markup() . $custom, declared_slots());

    assert_true(str_ends_with($pattern->serialize(['headline' => 'Updated']), $custom));
});
