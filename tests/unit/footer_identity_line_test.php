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
    $plain = '<h2 class="wp-block-heading alignfull">Noa — Web Design</h2>';
    assert_eq($plain, FooterMarkup::withIdentityLineName($plain, 'Noa', $warnings), 'only the fit-text line is bound');
    assert_eq($same, FooterMarkup::withIdentityLineName($same, '', $warnings), 'an empty name changes nothing');
    $amp = '<h2 class="wp-block-heading alignfull has-fit-text">Studio</h2>';
    assert_contains('>Bread &amp; Salt</h2>', FooterMarkup::withIdentityLineName($amp, 'Bread & Salt', $warnings), 'the name is escaped');
});
