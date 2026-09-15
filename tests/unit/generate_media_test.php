<?php
declare(strict_types=1);

require_once __DIR__ . '/../FakeImageClient.php';

use Automattic\SiteBuild\Patterns\GenerateMediaStep;
use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Patterns\ResolveMediaStep;
use Automattic\SiteBuild\Tests\FakeImageClient;

test('pattern images generate once, export files and retain protected siblings', function () {
    $source = 'https://example.com/wp-content/themes/neve/photo.jpg';
    $image = media_image($source);
    $protected = '<!-- wp:group {"className":"ai-ignore"} --><div class="ai-ignore">' . $image . '</div><!-- /wp:group -->';
    $project = media_project(['home' => $image . $protected, 'about' => $image], theme: 'neve');
    $client = new FakeImageClient();
    $step = new GenerateMediaStep($client);
    $step->run($project);
    assert_eq(1, count($client->calls));
    $pages = PatternArtifacts::pages($project);
    assert_contains($protected, $pages[1]['content']);
    assert_contains('media/', $pages[0]['content']);
    (new ResolveMediaStep())->run($project);
    $entry = $project->readJson(PatternArtifacts::MEDIA)['images'][0];
    assert_eq($source, $entry['fallback']);
    assert_true(is_file($project->path('bundle/' . $entry['file'])));
    $before = $pages;
    $step->run($project);
    assert_eq(1, count($client->calls));
    assert_eq($before, PatternArtifacts::pages($project));
});

test('pattern images preserve supplied pages, customer images and logos', function () {
    $source = '/wp-content/themes/neve/photo.jpg';
    $project = media_project(['legal' => media_image($source)], theme: 'neve');
    $page = $project->readJson('patterns/pages/legal.json');
    $page['provenance'] = 'blueprint';
    $project->writeJson('patterns/pages/legal.json', $page);
    $client = new FakeImageClient();
    (new GenerateMediaStep($client))->run($project);
    assert_eq([], $client->calls);
    assert_eq($page, $project->readJson('patterns/pages/legal.json'));
    assert_eq([], GenerateMediaStep::targets(media_image('/wp-content/themes/neve/logo.png'), 'neve'));
    assert_eq([], GenerateMediaStep::targets(media_image('https://customer.test/photo.jpg'), 'neve'));
});

/**
 * A pattern is stock wherever it is hosted. The approved inventory is what
 * says so: an image the pattern shipped with is stock even when the pattern
 * hotlinks it off-site, and an image that appears nowhere in the inventory
 * came from the customer and is not ours to replace.
 */
test('an image the inventory ships generates off-site, and the customer\'s own does not', function () {
    $stock = 'https://live.staticflickr.com/7875/31859115207_2d1fd593d0_b.jpg';
    $mine = 'https://customer.test/photo.jpg';
    $pattern = '<!-- wp:cover {"url":"' . $stock . '","dimRatio":60} --><div class="wp-block-cover"><img src="' . $stock . '"/></div><!-- /wp:cover -->';

    $project = media_project(
        ['home' => $pattern . media_image($mine)],
        theme: 'twentytwentythree',
        inventory: [['id' => 'tt3/hero', 'content' => $pattern]],
    );
    $client = new FakeImageClient();
    (new GenerateMediaStep($client))->run($project);

    assert_eq(1, count($client->calls));
    $content = PatternArtifacts::pages($project)[0]['content'];
    assert_contains('media/', $content);
    assert_true(!str_contains($content, $stock));
    assert_contains($mine, $content);
});

/**
 * A Blueprint that supplies its pages whole still wants photographs of its
 * own: `scope: "all"` is how it says so. The images in supplied markup are
 * the Blueprint author's, not a customer's, so under that scope every one is
 * a target -- the slot system cannot reach them anyway, having no core/cover
 * case and requiring a leaf block.
 */
test('a supplied page generates only when the Blueprint widens the scope', function () {
    $source = 'https://live.staticflickr.com/7875/31859115207_2d1fd593d0_b.jpg';
    $supplied = function (string $scope) use ($source) {
        $project = media_project(['home' => media_image($source)], theme: 'twentytwentythree');
        $page = $project->readJson('patterns/pages/home.json');
        $page['provenance'] = 'blueprint';
        $project->writeJson('patterns/pages/home.json', $page);
        $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
        $inputs['image_generation'] = ['enabled' => true, 'scope' => $scope];
        $project->writeJson(PatternArtifacts::NORMALIZED, $inputs);

        return $project;
    };

    $narrow = $supplied('composed');
    $quiet = new FakeImageClient();
    (new GenerateMediaStep($quiet))->run($narrow);
    assert_eq([], $quiet->calls);
    assert_contains($source, PatternArtifacts::pages($narrow)[0]['content']);

    $wide = $supplied('all');
    $client = new FakeImageClient();
    (new GenerateMediaStep($client))->run($wide);
    assert_eq(1, count($client->calls));
    $content = PatternArtifacts::pages($wide)[0]['content'];
    assert_contains('media/', $content);
    assert_true(!str_contains($content, $source));
});

