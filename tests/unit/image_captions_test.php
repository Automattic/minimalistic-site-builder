<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageCaptions;

/**
 * ImageCaptions removes <figcaption> from core/image blocks outside a
 * core/gallery (BIGR-867). Captions inside a gallery are permitted and left
 * alone. Both removal channels are exercised: the authored <figcaption>
 * element and the `caption` comment attribute, because the block fixer
 * round-trips one into the other.
 */

function ic_image(string $inner, string $attrs = '{"sizeSlug":"large"}'): string
{
    return "<!-- wp:image {$attrs} -->\n"
        . "<figure class=\"wp-block-image size-large\">{$inner}</figure>\n"
        . '<!-- /wp:image -->';
}

function ic_img(string $alt = 'A photo'): string
{
    return "<img src=\"theme:./assets/x.jpg\" alt=\"{$alt}\"/>";
}

function ic_caption(string $text): string
{
    return "<figcaption class=\"wp-element-caption\">{$text}</figcaption>";
}

function ic_gallery(string ...$images): string
{
    return "<!-- wp:gallery {\"columns\":2} -->\n"
        . "<figure class=\"wp-block-gallery\">\n" . implode("\n", $images) . "\n</figure>\n"
        . '<!-- /wp:gallery -->';
}

test('a figcaption on a standalone image is removed', function () {
    $markup = ic_image(ic_img() . ic_caption('Brunswick: Full slab removal.'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], '<figcaption'), 'figcaption removed');
    assert_true(!str_contains($out['markup'], 'Brunswick'), 'caption text removed');
    assert_contains('<img', $out['markup'], 'the image itself survives');
    assert_eq(1, count($out['warnings']), 'one warning per removed caption');
});

test('a figcaption on an image inside a gallery is kept', function () {
    $markup = ic_gallery(ic_image(ic_img() . ic_caption('Plate 4, 1972.')));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'gallery images are untouched');
    assert_eq(0, count($out['warnings']), 'nothing removed, nothing warned');
});

test('a caption comment attribute outside a gallery is dropped', function () {
    $markup = ic_image(ic_img(), '{"sizeSlug":"large","caption":"Coburg site"}');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'caption'), 'caption attr dropped');
    assert_contains('"sizeSlug":"large"', $out['markup'], 'sibling attrs survive');
    assert_eq(1, count($out['warnings']), 'attr removal is warned like an element');
});

test('a caption comment attribute inside a gallery is kept', function () {
    $markup = ic_gallery(ic_image(ic_img(), '{"sizeSlug":"large","caption":"Plate 4"}'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'gallery attrs are untouched');
});

test('the gallery block own caption is untouched', function () {
    $markup = "<!-- wp:gallery {\"columns\":2} -->\n"
        . "<figure class=\"wp-block-gallery\">\n" . ic_image(ic_img()) . "\n"
        . "<figcaption class=\"blocks-gallery-caption\">Selected work, 1971-1980.</figcaption>\n"
        . "</figure>\n<!-- /wp:gallery -->";
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_contains('blocks-gallery-caption', $out['markup'], 'gallery caption survives');
    assert_contains('Selected work', $out['markup'], 'gallery caption text survives');
});

test('table captions are untouched', function () {
    $markup = "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>a</td></tr></tbody></table>"
        . "<figcaption class=\"wp-element-caption\">Rates, 2026.</figcaption></figure>\n<!-- /wp:table -->";
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'only core/image is in scope');
});

test('embed captions are untouched', function () {
    $markup = "<!-- wp:embed {\"url\":\"https://example.com/watch\",\"type\":\"video\"} -->\n"
        . "<figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">"
        . "https://example.com/watch</div>"
        . "<figcaption class=\"wp-element-caption\">Site walkthrough.</figcaption></figure>\n"
        . '<!-- /wp:embed -->';
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'only core/image is in scope');
});

test('an empty caption attribute is left exactly as authored', function () {
    // Inert: CoreBlockRenderer guards on richTextEmpty(), so this renders
    // nothing. Rung 2 — leave harmless defects alone, emit no warning.
    $markup = ic_image(ic_img(), '{"sizeSlug":"large","caption":""}');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'byte-identical');
    assert_eq(0, count($out['warnings']), 'an inert value is not an actionable warning');
});

test('markup with no image is returned byte-identical', function () {
    $markup = "<!-- wp:paragraph -->\n<p>Just words.</p>\n<!-- /wp:paragraph -->";
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup']);
    assert_eq(0, count($out['notes']));
});

