<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\FooterMarkup;

test('a stated wordmark case changes only the footer identity and reaches a fixed point', function () {
    $identity = '<!-- wp:heading {"className":"has-fit-text footer-wordmark--upper"} --><h2 class="wp-block-heading has-fit-text footer-wordmark--upper">Studio Name</h2><!-- /wp:heading -->';
    $sibling = '<!-- wp:heading --><h2 class="wp-block-heading">Work With Us</h2><!-- /wp:heading -->';
    $repairs = [];
    $out = FooterMarkup::withIdentityLineCase($identity . $sibling, 'lowercase', 'parts/footer.html', $repairs);
    assert_contains('footer-wordmark--lower', $out);
    assert_true(!str_contains($out, 'footer-wordmark--upper'));
    assert_contains($sibling, $out);
    assert_eq(1, count($repairs));
    $again = [];
    assert_eq($out, FooterMarkup::withIdentityLineCase($out, 'lowercase', 'parts/footer.html', $again));
    assert_eq([], $again);
    assert_eq($identity . $sibling, FooterMarkup::withIdentityLineCase($identity . $sibling, null, 'parts/footer.html'));
});
