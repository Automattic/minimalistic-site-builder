<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageKind;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('image kind is a closed vocabulary with one style keyword and one render clause each', function () {
    assert_eq(['photo', '3d-object', 'ui-mockup', 'line-illustration', 'abstract-gradient'], ImageKind::ALL);
    assert_eq('photo', ImageKind::DEFAULT);
    assert_eq('', ImageKind::promptClause('photo'), 'a photo series is described by the grade alone');
    assert_eq('', ImageKind::promptClause(null));
    assert_eq('', ImageKind::promptClause('hologram'), 'an unknown kind falls back to photo');
    foreach (['3d-object', 'ui-mockup', 'line-illustration', 'abstract-gradient'] as $kind) {
        $clause = ImageKind::promptClause($kind);
        assert_contains('Imagery kind for all site imagery:', $clause);
        assert_true(!str_contains(ImageKind::meaning($kind), 'photograph') || $kind === 'photo');
    }
    assert_contains('no readable words', ImageKind::promptClause('ui-mockup'));
    assert_eq('3d-render', ImageKind::styleKeyword('3d-object'));
    assert_eq('ui-screenshot', ImageKind::styleKeyword('ui-mockup'), 'flat-design was itself a retro cue');
    $screen = ImageKind::promptClause('ui-mockup');
    foreach (['edge-to-edge screenshot', 'contemporary', 'filling the canvas to all four edges', 'never written on the screen', 'layered', 'soft elevation', 'no outer margin', 'no soft focus', 'no depth-of-field blur', 'no window frame', 'no title bar', 'no traffic-light dots', 'no browser tabs', 'no drop shadow', 'no backdrop', 'screen content only'] as $needle) {
        assert_contains($needle, $screen);
    }
    assert_true(!str_contains($screen, 'blurred'), 'the placeholder bars are soft, not blurred');
    assert_eq($screen, ImageKind::promptClause('ui-mockup', false, ''), 'an empty theme adds nothing');
    assert_true(str_ends_with(ImageKind::promptClause('ui-mockup', false, 'The interface uses a dark theme.'), 'no grain. The interface uses a dark theme.'), 'the theme sentence follows the clause');
    assert_eq(ImageKind::promptClause('3d-object'), ImageKind::promptClause('3d-object', false, 'The interface uses a dark theme.'), 'other kinds ignore the theme');
    assert_true(!str_contains($screen, 'framed'), 'the theme frames the screen, the picture does not');
    assert_true(ImageKind::skipsGrade('ui-mockup') && !ImageKind::skipsGrade('photo') && !ImageKind::skipsGrade('3d-object'));
    assert_eq('illustration', ImageKind::styleKeyword('line-illustration'));
    assert_eq('abstract', ImageKind::styleKeyword('abstract-gradient'));
    assert_eq('photorealistic', ImageKind::styleKeyword('nonsense'));
});

test('the composer appends the imagery kind as a render instruction, transparent assets included', function () {
    $plain = ImagePromptComposer::compose('A clay sphere and a torus', 'hero backdrop', '3d-render', 'A design studio.', 'Full colour, hard studio light.', false, null, 'landscape', '3d-object');
    assert_contains('Art direction for all site imagery: Full colour, hard studio light.', $plain);
    assert_contains('Imagery kind for all site imagery: smooth matte clay-like 3D objects', $plain);
    assert_true(strpos($plain, 'Imagery kind') > strpos($plain, 'Art direction'), 'the kind rides with the grade');

    $transparent = ImagePromptComposer::compose('A clay sphere', 'floating object', '3d-render', '', 'Full colour.', true, null, '', '3d-object');
    assert_true(!str_contains($transparent, 'Art direction'), 'a transparent asset skips the grade');
    assert_contains('Imagery kind for all site imagery', $transparent, 'but keeps the kind');

    $photo = ImagePromptComposer::compose('A loaf on a board', 'menu card', 'photorealistic', '', 'Warm film.', false, null, '', 'photo');
    assert_true(!str_contains($photo, 'Imagery kind'), 'a photo series adds no kind clause');
    assert_eq($photo, ImagePromptComposer::compose('A loaf on a board', 'menu card', 'photorealistic', '', 'Warm film.'), 'the default is byte-identical to the pre-field prompt');
});