test('an uncaptioned image is returned byte-identical', function () {
    $markup = ic_image(ic_img());
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup']);
    assert_eq(0, count($out['warnings']));
});

test('a caption containing inline markup is removed at its element boundary', function () {
    $markup = ic_image(ic_img() . ic_caption('Shot by <em>A. Maxwell</em>, <a href="/c">Coburg</a>'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], '<figcaption'), 'figcaption removed');
    assert_true(!str_contains($out['markup'], 'A. Maxwell'), 'inline markup went with it');
    assert_contains('<img', $out['markup'], 'the image survives');
    assert_contains('</figure>', $out['markup'], 'the figure closer survives');
});

test('a caption whose attribute value contains > is not over-spliced', function () {
    $inner = ic_img() . '<figcaption class="wp-element-caption" data-note="a > b">Before</figcaption>';
    $markup = ic_image($inner);
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], '<figcaption'), 'figcaption removed');
    assert_true(!str_contains($out['markup'], 'Before'), 'caption text removed');
    assert_contains('<img', $out['markup'], 'the image was not swallowed');
});

test('tag-like caption text inside a quoted image attribute is not an element', function () {
    $markup = ic_image(
        '<img src="theme:./assets/x.jpg" alt="Literal <figcaption>syntax</figcaption> sample"/>',
    );
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'quoted attribute text stays byte-identical');
    assert_eq(0, count($out['warnings']), 'no caption element was removed');
});

test('a recoverable closing-tag spelling is still removed', function () {
    $markup = ic_image(ic_img() . '<figcaption class="wp-element-caption">Before</figcaption >');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'Before'), 'caption text removed');
    assert_true(!str_contains($out['markup'], '<figcaption'), 'caption element removed');
    assert_eq(1, count($out['warnings']));
});

test('the pass is idempotent', function () {
    $markup = ic_image(ic_img() . ic_caption('Removed once.'));
    $first = ImageCaptions::stripOutsideGalleries($markup);
    $second = ImageCaptions::stripOutsideGalleries($first['markup']);

    assert_eq($first['markup'], $second['markup'], 'second run is a no-op');
    assert_eq(0, count($second['warnings']), 'second run removes nothing');
});

test('malformed markup is returned untouched without throwing', function () {
    $markup = "<!-- wp:image -->\n<figure><img src=\"x.jpg\"" ;
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'unparseable input survives');
});

test('a file with mismatched delimiters is skipped whole and noted', function () {
    // Stray foreign closer: hasMismatchedDelimiters() is true. Skip the whole
    // file — splice offsets in a structurally broken document are untrustworthy.
    $markup = "<!-- wp:gallery -->\n<figure>\n"
        . ic_image(ic_img() . ic_caption('Plate 4.')) . "\n<!-- /wp:group -->\n";
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'nothing is stripped from a broken document');
    assert_eq(0, count($out['warnings']), 'a skip removed nothing, so it does not warn');
    assert_eq(1, count($out['notes']), 'the skip is logged');
});

test('an image under an unclosed gallery keeps its caption via the ancestor walk', function () {
    $markup = "<!-- wp:gallery -->\n<figure class=\"wp-block-gallery\">\n"
        . ic_image(ic_img() . ic_caption('Plate 4.')) . "\n";
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq($markup, $out['markup'], 'unclosed gallery still parents its image');
    assert_eq(0, count($out['warnings']));
    assert_eq(0, count($out['notes']), 'spared by ancestry, not by the skip guard');
});

test('the warning row carries block path, authored text and disposition', function () {
    $markup = ic_image(ic_img() . ic_caption('Preston: Site clean-up, 3 days.'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    $row = $out['warnings'][0];
    assert_contains('wp:image', $row, 'names the block');
    assert_contains('authored value', $row, 'names the authored value');
    assert_contains('Preston: Site clean-up, 3 days.', $row, 'carries the removed text');
    assert_contains('delivered removed', $row, 'names the delivered value');
    assert_contains('disposition:', $row, 'carries a disposition');
});

test('authored text containing quotes and semicolons stays encoded in the row', function () {
    $markup = ic_image(ic_img() . ic_caption('He said "go"; then left'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    $row = $out['warnings'][0];
    // Warnings::value() JSON-encodes, so the inner quotes arrive escaped and
    // the row's own "; " delimiter stays unambiguous.
    assert_contains('\\"go\\"', $row, 'inner quotes are escaped, not raw');
    assert_contains('delivered removed', $row, 'the row is still parseable');
});

test('an image with only a caption attribute and no element is still cleaned', function () {
    $markup = ic_image(ic_img(), '{"caption":"Orphan attr"}');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'Orphan attr'), 'attr-only caption removed');
    assert_eq(1, count($out['warnings']));
});

test('a malformed non-string caption attribute is removed and recorded', function () {
    $markup = ic_image(ic_img(), '{"sizeSlug":"large","caption":["injected"]}');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'caption'), 'malformed caption attr removed');
    assert_contains('"sizeSlug":"large"', $out['markup'], 'sibling attrs survive');
    assert_eq(1, count($out['warnings']));
    assert_contains('["injected"]', $out['warnings'][0], 'authored array value stays encoded');
});

