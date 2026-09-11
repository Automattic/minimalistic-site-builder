<?php
declare(strict_types=1);

use Automattic\SiteBuild\AuthoredLayoutCss;

test('authored layout spacing wins block defaults without inventing values', function () {
    $html = '<div class="wp-block-group design-claim" style="padding-right:2rem"><div class="wp-block-group design-copy"></div></div>';
    $css = '.design-copy { margin-left:0; margin-right:auto; max-width:42ch; }'
        . '@media (min-width:1024px) { .design-claim { padding-right:48%; display:flex; } }'
        . '@media (max-width:1023px) { .design-copy { margin-inline:auto; } }';
    $result = AuthoredLayoutCss::reconcile($css, $html);
    assert_contains('margin-left:0 !important;', $result['css']);
    assert_contains('margin-right:auto !important;', $result['css']);
    assert_contains('padding-right:48% !important;', $result['css']);
    assert_contains('margin-inline:auto !important;', $result['css']);
    assert_contains('max-width:42ch;', $result['css']);
    assert_eq(4, count($result['repairs']));
    assert_eq($result['css'], AuthoredLayoutCss::reconcile($result['css'], $html)['css']);
    assert_eq([], AuthoredLayoutCss::reconcile($result['css'], $html)['repairs']);
});

test('layout reconciliation leaves non-layout subjects and unsupported selectors untouched', function () {
    $html = '<div class="wp-block-group design-copy"><h1>Heading</h1><button class="design-button">Go</button></div>';
    $css = '.design-copy h1 { margin-left:0; } .design-button { padding:2rem; }'
        . '.design-copy::before { margin:0; content:"padding:1rem;"; }'
        . '.missing { margin:0; } .design-copy, h1 { padding:0; }';
    assert_eq($css, AuthoredLayoutCss::reconcile($css, $html)['css']);
});

test('layout reconciliation handles lists and preserves priorities and custom properties', function () {
    $html = '<div class="wp-block-group design-left"></div><div class="wp-block-cover design-right"></div>';
    $css = '.design-left, .design-right { margin-inline: auto; padding:clamp(1rem, 2vw, 3rem); --copy:"margin:0;"; }'
        . '.design-left { padding-top:1rem !important; color:inherit; }';
    $result = AuthoredLayoutCss::reconcile($css, $html)['css'];
    assert_contains('margin-inline: auto !important;', $result);
    assert_contains('padding:clamp(1rem, 2vw, 3rem);', $result, 'do not promote a shorthand over an authored important longhand');
    assert_contains('--copy:"margin:0;";', $result);
    assert_contains('padding-top:1rem !important; color:inherit;', $result);
});

test('layout reconciliation preserves authored priority relationships across matching selectors', function () {
    $html = '<div class="wp-block-group design-copy wide"></div><div class="wp-block-group design-other"></div>';
    $css = '.design-copy {margin-left:2rem !important;}'
        . '@media(min-width:900px) {.design-copy.wide {margin-left:0;}}'
        . '.design-other {margin-left:0;}';
    $result = AuthoredLayoutCss::reconcile($css, $html)['css'];
    assert_contains('.design-copy.wide {margin-left:0;}', $result);
    assert_contains('.design-other {margin-left:0 !important;}', $result);
});

test('authored cover minimum height overrides inline height at each breakpoint', function () {
    $html = '<div class="wp-block-cover design-photo" style="min-height:68vh"><img src="photo.jpg"></div>';
    $css = '.design-photo {min-height:84vh;} @media(max-width:599px){.design-photo {min-height:42vh;}}';
    $result = AuthoredLayoutCss::reconcile($css, $html)['css'];
    assert_contains('min-height:84vh !important;', $result);
    assert_contains('min-height:42vh !important;', $result);
    assert_eq($result, AuthoredLayoutCss::reconcile($result, $html)['css']);
});

test('a promoted rhythm on a page-level band is recorded (frm PR-8d)', function () {
    // Promoting is the point: an authored rhythm should reach the page. But
    // section-rhythm is the one owner of a band's block spacing, and it has no
    // important of its own, so the promotion wins silently. Two owners of one
    // property is worth a row.
    $markup = '<div class="wp-block-group design-studio-opening"><p>Copy.</p></div>'
        . '<div class="wp-block-group design-card-shell"><div class="wp-block-group design-inner-note">'
        . '<p>Nested.</p></div></div>';

    $band = AuthoredLayoutCss::reconcile('.design-studio-opening{padding-block:9rem;}', $markup);
    assert_contains('padding-block:9rem !important', $band['css'], 'the authored rhythm still wins');
    assert_eq(1, count($band['warnings']), 'and the conflict is recorded');
    assert_contains('section-rhythm', $band['warnings'][0]);
    assert_contains('.design-studio-opening', $band['warnings'][0]);

    // A nested group is not a band: section-rhythm does not own its spacing.
    $nested = AuthoredLayoutCss::reconcile('.design-inner-note{padding-block:2rem;}', $markup);
    assert_contains('padding-block:2rem !important', $nested['css']);
    assert_eq([], $nested['warnings'], 'a nested group owns its own spacing');

    // Inline-axis spacing is nobody else's: no row.
    $inline = AuthoredLayoutCss::reconcile('.design-studio-opening{margin-inline:4rem;}', $markup);
    assert_contains('margin-inline:4rem !important', $inline['css']);
    assert_eq([], $inline['warnings'], 'the inline axis has one owner');

    // min-height is not spacing and is nobody else's either.
    $height = AuthoredLayoutCss::reconcile('.design-studio-opening{min-height:60vh;}', $markup);
    assert_contains('min-height:60vh !important', $height['css']);
    assert_eq([], $height['warnings']);
});

