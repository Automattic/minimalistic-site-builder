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

test('collect-images collects an assigned short AI_IMAGE alt from a theme part', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-contact--details.html',
        '<img src="theme:./assets/plate-iv-abcd1234.jpg" alt="AI_IMAGE: a studio desk"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('plate-iv-abcd1234.jpg', $images[0]['filename']);
    assert_eq('a studio desk', $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps canonical fields for an assigned four-field AI_IMAGE alt', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeText('theme/parts/page-home--hero.html',
        '<img src="theme:./assets/canonical-hero.jpg" '
        . 'alt="AI_IMAGE: Dawn over a quiet valley | full-bleed homepage hero | Photorealistic | Landscape"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('Dawn over a quiet valley', $images[0]['subject']);
    assert_eq('full-bleed homepage hero', $images[0]['pageContext']);
    assert_eq('photorealistic', $images[0]['style']);
    assert_eq('landscape', $images[0]['aspectRatio']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images records one source when canonical and assigned parsing find the same image', function () {
    [$project, $tmp] = collect_fixture();
    $source = 'parts/page-home--hero.html';
    $project->writeText('theme/' . $source,
        '<img src="theme:./assets/shared-hero.jpg" '
        . 'alt="AI_IMAGE: Dawn over a quiet valley | full-bleed homepage hero | photorealistic | square"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq([$source], $images[0]['sources']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images upgrades an earlier assigned image when a later file is canonical', function () {
    [$project, $tmp] = collect_fixture();
    $earlySource = 'parts/page-a--lead.html';
    $laterSource = 'parts/page-z--hero.html';
    $project->writeText('theme/' . $earlySource,
        '<img src="theme:./assets/shared-scene.jpg" alt="AI_IMAGE: generic fallback scene"/>'
    );
    $project->writeText('theme/' . $laterSource,
        '<img src="theme:./assets/shared-scene.jpg" '
        . 'alt="AI_IMAGE: Sunrise over a mountain lake | homepage hero backdrop | cinematic | 21:9"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('Sunrise over a mountain lake', $images[0]['subject']);
    assert_eq('homepage hero backdrop', $images[0]['pageContext']);
    assert_eq('cinematic', $images[0]['style']);
    assert_eq('21:9', $images[0]['aspectRatio']);
    assert_eq([$earlySource, $laterSource], $images[0]['sources']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images keeps plain prose assigned image subjects unchanged', function () {
    [$project, $tmp] = collect_fixture();
    $subject = 'Studio flat lay with sketchbooks, pencils, and warm window light';
    $project->writeText('theme/parts/page-work--gallery.html',
        '<img src="theme:./assets/studio-flat-lay.jpg" alt="' . $subject . '"/>'
    );

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq($subject, $images[0]['subject']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images lets canonical fields win when assigned parsing finds the same filename', function () {
    [$project, $tmp] = collect_fixture();
    $tag = '<img src="theme:./assets/canonical-wins.jpg" '
        . 'alt="AI_IMAGE: Coffee | tea | pastries on a table | menu item card | flat-design | square"/>';
    $project->writeText('theme/parts/page-home--menu.html', $tag);

    $assigned = CollectImagesStep::parseAssignedImages($tag, 'parts/page-home--menu.html');
    assert_eq(1, count($assigned));
    assert_eq('Coffee', $assigned[0]['subject']);

    (new CollectImagesStep(true))->run($project);

    $images = $project->readJson('images.json');
    assert_eq(1, count($images));
    assert_eq('canonical-wins.jpg', $images[0]['filename']);
    assert_eq('Coffee | tea | pastries on a table', $images[0]['subject']);
    assert_eq('menu item card', $images[0]['pageContext']);
    assert_eq('flat-design', $images[0]['style']);
    assert_eq('square', $images[0]['aspectRatio']);

    exec('rm -rf ' . escapeshellarg($tmp));
});
