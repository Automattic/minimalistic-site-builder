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
    assert_contains('.feature-media img', $css);
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
    assert_contains('.wp-block-group.copy-end > * {', $css);
    assert_contains('margin-inline-start: auto !important;', $css);
    assert_contains('margin-inline-end: 0 !important;', $css);

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
        'foreground-split',
        'foreground-split',
        'foreground-split',
        'layered-poster',
        'panel-stage',
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

test('scaffold-theme unbullets a page-list rendered outside a navigation', function () {
    // Core ships `.wp-block-page-list { box-sizing: border-box }` and nothing
    // else — its flex/list-style rules are all scoped under
    // `.wp-block-navigation`. Footers use the block bare (4 of 7 demo builds),
    // so the UA stylesheet's discs and 40px indent ship to the visitor.
    $tmp = sys_get_temp_dir() . '/builder_scaffold_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');

    (new ScaffoldThemeStep())->run($project);
    $css = $project->readText('theme/style.css');

    // Pull the standalone rule specifically: the pre-existing
    // `.is-menu-open … .wp-block-page-list` rule must not be able to satisfy
    // this assertion, or the reset could be deleted without a failure.
    $matched = preg_match(
        '~(?<!\S)\.wp-site-blocks\s+\.wp-block-page-list\s*\{(?<body>[^}]*)\}~',
        $css,
        $rule
    );
    assert_eq(1, $matched, 'a standalone .wp-block-page-list reset exists');
    assert_contains('list-style: none', $rule['body']);
    assert_contains('padding-inline-start: 0', $rule['body']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme has stable id and label', function () {
    $s = new ScaffoldThemeStep();
    assert_eq('scaffold-theme', $s->id());
    assert_true($s->label() !== '');
});