/**
 * `"slots": []` freezes a page so a legal notice stays byte for byte. That
 * outranks the scope: widening what generation may reach must not reach it.
 */
test('the widest scope still never touches a frozen page', function () {
    $source = 'https://live.staticflickr.com/7875/31859115207_2d1fd593d0_b.jpg';
    $project = media_project(['legal' => media_image($source)], theme: 'twentytwentythree');
    $page = $project->readJson('patterns/pages/legal.json');
    $page['provenance'] = 'blueprint';
    $page['frozen'] = true;
    $project->writeJson('patterns/pages/legal.json', $page);
    $inputs = $project->readJson(PatternArtifacts::NORMALIZED);
    $inputs['image_generation'] = ['enabled' => true, 'scope' => 'all'];
    $project->writeJson(PatternArtifacts::NORMALIZED, $inputs);

    $client = new FakeImageClient();
    (new GenerateMediaStep($client))->run($project);

    assert_eq([], $client->calls);
    assert_contains($source, PatternArtifacts::pages($project)[0]['content']);
});

test('partial image failures keep source bytes and actionable warnings while siblings finish', function () {
    $good = media_image('/wp-content/themes/neve/good.jpg');
    $bad = str_replace('alt=""', 'alt="broken"', media_image('/wp-content/themes/neve/bad.jpg'));
    $project = media_project(['home' => $good . $bad], theme: 'neve');
    $client = new FakeImageClient();
    $client->bytesByPromptSubstring = ['broken' => 'not an image'];
    (new GenerateMediaStep($client))->run($project);
    $content = $project->readJson('patterns/pages/home.json')['content'];
    assert_contains($bad, $content);
    assert_contains('media/', $content);
    $warning = json_decode($project->readJson('warnings.json')['generate-media'][0], true);
    assert_eq('patterns/pages/home.json', $warning['file']);
    assert_eq('/wp-content/themes/neve/bad.jpg', $warning['delivered']);
    assert_eq('kept-original', $warning['disposition']);
    assert_true(isset($warning['block_path']));
});

test('cover image generation preserves nested text and rewrites escaped attributes', function () {
    $url = 'https://example.com/wp-content/themes/neve/banner.jpg';
    $heading = '<!-- wp:heading --><h2>Keep this headline.</h2><!-- /wp:heading -->';
    $markup = '<!-- wp:cover ' . json_encode(['url' => $url]) . ' --><div><img src="' . $url . '"/>' . $heading . '</div><!-- /wp:cover -->';
    $project = media_project(['home' => $markup], theme: 'neve');
    $client = new FakeImageClient();
    (new GenerateMediaStep($client))->run($project);
    assert_eq('16:9', $client->calls[0]['opts']['aspect_ratio']);
    $content = $project->readJson('patterns/pages/home.json')['content'];
    assert_contains($heading, $content);
    assert_true(!str_contains($content, 'banner.jpg'));
});


test('disabled pattern image generation stays offline and adds no defect warnings', function () {
    $project = media_project(['home' => media_image('/wp-content/themes/neve/photo.jpg')], theme: 'neve');
    $before = PatternArtifacts::pages($project);
    (new GenerateMediaStep())->run($project);
    assert_eq($before, PatternArtifacts::pages($project));
    assert_true(!$project->exists('warnings.json'));
});

test('every failed image occurrence receives its own durable warning', function () {
    $project = media_project([
        'home' => media_image('/wp-content/themes/neve/one.jpg') . media_image('/wp-content/themes/neve/two.jpg'),
        'about' => media_image('/wp-content/themes/neve/one.jpg'),
    ], theme: 'neve');
    $before = PatternArtifacts::pages($project);
    (new GenerateMediaStep(new FakeImageClient(fail: true)))->run($project);
    assert_eq($before, PatternArtifacts::pages($project));
    assert_eq(3, count($project->readJson('warnings.json')['generate-media']));
});

test('generated images drop responsive URLs for the old theme photograph', function () {
    $markup = str_replace('alt=""', 'alt="" srcset="/wp-content/themes/neve/small.jpg 400w" sizes="100vw"', media_image('/wp-content/themes/neve/large.jpg'));
    $project = media_project(['home' => $markup], theme: 'neve');
    (new GenerateMediaStep(new FakeImageClient()))->run($project);
    $content = PatternArtifacts::pages($project)[0]['content'];
    assert_true(!str_contains($content, 'srcset'));
    assert_true(!str_contains($content, 'sizes='));
});
