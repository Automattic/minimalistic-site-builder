<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\ContentBindings;

function bindings_block(string $className, string $text = 'Placeholder'): string
{
    return '<!-- wp:paragraph {"className":"' . $className . '"} -->
<p class="' . $className . '">' . $text . '</p>
<!-- /wp:paragraph -->';
}

/** @param array<string, mixed> $facts */
function bindings_apply(string $markup, array $facts): array
{
    return ContentBindings::apply($markup, $facts);
}

test('a bound block gets the site fact, not a plausible one', function () {
    $out = bindings_apply(bindings_block('ai-bind-phone'), ['phone' => '+34 600 111 222']);

    assert_eq(1, $out['bound']);
    assert_contains('+34 600 111 222', $out['markup']);
    assert_eq(false, str_contains($out['markup'], 'Placeholder'));
});

/**
 * `\w+` stops at the hyphen, so `phone-email` binds to `phone` and a footer
 * shows a phone number where it should show a phone number and an address.
 */
test('a binding whose name has a hyphen is not read as its first half', function () {
    $out = bindings_apply(bindings_block('ai-bind-phone-email'), [
        'email' => 'hola@example.com',
        'phone' => '+34 600 111 222',
    ]);

    assert_contains('hola@example.com<br>+34 600 111 222', $out['markup']);
});

test('a composed binding with one fact missing writes the one it has', function () {
    $out = bindings_apply(bindings_block('ai-bind-address-phone'), ['address' => 'Calle Falsa 123']);

    assert_contains('>Calle Falsa 123</p>', $out['markup']);
});

test('hours arrive either as a list or as one joined string', function () {
    $asList = bindings_apply(bindings_block('ai-bind-hours'), ['hours' => ['Mon-Fri 9-17', 'Sat 10-14']]);
    $asString = bindings_apply(bindings_block('ai-bind-hours'), ['hours' => 'Mon-Fri 9-17; Sat 10-14']);

    assert_contains('Mon-Fri 9-17<br>Sat 10-14', $asList['markup']);
    assert_eq($asList['markup'], $asString['markup']);
});

/**
 * Opening hours only apply to some sites. Leaving the pattern's placeholder
 * publishes invented opening hours for a business that has none, and the class
 * sits on the container so the heading above them goes too.
 */
test('a site with no hours loses the whole block bound to them', function () {
    $markup = '<!-- wp:group {"className":"ai-bind-hours"} -->
<div class="wp-block-group"><!-- wp:heading -->
<h3 class="wp-block-heading">Opening hours</h3>
<!-- /wp:heading --></div>
<!-- /wp:group -->';

    $out = bindings_apply($markup, []);

    assert_eq('', trim($out['markup']));
    assert_eq(1, count($out['unbound']));
});

/**
 * Only hours drop. A site that did not supply a phone number keeps whatever
 * the pattern drew, because an empty footer slot is worse than a placeholder.
 */
test('a binding with no value and no drop rule keeps the pattern copy', function () {
    $out = bindings_apply(bindings_block('ai-bind-phone'), []);

    assert_eq(0, $out['bound']);
    assert_contains('Placeholder', $out['markup']);
});

test('a conditional block is kept when the site declares its feature', function () {
    $out = bindings_apply(bindings_block('ai-show-if-booking'), ['features' => ['booking']]);

    assert_contains('Placeholder', $out['markup']);
    assert_eq([], $out['hidden']);
});

/**
 * Shown by default would put a booking form on a site that takes no bookings,
 * so a condition nobody met removes the block.
 */
test('a conditional block is removed when the site declares nothing', function () {
    $out = bindings_apply(bindings_block('ai-show-if-booking'), []);

    assert_eq('', trim($out['markup']));
    assert_eq(1, count($out['hidden']));
});

test('any one condition matching is enough to keep a block', function () {
    $out = bindings_apply(bindings_block('ai-show-if-booking ai-show-if-menu'), ['features' => ['menu']]);

    assert_contains('Placeholder', $out['markup']);
});

/**
 * A `core/buttons` with no buttons still renders its row of padding, so the
 * page keeps a gap that reads as a layout bug.
 */
