<?php
declare(strict_types=1);

use Automattic\SiteBuild\LinkTargets;

test('LinkTargets::hrefsIn reads rendered hrefs ONLY', function (): void {
    $markup = '<!-- wp:navigation-link {"url":"\/menu\/"} /-->' . "\n"
            . '<p><a href="/contact/">Contact</a></p>';
    $hrefs = LinkTargets::hrefsIn($markup);
    assert_true(in_array('/contact/', $hrefs, true), 'reads rendered href');
    assert_true(!in_array('/menu/', $hrefs, true), 'JSON urls are NOT this method job');
});

test('LinkTargets::urlAttrsIn reads block-JSON urls and unescapes them', function (): void {
    $urls = LinkTargets::urlAttrsIn('<!-- wp:navigation-link {"url":"\/menu\/"} /-->');
    assert_eq(['/menu/'], $urls);
});

test('LinkTargets::allTargets is hrefs then urls, in that order', function (): void {
    $markup = '<p><a href="/contact/">C</a></p><!-- wp:navigation-link {"url":"\/menu\/"} /-->';
    assert_eq(['/contact/', '/menu/'], LinkTargets::allTargets($markup),
        'order is the caller order this replaces; downstream may rely on it');
});

test('LinkTargets::hrefAttrsIn reads block-JSON hrefs and unescapes them', function (): void {
    $hrefs = LinkTargets::hrefAttrsIn('<!-- wp:image {"href":"javascript:alert(1)"} /-->');
    assert_eq(['javascript:alert(1)'], $hrefs);
    $escaped = LinkTargets::hrefAttrsIn('<!-- wp:file {"href":"\/contact\/"} /-->');
    assert_eq(['/contact/'], $escaped);
});

test('LinkTargets::allTargets includes JSON href on image file and media-text', function (): void {
    $markup = '<!-- wp:image {"href":"javascript:alert(1)"} /-->'
        . '<!-- wp:file {"href":"data:text/html,hi"} /-->'
        . '<!-- wp:media-text {"href":"vbscript:msgbox(1)"} /-->';
    $targets = LinkTargets::allTargets($markup);
    assert_true(in_array('javascript:alert(1)', $targets, true), 'JSON href javascript: is visible');
    assert_true(in_array('data:text/html,hi', $targets, true), 'JSON href data: is visible');
    assert_true(in_array('vbscript:msgbox(1)', $targets, true), 'JSON href vbscript: is visible');
    assert_eq([], LinkTargets::hrefsIn($markup), 'JSON href is not an HTML href');
    assert_eq([], LinkTargets::urlAttrsIn($markup), 'JSON href is not a JSON url');
});

test('LinkTargets::textLinkHrefAttrsIn reads block-JSON textLinkHref and unescapes them', function (): void {
    $hrefs = LinkTargets::textLinkHrefAttrsIn('<!-- wp:file {"textLinkHref":"javascript:alert(1)"} /-->');
    assert_eq(['javascript:alert(1)'], $hrefs);
    $escaped = LinkTargets::textLinkHrefAttrsIn('<!-- wp:file {"textLinkHref":"\/download\/"} /-->');
    assert_eq(['/download/'], $escaped);
});

test('LinkTargets::allTargets includes JSON textLinkHref', function (): void {
    $markup = '<!-- wp:file {"href":"/file.pdf","textLinkHref":"javascript:alert(1)"} /-->';
    $targets = LinkTargets::allTargets($markup);
    assert_true(in_array('/file.pdf', $targets, true), 'JSON href is visible');
    assert_true(in_array('javascript:alert(1)', $targets, true), 'JSON textLinkHref javascript: is visible');
    assert_eq([], LinkTargets::hrefsIn($markup), 'JSON textLinkHref is not an HTML href');
    assert_eq([], LinkTargets::urlAttrsIn($markup), 'JSON textLinkHref is not a JSON url');
    assert_eq(['/file.pdf'], LinkTargets::hrefAttrsIn($markup));
    assert_eq(['javascript:alert(1)'], LinkTargets::textLinkHrefAttrsIn($markup));
});

test('LinkTargets::isThemeAssetPath recognizes media, not routes', function (): void {
    assert_true(LinkTargets::isThemeAssetPath('/wp-content/themes/demo/assets/a.jpg'));
    assert_true(LinkTargets::isThemeAssetPath('/whatever/photo.png'));
    assert_true(!LinkTargets::isThemeAssetPath('/contact/'));
});