test('the direction normalizes, persists, formats and reads image_kind', function () {
    $repairs = [];
    $warnings = [];
    $direction = DesignDirectionStep::normalize(['description' => 'x', 'image_kind' => ' UI-Mockup '], 'cinematic-safe-zone', 'seed', $repairs, $warnings);
    assert_eq('ui-mockup', $direction['image_kind']);
    $stray = DesignDirectionStep::normalize(['description' => 'x', 'image_kind' => 'hologram'], 'cinematic-safe-zone', 'seed', $repairs, $warnings);
    assert_eq('photo', $stray['image_kind']);
    assert_true(count(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'image_kind'))) === 1);
    assert_eq('photo', DesignDirectionStep::normalize(['description' => 'x'], 'cinematic-safe-zone')['image_kind']);
    assert_eq('photo', DesignDirectionStep::fallbackDirection('seed', 'cinematic-safe-zone')['image_kind']);

    $fact = DesignDirectionStep::format(['description' => 'x', 'image_kind' => '3d-object']);
    assert_contains('**Image kind**: 3d-object', $fact);
    assert_contains('style keyword `3d-render`', $fact);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'image_kind' => 'photo']), 'Image kind'), 'photo states no fact');

    with_project('frm-image-kind', function ($project): void {
        assert_eq('photo', DesignDirectionStep::imageKindFor($project));
        $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'line-illustration']);
        assert_eq('line-illustration', DesignDirectionStep::imageKindFor($project));
    });
});

test('the framed-screen kit ships for ui-mockup only, keys on the image role hooks and spares covers, avatars and transparent assets', function () {
    assert_eq(null, ImageKind::kitCss('photo'));
    assert_eq(null, ImageKind::kitCss('3d-object'));
    assert_eq(null, ImageKind::kitCss(null));
    $css = (string) ImageKind::kitCss(' UI-Mockup ');
    assert_contains('.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__media', $css);
    assert_contains(':not(.wp-block-cover *)', $css, 'a cover keeps its own treatment');
    assert_contains(':not([class*="avatar"])', $css, 'an avatar is not a screen');
    assert_contains(':has(> img:not([src$=".png"]))', $css, 'a transparent asset is not a screen');
    assert_contains('border-radius: var(--shape-radius-panel, 1rem)', $css, 'the frame accepts a panel radius and uses 1rem when it is absent');
    assert_contains('inset 0 0 0 1px color-mix(in srgb, currentColor 14%, transparent)', $css, 'the ring is drawn in the surface ink');
    assert_contains('::after {', $css, 'the ring overlays the picture edge');
    assert_contains('inset 0 1px 0 rgb(255 255 255 / 0.35)', $css, 'a light top edge reads as glass on a dark page');
    foreach (['::before', 'padding-block-start', 'radial-gradient', 'inset-inline-start'] as $chrome) {
        assert_true(!str_contains($css, $chrome), "no window chrome: {$chrome}");
    }
    assert_contains('.screen-frame--tilt', $css);
    assert_contains('rotate: x 6deg', $css, 'the tilt is the individual rotate property, never transform');
    assert_contains('rotate: none', $css, 'phones lie the screen flat');
    assert_true(!str_contains($css, '!important'), 'the screen kit fights nothing');
    assert_eq('screen-frame--tilt', ImageKind::TILT_CLASS);
});

test('a ui-mockup site inspects every picture and reads placeholder bars as shapes, not text', function () {
    assert_true(ImageKind::inspectsEveryImage('ui-mockup'));
    assert_true(!ImageKind::inspectsEveryImage('photo'));
    assert_true(!ImageKind::inspectsEveryImage(null));
    assert_eq('', ImageKind::qaTextRule('photo'));
    assert_contains('blurred placeholder bars', ImageKind::qaTextRule('ui-mockup'));
    assert_contains('legible letters, words or numerals', ImageKind::qaTextRule('ui-mockup'));

    $card = ['filename' => 'feature-dashboard.jpg', 'aspectRatio' => 'landscape', 'pageContext' => 'card thumbnail in a feature grid'];
    assert_true(!\Automattic\SiteBuild\ImageQa::applies($card), 'a photo card is not inspected');
    assert_true(\Automattic\SiteBuild\ImageQa::applies($card + ['image_kind' => 'ui-mockup']), 'a mockup card is inspected');
    assert_true(!\Automattic\SiteBuild\ImageQa::applies(['filename' => 'object.png', 'image_kind' => 'ui-mockup']), 'a transparent asset never is');

    $prompt = \Automattic\SiteBuild\PromptRenderer::fill(
        (string) file_get_contents(__DIR__ . '/../../prompts/image-qa.md'),
        ['subject' => 'a dashboard', 'upright_rule' => '', 'text_rule' => ImageKind::qaTextRule('ui-mockup')],
    );
    assert_contains('abstract marks do not count. This picture is a product-interface mockup', $prompt);
});

