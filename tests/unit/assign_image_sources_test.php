<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\AssignImageSourcesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;

require_once __DIR__ . '/../FakeImageClient.php';

/**
 * AssignImageSourcesStep: the HTML-first bridge between a design's prose-alt
 * <img> tags and the theme asset paths the image pipeline generates into.
 */

function ais_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_ais_' . uniqid();
    return [(new ProjectStore($tmp))->create('demo'), $tmp];
}

test('assign-image-sources gives empty and invented srcs a theme asset path', function () {
    $html = '<main><section id="hero">'
        . '<img src="" alt="A baker pulling a tray of dark sourdough from a deck oven">'
        . '<img src="beans.jpg" alt="Green coffee beans spread across a linen cloth">'
        . '</section></main>';

    $result = AssignImageSourcesStep::assign($html, 'home');

    assert_eq(2, count($result['assigned']));
    assert_contains('src="theme:./assets/a-baker-pulling-a-tray-of-dark-sourdough-', $result['content']);
    assert_contains('src="theme:./assets/green-coffee-beans-spread-across-a-linen-', $result['content']);
    assert_true(!str_contains($result['content'], 'beans.jpg'), 'the invented src is gone');
    // The prose alt is the generation prompt AND the a11y text — never touched.
    assert_contains('alt="A baker pulling a tray of dark sourdough from a deck oven"', $result['content']);
    assert_contains('alt="Green coffee beans spread across a linen cloth"', $result['content']);
});

test('assign-image-sources is stable, deduping, and idempotent', function () {
    $alt = 'A copper roaster drum lit from one side';
    $once = AssignImageSourcesStep::assign('<img src="" alt="' . $alt . '">', 'home');
    $again = AssignImageSourcesStep::assign($once['content'], 'home');

    assert_eq($once['content'], $again['content'], 'a second pass changes nothing');
    assert_eq([], $again['assigned'], 'and assigns nothing new');

    // The same described image on another page resolves to one asset.
    $other = AssignImageSourcesStep::assign('<img src="x.png" alt="' . $alt . '">', 'about');
    assert_eq($once['assigned'][0], $other['assigned'][0]);

    // Different prose never collides.
    $different = AssignImageSourcesStep::assign('<img src="" alt="A copper roaster drum lit from above">', 'home');
    assert_true($different['assigned'][0] !== $once['assigned'][0]);
});

test('assign-image-sources uses png for alpha assets and page+index for an empty alt', function () {
    $logo = AssignImageSourcesStep::assign('<img src="logo.svg" alt="The roastery wordmark">', 'home');
    assert_contains('.png"', $logo['content']);

    $blank = AssignImageSourcesStep::assign('<p></p><img src="" alt="">', 'visit');
    assert_contains('src="theme:./assets/visit-image-1-', $blank['content']);
    assert_contains('.jpg"', $blank['content']);
});

test('assign-image-sources leaves data URIs alone and drops stale srcset candidates', function () {
    $data = '<img src="data:image/svg+xml,%3Csvg%3E" alt="An inline mark">';
    assert_eq($data, AssignImageSourcesStep::assign($data, 'home')['content']);

    $responsive = '<img src="hero.jpg" srcset="hero@2x.jpg 2x" sizes="100vw" alt="A wide shopfront at dusk">';
    $result = AssignImageSourcesStep::assign($responsive, 'home');
    assert_true(!str_contains($result['content'], 'srcset'), 'srcset named the source we replaced');
    assert_true(!str_contains($result['content'], 'sizes='));
    assert_contains('alt="A wide shopfront at dusk"', $result['content']);
});

