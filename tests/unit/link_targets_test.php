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

test('LinkTargets::isThemeAssetPath recognizes media, not routes', function (): void {
    assert_true(LinkTargets::isThemeAssetPath('/wp-content/themes/demo/assets/a.jpg'));
    assert_true(LinkTargets::isThemeAssetPath('/whatever/photo.png'));
    assert_true(!LinkTargets::isThemeAssetPath('/contact/'));
});

test('LinkTargets::anchorsIn collects ids', function (): void {
    $ids = LinkTargets::anchorsIn('<section id="hero"></section><div id="cta"></div>');
    assert_true(isset($ids['hero']) && isset($ids['cta']));
});