test('a mockup or a rendered object keeps its tilt through the QA upright question', function () {
    assert_true(ImageKind::keepsTilt('ui-mockup'));
    assert_true(ImageKind::keepsTilt('3d-object'));
    assert_true(!ImageKind::keepsTilt('photo'));
    assert_true(!ImageKind::keepsTilt('line-illustration'));
    assert_true(!ImageKind::keepsTilt(null));
    assert_eq('', ImageKind::qaUprightRule('photo'));
    assert_contains('product-interface mockup, not a photograph', ImageKind::qaUprightRule('ui-mockup'));
    assert_contains('rendered object, not a photograph', ImageKind::qaUprightRule('3d-object'));
    assert_contains('upside down or rotated a full quarter turn', ImageKind::qaUprightRule('ui-mockup'));

    $prompt = \Automattic\SiteBuild\PromptRenderer::fill(
        (string) file_get_contents(__DIR__ . '/../../prompts/image-qa.md'),
        ['subject' => 'a dashboard', 'upright_rule' => ImageKind::qaUprightRule('ui-mockup'), 'text_rule' => ''],
    );
    assert_contains('is NOT upright. This picture is a product-interface mockup, not a photograph', $prompt);
});

test('the direction fact tells a ui-mockup author about the frame and the one tilt class', function () {
    $rendered = DesignDirectionStep::format(['description' => 'x', 'image_kind' => 'ui-mockup']);
    assert_contains('frames every contained picture as a product screen', $rendered);
    assert_contains('no window chrome', $rendered);
    assert_contains('`screen-frame--tilt` to the figure or hero media wrapper of at most ONE screen per page', $rendered);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'image_kind' => '3d-object']), 'screen-frame--tilt'));
});

test('finalize-theme ships the screen kit for ui-mockup and prunes it for photo', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_screen_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Zova');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'ui-mockup']);
    finalize_static_header($project);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_contains('.hero-composition__media', $project->readText('theme/assets/screen/screen.css'));
    $php = $project->readText('theme/functions.php');
    assert_contains("wp_enqueue_style('zova-screen', get_theme_file_uri('assets/screen/screen.css'), array('zova-style'), \$ver);", $php);

    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'photo']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/screen/screen.css'), 'stale screen kit pruned');
    assert_true(!str_contains($project->readText('theme/functions.php'), 'zova-screen'), 'stale screen enqueue pruned');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the screen frame skips portraits and transparent assets, and only the first tilt per page stands', function () {
    $screen = ['filename' => 'clarity-dashboard.jpg', 'subject' => 'An abstract financial dashboard seen straight on', 'pageContext' => 'foreground product screen'];
    $portrait = ['filename' => 'customer-priya-portrait.jpg', 'subject' => 'A calm head-and-shoulders portrait of a woman seated at a desk', 'pageContext' => 'single customer portrait card beside a testimonial quote'];
    $logo = ['filename' => 'site-logo.png', 'subject' => 'simple geometric brand mark', 'pageContext' => 'site logo'];
    assert_true(ImageKind::isScreen($screen));
    assert_true(!ImageKind::isScreen($portrait), 'a person is never a screen');
    assert_true(!ImageKind::isScreen($logo), 'a transparent asset is never a screen');
    assert_true(!ImageKind::isScreen(['filename' => 'team.jpg', 'subject' => 'x', 'pageContext' => 'the founders at a table']), 'the slot text counts too');
    assert_eq(['customer-priya-portrait.jpg'], ImageKind::offKindFiles([$screen, $portrait, $logo, 'junk']), 'only jpg portraits are listed');

    $css = (string) ImageKind::kitCss('ui-mockup', ['customer-priya-portrait.jpg']);
    assert_eq(3, substr_count($css, ':not(:has(> img[src$="/customer-priya-portrait.jpg"]))'), 'frame, bar and image rule all skip the portrait');
    assert_true(!str_contains((string) ImageKind::kitCss('ui-mockup'), 'priya'));
    assert_contains(':is(:has(.screen-frame--tilt), .screen-frame--tilt) ~ * .screen-frame--tilt', $css, 'a later tilt lies flat');
    assert_contains('a"b', str_replace('\\"', '"', ImageKind::kitCss('ui-mockup', ['a"b.jpg']) ?? ''), 'a quote in a filename is escaped');
});

