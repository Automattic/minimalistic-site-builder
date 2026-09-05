<?php
declare(strict_types=1);

use Automattic\SiteBuild\Units\FooterMarkup;

test('a color-field footer panel on primary, accent or secondary takes the contrast surface with base ink (frm PR-4k)', function () {
    $footer = static fn (string $panel): string => '<!-- wp:group {"backgroundColor":"base","textColor":"contrast","align":"full","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull has-contrast-color has-base-background-color has-text-color has-background">' . $panel . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">© 2026 Chromavue</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $panel = static fn (string $color, string $text): string => '<!-- wp:group {"backgroundColor":"' . $color . '","textColor":"' . $text . '","align":"wide","layout":{"type":"constrained"}} --><div class="wp-block-group alignwide has-' . $text . '-color has-' . $color . '-background-color has-text-color has-background"><!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Chromavue</h2><!-- /wp:heading --></div><!-- /wp:group -->';

    foreach (['primary', 'accent', 'secondary'] as $loud) {
        $notes = [];
        $out = FooterMarkup::withBoundedColorFieldPanel($footer($panel($loud, 'base')), $notes);
        assert_contains('"backgroundColor":"contrast","textColor":"base","align":"wide"', $out, "{$loud} panel takes contrast");
        assert_true(!str_contains($out, "has-{$loud}-background-color"), "{$loud} class token dropped");
        assert_contains('has-contrast-background-color', $out);
        assert_contains('has-base-color', $out);
        assert_contains('"backgroundColor":"base","textColor":"contrast","align":"full"', $out, 'the root mat is untouched');
        assert_eq(1, count($notes));
        assert_contains("authored backgroundColor '{$loud}'", $notes[0]);
    }

    foreach (['contrast', 'band'] as $quiet) {
        $notes = [];
        $markup = $footer($panel($quiet, 'base'));
        assert_eq($markup, FooterMarkup::withBoundedColorFieldPanel($markup, $notes), "{$quiet} panel keeps its surface");
        assert_eq([], $notes);
    }
});