test('assign-image-sources rewrites every design page and logs what it assigned', function () {
    [$project, $tmp] = ais_fixture();
    $project->writeText('design/home.html', '<img src="" alt="Steam rising off a fresh pour">');
    $project->writeText('design/menu.html', '<img src="cup.jpg" alt="A flat white in a ceramic cup">');
    $project->writeText('design/site.css', '.hero{}');

    (new AssignImageSourcesStep())->run($project);

    assert_contains('theme:./assets/steam-rising-off-a-fresh-pour-', $project->readText('design/home.html'));
    assert_contains('theme:./assets/a-flat-white-in-a-ceramic-cup-', $project->readText('design/menu.html'));
    assert_eq('.hero{}', $project->readText('design/site.css'), 'non-HTML design artifacts are untouched');
    assert_contains('design/menu.html', $project->readText('logs/assign-image-sources.log'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images on HTML-first builds specs from assigned paths and prose alts', function () {
    [$project, $tmp] = ais_fixture();
    $assigned = AssignImageSourcesStep::assign(
        '<img src="" alt="A baker scoring a boule">'
        . '<img src="x.jpg" alt="Sacks of green coffee stacked by the roaster">',
        'home',
    );
    $project->writeText('theme/parts/page-home--hero.html', $assigned['content']);

    (new CollectImagesStep(htmlFirst: true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(2, count($images));
    $bySrc = array_column($images, null, 'src');
    foreach ($assigned['assigned'] as $src) {
        assert_true(isset($bySrc[$src]), "images.json is keyed by {$src}");
        assert_eq('pending', $bySrc[$src]['status']);
        assert_eq('landscape', $bySrc[$src]['aspectRatio']);
        assert_eq(['parts/page-home--hero.html'], $bySrc[$src]['sources']);
        assert_eq('hero section of the home page', $bySrc[$src]['pageContext']);
        assert_eq(substr($src, strlen('theme:./assets/')), $bySrc[$src]['filename']);
    }
    $subjects = array_column($images, 'subject');
    sort($subjects);
    assert_eq(['A baker scoring a boule', 'Sacks of green coffee stacked by the roaster'], $subjects);

    // The legacy collector sees none of this — the AI_IMAGE marker is absent.
    assert_eq([], CollectImagesStep::parsePlaceholders($assigned['content']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images on HTML-first still collects AI_IMAGE placeholders from legacy fallbacks', function () {
    [$project, $tmp] = ais_fixture();
    // Chrome and rerouted inner pages still come from the legacy prompts.
    $project->writeText(
        'theme/parts/header.html',
        '<img src="theme:./assets/mark.png" alt="AI_IMAGE: A roastery wordmark | site header | minimalist | square"/>',
    );
    $project->writeText(
        'theme/parts/page-visit--map.html',
        '<img src="theme:./assets/storefront-a1b2c3d4.jpg" alt="The shopfront on a rainy morning"/>',
    );

    (new CollectImagesStep(htmlFirst: true))->run($project);

    $images = array_column($project->readJson('images.json'), null, 'filename');
    assert_eq(2, count($images));
    assert_eq('A roastery wordmark', $images['mark.png']['subject']);
    assert_eq('square', $images['mark.png']['aspectRatio']);
    assert_eq('The shopfront on a rainy morning', $images['storefront-a1b2c3d4.jpg']['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images falls back to the slot when a design image has no alt', function () {
    [$project, $tmp] = ais_fixture();
    $assigned = AssignImageSourcesStep::assign('<img src="deco.jpg" alt="">', 'visit');
    $project->writeText('theme/parts/page-visit--gallery.html', $assigned['content']);

    (new CollectImagesStep(htmlFirst: true))->run($project);

    // An assigned path with no spec would ship as a reference nothing writes.
    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq($assigned['assigned'][0], $images[0]['src']);
    assert_eq('gallery section of the visit page', $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images fills the assigned paths in every shipped scope', function () {
    [$project, $tmp] = ais_fixture();
    $assigned = AssignImageSourcesStep::assign(
        '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="hero.jpg" alt="A roaster tilting a cooling tray of fresh beans"/>'
        . '</figure><!-- /wp:image -->',
        'home',
    );
    // assemble-pages drops the section parts and inlines the markup into the
    // content plugin, so the rewrite has to reach both scopes.
    $project->writeText('theme/parts/page-home--hero.html', $assigned['content']);
    (new CollectImagesStep(htmlFirst: true))->run($project);
    $project->writeText('plugin/pages/home.html', $assigned['content']);
    $project->writeText('theme/templates/page.html', $assigned['content']);

    (new GenerateImagesStep(new FakeImageClient()))->run($project);

    $filename = substr($assigned['assigned'][0], strlen('theme:./assets/'));
    assert_true($project->exists('theme/assets/' . $filename), 'the file lands at the assigned path');
    foreach (['plugin/pages/home.html', 'theme/templates/page.html'] as $rel) {
        $content = $project->readText($rel);
        assert_contains("/wp-content/themes/demo/assets/{$filename}", $content);
        assert_true(!str_contains($content, 'theme:./assets/'), "{$rel} keeps no placeholder");
    }
    assert_eq('completed', $project->readJson('images.json')[0]['status']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images without the HTML-first flag ignores prose alts', function () {
    [$project, $tmp] = ais_fixture();
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<img src="theme:./assets/boule-a1b2c3d4.jpg" alt="A baker scoring a boule"/>',
    );

    (new CollectImagesStep())->run($project);

    assert_eq([], $project->readJson('images.json'), 'legacy collection is unchanged');

    exec('rm -rf ' . escapeshellarg($tmp));
});