test('a container left with no children is removed too', function () {
    $markup = '<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"ai-show-if-booking"} -->
<div class="wp-block-button ai-show-if-booking"><a class="wp-block-button__link" href="/book">Book</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->';

    $out = bindings_apply($markup, []);

    assert_eq('', trim($out['markup']));
    assert_eq(1, count($out['emptied']));
});

/**
 * Big Sky rebuilds the parsed block's `innerContent` slots and caps them at the
 * pattern's original count, so a site with more accounts than the pattern drew
 * loses the extra ones with nothing said.
 */
test('social links are rebuilt from the site accounts however many there are', function () {
    $markup = '<!-- wp:social-links {"className":"ai-bind-social-links"} -->
<ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://example.com","service":"twitter"} /--></ul>
<!-- /wp:social-links -->';

    $out = bindings_apply($markup, ['links' => [
        ['type' => 'mastodon', 'url' => 'https://m.example/@hub'],
        ['type' => 'instagram', 'url' => 'https://instagram.com/hub'],
        ['type' => 'website', 'url' => 'https://hub.example'],
    ]]);

    assert_eq(2, substr_count($out['markup'], '"service":"'));
    assert_contains('"service":"mastodon"', $out['markup']);
    assert_contains('"service":"instagram"', $out['markup']);
    assert_eq(false, str_contains($out['markup'], 'twitter'), 'the pattern default is replaced');
    assert_eq(false, str_contains($out['markup'], 'hub.example'), 'a website is not a social service');
});

test('a site with no accounts keeps the links the pattern drew', function () {
    $markup = '<!-- wp:social-links {"className":"ai-bind-social-links"} -->
<ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://example.com","service":"twitter"} /--></ul>
<!-- /wp:social-links -->';

    assert_contains('twitter', bindings_apply($markup, [])['markup']);
});

/**
 * An avatar is an image, and an image in a bundle is a reference the host
 * resolves on import — the attachment id only exists on the destination site.
 * Writing a URL here produces a page pointing at whatever the fact held.
 */
test('an avatar binding is left for the stage that owns images', function () {
    $markup = '<!-- wp:image {"className":"ai-bind-avatar"} -->
<figure class="wp-block-image ai-bind-avatar"><img src="/pattern.jpg" alt=""/></figure>
<!-- /wp:image -->';

    $out = bindings_apply($markup, ['avatar' => ['url' => 'https://example.com/me.jpg']]);

    assert_contains('/pattern.jpg', $out['markup']);
    assert_eq(1, count($out['deferred']));
});

/**
 * Writing into a block and then cutting the range it sits in means cutting a
 * range whose length was measured before the write, which takes the
 * neighbouring markup with it.
 */
test('a bound block inside a hidden one is removed, not written into', function () {
    $markup = '<!-- wp:group {"className":"ai-show-if-booking"} -->
<div class="wp-block-group">' . bindings_block('ai-bind-phone') . '</div>
<!-- /wp:group -->

<!-- wp:paragraph -->
<p>Still here.</p>
<!-- /wp:paragraph -->';

    $out = bindings_apply($markup, ['phone' => '+34 600 111 222']);

    assert_eq(false, str_contains($out['markup'], '600 111 222'));
    assert_contains('<p>Still here.</p>', $out['markup']);
    assert_eq(1, count($out['hidden']), 'the parent is reported once, not with its child');
});

/**
 * Site metadata is something a person typed, and it lands in markup. Only the
 * separator this adds may be markup.
 */
test('a fact carrying markup is escaped on its way in', function () {
    $out = bindings_apply(bindings_block('ai-bind-address'), ['address' => 'Calle <b>Falsa</b> & 123']);

    assert_contains('Calle &lt;b&gt;Falsa&lt;/b&gt; &amp; 123', $out['markup']);
});

test('a binding this does not know is left alone', function () {
    $out = bindings_apply(bindings_block('ai-bind-mascot'), ['mascot' => 'Wapuu']);

    assert_eq(0, $out['bound']);
    assert_contains('Placeholder', $out['markup']);
});
