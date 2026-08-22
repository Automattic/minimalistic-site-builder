<?php
declare(strict_types=1);

use Automattic\SiteBuild\PlainText;

test('PlainText::fromMarkup strips tags and decodes HTML5 entities', function (): void {
    assert_eq('Rock & Roll', PlainText::fromMarkup('<p>Rock <b>&amp;</b> Roll</p>'));
    assert_eq("it's", PlainText::fromMarkup('<p>it&#039;s</p>'));
    // &nbsp; is HTML5-only in the sense that ENT_HTML401 also has it, but the
    // flags matter for the wider set; assert one that separates them.
    assert_eq("\u{2009}", PlainText::fromMarkup('&thinsp;'));
});

test('PlainText::fromMarkup does not trim', function (): void {
    assert_eq('  spaced  ', PlainText::fromMarkup('<p>  spaced  </p>'));
});