test('LinkTargets::anchorsIn collects ids', function (): void {
    $ids = LinkTargets::anchorsIn('<section id="hero"></section><div id="cta"></div>');
    assert_true(isset($ids['hero']) && isset($ids['cta']));
});

test('LinkTargets::normalizeTarget decodes JSON unicode and HTML entities', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('\u006Aavascript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('javascript&colon;alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('\u006Aavascript:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('javascript&colon;alert(1)'));
});

test('LinkTargets::normalizeTarget strips tab or C0 inside the scheme', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget("java\tscript:alert(1)"));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java script:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme("java\tscript:alert(1)"));
    assert_true(LinkTargets::isDangerousScheme('java script:alert(1)'));
});

test('LinkTargets::normalizeTarget decodes unterminated colon entities', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('javascript&#58alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('javascript&#x3aalert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('javascript&#X3Aalert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('javascript&colonalert(1)'));
    assert_true(LinkTargets::isDangerousScheme('javascript&#58alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('javascript&#x3aalert(1)'));
});

test('LinkTargets::normalizeTarget decodes unterminated tab entities', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#9script:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x9script:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&tabscript:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('java&#9script:alert(1)'));
});

test('LinkTargets::normalizeTarget decodes unterminated numeric letter entities', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#115cript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('&#106avascript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x73cript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('&#x6aavascript:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('java&#115cript:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('&#x6aavascript:alert(1)'));
});

test('LinkTargets::normalizeTarget decodes CR numeric entities with chr', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#13script:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#13;script:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x0dscript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#xd;script:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('java&#13;script:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('java&#x0d;script:alert(1)'));
    assert_true(!str_contains(LinkTargets::normalizeTarget('java&#13;script:alert(1)'), '&#1;'));
});

test('LinkTargets::normalizeTarget strips DEL inside the scheme', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget("java\x7fscript:alert(1)"));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#127script:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x7fscript:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme("java\x7fscript:alert(1)"));
    assert_true(LinkTargets::isDangerousScheme('java&#x7f;script:alert(1)'));
});

test('LinkTargets::normalizeTarget decodes padded hex letter entities', function (): void {
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x0073cript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x000073cript:alert(1)'));
    assert_eq('javascript:alert(1)', LinkTargets::normalizeTarget('java&#x073cript:alert(1)'));
    assert_true(LinkTargets::isDangerousScheme('java&#x0073cript:alert(1)'));
});

test('LinkTargets::allTargets reads unicode-escaped JSON keys', function (): void {
    $full = LinkTargets::allTargets('<!-- wp:image {"\u0068ref":"javascript:alert(1)"} /-->');
    $split = LinkTargets::allTargets('<!-- wp:file {"textLinkHr\u0065f":"javascript:alert(2)"} /-->');
    assert_true(LinkTargets::isDangerousScheme($full[0] ?? ''));
    assert_true(LinkTargets::isDangerousScheme($split[0] ?? ''));
});

test('LinkTargets::allTargets includes JSON src and poster', function (): void {
    $markup = '<!-- wp:video {"src":"javascript:alert(1)","poster":"javascript:alert(2)"} /-->';
    $targets = LinkTargets::allTargets($markup);
    assert_true(in_array('javascript:alert(1)', $targets, true), 'JSON src javascript: is visible');
    assert_true(in_array('javascript:alert(2)', $targets, true), 'JSON poster javascript: is visible');
    assert_eq(['javascript:alert(1)'], LinkTargets::srcAttrsIn($markup));
    assert_eq(['javascript:alert(2)'], LinkTargets::posterAttrsIn($markup));
    assert_true(LinkTargets::isDangerousScheme($targets[array_search('javascript:alert(1)', $targets, true)]));
});

test('LinkTargets::postersIn reads rendered poster attributes', function (): void {
    $markup = '<!-- wp:video {"poster":"javascript:from-json"} /-->'
            . '<video poster="javascript:alert(1)"></video>';
    assert_eq(['javascript:alert(1)'], LinkTargets::postersIn($markup));
    assert_true(in_array('javascript:alert(1)', LinkTargets::allTargets($markup), true));
});

