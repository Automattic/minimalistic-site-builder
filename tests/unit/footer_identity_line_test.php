<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\FooterMarkup;

test('the fit-text identity line carries the site name, not the title (frm PR-4p)', function () {
    $markup = '<!-- wp:heading {"level":2,"align":"full","fitText":true} --><h2 class="wp-block-heading alignfull has-fit-text has-base-color" style="margin-top:0">Noa &#8212; Web Design</h2><!-- /wp:heading -->';
    $warnings = [];
    $out = FooterMarkup::withIdentityLineName($markup, 'Noa', $warnings);
    assert_contains('has-fit-text has-base-color" style="margin-top:0">Noa</h2>', $out);
    assert_eq(1, count($warnings));
    assert_contains("authored=identity line", $warnings[0]);
    assert_contains('disposition=the fit-text identity line carries the site name, not the title', $warnings[0]);

    $warnings = [];
    $same = '<h2 class="wp-block-heading alignfull has-fit-text">Noa</h2>';
    assert_eq($same, FooterMarkup::withIdentityLineName($same, 'Noa', $warnings), 'the name stands');
    assert_eq([], $warnings);
    $lower = '<h2 class="wp-block-heading alignfull has-fit-text">meridian</h2>';
    assert_eq($lower, FooterMarkup::withIdentityLineName($lower, 'Meridian', $warnings), 'a case-only difference keeps the authored case (frm PR-4p-2)');
    assert_eq([], $warnings);
    $plain = '<h2 class="wp-block-heading alignfull">Noa — Web Design</h2>';
    assert_eq($plain, FooterMarkup::withIdentityLineName($plain, 'Noa', $warnings), 'only the fit-text line is bound');
    assert_eq($same, FooterMarkup::withIdentityLineName($same, '', $warnings), 'an empty name changes nothing');
    $amp = '<h2 class="wp-block-heading alignfull has-fit-text">Studio</h2>';
    assert_contains('>Bread &amp; Salt</h2>', FooterMarkup::withIdentityLineName($amp, 'Bread & Salt', $warnings), 'the name is escaped');
});

test('the stated wordmark case reaches the footer identity line (frm PR-2ae)', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":2,"align":"full","fitText":true} --><h2 class="wp-block-heading alignfull has-fit-text has-base-color" style="margin-top:0">Studio Noir</h2><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Index</h3><!-- /wp:heading --></div><!-- /wp:group -->';
    $repairs = [];
    $upper = FooterMarkup::withIdentityLineCase($markup, 'uppercase', 'footer', $repairs);
    assert_contains('"className":"footer-wordmark\\u002d\\u002dupper"', $upper, 'the block JSON carries the class');
    assert_contains('<h2 class="footer-wordmark--upper wp-block-heading alignfull has-fit-text has-base-color"', $upper, 'and the HTML');
    assert_true(!str_contains($upper, 'footer-wordmark--upper wp-block-heading">Index'), 'only the fit-text line');
    assert_eq('identity-line-case', $repairs[0]['code'] ?? null);
    assert_true(\Automattic\SiteBuild\BlockMarkup::parse($upper)->unclosedIndices() === []);
    $again = FooterMarkup::withIdentityLineCase($upper, 'uppercase', 'footer', $repairs);
    assert_eq(1, substr_count($again, 'footer-wordmark--upper wp-block-heading'), 'a second pass stacks nothing');
    $lower = FooterMarkup::withIdentityLineCase($upper, 'lowercase', 'footer', $repairs);
    assert_contains('footer-wordmark--lower', $lower);
    assert_true(!str_contains($lower, 'footer-wordmark--upper'), 'the opposite class is replaced');
    $repairs = [];
    assert_eq($markup, FooterMarkup::withIdentityLineCase($markup, null, 'footer', $repairs), 'no stated case, no change');
    assert_eq([], $repairs);
});
