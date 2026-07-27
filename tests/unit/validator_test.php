<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
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
    assert_contains('form markup', $joined);
    assert_contains('no form backend', $joined);

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
        . '<a href="https://example.com">Ext</a> · <a href="mailto:a@b.c">Mail</a> · <a href="#">Social</a></p><!-- /wp:paragraph -->'
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
    $theme['settings']['spacing']['spacingSizes'][4]['size'] = '12rem';
    $project->writeJson('theme/theme.json', $theme);
    $joined = implode(' ', ThemeValidator::spacingWarnings($project));
    assert_contains('bounded canonical profile', $joined);
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