test('a malformed caption attribute is removed before an unsafe image is deferred', function () {
    $markup = '<!-- wp:image {"sizeSlug":"large","caption":["injected"]} -->'
        . '<figure class="wp-block-image size-large"><img src="/x.jpg" alt="A yard"/></figure>';
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'caption'), 'unsafe image comment attr is sanitized');
    assert_contains('<img', $out['markup'], 'unsafe image bytes otherwise survive');
    assert_eq(1, count($out['warnings']));
    assert_contains('["injected"]', $out['warnings'][0], 'original array evidence is retained');
    assert_contains('not structurally safe', implode("\n", $out['notes']), 'other repairs stay deferred');
});

test('a malformed gallery caption attribute is removed without dropping its element caption', function () {
    $image = ic_image(ic_img() . ic_caption('Allowed gallery caption.'),
        '{"sizeSlug":"large","caption":["injected"]}');
    $markup = '<!-- wp:gallery --><figure class="wp-block-gallery">'
        . $image
        . '</figure><!-- /wp:gallery -->';
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_contains('Allowed gallery caption.', $out['markup'], 'valid gallery element caption survives');
    assert_true(!str_contains($out['markup'], '"caption":["injected"]'), 'malformed comment attr removed');
    assert_eq(1, count($out['warnings']));
    assert_contains('["injected"]', $out['warnings'][0], 'malformed value is recorded');
});

test('an empty figcaption beside a non-empty caption attribute still warns', function () {
    $markup = ic_image(ic_img() . '<figcaption class="wp-element-caption"></figcaption>',
        '{"sizeSlug":"large","caption":"Coburg site"}');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'Coburg site'), 'attr text removed');
    assert_eq(1, count($out['warnings']), 'non-empty attr text is never lost silently');
    assert_contains('Coburg site', $out['warnings'][0]);
});

test('every figcaption on one image is removed in a single pass', function () {
    $markup = ic_image(ic_img() . ic_caption('One') . ic_caption('Two'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'One'), 'first removed');
    assert_true(!str_contains($out['markup'], 'Two'), 'second removed too');
    assert_true(!str_contains($out['markup'], '<figcaption'), 'none survive');
    assert_eq(1, count($out['warnings']), 'one warning per image, not per caption');
    assert_contains('One', $out['warnings'][0], 'warning retains the first authored value');
    assert_contains('Two', $out['warnings'][0], 'warning retains the second authored value');
    $again = ImageCaptions::stripOutsideGalleries($out['markup']);
    assert_eq($out['markup'], $again['markup'], 'already a fixed point');
});

test('one warning retains divergent element and comment attribute values', function () {
    $markup = ic_image(ic_img() . ic_caption('Element value'), '{"caption":"Attribute value"}');
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_true(!str_contains($out['markup'], 'Element value'));
    assert_true(!str_contains($out['markup'], 'Attribute value'));
    assert_eq(1, count($out['warnings']), 'one warning row per image');
    assert_contains('Element value', $out['warnings'][0]);
    assert_contains('Attribute value', $out['warnings'][0]);
});

test('one warning retains every long distinct caption value', function () {
    $first = str_repeat('A', 200);
    $second = str_repeat('B', 200);
    $markup = ic_image(ic_img() . ic_caption($first) . ic_caption($second));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq(1, count($out['warnings']), 'one warning row per image');
    assert_contains(str_repeat('A', 40), $out['warnings'][0], 'first value is represented');
    assert_contains(str_repeat('B', 40), $out['warnings'][0], 'later values survive per-value truncation');
});

test('truncated warning collisions retain distinct fingerprints', function () {
    $prefix = str_repeat('Same prefix ', 20);
    $markup = ic_image(ic_img() . ic_caption($prefix . 'first') . ic_caption($prefix . 'second'));
    $out = ImageCaptions::stripOutsideGalleries($markup);

    assert_eq(1, count($out['warnings']), 'one warning row per image');
    assert_eq(2, substr_count($out['warnings'][0], 'fingerprint:'), 'both raw values remain distinguishable');
});
