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
    // card sizing out of inline CSS, which fix-blocks would strip). All card
    // media crops by aspect ratio, not fixed pixel heights, so proportions
    // survive 2/3/4-column layouts and viewports (BIGR-771); the list thumb's
    // fixed 110px height letterboxed its square image at whatever ratio the
    // column width produced (BIGR-777).
    assert_contains('.card-media img', $css);
    assert_contains('.card-media img { aspect-ratio: 3 / 2; height: auto; }', $css);
    assert_contains('.card-media-tall img { aspect-ratio: 4 / 5; height: auto; }', $css);
    assert_contains('.card-media-thumb img { aspect-ratio: 1 / 1; height: auto; }', $css);
    assert_true(!str_contains($css, 'height: 110px'), 'fixed thumb crop height is gone');
    assert_true(!str_contains($css, 'height: 200px'), 'fixed card crop heights are gone');
    assert_true(!str_contains($css, 'height: 320px'), 'fixed tall crop heights are gone');

    // The caption color hooks ContrastFix repairs unreadable figcaptions
    // through (the image block supports no textColor of its own, BIGR-784).
    assert_contains('.caption-text-base > figcaption { color: var(--wp--preset--color--base); }', $css);
    assert_contains('.caption-text-contrast > figcaption { color: var(--wp--preset--color--contrast); }', $css);

    // Flush list-thumb rows: the zeroed row padding must beat generated inline
    // padding, the row clips the bleeding thumb under its border radius, and
    // the thumb releases its square crop to stretch to the text-driven row
    // height (BIGR-777). The zeroed column gap keeps the text column's own
    // left padding as the whole image-to-text distance — the default md gap
    // would stack with it and push the text farther from its own thumb than
    // the md rhythm separating rows. Column-level align-self pins the stretch
    // against generator-authored verticalAlignment:center. The row also stays
    // horizontal when generated isStackedOnMobile:false attributes drift.
    assert_contains(
        ".wp-block-columns.list-thumb-flush {\n"
            . "    overflow: hidden;\n"
            . "    padding: 0 !important;\n"
            . "    align-items: stretch;\n"
            . "    flex-wrap: nowrap !important;\n"
            . "    gap: 0;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".wp-block-columns.list-thumb-flush > .wp-block-column {\n"
            . "    align-self: stretch;\n"
            . '}',
        $css,
    );
    // Core forces ordinary columns to 100% at its <=781px stacking breakpoint.
    // The behavior hook restores the recipe's 18/82 split even if the model
    // omitted isStackedOnMobile:false.
    assert_contains(
        "@media (max-width: 781px) {\n"
            . "    .wp-block-columns.list-thumb-flush > .wp-block-column:first-child {\n"
            . "        flex-basis: 18% !important;\n"
            . "    }\n"
            . "    .wp-block-columns.list-thumb-flush > .wp-block-column:last-child {\n"
            . "        flex-basis: 82% !important;\n"
            . "    }\n"
            . '}',
        $css,
    );
    // When the square thumb out-measures a short text stack the row takes the
    // thumb's height; the text column centers its copy in the extra space.
    assert_contains(
        ".wp-block-columns.list-thumb-flush > .wp-block-column:not(:has(figure.card-media-thumb)) {\n"
            . "    display: flex;\n"
            . "    flex-direction: column;\n"
            . "    justify-content: center;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".list-thumb-flush > .wp-block-column > figure.wp-block-image.card-media-thumb {\n"
            . "    height: 100%;\n"
            . "    margin: 0;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".list-thumb-flush .card-media-thumb img {\n"
            . "    aspect-ratio: auto;\n"
            . "    height: 100%;\n"
            . "    border-radius: 0 !important;\n"
            . '}',
        $css,
    );

    // Card media fills the card's content box even though the equal-cards card
    // is a flex column (core's constrained-layout auto margins would otherwise
    // shrink-wrap the figure to the image's intrinsic width, BIGR-771).
    assert_contains(
        ".equal-cards .wp-block-group > figure.wp-block-image {\n"
            . "    width: 100%;\n"
            . "    max-width: none;\n"
            . "    margin-left: 0 !important;\n"
            . "    margin-right: 0 !important;\n"
            . "    align-self: stretch;\n"
            . '}',
        $css,
    );

    // Any nested card text wrapper carries card-body. It grows as a flex column
    // only in an equal-height row, so its nested CTA can consume the remaining
    // height and align with sibling card buttons across all four treatments.
    assert_contains(
        ".equal-cards .wp-block-group.card-body {\n"
            . "    display: flex;\n"
            . "    flex-direction: column;\n"
            . "    flex-grow: 1;\n"
            . '}',
        $css,
    );

    // Width and constrained-layout margin resets apply to every marked card,
    // including ordinary staggered/editorial cards outside .equal-cards. The
    // overlap treatment then restores its deliberate one-rem side reveal.
    assert_contains(
        ".wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap, .card-style--borderless) > .wp-block-group.card-body {\n"
            . "    box-sizing: border-box;\n"
            . "    width: 100%;\n"
            . "    max-width: none;\n"
            . "    margin-left: 0 !important;\n"
            . "    margin-right: 0 !important;\n"
            . "    align-self: stretch;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".wp-block-group.card-style--overlap > .wp-block-group.card-body.overlap-up {\n"
            . "    width: calc(100% - 2rem);\n"
            . "    margin-left: 1rem !important;\n"
            . "    margin-right: 1rem !important;\n"
            . '}',
        $css,
    );
    assert_contains(
        ".wp-block-group:is(.card-style--flush, .card-style--framed, .card-style--overlap, .card-style--borderless) > .wp-block-group.card-body > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {\n"
            . "    box-sizing: border-box;\n"
            . "    width: 100%;\n"
            . "    max-width: none;\n"
            . "    margin-left: 0 !important;\n"
            . "    margin-right: 0 !important;\n"
            . "    align-self: stretch;\n"
            . '}',
        $css,
    );
    assert_true(
        !str_contains($css, '.wp-site-blocks .equal-cards'),
        'card layout rules remain available in both the editor and front end',
    );
    assert_true(
        !str_contains($css, '.equal-cards .wp-block-group.card-body.overlap-up'),
        'overlap side-inset geometry is not limited to the equal-grid recipe',
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

    assert_contains('.wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open {', $css);
    assert_contains('background-color: var(--wp--preset--color--base) !important;', $css);
    assert_contains('color: var(--wp--preset--color--contrast) !important;', $css);
    assert_contains('--navigation-layout-justification-setting: flex-start;', $css);
    assert_contains('flex-direction: column !important;', $css);
    $openBlock = substr($css, (int) strpos($css, '.wp-site-blocks .wp-block-navigation__responsive-container.is-menu-open {'));
    assert_contains('justify-content: flex-start !important;', $openBlock);
    assert_eq(
        1,
        preg_match(
            '/\.wp-site-blocks \.wp-block-navigation__responsive-container\.is-menu-open\s*\{'
                . '(?![^}]*z-index:)[^}]*\}/s',
            $css,
        ),
        'overlay leaves Core z-index ownership intact',
    );
    assert_eq(
        1,
        preg_match(
            '/\.wp-site-blocks \.wp-block-navigation__responsive-container\.is-menu-open\s+'
                . '\.wp-block-navigation__responsive-dialog,\s*'
                . '\.wp-site-blocks \.wp-block-navigation__responsive-container\.is-menu-open\s+'
                . '\.wp-block-navigation__responsive-container-content\s*\{'
                . '(?![^}]*margin:)[^}]*\}/s',
            $css,
        ),
        'shared dialog geometry does not erase Core admin-bar margin',
    );
    assert_eq(
        1,
        preg_match(
            '/\.wp-site-blocks \.wp-block-navigation__responsive-container\.is-menu-open\s+'
                . '\.wp-block-navigation__responsive-container-content\s*\{'
                . '(?=[^}]*margin:\s*0 !important;)[^}]*\}/s',
            $css,
        ),
        'responsive content alone keeps the margin reset',
    );
    assert_eq(
        1,
        preg_match(
            '/\.wp-site-blocks \.wp-block-navigation__responsive-container\.is-menu-open\s+'
                . '\.wp-block-navigation-item__content:not\(\.wp-element-button\)\s*\{'
                . '(?=[^}]*color:\s*inherit !important;)(?![^}]*color:[^;}]*contrast)[^}]*\}/s',
            $css,
        ),
        'overlay links inherit the modal foreground without forcing contrast',
    );
    assert_eq(
        1,
        preg_match(
            '/\.wp-site-blocks \.wp-block-navigation__responsive-container\.is-menu-open\s+'
                . ':is\([^}]+\)\s*\{'
                . '(?=[^}]*color:\s*inherit !important;)(?![^}]*color:[^;}]*contrast)[^}]*\}/s',
            $css,
        ),
        'overlay close and submenu controls inherit the modal foreground without forcing contrast',
    );

    // Hero topology and mobile behavior are code-owned. All recipe hooks ship
    // in the static stylesheet (unused hooks are inert), and the mobile rules
    // consume only the transformation marker normalized by HeroUnit.
    foreach ([
        'cinematic-safe-zone',
        'editorial-split',
        'framed-portrait',
        'focal-subject-stage',
        'layered-poster',
    ] as $recipe) {
        assert_contains('.hero-composition--' . $recipe, $css);
    }
    // cinematic-safe-zone reserves image room with a percentage inset; a
    // columns copy container must span full width or the constrained-layout
    // contentSize cap collides with the inset and starves the copy.
    assert_contains('.hero-composition--cinematic-safe-zone .wp-block-columns {', $css);
    assert_contains('@media (max-width: 781.98px)', $css);
    foreach ([
        'stack-copy-first',
        'stack-media-first',
        'flatten-layers',
        'retain-media-overlay',
    ] as $transformation) {
        assert_contains('.hero-mobile--' . $transformation, $css);
    }
    // Retired with diptych-editorial: no dead recipe or transformation hooks.
    assert_true(!str_contains($css, 'diptych'), 'retired recipe CSS is gone');
    assert_true(!str_contains($css, 'collapse-to-single-focus'), 'retired transformation CSS is gone');
    // Retired with panorama-rail (BIGR-775): no dead recipe or transformation hooks.
    assert_true(!str_contains($css, 'panorama-rail'), 'retired panorama-rail recipe CSS is gone');
    assert_true(!str_contains($css, 'rail-below'), 'retired rail-below transformation CSS is gone');
    assert_contains('.hero-mobile--stack-copy-first .wp-block-media-text__content', $css);
    assert_contains('.hero-mobile--stack-copy-first .wp-block-media-text__media', $css);
    assert_contains('.hero-mobile--stack-media-first .wp-block-media-text__media', $css);
    assert_contains('.hero-mobile--stack-media-first .wp-block-media-text__content', $css);
    assert_contains('grid-template-columns: minmax(0, 1fr) !important;', $css);
    assert_contains('grid-row: 2;', $css);
    // The cinematic stack-media-first panel owns its copy color (BIGR-788):
    // authored overlay-tuned colors must not survive onto the solid panel.
    assert_eq(
        1,
        preg_match(
            '/\.hero-composition--cinematic-safe-zone\.hero-mobile--stack-media-first\s+'
                . '\.wp-block-cover__inner-container\s+'
                . ':is\(h1, h2, h3, h4, h5, h6, p, cite\):not\(\.wp-block-button__link\)\s*\{\s*'
                . 'color:\s*var\(--wp--preset--color--base\)\s*!important;\s*\}/',
            $css
        ),
        'the transformed solid panel owns descendant copy color as one scoped rule'
    );

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
