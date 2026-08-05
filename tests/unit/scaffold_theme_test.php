<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\ScaffoldThemeStep;

test('scaffold-theme writes style.css and readme with placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    assert_true($project->exists('theme/style.css'), 'style.css written');
    assert_true($project->exists('theme/readme.txt'), 'readme.txt written');

    $css = $project->readText('theme/style.css');
    assert_contains('Theme Name: {{THEME_NAME}}', $css);
    assert_contains('Text Domain: {{THEME_SLUG}}', $css);
    assert_contains('Description: {{DESCRIPTION}}', $css);

    // The card-cropping class hooks the section recipes reference (they keep
    // card sizing out of inline CSS, which fix-blocks would strip). Card media
    // crops by aspect ratio, not fixed pixel heights, so proportions survive
    // 2/3/4-column layouts (BIGR-771); only the tiny list thumb stays px-based.
    assert_contains('.card-media img', $css);
    assert_contains('.card-media img { aspect-ratio: 3 / 2; height: auto; }', $css);
    assert_contains('.card-media-tall img { aspect-ratio: 4 / 5; height: auto; }', $css);
    assert_contains('.card-media-thumb img { height: 110px; }', $css);
    assert_true(!str_contains($css, 'height: 200px'), 'fixed card crop heights are gone');
    assert_true(!str_contains($css, 'height: 320px'), 'fixed tall crop heights are gone');

    // Card media fills the card's content box even though the equal-cards card
    // is a flex column (core's constrained-layout auto margins would otherwise
    // shrink-wrap the figure to the image's intrinsic width, BIGR-771).
    assert_contains('.equal-cards .wp-block-group > figure.wp-block-image', $css);
    assert_contains('align-self: stretch;', $css);

    // Flush/overlap copy lives inside a nested body group. That body must grow
    // as a flex column, otherwise its nested CTA has no remaining height for
    // margin-top:auto to consume and sibling card buttons do not align.
    assert_contains(
        ".equal-cards .wp-block-group.card-body {\n"
            . "    display: flex;\n"
            . "    flex-direction: column;\n"
            . "    flex-grow: 1;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".equal-cards .cta-bottom {\n"
            . "    margin-top: auto;\n"
            . "    justify-content: center;\n"
            . '}',
        $css,
    );

    // The reset targets only the outer flush group, leaving .card-body padding
    // intact. Importance makes the zero padding stable against later global
    // Group styles and generated inline padding. Inline image radii need the
    // same precedence; the descendant img selector also handles linked images.
    assert_contains(
        ".wp-block-group.card-flush {\n"
            . "    overflow: hidden;\n"
            . "    padding: 0 !important;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".wp-block-group.card-flush > figure.wp-block-image img {\n"
            . "    border-radius: 0 !important;\n"
            . '}',
        $css,
    );
    assert_true(
        !str_contains($css, '.card-flush .card-body'),
        'the flush reset must not remove the inner text body padding',
    );

    // Core's font-relative pullquote spacing must not compound unpredictably
    // with the deterministic section rhythm.
    assert_contains('.wp-site-blocks .wp-block-pullquote {', $css);
    assert_contains('margin-block: 0;', $css);
    assert_contains('padding-block: var(--wp--preset--spacing--lg);', $css);

    // The raised hamburger breakpoint (BIGR-735): core swaps to the inline nav
    // at 600px, but a tracked title/nav row that fits at 768px can still wrap
    // or overflow in the 600-719px band — the shipped override keeps the nav
    // collapsed there. Both halves of core's swap must be countered.
    assert_contains('@media (min-width: 600px) and (max-width: 719.98px)', $css);
    assert_contains('.wp-site-blocks .wp-block-navigation__responsive-container-open:not(.always-shown)', $css);
    assert_contains('.wp-site-blocks .wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open)', $css);

    // Hero topology and mobile behavior are code-owned. All recipe hooks ship
    // in the static stylesheet (unused hooks are inert), and the mobile rules
    // consume only the transformation marker normalized by HeroUnit.
    foreach ([
        'cinematic-safe-zone',
        'editorial-split',
        'framed-portrait',
        'panorama-rail',
        'focal-subject-stage',
        'layered-poster',
    ] as $recipe) {
        assert_contains('.hero-composition--' . $recipe, $css);
    }
    assert_contains('@media (max-width: 781.98px)', $css);
    foreach ([
        'stack-copy-first',
        'stack-media-first',
        'rail-below',
        'flatten-layers',
        'retain-media-overlay',
    ] as $transformation) {
        assert_contains('.hero-mobile--' . $transformation, $css);
    }
    // Retired with diptych-editorial: no dead recipe or transformation hooks.
    assert_true(!str_contains($css, 'diptych'), 'retired recipe CSS is gone');
    assert_true(!str_contains($css, 'collapse-to-single-focus'), 'retired transformation CSS is gone');
    assert_contains('.hero-mobile--stack-copy-first .wp-block-media-text__content', $css);
    assert_contains('.hero-mobile--stack-copy-first .wp-block-media-text__media', $css);
    assert_contains('.hero-mobile--stack-media-first .wp-block-media-text__media', $css);
    assert_contains('.hero-mobile--stack-media-first .wp-block-media-text__content', $css);
    assert_contains('.hero-mobile--rail-below .wp-block-media-text__media', $css);
    assert_contains('grid-template-columns: minmax(0, 1fr) !important;', $css);
    assert_contains('grid-row: 2;', $css);

    $readme = $project->readText('theme/readme.txt');
    assert_contains('=== {{THEME_NAME}} ===', $readme);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme copies the static motion kit verbatim into the theme', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    foreach (['motion.css', 'motion.js'] as $file) {
        assert_eq(
            file_get_contents(\Automattic\SiteBuild\Package::motionDir() . '/' . $file),
            $project->readText('theme/assets/motion/' . $file),
            "{$file} copied byte-for-byte"
        );
    }
    foreach (['calm', 'energetic', 'dramatic', 'minimal'] as $profile) {
        assert_true($project->exists("theme/assets/motion/profiles/{$profile}.css"), "{$profile} profile copied");
    }

    // The kit's accessibility contract: reveals hide only under the
    // JS-set, motion-owned scope, and everything respects reduced motion AND
    // stays out of print media (unvisited reveals would print blank).
    $css = $project->readText('theme/assets/motion/motion.css');
    assert_contains('@media screen and (prefers-reduced-motion: no-preference)', $css);
    assert_contains('html.motion-js:not(.motion-ready) .reveal', $css);
    assert_true(!preg_match('/^\s*\.reveal[^{]*\{[^}]*opacity:\s*0/m', $css), 'no unscoped hiding');
    $js = $project->readText('theme/assets/motion/motion.js');
    assert_contains("classList.add('motion-js')", $js);
    assert_contains("classList.add('motion-target')", $js);
    assert_contains('prefers-reduced-motion: reduce', $js);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme copies the trusted adaptive-header kit verbatim', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);

    foreach (['header.css', 'header.js'] as $file) {
        assert_eq(
            file_get_contents(\Automattic\SiteBuild\Package::headerDir() . '/' . $file),
            $project->readText('theme/assets/header/' . $file),
            "{$file} copied byte-for-byte"
        );
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme has stable id and label', function () {
    $s = new ScaffoldThemeStep();
    assert_eq('scaffold-theme', $s->id());
    assert_true($s->label() !== '');
});
