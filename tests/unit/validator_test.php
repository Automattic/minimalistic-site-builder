<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\HeaderBehavior;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\SectionRhythm;
use Automattic\SiteBuild\Steps\ThemeJsonStep;
use Automattic\SiteBuild\ThemeValidator;

function validator_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_val_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    // Minimal valid theme.
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $ok = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
    $project->writeText('theme/templates/index.html', $ok);
    $project->writeText('theme/templates/page.html', $ok);
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText('theme/parts/footer.html', '<!-- wp:paragraph --><p>f</p><!-- /wp:paragraph -->');
    return [$project, $tmp];
}

test('validator passes a well-formed theme', function () {
    [$project, $tmp] = validator_project();
    assert_eq([], ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

/** @return array{0:\Automattic\SiteBuild\Project,1:string} */
function validator_above_fold_project(): array
{
    [$project, $tmp] = validator_project();
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [[
            'slug' => 'hero',
            'title' => 'Home',
            'layout_archetype' => 'mixed-width-editorial',
            'background' => 'contrast',
            'primary_action' => null,
        ]],
    ]];
    $blueprint = HeroBlueprint::defaultFor('focal-subject-stage');
    $delivery = AboveFoldContract::resolve(
        $pages,
        $blueprint,
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#111111'],
        ['stable_id' => 'validator-test', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'standard-row',
    );
    $header = '<!-- wp:group {"className":"header-archetype--standard-row","backgroundColor":"base","textColor":"contrast"} -->'
        . '<div class="wp-block-group header-archetype--standard-row has-base-background-color has-contrast-color"></div>'
        . '<!-- /wp:group -->';
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage"></div><!-- /wp:group -->';
    $final = AboveFoldContract::finalizeMarkup($delivery, $pages, [
        'part_keys' => ['header', 'page-home--hero'],
        'opening_overlay_support' => ['page-home--hero' => false],
        'primary_action_delivered' => true,
    ]);
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $final);
    $project->writeText('theme/parts/header.html', $header);
    $project->writeText('plugin/pages/home.html', $hero . "\n");
    return [$project, $tmp];
}

test('final above-fold validator accepts the persisted header and hero relation', function () {
    [$project, $tmp] = validator_above_fold_project();
    assert_eq([], ThemeValidator::aboveFoldWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('final above-fold advisory ignores a rejected header artifact for its independent scan', function () {
    [$project, $tmp] = validator_above_fold_project();
    $project->writeJson(HeaderBehavior::FILE, [
        'behavior' => HeaderBehavior::STATIC,
        'mode' => HeaderBehavior::MODE_STACKED,
        'transition' => HeaderBehavior::TRANSITION_INSTANT,
        'topSurface' => 'base',
        'scrolledSurface' => 'base',
        'foreground' => 'contrast',
        'topTreatment' => HeaderBehavior::TREATMENT_SOLID,
        'scrolledTreatment' => HeaderBehavior::TREATMENT_SOLID,
        'generatedExtension' => true,
    ]);

    assert_eq([], ThemeValidator::aboveFoldWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('final above-fold validator accepts an exact custom protection color', function () {
    [$project, $tmp] = validator_project();
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'path' => '/',
        'front' => true,
        'sections' => [[
            'slug' => 'hero',
            'title' => 'Home',
            'layout_archetype' => 'full-bleed-cover',
            'background' => 'image',
            'primary_action' => null,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('cinematic-safe-zone'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#161513'],
        ['stable_id' => 'validator-custom-overlay', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'minimal-overlay',
    );
    $final = AboveFoldContract::finalizeMarkup($delivery, $pages, [
        'part_keys' => ['header', 'page-home--hero'],
        'opening_overlay_support' => ['page-home--hero' => true],
        'opening_surfaces' => ['page-home--hero' => 'image'],
        'primary_action_delivered' => true,
    ]);
    $header = '<!-- wp:group {"className":"header-overlay header-archetype--minimal-overlay","textColor":"base"} -->'
        . '<div class="wp-block-group header-overlay header-archetype--minimal-overlay has-base-color"></div>'
        . '<!-- /wp:group -->';
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--cinematic-safe-zone">'
        . '<!-- wp:cover {"dimRatio":60,"customOverlayColor":"#161513","isUserOverlayColor":true} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" '
        . 'class="wp-block-cover__background has-background-dim-60 has-background-dim" '
        . 'style="background-color:#161513"></span>'
        . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $final);
    $project->writeText('theme/parts/header.html', $header);
    $project->writeText('plugin/pages/home.html', $hero . "\n");

    assert_eq([], ThemeValidator::aboveFoldWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('final above-fold validator checks saved Cover paint for an earned clear header', function () {
    [$project, $tmp] = validator_project();
    $pages = [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [[
            'slug' => 'hero', 'title' => 'Home', 'layout_archetype' => 'full-bleed-cover',
            'background' => 'image', 'primary_action' => null,
        ]],
    ]];
    $delivery = AboveFoldContract::resolve(
        $pages,
        HeroBlueprint::defaultFor('cinematic-safe-zone'),
        'full-bleed',
        ['base' => '#FFFFFF', 'contrast' => '#161513'],
        ['stable_id' => 'validator-clear-overlay', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
        'minimal-overlay',
    );
    $final = AboveFoldContract::finalizeMarkup($delivery, $pages, [
        'part_keys' => ['header', 'page-home--hero'],
        'opening_overlay_support' => ['page-home--hero' => true],
        'opening_surfaces' => ['page-home--hero' => 'image'],
        'primary_action_delivered' => true,
    ]);
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', $final);
    $project->writeJson(HeaderBehavior::FILE, [
        'behavior' => HeaderBehavior::OVERLAY_TO_SOLID,
        'mode' => HeaderBehavior::MODE_OVERLAY,
        'transition' => HeaderBehavior::TRANSITION_SMOOTH,
        'topSurface' => HeaderBehavior::TRANSPARENT,
        'scrolledSurface' => 'contrast',
        'foreground' => 'base',
        'topTreatment' => HeaderBehavior::TREATMENT_TRANSPARENT,
        'scrolledTreatment' => HeaderBehavior::TREATMENT_SOLID,
    ]);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"className":"header-overlay header-archetype--minimal-overlay","textColor":"base"} -->'
            . '<div class="wp-block-group header-overlay header-archetype--minimal-overlay has-base-color"></div>'
            . '<!-- /wp:group -->',
    );
    $hero = '<!-- wp:group {"anchor":"hero","className":"hero-composition--cinematic-safe-zone","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--cinematic-safe-zone">'
        . '<!-- wp:cover {"dimRatio":60,"overlayColor":"contrast"} -->'
        . '<div class="wp-block-cover"><span aria-hidden="true" '
        . 'class="wp-block-cover__background has-contrast-background-color has-background-dim-60 has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('plugin/pages/home.html', $hero . "\n");
    assert_eq([], ThemeValidator::aboveFoldWarnings($project));

    $project->writeText(
        'plugin/pages/home.html',
        str_replace('has-background-dim-60', 'has-background-dim-40', $hero) . "\n",
    );
    $warnings = implode("\n", ThemeValidator::aboveFoldWarnings($project));
    assert_contains("openings[page='home'].top_protection_token", $warnings);
    assert_contains('delivered="unsupported"', $warnings);

    $project->writeText(
        'plugin/pages/home.html',
        str_replace(
            ['"dimRatio":60', 'has-background-dim-60'],
            ['"dimRatio":40', 'has-background-dim-40'],
            $hero,
        ) . "\n",
    );
    $warnings = implode("\n", ThemeValidator::aboveFoldWarnings($project));
    assert_contains(
        "openings[page='home'].top_protection_token",
        $warnings,
        'coherent saved paint still needs enough effective dim to prove the clear header',
    );
    assert_contains('delivered="unsupported"', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('final above-fold validator reports downstream drift without mutating markup', function () {
    [$project, $tmp] = validator_above_fold_project();
    $header = str_replace('header-archetype--standard-row', 'header-archetype--split-nav', $project->readText('theme/parts/header.html'));
    $hero = str_replace('hero-composition--focal-subject-stage', 'hero-composition--editorial-split', $project->readText('plugin/pages/home.html'));
    $project->writeText('theme/parts/header.html', $header);
    $project->writeText('plugin/pages/home.html', $hero);

    $warnings = ThemeValidator::aboveFoldWarnings($project);
    $joined = implode("\n", $warnings);
    assert_contains('header.archetype', $joined);
    assert_contains('hero.recipe_marker', $joined);
    assert_contains("file='theme/parts/header.html'", $joined);
    assert_contains('authored=', $joined);
    assert_contains('delivered=', $joined);
    assert_contains('disposition=', $joined);
    assert_eq($header, $project->readText('theme/parts/header.html'), 'advisory validation must not mutate header');
    assert_eq($hero, $project->readText('plugin/pages/home.html'), 'advisory validation must not mutate page');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('final above-fold validator reports competing stacked chrome and an over-budget opening cover', function () {
    [$project, $tmp] = validator_above_fold_project();
    $header = str_replace(
        '"backgroundColor":"base"',
        '"backgroundColor":"base","gradient":"invented-gradient"',
        $project->readText('theme/parts/header.html'),
    );
    $opening = '<!-- wp:group {"anchor":"hero","className":"hero-composition--focal-subject-stage","layout":{"type":"constrained"}} -->'
        . '<div id="hero" class="wp-block-group hero-composition--focal-subject-stage">'
        . '<!-- wp:cover {"minHeight":92,"minHeightUnit":"vh"} --><div class="wp-block-cover" style="min-height:92vh"></div><!-- /wp:cover -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('theme/parts/header.html', $header);
    $project->writeText('plugin/pages/home.html', $opening);

    $warnings = ThemeValidator::aboveFoldWarnings($project);
    $joined = implode("\n", $warnings);
    assert_contains('header.stacked_surface', $joined);
    assert_contains('stacked_cover_max_vh', $joined);
    assert_contains('authored=80', $joined);
    assert_contains('delivered=92', $joined);
    assert_eq($header, $project->readText('theme/parts/header.html'));
    assert_eq($opening, $project->readText('plugin/pages/home.html'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags unbalanced block comments', function () {
    [$project, $tmp] = validator_project();
    // Opening with no close.
    $project->writeText('theme/templates/page.html', '<!-- wp:group --><div>oops</div>');
    $problems = ThemeValidator::validate($project);
    assert_true(count($problems) > 0, 'should report a problem');
    assert_contains('unbalanced', implode(' ', $problems));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typography warnings flag hardcoded font sizes and an unused display preset', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'small', 'name' => 'Small', 'size' => '0.875rem'],
        ['slug' => 'display', 'name' => 'Display', 'size' => 'clamp(3rem, 7vw, 6rem)'],
    ]]]]);
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.75rem, 5vw, 4.25rem)"}}} -->'
        . '<h1 class="wp-block-heading" style="font-size:clamp(2.75rem, 5vw, 4.25rem)">Big</h1><!-- /wp:heading -->'
    );
    $warnings = ThemeValidator::typographyWarnings($project);
    $joined = implode(' ', $warnings);
    assert_contains('hardcoded font-size', $joined);
    assert_contains('section-hero.html', $joined);
    assert_contains('"display" fontSize', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typography warnings flag long paragraphs at heading-scale presets', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'medium', 'name' => 'Body', 'size' => '1rem'],
        ['slug' => 'large', 'name' => 'Large', 'size' => '1.25rem'],
    ]]]]);
    $long = 'Naturaleza Sabia was born from a simple conviction: that the soul of Argentine cooking lives in its rituals, not only its meat, reimagined at the market each morning.';
    $project->writeText(
        'theme/parts/section-hero.html',
        // A long intro pushed up the scale (flagged) and a short lead line (fine).
        '<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">' . $long . '</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">One short lead line.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"medium"} --><p class="has-medium-font-size">' . $long . '</p><!-- /wp:paragraph -->'
    );
    $warnings = ThemeValidator::typographyWarnings($project);
    $joined = implode(' ', $warnings);
    assert_contains('heading-scale', $joined);
    assert_contains('section-hero.html (1)', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typography warnings flag long paragraphs at caption-scale presets', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'caption', 'name' => 'Caption', 'size' => '0.875rem'],
        ['slug' => 'body', 'name' => 'Body', 'size' => '1.125rem'],
    ]]]]);
    $long = 'Down a cobbled lane in Tbilisi Old Town, our tavern keeps its supra rituals alive: wine from clay qvevri, khachapuri still steaming, and a toast to close each night.';
    $project->writeText(
        'theme/parts/section-story.html',
        // Running copy shrunk to caption (flagged); a caption-size label (fine).
        '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">' . $long . '</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Est. 1998 · Old Town</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"body"} --><p class="has-body-font-size">' . $long . '</p><!-- /wp:paragraph -->'
    );
    $warnings = ThemeValidator::typographyWarnings($project);
    $joined = implode(' ', $warnings);
    assert_contains('caption-scale', $joined);
    assert_contains('section-story.html (1)', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('typography warnings stay quiet when sizes come from the preset scale', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['typography' => ['fontSizes' => [
        ['slug' => 'display', 'name' => 'Display', 'size' => 'clamp(3rem, 7vw, 6rem)'],
    ]]]]);
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:heading {"level":1,"fontSize":"display"} -->'
        . '<h1 class="wp-block-heading has-display-font-size">Big</h1><!-- /wp:heading -->'
    );
    assert_eq([], ThemeValidator::typographyWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags bad theme.json and leftover placeholders', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('theme/theme.json', '{not json');
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title {{THEME_NAME}} /-->');
    $problems = ThemeValidator::validate($project);
    $joined = implode(' ', $problems);
    assert_contains('theme.json', $joined);
    assert_contains('placeholder', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('image source validator flags unresolved AI_IMAGE values in JSON and HTML sources', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:cover {"url":"AI_IMAGE:dense fog over the dunes|ratio:21:9|role:hero"} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" '
        . 'src="AI_IMAGE:dense fog over the dunes|ratio:21:9|role:hero" alt=""/></div>'
        . '<!-- /wp:cover -->'
    );
    $joined = implode(' ', ThemeValidator::unresolvedImageSourceProblems($project));
    assert_contains('plugin/pages/home.html', $joined);
    assert_contains('AI_IMAGE', $joined);
    assert_contains('block JSON', $joined);
    assert_contains('HTML src', $joined);
    assert_contains('plugin/pages/home.html', implode(' ', ThemeValidator::validate($project)));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('image source validator ignores the documented AI_IMAGE alt after generation', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="/wp-content/themes/demo/assets/coffee.jpg" '
        . 'alt="AI_IMAGE: coffee and croissants | hero | photo | landscape"/>'
        . '</figure><!-- /wp:image -->'
    );
    assert_eq([], ThemeValidator::unresolvedImageSourceProblems($project));
    assert_eq([], ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('image source validator flags references collect-images never recorded', function () {
    // The silent failure this exists to stop: a page full of invented srcs let
    // generate-images report "completed" with zero images.
    [$project, $tmp] = validator_project();
    $project->writeJson('images.json', []);
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:image --><figure class="wp-block-image"><img src="hero.jpg" alt="A hero"/></figure><!-- /wp:image -->'
        . '<!-- wp:cover {"url":"bg.png"} --><div class="wp-block-cover"></div><!-- /wp:cover -->'
    );

    $joined = implode(' ', ThemeValidator::unresolvedImageSourceProblems($project));
    assert_contains('hero.jpg', $joined);
    assert_contains('bg.png', $joined);
    assert_contains('never collected for generation', $joined);
    assert_contains('plugin/pages/home.html', implode(' ', ThemeValidator::validate($project)));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('image source validator judges only unresolvable image references', function () {
    [$project, $tmp] = validator_project();
    // A collected image keeps its placeholder whatever its status — generation
    // leaves a failed image in place rather than abort the build.
    $project->writeJson('images.json', [
        ['filename' => 'known.jpg', 'src' => 'theme:./assets/known.jpg', 'status' => 'failed'],
    ]);
    $project->writeText('plugin/pages/home.html',
        '<img src="theme:./assets/known.jpg" alt="Collected but not generated"/>'
        . '<img src="/wp-content/themes/demo/assets/done.jpg" alt="Generated"/>'
        . '<img src="https://cdn.example.com/remote.jpg" alt="Remote"/>'
        . '<img src="data:image/svg+xml,%3Csvg%3E" alt="Inline"/>'
        . '<!-- wp:social-link {"url":"#","service":"x"} /-->'
    );

    assert_eq([], ThemeValidator::unresolvedImageSourceProblems($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('image source validator stays quiet when collection never ran', function () {
    // Theme-only fixtures and hosts with their own image pipeline write no
    // images.json, so nothing can be called dangling.
    [$project, $tmp] = validator_project();
    $project->writeText('plugin/pages/home.html', '<img src="hero.jpg" alt="A hero"/>');

    assert_eq([], ThemeValidator::unresolvedImageSourceProblems($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator checks the content plugin pages for balance and placeholders', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('plugin/pages/home.html', '<!-- wp:group --><div>oops, never closed</div>');
    $project->writeText('plugin/pages/menu.html', '<!-- wp:paragraph --><p>{{TITLE}}</p><!-- /wp:paragraph -->');

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('plugin/pages/home.html', $joined);
    assert_contains('unbalanced', $joined);
    assert_contains('plugin/pages/menu.html', $joined);
    assert_contains('placeholder', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags raw form markup in generated markup', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('plugin/pages/contact.html', '<!-- wp:group --><div class="wp-block-group"><form><input type="text"></form></div><!-- /wp:group -->');

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('plugin/pages/contact.html', $joined);
    assert_contains('raw form markup', $joined);
    assert_contains('supported form contract', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator accepts Jetpack Forms block markup', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('plugin/pages/contact.html',
        '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/field-name {"label":"Name","required":true} /-->'
        . '<!-- wp:jetpack/field-email {"label":"Email","required":true} /-->'
        . '<!-- wp:button {"tagName":"button","type":"submit","className":"form-button-submit is-submit"} -->'
        . '<div class="wp-block-button form-button-submit is-submit">'
        . '<button type="submit" class="wp-block-button__link wp-element-button">Send</button>'
        . '</div><!-- /wp:button -->'
        . '</div><!-- /wp:jetpack/contact-form -->'
    );

    assert_eq([], ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator rejects a submit claim whose saved HTML is a link', function () {
    [$project, $tmp] = validator_project();

    // Attributes claim a submit button, but the saved HTML is an anchor — a
    // link styled as a button submits nothing.
    $project->writeText('plugin/pages/contacto.html',
        '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/field-email {"label":"Email","required":true} /-->'
        . '<!-- wp:button {"tagName":"button","type":"submit","className":"form-button-submit is-submit"} -->'
        . '<div class="wp-block-button form-button-submit is-submit">'
        . '<a class="wp-block-button__link wp-element-button" href="/gracias">Enviar</a>'
        . '</div><!-- /wp:button -->'
        . '</div><!-- /wp:jetpack/contact-form -->'
    );
    $problems = ThemeValidator::validate($project);
    assert_eq(1, count($problems));
    assert_true(str_contains($problems[0], 'no working submit control'), 'anchor-saved submit flagged');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags a contact form whose submit control cannot submit', function () {
    [$project, $tmp] = validator_project();

    // No submit control at all.
    $project->writeText('plugin/pages/contact.html',
        '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/field-email {"label":"Email","required":true} /-->'
        . '</div><!-- /wp:jetpack/contact-form -->'
    );
    $problems = ThemeValidator::validate($project);
    assert_eq(1, count($problems));
    assert_true(str_contains($problems[0], 'no working submit control'), 'missing control flagged');

    // An explicit anchor jetpack/button is exactly as dead as the implicit
    // one the fixer repairs — and the fixer deliberately skips it.
    $project->writeText('plugin/pages/contact.html',
        '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/field-email {"label":"Email","required":true} /-->'
        . '<!-- wp:jetpack/button {"element":"a","text":"Send"} /-->'
        . '</div><!-- /wp:jetpack/contact-form -->'
    );
    $problems = ThemeValidator::validate($project);
    assert_eq(1, count($problems));
    assert_true(str_contains($problems[0], 'no working submit control'), 'anchor jetpack/button flagged');

    // The fixer's repaired shape passes: both blessed grammars stay valid.
    $project->writeText('plugin/pages/contact.html',
        '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form">'
        . '<!-- wp:jetpack/field-email {"label":"Email","required":true} /-->'
        . '<!-- wp:jetpack/button {"element":"button","text":"Send"} /-->'
        . '</div><!-- /wp:jetpack/contact-form -->'
    );
    assert_eq([], ThemeValidator::validate($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

/** A two-page site (home + visit) with markup on disk, for the link checks. */
function validator_linked_project(): array
{
    [$project, $tmp] = validator_project();
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'path' => '/', 'front' => true],
        ['slug' => 'visit', 'path' => '/visit/', 'front' => false],
    ]]);
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:group {"anchor":"hero"} --><div class="wp-block-group" id="hero">'
        . '<!-- wp:paragraph --><p><a href="/visit/#directions">Find us</a></p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->');
    $project->writeText('plugin/pages/visit.html',
        '<!-- wp:group {"anchor":"directions"} --><div class="wp-block-group" id="directions">'
        . '<!-- wp:paragraph --><p><a href="/">Home</a> · <a href="#directions">Top</a> · '
        . '<a href="https://example.com">Ext</a> · <a href="mailto:a@b.c">Mail</a></p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->');
    return [$project, $tmp];
}

test('validator passes links that resolve to real pages and anchors', function () {
    [$project, $tmp] = validator_linked_project();
    assert_eq([], ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags links to routes no generated page has', function () {
    [$project, $tmp] = validator_linked_project();
    // Trailing-slash-less form of a real page is fine; /work/ does not exist.
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:paragraph --><p><a href="/visit">ok</a> <a href="/work/?strand=a">nope</a></p><!-- /wp:paragraph -->');

    $problems = ThemeValidator::validate($project);
    assert_eq(1, count($problems), implode('; ', $problems));
    assert_contains('href="/work/?strand=a"', $problems[0]);
    assert_contains('no page has path /work/', $problems[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags fragments missing on the page they target', function () {
    [$project, $tmp] = validator_linked_project();
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:paragraph --><p><a href="/visit/#reservations">Book</a> <a href="#missing-here">In-page</a></p><!-- /wp:paragraph -->');

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('href="/visit/#reservations"', $joined);
    assert_contains("page 'visit', which has no id=\"reservations\"", $joined);
    assert_contains('href="#missing-here"', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator judges block-JSON url destinations (navigation links)', function () {
    [$project, $tmp] = validator_linked_project();
    $project->writeText('theme/parts/header.html',
        '<!-- wp:navigation-link {"label":"Menu","url":"\/menu\/"} /-->');

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('theme/parts/header.html', $joined);
    assert_contains('no page has path /menu/', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator ignores rewritten theme asset urls from generate-images', function () {
    [$project, $tmp] = validator_linked_project();
    // Cover "url" after GenerateImagesStep rewrite — root-relative but not a page.
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:cover {"url":"/wp-content/themes/demo/assets/hero-barista.jpg","dimRatio":40} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" '
        . 'src="/wp-content/themes/demo/assets/hero-barista.jpg" alt=""/></div>'
        . '<!-- /wp:cover -->'
        . '<!-- wp:paragraph --><p><a href="/visit">Visit</a></p><!-- /wp:paragraph -->'
    );

    $problems = ThemeValidator::validate($project);
    assert_eq([], $problems, 'theme assets must not be judged as page links: ' . implode('; ', $problems));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator holds chrome fragments to anchors that exist on every page', function () {
    [$project, $tmp] = validator_linked_project();
    // "hero" exists only on the home page; a header link to it 404s (well,
    // scrolls nowhere) on /visit/.
    $project->writeText('theme/parts/header.html',
        '<!-- wp:navigation-link {"label":"Hero","url":"#hero"} /-->');

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('chrome link href="#hero"', $joined);
    assert_contains('not every page', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator flags button links without an href', function () {
    [$project, $tmp] = validator_linked_project();
    $project->writeText('plugin/pages/home.html',
        '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link wp-element-button">Reserve</a></div><!-- /wp:button -->');

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('button link has no href', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator reports placeholder links with actionable delivery context', function () {
    [$project, $tmp] = validator_linked_project();
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:button {"url":"#"} --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="#">Instagram</a></div><!-- /wp:button -->'
        . '<!-- wp:navigation-link {"label":"Social","url":"#"} --><!-- /wp:navigation-link -->'
        . "<!-- wp:paragraph --><p><a href='#'>Legal</a></p><!-- /wp:paragraph -->"
        . '<!-- wp:paragraph --><p><a href=#>Privacy</a></p><!-- /wp:paragraph -->'
        . '<!-- wp:cover {"url":"#"} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src=# alt=""/></div><!-- /wp:cover -->'
    );

    $problems = ThemeValidator::placeholderLinkProblems($project);
    assert_eq(4, count($problems), 'rendered and mirrored block URLs are reported once per dead interaction');
    $joined = implode("\n", $problems);
    assert_contains('theme/parts/footer.html', $joined);
    assert_contains('link[1]', $joined);
    assert_contains('link[2]', $joined);
    assert_contains('link[3]', $joined);
    assert_contains('authored href="#" -> delivered href="#"', $joined);
    assert_contains('block-url[1]', $joined);
    assert_contains('authored url="#" -> delivered url="#"', $joined);
    assert_contains('disposition:', $joined);

    $media = ThemeValidator::placeholderMediaSourceProblems($project);
    assert_eq(1, count($media), 'mirrored block and HTML media sources describe one broken image');
    assert_contains('media-src[1]', $media[0]);
    assert_contains('authored src="#" -> delivered src="#"', $media[0]);
    assert_contains('dead media source', $media[0]);
    assert_contains('disposition:', $media[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator reports empty list blocks and leaves populated siblings alone', function () {
    [$project, $tmp] = validator_project();
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:list --><ul class="wp-block-list"></ul><!-- /wp:list -->'
        . '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item -->'
        . '<li>Kept</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
    );

    $problems = ThemeValidator::emptyListProblems($project);
    assert_eq(1, count($problems));
    assert_contains('theme/parts/footer.html', $problems[0]);
    assert_contains('wp:list[1]', $problems[0]);
    assert_contains('authored list block -> delivered empty list (0 items)', $problems[0]);
    assert_contains('disposition:', $problems[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator reports an empty nested list without flagging its populated parent', function () {
    [$project, $tmp] = validator_project();
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item -->'
        . '<li>Parent<!-- wp:list --><ul class="wp-block-list"></ul><!-- /wp:list --></li>'
        . '<!-- /wp:list-item --></ul><!-- /wp:list -->'
    );

    $problems = ThemeValidator::emptyListProblems($project);
    assert_eq(1, count($problems));
    assert_contains('wp:list[2]', $problems[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('link checks stay quiet without pages.json', function () {
    [$project, $tmp] = validator_project();
    $project->writeText('theme/parts/header.html', '<!-- wp:navigation-link {"label":"X","url":"/nowhere/"} /-->');
    assert_eq([], ThemeValidator::validate($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('plan warnings flag interior pages opening with a full-bleed cover', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'hero', 'layout_archetype' => 'full-bleed-cover'],
        ]],
        ['slug' => 'menu', 'front' => false, 'sections' => [
            ['slug' => 'menu-hero', 'layout_archetype' => 'full-bleed-cover'],
            ['slug' => 'menu-grid', 'layout_archetype' => 'equal-card-grid'],
        ]],
        ['slug' => 'about', 'front' => false, 'sections' => [
            ['slug' => 'about-hero', 'layout_archetype' => 'asymmetric-split'],
        ]],
    ]]);

    $warnings = ThemeValidator::planWarnings($project);
    assert_eq(1, count($warnings));
    assert_contains("interior page 'menu'", $warnings[0]);
    assert_contains('full-bleed-cover', $warnings[0]);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('plan warnings report a footer-like page section without hiding valid siblings', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'hero', 'title' => 'Hero', 'type' => 'hero', 'layout_archetype' => 'full-bleed-cover'],
            ['slug' => 'legal', 'title' => 'Legal', 'type' => 'footerInfo', 'layout_archetype' => 'centered-stack'],
        ]],
    ]]);

    $warnings = ThemeValidator::planWarnings($project);
    assert_eq(1, count($warnings));
    assert_contains('pages.json: page[home]/sections[legal]', $warnings[0]);
    assert_contains('delivered alongside theme/parts/footer.html', $warnings[0]);
    assert_contains('disposition:', $warnings[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('plan warnings are empty without pages.json', function () {
    [$project, $tmp] = validator_project();
    assert_eq([], ThemeValidator::planWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('spacing warnings detect theme-profile and section-root rhythm drift', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson(
        'theme/theme.json',
        ThemeJsonStep::normalizeSpacingSettings(['version' => 3])
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'story', 'background' => 'base', 'vertical_density' => 'standard'],
        ]],
    ]]);

    $raw = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>Story</p><!-- /wp:paragraph --></div>'
        . '<!-- /wp:group -->';
    $normalized = SectionRhythm::rewrite([[
        'slug' => 'story', 'markup' => $raw, 'density' => 'standard', 'background' => 'base',
    ]]);
    $project->writeText('plugin/pages/home.html', $normalized['markups'][0]);
    assert_eq([], ThemeValidator::spacingWarnings($project));

    $classDrift = BlockMarkup::parse($normalized['markups'][0]);
    $root = $classDrift->indices()[0];
    $attrs = $classDrift->attrs($root) ?? [];
    $attrs['className'] = 'overlap-up';
    $classDrift->setAttrs($root, $attrs);
    $classDrift->replaceInOwnHtml($root, 'wp-block-group', 'wp-block-group overlap-up');
    $project->writeText('plugin/pages/home.html', $classDrift->render());
    $project->writeText(
        'theme/style.css',
        $project->readText('theme/style.css') . ".overlap-up{margin-top:-6rem!important}\n"
    );
    $joined = implode(' ', ThemeValidator::spacingWarnings($project));
    assert_contains('section root spacing drift', $joined, 'a root utility can override the owned margin reset');

    $project->writeText('plugin/pages/home.html', $raw);
    $joined = implode(' ', ThemeValidator::spacingWarnings($project));
    assert_contains('section root spacing drift', $joined);

    $theme = $project->readJson('theme/theme.json');
    $spacingSlugs = array_column($theme['settings']['spacing']['spacingSizes'], 'slug');
    $xxlIndex = array_search('xxl', $spacingSlugs, true);
    assert_true(is_int($xxlIndex), 'canonical spacing profile includes xxl');
    $theme['settings']['spacing']['spacingSizes'][$xxlIndex]['size'] = '12rem';
    $project->writeJson('theme/theme.json', $theme);
    $joined = implode(' ', ThemeValidator::spacingWarnings($project));
    assert_contains('bounded canonical profile', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('spacing warnings report residual global Group vertical padding actionably', function () {
    [$project, $tmp] = validator_project();
    $theme = ThemeJsonStep::normalizeSpacingSettings(['version' => 3]);
    $theme['styles']['blocks']['core/group']['spacing']['padding'] = [
        'top' => 'var:preset|spacing|xl',
        'bottom' => 'var:preset|spacing|xl',
        'left' => 'var:preset|spacing|md',
    ];
    $project->writeJson('theme/theme.json', $theme);

    $joined = implode("\n", ThemeValidator::spacingWarnings($project));
    assert_contains("file='theme/theme.json'", $joined);
    assert_contains("block='styles.blocks.core/group.spacing.padding'", $joined);
    assert_contains('authored=', $joined);
    assert_contains('delivered=unchanged', $joined);
    assert_contains('disposition=remove global top/bottom Group padding', $joined);

    $project->writeJson('theme/theme.json', ThemeJsonStep::normalizeGroupBlockPadding($theme));
    assert_eq([], ThemeValidator::spacingWarnings($project), 'normalized theme passes');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('spacing warnings accept a coverless image section the build pass degraded', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson(
        'theme/theme.json',
        ThemeJsonStep::normalizeSpacingSettings(['version' => 3])
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'lugar-ubicacion', 'background' => 'image', 'vertical_density' => 'standard'],
        ]],
    ]]);

    // The validator re-runs rewrite() with the plan still saying 'image'; the
    // persisted fallback marker keeps that decision stable.
    $raw = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph --></div>'
        . '<!-- /wp:group -->';
    $degraded = SectionRhythm::rewrite([[
        'slug' => 'lugar-ubicacion', 'markup' => $raw, 'density' => 'standard', 'background' => 'image',
    ]]);
    assert_contains('degraded to solid-band', implode(' ', $degraded['notes']));
    $project->writeText('plugin/pages/home.html', $degraded['markups'][0]);
    assert_eq([], ThemeValidator::spacingWarnings($project));

    // A raw (never-rewritten) coverless image section is still drift, not an abort.
    $project->writeText('plugin/pages/home.html', $raw);
    $joined = implode(' ', ThemeValidator::spacingWarnings($project));
    assert_contains('section root spacing drift', $joined);
    assert_contains('degraded to solid-band', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('degraded image spacing survives FixBlocks before the final validator', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson(
        'theme/theme.json',
        ThemeJsonStep::normalizeSpacingSettings(['version' => 3])
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'bad-cover', 'background' => 'image', 'vertical_density' => 'compact'],
        ]],
    ]]);

    // Production order: rhythm sees invalid cover JSON and falls back; the
    // serializer repairs that opener into an attr-less valid cover; only the
    // persisted root marker prevents the validator from switching treatments.
    $raw = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:cover {"dimRatio": nope} --><div class="wp-block-cover">'
        . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:cover --></div><!-- /wp:group -->';
    $degraded = SectionRhythm::rewrite([[
        'slug' => 'bad-cover', 'markup' => $raw, 'density' => 'compact', 'background' => 'image',
    ]]);
    assert_eq('invalid-cover-attributes', $degraded['degradations'][0]['code']);
    $project->writeText('theme/parts/page-home--bad-cover.html', $degraded['markups'][0]);

    (new PhpBlockFixer())->fix($project->themePath());
    $fixed = $project->readText('theme/parts/page-home--bad-cover.html');
    assert_contains('site-build-section-rhythm-degraded-image', $fixed);
    assert_contains('<!-- wp:cover -->', $fixed, 'FixBlocks repaired the formerly invalid opener');

    // AssemblePages inlines this final fixed markup into plugin/pages/home.html.
    $project->writeText('plugin/pages/home.html', $fixed);
    assert_eq([], ThemeValidator::spacingWarnings($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator reports an unmaterialized form placeholder', function () {
    [$project, $tmp] = validator_project();

    $project->writeText('plugin/pages/contacto.html',
        '<!-- wp:html --><div class="html-form-placeholder"><h3>Booking form</h3>'
        . '<p>Booking form: name, email, message</p></div><!-- /wp:html -->'
    );
    $problems = ThemeValidator::validate($project);
    assert_eq(1, count($problems));
    assert_true(str_contains($problems[0], 'unmaterialized html-form-placeholder'), 'stub reported');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('validator reports an HTML-first form stub even when a JP_FORM placeholder is on the same page', function () {
    [$project, $tmp] = validator_project();
    $project->writeJson('meta.json', ['prompt' => 'x', 'form_placeholders' => true]);
    $project->writeText(
        'plugin/pages/contacto.html',
        '<!-- wp:paragraph {"className":"jetpack-form-placeholder"} -->'
        . '<p class="jetpack-form-placeholder">JP_FORM: contact | Email:email:required | Send</p>'
        . '<!-- /wp:paragraph -->'
        . '<!-- wp:html --><div class="html-form-placeholder"><p>Booking form: name, email</p></div><!-- /wp:html -->'
    );

    $joined = implode(' ', ThemeValidator::validate($project));
    assert_contains('unmaterialized html-form-placeholder', $joined);
    assert_true(!str_contains($joined, 'unparseable form spec'), 'valid JP_FORM spec is not flagged');

    exec('rm -rf ' . escapeshellarg($tmp));
});
