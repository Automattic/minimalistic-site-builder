<?php
declare(strict_types=1);

test('a static marquee inside a column reads at section-title scale (frm PR-8m)', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $flat = preg_replace('/\s+/', ' ', $css);
    assert_contains('@media screen and (prefers-reduced-motion: reduce) { .marquee { white-space: normal;', $flat, 'the reduced-motion branch still wraps the line');
    assert_contains('.wp-block-column .marquee, .marquee.is-long-line { font-size: var(--wp--preset--font-size--section-title, 2rem); }', $flat);
    assert_contains('html:not(.motion-js) .wp-block-column .marquee, html:not(.motion-js) .marquee.is-long-line { font-size: var(--wp--preset--font-size--section-title, 2rem); }', $flat, 'the no-script branch matches');
});


test('a marquee line over 64 characters is marked for the static branches (frm PR-8n)', function () {
    $long = '<!-- wp:paragraph {"textColor":"contrast","className":"marquee"} --><p class="marquee has-contrast-color has-text-color">Bellwether Press · Fathom Optics · Quarter Tone Records · Northline Ferry · Aperture Trust</p><!-- /wp:paragraph -->';
    $short = '<!-- wp:paragraph {"className":"marquee"} --><p class="marquee">Identity · Editorial · Wayfinding</p><!-- /wp:paragraph -->';
    $repairs = [];
    $out = \Automattic\SiteBuild\Units\GeneratedMarkup::markLongMarquee($long . $short, 'page-home--archive', $repairs);
    assert_contains('{"textColor":"contrast","className":"marquee is-long-line"} --><p class="marquee has-contrast-color has-text-color is-long-line">Bellwether', $out);
    assert_contains('<p class="marquee">Identity · Editorial · Wayfinding</p>', $out, 'a short line stays');
    assert_eq(1, count($repairs));
    assert_eq('long-marquee-marked', $repairs[0]['code']);
    $repairs = [];
    assert_eq($out, \Automattic\SiteBuild\Units\GeneratedMarkup::markLongMarquee($out, 'page-home--archive', $repairs), 'idempotent');
    assert_eq([], $repairs);
});
