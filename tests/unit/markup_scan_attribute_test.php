<?php
declare(strict_types=1);

use Automattic\SiteBuild\MarkupScan;

test('the attribute scanner skips names inside another attribute value', function () {
    $tag = '<div title=" class=\'decoy\' > " data-class="meta" class="actual">';
    $class = MarkupScan::tagAttribute($tag, 'class');
    assert_eq('actual', $class[0]);
    assert_eq('actual', substr($tag, $class[1], strlen($class[0])));
    assert_eq('meta', MarkupScan::tagAttribute($tag, 'data-class')[0]);
    assert_eq('', MarkupScan::tagAttribute('<p class="">', 'class')[0]);
    assert_eq(null, MarkupScan::tagAttribute('<p class=raw>', 'class'));
});
