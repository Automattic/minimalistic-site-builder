<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\CollectImagesStep;
function collect_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_ci_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    return [$project, $tmp];
}

test('collect-images parses img alt placeholders into specs', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/templates/front-page.html',
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

test('collect-images ignores plain images with no AI_IMAGE marker', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/templates/index.html',
        '<img src="theme:./assets/x.jpg" alt="just a normal alt"/>'
    );

    (new CollectImagesStep())->run($project);

    assert_eq([], $project->readJson('images.json'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps subject pipes and parses the three trailing fields', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/templates/front-page.html',
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