test('finalize-theme reads images.json to exempt portraits from the screen frame', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_screen2_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Zova');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'ui-mockup']);
    $project->writeJson('images.json', [
        ['filename' => 'dash.jpg', 'subject' => 'an abstract dashboard', 'pageContext' => 'product screen'],
        ['filename' => 'priya.jpg', 'subject' => 'portrait of a woman', 'pageContext' => 'testimonial card'],
    ]);
    finalize_static_header($project);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    $css = $project->readText('theme/assets/screen/screen.css');
    assert_contains('img[src$="/priya.jpg"]', $css);
    assert_true(!str_contains($css, 'dash.jpg'), 'a screen is framed');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a 3d-object cutout keeps its pale surfaces opaque; other kinds unmatte their edges', function () {
    assert_true(ImageKind::keepsSolidCutout('3d-object'));
    assert_true(!ImageKind::keepsSolidCutout('photo'));
    assert_true(!ImageKind::keepsSolidCutout('line-illustration'));
    assert_true(!ImageKind::keepsSolidCutout(null));
});


test('a transparent 3d-object request asks for a floating, shadowless object', function () {
    assert_contains('no contact shadow', ImageKind::promptClause('3d-object', true));
    assert_contains('floating', ImageKind::promptClause('3d-object', true));
    assert_contains('plain seamless backdrop', ImageKind::promptClause('3d-object'));
    assert_eq('', ImageKind::promptClause('ui-mockup', true));
});

test('a person on a ui-mockup site takes the portrait clause instead of the interface clause', function () {
    assert_true(ImageKind::namesPerson('A stylized abstract user avatar tile: a simple rounded geometric figure silhouette'));
    assert_true(ImageKind::namesPerson('portrait of the founder at her desk'));
    assert_true(!ImageKind::namesPerson('A dashboard with a rising area chart'));
    $portrait = ImagePromptComposer::compose('A stylized abstract user avatar tile on a pale panel', 'testimonial card beside a quote', 'photorealistic', '', 'Cool, evenly lit interface renders', false, null, 'card-landscape', 'ui-mockup');
    assert_contains('a photographic portrait of one real person', $portrait);
    assert_true(!str_contains($portrait, 'an edge-to-edge screenshot of a contemporary, design-led web application'), 'the interface clause yields');
    assert_true(!str_contains($portrait, 'Art direction for all site imagery: Cool, evenly lit interface renders'), 'the interface grade yields too');
    $screen = ImagePromptComposer::compose('A dashboard with a rising area chart', 'product tour', 'photorealistic', '', 'Cool, evenly lit interface renders', false, null, 'card-landscape', 'ui-mockup');
    assert_contains('an edge-to-edge screenshot of a contemporary, design-led web application', $screen, 'a screen keeps the interface clause');
    $clay = ImagePromptComposer::compose('portrait of the founder', 'testimonial', 'photorealistic', '', '', false, null, 'card-landscape', '3d-object');
    assert_true(!str_contains($clay, 'photographic portrait'), 'only the ui-mockup kind yields; a 3D-object site keeps its objects');
});

