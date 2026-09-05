<?php
declare(strict_types=1);

use Automattic\SiteBuild\Motion;
use Automattic\SiteBuild\Units\GeneratedMarkup;

test('marquee is an ambient kit class with its own duration in every profile (frm W8c)', function () {
    assert_true(in_array('marquee', Motion::AMBIENT_CLASSES, true));
    assert_true(Motion::looksLikeMotionClass('marquee'));
    assert_true(in_array('marquee', Motion::allowedClasses('calm'), true));
    assert_true(!in_array('marquee', Motion::allowedClasses('minimal'), true), 'minimal allows hover only');
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    assert_contains('.marquee .marquee__track {', $css);
    assert_contains('animation-name: motion-kit-marquee', $css);
    assert_contains('animation-timing-function: linear', $css);
    assert_contains('to { transform: translateX(-50%); }', $css);
    assert_contains('.marquee:focus-within .marquee__track', $css, 'keyboard focus pauses the loop');
    assert_true(strpos($css, '.marquee .marquee__track {') > strpos($css, 'prefers-reduced-motion: no-preference'), 'the loop is inert under reduced motion (the scale rule may sit outside, frm PR-8g)');
    foreach (['calm', 'energetic', 'dramatic', 'minimal'] as $profile) {
        assert_contains('--motion-marquee-duration:', (string) file_get_contents(repo_path("assets/motion/profiles/{$profile}.css")), $profile);
    }
    $js = (string) file_get_contents(repo_path('assets/motion/motion.js'));
    assert_contains('function buildMarquees()', $js);
    assert_contains("'.marquee:not(.marquee--built)'", $js);
    assert_true(strpos($js, 'buildMarquees();') < strpos($js, "root.classList.add('motion-ready')"), 'the track is built before motion-ready');
    assert_contains("setAttribute('aria-hidden', 'true')", $js, 'repeats are hidden from assistive tech');
});

test('a phrase repeated three or more times in one block collapses to the phrase and the marquee class (frm W8c)', function () {
    $part = 'page-home--archive';
    $paragraph = '<!-- wp:paragraph {"fontSize":"display","className":"has-text-align-left"} -->' . "\n"
        . '<p class="has-text-align-left has-display-font-size">MORE PROJECTS — MORE PROJECTS — MORE PROJECTS — MORE PROJECTS —</p>' . "\n"
        . '<!-- /wp:paragraph -->';
    $repairs = [];
    $out = GeneratedMarkup::collapseRepeatedPhrase($paragraph, $part, $repairs);
    assert_contains('<p class="marquee has-text-align-left has-display-font-size">MORE PROJECTS</p>', $out);
    assert_contains('"className":"marquee has-text-align-left"', $out);
    assert_eq(1, count($repairs));
    assert_eq('MORE PROJECTS', $repairs[0]['delivered']);

    // No class attribute at all: one is created.
    $bare = '<!-- wp:paragraph --><p>Studio · Studio · Studio</p><!-- /wp:paragraph -->';
    $out = GeneratedMarkup::collapseRepeatedPhrase($bare, $part, $repairs);
    assert_contains('<p class="marquee">Studio</p>', $out);
    assert_contains('{"className":"marquee"}', $out);

    // A heading collapses but never takes the kit class.
    $heading = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Work work work</h2><!-- /wp:heading -->';
    $out = GeneratedMarkup::collapseRepeatedPhrase($heading, $part, $repairs);
    assert_contains('<h2 class="wp-block-heading">Work</h2>', $out);
    assert_true(!str_contains($out, 'marquee'));

    // Twice is not a marquee; inline markup and ordinary prose are untouched.
    foreach ([
        '<!-- wp:paragraph --><p>Studio — Studio</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p><strong>Studio</strong> — Studio — Studio</p><!-- /wp:paragraph -->',
        '<!-- wp:paragraph --><p>We design, we build, we ship, and we stay.</p><!-- /wp:paragraph -->',
    ] as $untouched) {
        $before = count($repairs);
        assert_eq($untouched, GeneratedMarkup::collapseRepeatedPhrase($untouched, $part, $repairs));
        assert_eq($before, count($repairs));
    }
});

test('the kit owns the marquee scale and the boundary drops an authored size or face (frm PR-8g)', function () {
    $css = (string) file_get_contents(repo_path('assets/motion/motion.css'));
    $base = substr($css, 0, strpos($css, 'prefers-reduced-motion: no-preference'));
    assert_contains('.marquee {', $base, 'the scale applies in every motion preference');
    assert_contains('font-size: var(--wp--preset--font-size--display, 3rem)', $base);
    assert_contains('white-space: nowrap', $base);
    assert_true(!str_contains($base, '!important'), 'no fight with the preset classes');

    $authored = '<!-- wp:paragraph {"className":"marquee has-text-align-center","fontSize":"caption","fontFamily":"body","style":{"typography":{"letterSpacing":"0.1em"}}} -->'
        . '<p class="has-text-align-center marquee has-body-font-family has-caption-font-size" style="letter-spacing:0.1em">Identity, editorial, packaging</p><!-- /wp:paragraph -->';
    $repairs = [];
    $out = \Automattic\SiteBuild\Units\GeneratedMarkup::ownMarqueeScale($authored, 'page-home--marquee', $repairs);
    assert_true(!str_contains($out, '"fontSize"'), 'fontSize attribute dropped');
    assert_true(!str_contains($out, 'has-caption-font-size'), 'size class dropped');
    assert_true(!str_contains($out, 'has-body-font-family'), 'face class dropped');
    assert_contains('marquee', $out);
    assert_eq(1, count($repairs));
    $plain = '<!-- wp:paragraph {"className":"marquee"} --><p class="marquee">Plain</p><!-- /wp:paragraph -->';
    assert_eq($plain, \Automattic\SiteBuild\Units\GeneratedMarkup::ownMarqueeScale($plain, 'x', $repairs), 'nothing authored, nothing changed');
});
