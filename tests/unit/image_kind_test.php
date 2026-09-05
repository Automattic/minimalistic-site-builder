<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageKind;
use Automattic\SiteBuild\ImagePromptComposer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

test('image kind is a closed vocabulary with one style keyword and one render clause each (frm W7a)', function () {
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
    assert_eq('flat-design', ImageKind::styleKeyword('ui-mockup'));
    assert_eq('illustration', ImageKind::styleKeyword('line-illustration'));
    assert_eq('abstract', ImageKind::styleKeyword('abstract-gradient'));
    assert_eq('photorealistic', ImageKind::styleKeyword('nonsense'));
});

test('the composer appends the imagery kind as a render instruction, transparent assets included (frm W7a)', function () {
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

test('the direction normalizes, persists, formats and reads image_kind (frm W7a)', function () {
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

test('the framed-screen kit ships for ui-mockup only, keys on the image role hooks and spares covers, avatars and transparent assets (frm W7b)', function () {
    assert_eq(null, ImageKind::kitCss('photo'));
    assert_eq(null, ImageKind::kitCss('3d-object'));
    assert_eq(null, ImageKind::kitCss(null));
    $css = (string) ImageKind::kitCss(' UI-Mockup ');
    assert_contains('.wp-block-image, .card-media, .card-media-tall, .card-media-thumb, .feature-media, .hero-composition__stage', $css);
    assert_contains(':not(.wp-block-cover *)', $css, 'a cover keeps its own treatment');
    assert_contains(':not([class*="avatar"])', $css, 'an avatar is not a screen');
    assert_contains(':has(> img:not([src$=".png"]))', $css, 'a transparent asset is not a screen');
    assert_contains('border-radius: var(--shape-radius-panel, 1rem)', $css, 'the frame takes the committed panel radius');
    assert_contains('inset 0 0 0 1px color-mix(in srgb, currentColor 16%, transparent)', $css, 'the ring is drawn in the surface ink');
    assert_contains('inset-inline-start: 0.875rem', $css, 'the window dots follow the writing direction');
    assert_contains('.screen-frame--tilt', $css);
    assert_contains('rotate: x 6deg', $css, 'the tilt is the individual rotate property, never transform');
    assert_contains('rotate: none', $css, 'phones lie the screen flat');
    assert_true(!str_contains($css, '!important'), 'the screen kit fights nothing');
    assert_eq('screen-frame--tilt', ImageKind::TILT_CLASS);
});

test('a ui-mockup site inspects every picture and reads placeholder bars as shapes, not text (frm W7b)', function () {
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
        ['subject' => 'a dashboard', 'text_rule' => ImageKind::qaTextRule('ui-mockup')],
    );
    assert_contains('abstract marks do not count. This picture is a product-interface mockup', $prompt);
});

test('the direction fact tells a ui-mockup author about the frame and the one tilt class (frm W7b)', function () {
    $rendered = DesignDirectionStep::format(['description' => 'x', 'image_kind' => 'ui-mockup']);
    assert_contains('frames every contained picture as a product window', $rendered);
    assert_contains('`screen-frame--tilt` to at most ONE screen per page', $rendered);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'image_kind' => '3d-object']), 'screen-frame--tilt'));
});

test('finalize-theme ships the screen kit for ui-mockup and prunes it for photo (frm W7b)', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_screen_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Zova');
    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'ui-mockup']);
    finalize_static_header($project);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_contains('.hero-composition__stage', $project->readText('theme/assets/screen/screen.css'));
    $php = $project->readText('theme/functions.php');
    assert_contains("wp_enqueue_style('zova-screen', get_theme_file_uri('assets/screen/screen.css'), array('zova-style'), \$ver);", $php);

    $project->writeJson('designDirection.json', ['description' => 'x', 'image_kind' => 'photo']);
    quietly(fn () => (new FinalizeThemeStep())->run($project));
    assert_true(!$project->exists('theme/assets/screen/screen.css'), 'stale screen kit pruned');
    assert_true(!str_contains($project->readText('theme/functions.php'), 'zova-screen'), 'stale screen enqueue pruned');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the screen frame skips portraits and transparent assets, and only the first tilt per page stands (frm W7b)', function () {
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

test('finalize-theme reads images.json to exempt portraits from the screen frame (frm W7b)', function () {
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


test('the direction fact tells a 3d-object author about the floating-object group (frm W7c)', function () {
    $rendered = DesignDirectionStep::format(['description' => 'x', 'image_kind' => '3d-object']);
    assert_contains('floating-object group', $rendered);
    assert_true(!str_contains(DesignDirectionStep::format(['description' => 'x', 'image_kind' => 'photo']), 'floating-object'));
});