test('the interface theme follows the page ground and names the accent', function () {
    $dark = ImageKind::screenTheme('#101214', '#e8552f');
    assert_contains('dark theme', $dark);
    assert_contains('The single accent colour is red.', $dark);
    assert_true(preg_match('/#|\\d/', $dark) !== 1, 'no hex code and no numeral reaches the picture as a label');
    $light = ImageKind::screenTheme('#F7F4EE', null);
    assert_contains('light theme', $light);
    assert_true(!str_contains($light, 'accent colour'), 'no accent, no accent sentence');
    assert_eq('', ImageKind::screenTheme(null, '#E8552F'), 'no base, no theme');
    assert_eq('', ImageKind::screenTheme('white', '#E8552F'), 'an unusable base gives no theme');
    assert_contains('dark theme', ImageKind::screenTheme('#101214', 'red'));
    assert_true(!str_contains(ImageKind::screenTheme('#101214', 'red'), 'accent colour'), 'an unusable accent adds no accent sentence');
    foreach (['#7C5CFF' => 'violet', '#E8552F' => 'red', '#F28C28' => 'orange', '#1F6FEB' => 'blue', '#0F9D8A' => 'teal', '#C0392B' => 'red', '#F5C518' => 'amber', '#2E7D32' => 'green', '#D81B60' => 'pink', '#888888' => 'a neutral grey', '#101214' => 'a neutral grey'] as $hex => $name) {
        assert_eq($name, ImageKind::hueName($hex), $hex);
    }
    assert_eq(null, ImageKind::hueName('red'));

    $tmp = sys_get_temp_dir() . '/builder_screen_theme_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Zova');
    assert_eq('', DesignDirectionStep::screenThemeFor($project), 'no direction, no theme');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'ui-mockup', 'palette' => ['base' => '#0B0D10', 'accent' => '#7C5CFF']]);
    assert_contains('dark theme', DesignDirectionStep::screenThemeFor($project));
    assert_contains('The single accent colour is violet.', DesignDirectionStep::screenThemeFor($project));
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'ui-mockup', 'palette' => 'none']);
    assert_eq('', DesignDirectionStep::screenThemeFor($project), 'a malformed palette gives no theme');

    $screen = ImagePromptComposer::compose('A dashboard', 'product tour', 'ui-screenshot', '', 'Cool light.', false, null, '', 'ui-mockup', 'The interface uses a dark theme.');
    assert_contains('no grain. The interface uses a dark theme.', $screen, 'the composer carries the theme into the clause');
    $person = ImagePromptComposer::compose('A portrait of the founder', 'testimonial', 'ui-screenshot', '', 'Cool light.', false, null, '', 'ui-mockup', 'The interface uses a dark theme.');
    assert_true(!str_contains($person, 'dark theme'), 'a portrait on the same site takes no interface theme');
});

test('a screenshot prompt keeps its subject and swaps the scenery guidance for interface guidance', function () {
    $screen = ImagePromptComposer::compose('A revenue analytics overview', 'product tour', 'ui-screenshot', 'Zova, a finance tool.', 'Cool light.', false, null, '', 'ui-mockup');
    assert_true(str_starts_with($screen, 'A revenue analytics overview. Style: ui-screenshot'), 'a prefix on the subject made the model paint a margin and more labels');
    assert_contains('Purely pictorial interface: the screen itself fills every part of the canvas', $screen);
    assert_contains('none of their words is written on the screen: Composition:', $screen);
    assert_true(!str_contains($screen, 'continuous unbroken scenery'), 'no photo scenery guidance on a screen');
    $person = ImagePromptComposer::compose('A portrait of the founder', 'testimonial', 'ui-screenshot', 'Zova, a finance tool.', 'Cool light.', false, null, '', 'ui-mockup');
    assert_true(str_starts_with($person, 'A portrait of the founder.'), 'a portrait keeps its subject');
    assert_contains('continuous unbroken scenery', $person);
    $photo = ImagePromptComposer::compose('A loaf on a board', 'menu card', 'photorealistic', 'A bakery.', 'Warm film.', false, null, '', 'photo');
    assert_true(str_starts_with($photo, 'A loaf on a board.'));
});

test('image kinds preserve screen subjects and isolate portraits and transparent assets', function () {
    foreach (['ui-mockup', 'line-illustration', 'abstract-gradient'] as $kind) {
        assert_eq('', ImageKind::promptClause($kind, true));
    }
    assert_true(!ImageKind::namesPerson('a team board with round avatars and status pills'));
    assert_true(!ImageKind::namesPerson('a man-made sculpture'));
    foreach (['customer portrait', 'CEO', 'hands'] as $subject) {
        assert_true(ImageKind::namesPerson($subject));
    }
    $prompt = \Automattic\SiteBuild\ImagePromptComposer::compose('a founder portrait', 'team', 'ui-screenshot', imageKind: 'ui-mockup');
    assert_contains('Style: photorealistic', $prompt);
    assert_true(!str_contains($prompt, 'Style: ui-screenshot'));
    assert_eq('photo', ImageKind::effectiveKind(['image_kind' => 'ui-mockup', 'subject' => 'founder portrait']));
    $prompt = \Automattic\SiteBuild\ImagePromptComposer::compose('billing dashboard with one teal accent bar', 'product', 'ui-screenshot', imageGrade: 'cool interface renders with one teal accent', imageKind: 'ui-mockup');
    assert_contains('billing dashboard with one teal accent bar', $prompt);
});
