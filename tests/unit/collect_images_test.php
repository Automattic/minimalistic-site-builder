<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;

function collect_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_ci_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    return [$project, $tmp];
}

test('collect-images parses img alt placeholders into specs', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/hero-dawn.jpg" '
        . 'alt="AI_IMAGE: A misty valley at dawn, soft light | full-bleed hero with text overlay | photorealistic | landscape"/></figure><!-- /wp:image -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero-dawn.jpg', $images[0]['filename']);
    assert_eq('theme:./assets/hero-dawn.jpg', $images[0]['src']);
    assert_eq('landscape', $images[0]['aspectRatio']);
    assert_eq('photorealistic', $images[0]['style']);
    assert_eq('pending', $images[0]['status']);
    assert_contains('misty valley at dawn', $images[0]['subject']);
    assert_eq('full-bleed hero with text overlay', $images[0]['pageContext']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images dedupes the same asset across files and records sources', function () {
    [$project, $tmp] = collect_fixture();
    $tag = '<img src="theme:./assets/logo.jpg" alt="AI_IMAGE: A clean wordmark | site logo in the header | minimalist | square"/>';
    $project->writeText('theme/parts/header.html', $tag);
    $project->writeText('theme/parts/footer.html', $tag);

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq(2, count($images[0]['sources']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images collects .png placeholders (transparent-background assets)', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/footer.html',
        '<img src="theme:./assets/grapevine-flourish.png" '
        . 'alt="AI_IMAGE: A small grapevine flourish, thin gold linework | decorative accent under a subheading | illustration | landscape"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('grapevine-flourish.png', $images[0]['filename']);
    assert_eq('theme:./assets/grapevine-flourish.png', $images[0]['src']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers an AI_IMAGE spec left in a cover url', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:dense fog over the dunes at golden hour|ratio:21:9|role:hero","dimRatio":40} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    // Recovery immediately installs a serializer-stable canonical path.
    assert_eq('theme:./assets/' . $images[0]['filename'], $images[0]['src']);
    assert_contains('dense fog over the dunes', $images[0]['subject']);
    assert_eq('16:9', $images[0]['aspectRatio']);
    assert_eq('.jpg', substr($images[0]['filename'], -4));
    $markup = $project->readText('theme/parts/hero.html');
    assert_contains($images[0]['src'], $markup);
    assert_true(!str_contains($markup, '"url":"AI_IMAGE:'), 'raw prompt removed from cover url');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images decodes serializer-equivalent cover values into one canonical image', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:coffee \u0026 croissant at dawn|ratio:21:9|role:hero"} -->'
        . '<img class="wp-block-cover__image-background" alt="" '
        . 'src="AI_IMAGE:coffee &amp; croissant at dawn|ratio:21:9|role:hero"/>'
        . '<!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    // JSON \u0026 and HTML &amp; represent the same prompt — one image/path.
    assert_eq(1, count($images));
    assert_eq('16:9', $images[0]['aspectRatio']);
    $markup = $project->readText('theme/parts/hero.html');
    assert_eq(2, substr_count($markup, $images[0]['src']));
    assert_true(!str_contains($markup, 'AI_IMAGE:'), 'both malformed source contexts normalized');

    // Once canonicalized, the normal content-image manifest path sees it.
    assert_eq([[
        'filename' => $images[0]['filename'],
        'title' => 'coffee & croissant at dawn',
    ]], AssemblePagesStep::contentImages(['home' => $markup], $images));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers a bare img src placeholder with no ratio', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/band.html',
        '<img src="AI_IMAGE:roasted coffee beans on a wooden table"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_contains('roasted coffee beans', $images[0]['subject']);
    // Defaults to landscape when no ratio is named.
    assert_eq('landscape', $images[0]['aspectRatio']);
    $markup = $project->readText('theme/parts/band.html');
    assert_contains($images[0]['src'], $markup);
    assert_true(!str_contains($markup, 'src="AI_IMAGE:'), 'bare src normalized');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images recovers every source shape the validator flags', function () {
    [$project, $tmp] = collect_fixture();
    // Leading whitespace inside the quoted values, plus an unquoted src —
    // shapes ThemeValidator::unresolvedImageSourceProblems() detects, so
    // recovery must repair them too or an images build dies at the
    // generate-images gate after paying for every other image.
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":" AI_IMAGE:dense fog over the dunes|ratio:21:9"} -->'
        . '<div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" alt="" src=" AI_IMAGE:dense fog over the dunes|ratio:21:9"/>'
        . '</div><!-- /wp:cover -->'
    );
    $project->writeText('theme/parts/band.html', '<img src=AI_IMAGE:beans alt=""/>');

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(2, count($images));
    $bySubject = array_column($images, null, 'subject');
    $hero = $project->readText('theme/parts/hero.html');
    // The whitespace-padded url and src decode to one prompt and one path.
    assert_eq(2, substr_count($hero, $bySubject['dense fog over the dunes']['src']));
    $band = $project->readText('theme/parts/band.html');
    // The unquoted src gains the quotes the model omitted.
    assert_contains('src="' . $bySubject['beans']['src'] . '"', $band);
    foreach (['hero', 'band'] as $part) {
        assert_true(
            !str_contains($project->readText("theme/parts/{$part}.html"), 'AI_IMAGE:'),
            "{$part} fully normalized"
        );
    }
    assert_eq([], \Automattic\SiteBuild\ThemeValidator::unresolvedImageSourceProblems($project));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images points a recovered cover url at its canonical inner img asset', function () {
    [$project, $tmp] = collect_fixture();
    // Half-canonical cover: the url carries the raw prompt while the inner img
    // already follows the documented form. The url must adopt the img's asset
    // instead of synthesizing a second image for the same background.
    $project->writeText('theme/parts/hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:fog over dunes at dawn|ratio:16:9"} -->'
        . '<div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: fog over dunes at dawn | full-bleed hero | photorealistic | landscape"/>'
        . '</div><!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero.jpg', $images[0]['filename']);
    // The canonical spec's rich fields win over the recovery fallback.
    assert_eq('full-bleed hero', $images[0]['pageContext']);
    $markup = $project->readText('theme/parts/hero.html');
    assert_contains('"url":"theme:./assets/hero.jpg"', $markup);
    assert_eq(2, substr_count($markup, 'theme:./assets/hero.jpg'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images adopts the enclosing cover url when an AI_IMAGE img lacks src', function () {
    [$project, $tmp] = collect_fixture();
    // Exact failure shape from projects/naturaleza3/logs/llms/10-page-home--hero.log:858
    // (BIGR-738): a full AI_IMAGE spec in the cover img's alt, but no src on the
    // img — the asset path lives only in the cover's url attribute. The alt-led
    // parser used to skip it and the 96vh hero shipped as an empty color panel.
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"theme:./assets/hero-provoleta-raking-light.jpg","dimRatio":60,"overlayColor":"base","isDark":true,"align":"full","minHeight":96,"minHeightUnit":"vh","contentPosition":"center left","className":"ken-burns","style":{"spacing":{"padding":{"left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}}} -->'
        . "\n" . '<div class="wp-block-cover alignfull has-custom-content-position is-position-center-left ken-burns" style="min-height:96vh"><span aria-hidden="true" class="wp-block-cover__background has-base-background-color has-background-dim-60 has-background-dim"></span><img class="wp-block-cover__image-background" alt="AI_IMAGE: A tall grilled chickpea provoleta with smoked paprika resting on dark slate beside a split pumpkin-and-walnut empanada, lit by a single hard raking key light from the right so the plate rim glows and the left half of the frame falls into deep unlit shadow | full-bleed hero background for a vegetarian Argentine restaurant, headline overlaid on the calm dark left third | photorealistic | landscape"/><div class="wp-block-cover__inner-container"></div></div>'
        . "\n" . '<!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero-provoleta-raking-light.jpg', $images[0]['filename']);
    assert_eq('theme:./assets/hero-provoleta-raking-light.jpg', $images[0]['src']);
    // The rich alt fields survive — this is the canonical spec, not a fallback.
    assert_contains('grilled chickpea provoleta', $images[0]['subject']);
    assert_contains('vegetarian Argentine restaurant', $images[0]['pageContext']);
    assert_eq('photorealistic', $images[0]['style']);
    assert_eq('landscape', $images[0]['aspectRatio']);
    // The adopted path is mirrored onto the rendered img src.
    $markup = $project->readText('theme/parts/page-home--hero.html');
    assert_contains('<img src="theme:./assets/hero-provoleta-raking-light.jpg"', $markup);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images synthesizes a spec for a cover url no placeholder covers', function () {
    [$project, $tmp] = collect_fixture();
    // The portfolio3 shape (BIGR-738): the cover url names a theme asset but
    // the inner img carries neither alt nor src — no AI_IMAGE marker anywhere,
    // so the whole file used to be skipped and the hero shipped flat gray.
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"theme:./assets/hero-buenos-aires-crowd.jpg","dimRatio":50,"overlayColor":"primary","isUserOverlayColor":true,"minHeight":62,"minHeightUnit":"vh","contentPosition":"bottom left","align":"full","className":"ken-burns"} -->'
        . '<div class="wp-block-cover alignfull"><span aria-hidden="true" class="wp-block-cover__background has-primary-background-color has-background-dim-50 has-background-dim"></span><img class="wp-block-cover__image-background"/><div class="wp-block-cover__inner-container"></div></div>'
        . '<!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero-buenos-aires-crowd.jpg', $images[0]['filename']);
    assert_eq('theme:./assets/hero-buenos-aires-crowd.jpg', $images[0]['src']);
    // Subject from the filename slug; context from the part's page/section.
    assert_eq('hero buenos aires crowd', $images[0]['subject']);
    assert_contains("the 'hero' section of the 'home' page", $images[0]['pageContext']);
    assert_eq('photorealistic', $images[0]['style']);
    assert_eq('landscape', $images[0]['aspectRatio']);
    assert_eq('pending', $images[0]['status']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images does not duplicate a cover url already covered by its inner img spec', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"theme:./assets/hero-dawn.jpg","dimRatio":50} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" src="theme:./assets/hero-dawn.jpg" '
        . 'alt="AI_IMAGE: A misty valley at dawn | full-bleed hero | photorealistic | landscape"/></div>'
        . '<!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('hero-dawn.jpg', $images[0]['filename']);
    // The canonical alt spec wins — no synthesized filename-slug fallback.
    assert_contains('misty valley', $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images ignores plain images with no AI_IMAGE marker', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/plain-image.html',
        '<img src="theme:./assets/x.jpg" alt="just a normal alt"/>'
    );

    (new CollectImagesStep())->run($project);

    assert_eq([], $project->readJson('images.json'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps subject pipes and parses the three trailing fields', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--combo.html',
        '<img src="theme:./assets/combo.jpg" alt="AI_IMAGE: Coffee | tea | pastries on a table | menu item card | flat-design | square"/>'
    );

    (new CollectImagesStep())->run($project);

    $images = $project->readJson('images.json');
    assert_eq('square', $images[0]['aspectRatio']);
    assert_eq('flat-design', $images[0]['style']);
    assert_eq('menu item card', $images[0]['pageContext']);
    // Only the three trailing fields are popped; the subject keeps its pipes.
    assert_eq('Coffee | tea | pastries on a table', $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});