test('scaffold hero headings wrap at word boundaries and never snap mid-word', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_wrap_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    $css = $project->readText('theme/style.css');

    $matched = preg_match(
        '~\\.hero-composition__copy\s+\\.wp-block-heading,\s*'
            . '\\.hero-composition--layered-poster\s+\\.wp-block-heading\s*'
            . '\{(?<body>[^}]*)\}~',
        $css,
        $rule
    );
    assert_eq(1, $matched, 'hero heading wrap rule exists');
    assert_contains('overflow-wrap: normal', $rule['body']);
    assert_contains('word-break: normal', $rule['body']);
    assert_true(
        !str_contains($rule['body'], 'break-word'),
        'hero headings must not use overflow-wrap:break-word as a last-resort snap'
    );
    assert_true(
        !str_contains($rule['body'], 'anywhere'),
        'hero headings must not overflow-wrap:anywhere'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme owns the guaranteed sticky-side pin rule', function () {
    // BIGR-945: `SectionComposition::PIN_CLASS` REQUIRES this behavior. The
    // page-styles appendix is model-authored and can be dropped, so the
    // scaffold ships the rule itself.
    $tmp = sys_get_temp_dir() . '/builder_scaffold_pin_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    $css = $project->readText('theme/style.css');

    $hook = '.section-composition--asymmetric-split .wp-block-column.sticky-side';
    assert_contains($hook . ' {', $css, 'the pin rule targets the archetype root and the pin class');

    // The stretch opt-out applies at every width; the sticky part is
    // desktop-only.
    $matched = preg_match(
        '~' . preg_quote($hook, '~') . '\s*\{(?<base>[^}]*)\}~',
        $css,
        $base
    );
    assert_eq(1, $matched, 'the base pin rule exists');
    assert_contains('align-self: flex-start', $base['base']);

    $matched = preg_match(
        '~@media \(min-width: 782px\)\s*\{\s*'
            . preg_quote($hook, '~') . '\s*\{(?<body>[^}]*)\}~',
        $css,
        $rule
    );
    assert_eq(1, $matched, 'the sticky part is gated to desktop widths');
    assert_contains('position: sticky', $rule['body']);
    assert_contains('top: var(--wp--preset--spacing--lg, 3rem)', $rule['body']);
    // The pin must not clamp the column: a height clamp with an inner
    // scroll draws a nested scroll bar on the lead column.
    assert_true(
        !str_contains($rule['body'], 'max-block-size'),
        'the pinned column has no height clamp'
    );
    assert_true(
        !str_contains($rule['body'], 'overflow'),
        'the pinned column has no inner scroll'
    );
    assert_true(
        !str_contains($rule['body'], 'overscroll'),
        'the pinned column has no overscroll trap'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme owns the guaranteed centered-stack alignment rule', function () {
    // BIGR-952: the archetype's alignment lived only in prompt prose, so a
    // band could ship a centered heading over start-aligned copy. The
    // scaffold ships the rule itself, like the pin rule above.
    $tmp = sys_get_temp_dir() . '/builder_scaffold_centered_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    (new ScaffoldThemeStep())->run($project);
    $css = $project->readText('theme/style.css');

    $hook = '.section-composition--centered-stack';
    // One matcher for every rule in the block: the hook, the rule's own
    // selector tail, any :not() guards, then the declaration body.
    $rule = static function (string $tail) use ($css, $hook): array {
        $matched = preg_match(
            '~' . preg_quote($hook, '~') . $tail . '(?<guards>[^{]*)\{(?<body>[^}]*)\}~',
            $css,
            $m
        );
        assert_eq(1, $matched, "the rule {$hook}{$tail} exists");
        return $m;
    };
    // Every exemption is named once, in one scope, and the two rules that
    // could re-center content inside an exemption exclude that same scope.
    $exempt = ':is(.item-pattern__item, form, .jetpack-contact-form-container)';

    $root = $rule('\s*');
    assert_contains('text-align: center', $root['body']);

    // A flex buttons row ignores inherited text-align, so it needs its own
    // justification — but only when the author set none.
    $buttons = $rule('\s+\.wp-block-buttons');
    assert_contains('justify-content: center', $buttons['body']);
    assert_contains(':not(.is-content-justification-left)', $buttons['guards'], 'an authored justification survives');
    // BIGR-952 review follow-up: a buttons row inside an exemption must not
    // center, or the start-aligned row gets a mixed alignment again.
    assert_contains(':not(' . $exempt . ' *)', $buttons['guards'], 'a buttons row inside an exemption stays exempt');

    // Repeated item rows, a host-substituted form, and the host's form
    // container stay start-aligned inside the centered band: centered labels
    // over start-aligned input text, or a centered rag on markers or on item
    // rows, is a new defect. The container carries Jetpack's server-side
    // error block and its success message as siblings of the form.
    $items = $rule('\s+' . preg_quote($exempt, '~'));
    assert_contains('text-align: start', $items['body']);

    $lists = $rule('\s+:is\(ul, ol\)');
    assert_contains('text-align: start', $lists['body']);
    assert_contains('margin-inline: auto', $lists['body'], 'the list block itself still centers');
    // BIGR-952 review follow-up: a list inside an exemption must not take the
    // fit-content centering, or it centers inside the start-aligned row or
    // form.
    assert_contains(':not(' . $exempt . ' *)', $lists['guards'], 'a list inside an exemption stays exempt');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme styles native accordion rows for the faq-split archetype (frm W3b)', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_faq_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    quietly(fn () => (new ScaffoldThemeStep())->run($project));
    $css = $project->readText('theme/style.css');
    assert_contains('.wp-block-details > summary', $css);
    assert_contains('.wp-block-details[open] > summary::after', $css);
    assert_contains('.faq-list > .wp-block-details:first-child', $css);
    assert_contains('prefers-reduced-motion', $css);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme rounds and clips the closing cta-panel from the shape scale (frm W3d)', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_cta_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    quietly(fn () => (new ScaffoldThemeStep())->run($project));
    $css = $project->readText('theme/style.css');
    assert_contains('.wp-block-group.cta-panel {', $css);
    assert_contains('border-radius: var(--shape-radius-panel, 0)', $css);
    assert_contains('overflow: hidden', $css);
    assert_contains('.wp-block-group.cta-panel :is(h1, h2, h3)', $css, 'a clipped panel never clips its headline');
    assert_contains('overflow-wrap: anywhere', $css);
    assert_contains('font-size: min(var(--wp--preset--font-size--section-title), 11vw) !important', $css, 'phone-scale headline cap');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme rounds the panel-stage hero panel from the shape scale (frm W2a)', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_panel_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    quietly(fn () => (new ScaffoldThemeStep())->run($project));
    $css = $project->readText('theme/style.css');
    assert_contains('.hero-composition--panel-stage .hero-composition__panel {', $css);
    assert_contains('radial-gradient(color-mix(in srgb, currentColor 9%, transparent) 1px, transparent 1.5px)', $css);
    assert_contains('.hero-composition--panel-stage .hero-composition__stage img', $css);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme paints the marquee-name hero name behind a centered stack (frm W2b)', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_marquee_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Ana Popescu');
    quietly(fn () => (new ScaffoldThemeStep())->run($project));
    $css = $project->readText('theme/style.css');
    assert_contains('.hero-composition--marquee-name .hero-composition__marquee {', $css);
    $block = substr($css, strpos($css, '.hero-composition--marquee-name .hero-composition__marquee {'));
    $block = substr($block, 0, strpos($block, '}'));
    assert_contains('position: absolute', $block);
    assert_contains('justify-content: center', $block, 'flex centering clips an over-wide name on both sides');
    assert_contains('font-size: clamp(6rem, 24vw, 22rem)', $block);
    assert_contains('opacity: 0.07', $block);
    assert_contains('pointer-events: none', $block);
    assert_contains('.hero-composition--marquee-name .hero-composition__media img {', $css);
    assert_contains('border-radius: var(--shape-radius-card, 0)', $css);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme sets the pricing figure in the heading face and lifts the recommended tier (frm W3c)', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_pricing_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Zova');
    quietly(fn () => (new ScaffoldThemeStep())->run($project));
    $css = $project->readText('theme/style.css');
    assert_contains('.section-composition--pricing-tiers .price-figure {', $css);
    assert_contains('font-size: var(--wp--preset--font-size--section-title)', $css);
    assert_contains('.section-composition--pricing-tiers .equal-cards > .wp-block-column > .card-highlight {', $css);
    assert_contains('transform: translateY(-0.75rem)', $css);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('scaffold-theme draws stat-ledger hairlines between figure columns and stacks them on phones (frm W3e)', function () {
    $tmp = sys_get_temp_dir() . '/builder_scaffold_ledger_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Spector');
    quietly(fn () => (new ScaffoldThemeStep())->run($project));
    $css = $project->readText('theme/style.css');
    assert_contains('.section-composition--stat-ledger .wp-block-column > .wp-block-heading:first-child {', $css);
    assert_contains('font-size: min(var(--wp--preset--font-size--display), 7vw)', $css, 'a figure never runs out of its column');
    assert_contains('.section-composition--stat-ledger .wp-block-columns > .wp-block-column + .wp-block-column {', $css);
    assert_contains('border-inline-start: 1px solid color-mix(in srgb, currentColor 14%, transparent)', $css);
    assert_contains('border-block-start: 1px solid color-mix(in srgb, currentColor 14%, transparent)', $css, 'the phone hairline is horizontal');
    exec('rm -rf ' . escapeshellarg($tmp));
});
