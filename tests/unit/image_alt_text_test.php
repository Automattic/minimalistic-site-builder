<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageAltText;

test('image alt cleanup preserves the subject and all other markup bytes', function () {
    $before = '<!-- wp:image --><figure><img class="photo" src="theme:./assets/food.jpg" alt="AI_IMAGE: Bread &amp; wine | hero image | photo | square" /></figure><!-- /wp:image -->';
    $sibling = '<!-- wp:paragraph --><p>Keep this text &amp; its spaces.</p><!-- /wp:paragraph -->';
    $result = ImageAltText::clean($before . $sibling);
    $expected = str_replace('AI_IMAGE: Bread &amp; wine | hero image | photo | square', 'Bread &amp; wine', $before) . $sibling;
    assert_eq($expected, $result['markup']);
    assert_eq([['index' => 0, 'authored' => 'AI_IMAGE: Bread & wine | hero image | photo | square', 'delivered' => 'Bread & wine']], $result['repairs']);
    assert_eq(['markup' => $expected, 'repairs' => []], ImageAltText::clean($expected));
});

test('image alt cleanup changes only real image alt attributes', function () {
    $untouched = '<!-- <img alt="AI_IMAGE: comment | photo"> -->'
        . '<p data-alt="AI_IMAGE: metadata">AI_IMAGE: visible text</p>'
        . '<img alt="" src="decoration.jpg"><img alt="A normal description" src="plain.jpg">'
        . '<script>const html = \'<img alt="AI_IMAGE: script | photo">\';</script>';
    $image = '<img title=\'alt="AI_IMAGE: nested"\' alt=\'AI_IMAGE: Georgian bread &quot;boat&quot; | card | photo | landscape\' src="bread.jpg">';
    $result = ImageAltText::clean($untouched . $image);
    assert_eq($untouched . str_replace('AI_IMAGE: Georgian bread &quot;boat&quot; | card | photo | landscape', 'Georgian bread &quot;boat&quot;', $image), $result['markup']);
    assert_eq(1, count($result['repairs']));
});

test('image alt cleanup preserves UTF-8 and handles separate image slots', function () {
    $markup = '<img alt="AI_IMAGE: ხაჭაპური | card | photo | square" src="a.jpg">'
        . '<img alt="AI_IMAGE: Pâté &amp; herbs | card | photo | square" src="b.jpg">';
    $result = ImageAltText::clean($markup);
    assert_eq('<img alt="ხაჭაპური" src="a.jpg"><img alt="Pâté &amp; herbs" src="b.jpg">', $result['markup']);
    assert_eq(2, count($result['repairs']));
});
