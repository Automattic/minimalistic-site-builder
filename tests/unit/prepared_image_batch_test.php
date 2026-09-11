<?php
declare(strict_types=1);

use Automattic\SiteBuild\PreparedImageBatch;
use Automattic\SiteBuild\Tests\FakeImageClient;

require_once __DIR__ . '/../FakeImageClient.php';

function prepared_image_fixture($project): void
{
    $project->writeJson('siteSpec.json', ['name' => 'Studio', 'topic' => 'glass lamps']);
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'path' => '/', 'front' => true, 'sections' => [['slug' => 'hero', 'role' => 'hero']]],
        ['slug' => 'about', 'path' => '/about/', 'front' => false, 'sections' => [
            ['slug' => 'intro', 'role' => 'hero'], ['slug' => 'details', 'role' => 'content'],
        ]],
    ]]);
    $project->writeJson('plugin/pages.json', ['pages' => [['slug' => 'home'], ['slug' => 'about']]]);
    seed_test_design_direction($project);
    $rows = [];
    foreach (['hero', 'removed', 'details', 'site-logo', 'complete'] as $index => $name) {
        $filename = $name . ($name === 'site-logo' ? '.png' : '.jpg');
        $rows[] = [
            'filename' => $filename, 'src' => 'theme:./assets/' . $filename,
            'subject' => $name === 'site-logo' ? 'A simple geometric mark' : 'A glass lamp named ' . $name,
            'pageContext' => 'A photograph beside the page copy', 'style' => 'photorealistic', 'aspectRatio' => 'landscape',
            'status' => $name === 'complete' ? 'completed' : 'pending',
            'sources' => [$name === 'details' ? 'parts/page-about--details.html' : 'parts/page-home--hero.html'],
            'role' => $name === 'site-logo' ? 'site-logo' : '',
        ];
    }
    $project->writeJson('images.json', $rows);
    $project->writeText('plugin/pages/home.html', '<img src="theme:./assets/hero.jpg"><p>The old filename was theme:./assets/removed.jpg.</p>');
    $project->writeText('plugin/pages/about.html', '<img src="theme:./assets/details.jpg">');
}

test('image preparation excludes removed completed and deferred requests', function () {
    with_project('builder_prepared_images_', function ($project) {
        prepared_image_fixture($project);
        $batch = PreparedImageBatch::fromProject($project);
        assert_eq([0, 3], array_keys($batch->requests()));
        assert_eq('unreferenced', $batch->omitted()[1]['reason']);
        assert_eq('deferred', $batch->omitted()[2]['reason']);
        assert_eq('completed', $batch->omitted()[4]['reason']);
        assert_eq('site-logo', $batch->specs()[3]['role']);
        assert_true($batch->isCurrent($project));
        assert_eq([0, 2, 3], array_keys(PreparedImageBatch::fromProject($project, true)->requests()));
    });
});

test('prepared requests stay immutable and reject changed source artifacts', function () {
    with_project('builder_prepared_immutable_', function ($project) {
        prepared_image_fixture($project);
        $batch = PreparedImageBatch::fromProject($project);
        $requests = $batch->requests();
        $original = $requests[0]['prompt'];
        $requests[0]['prompt'] = 'Changed by the caller';
        assert_eq($original, $batch->requests()[0]['prompt']);
        $project->writeText('theme/style.css', 'body { color: black; }');
        assert_true($batch->isCurrent($project), 'independent CSS work can overlap image requests');
        $project->writeText('plugin/pages/home.html', '<p>The photograph was removed.</p>');
        assert_true(!$batch->isCurrent($project));
        assert_eq($original, $batch->requests()[0]['prompt']);
    });
});

test('image results use isolated files and leave project artifacts unchanged', function () {
    with_project('builder_prepared_stage_', function ($project) {
        prepared_image_fixture($project);
        $batch = PreparedImageBatch::fromProject($project);
        $directory = sys_get_temp_dir() . '/builder_image_results_' . uniqid();
        mkdir($directory);
        try {
            $client = new FakeImageClient();
            $manifest = $batch->stage($client, $directory);
            assert_eq(2, count($client->calls));
            assert_eq($batch->fingerprint(), $manifest['fingerprint']);
            foreach ($manifest['results'] as $result) {
                assert_eq(true, $result['ok']);
                assert_true(!isset($result['bytes']));
                assert_eq($result['sha256'], hash_file('sha256', $directory . '/' . $result['file']));
            }
            assert_true($batch->isCurrent($project));
            assert_true(!$project->exists('images.generated.json'));
            assert_true(!$project->exists('theme/assets/hero.jpg'));
            assert_true(!$project->exists('warnings.json'));
            assert_throws(fn () => $batch->stage($client, $directory));
        } finally {
            remove_tree($directory);
        }
        $inside = $project->path('unsafe-result-directory');
        mkdir($inside);
        assert_throws(fn () => $batch->stage(new FakeImageClient(), $inside));
    });
});

test('one image failure preserves other staged results', function () {
    with_project('builder_prepared_failure_', function ($project) {
        prepared_image_fixture($project);
        $batch = PreparedImageBatch::fromProject($project);
        $directory = sys_get_temp_dir() . '/builder_image_failure_' . uniqid();
        mkdir($directory);
        try {
            $client = new FakeImageClient();
            $client->failPromptSubstrings = ['glass lamp named hero'];
            $manifest = $batch->stage($client, $directory);
            assert_eq(false, $manifest['results'][0]['ok']);
            assert_eq(true, $manifest['results'][3]['ok']);
            assert_true(!isset($manifest['results'][0]['file']));
            assert_true($batch->isCurrent($project));
        } finally {
            remove_tree($directory);
        }
    });
});

test('image preparation requires the page assembly artifact', function () {
    with_project('builder_prepared_early_', function ($project) {
        $error = assert_throws(fn () => PreparedImageBatch::fromProject($project));
        assert_contains('plugin/pages.json', $error->getMessage());
    });
});

test('image preparation keeps chrome and CSS references after assembly', function () {
    with_project('builder_prepared_chrome_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('theme/templates/page.html', '<!-- wp:template-part {"slug":"footer"} /-->');
        $project->writeText('theme/parts/footer.html', '<div style="background-image:url(theme:./assets/removed.jpg)"></div>');
        assert_eq([0, 1, 3], array_keys(PreparedImageBatch::fromProject($project)->requests()));
    });
});

test('staged results reject invalid MIME bytes without touching the project', function () {
    with_project('builder_prepared_mime_', function ($project) {
        prepared_image_fixture($project);
        $batch = PreparedImageBatch::fromProject($project);
        $directory = sys_get_temp_dir() . '/builder_image_mime_' . uniqid();
        mkdir($directory);
        try {
            $manifest = $batch->stage(new FakeImageClient('invalid image bytes'), $directory);
            foreach ($manifest['results'] as $result) {
                assert_eq(false, $result['ok']);
                assert_contains('MIME', $result['error']);
                assert_true(!isset($result['file']));
            }
            assert_true($batch->isCurrent($project));
            assert_eq(['results.json'], array_values(array_diff(scandir($directory), ['.', '..'])));
        } finally {
            remove_tree($directory);
        }
    });
});

test('a host predicate excludes local images from provider requests', function () {
    with_project('builder_prepared_eligibility_', function ($project) {
        prepared_image_fixture($project);
        $seenKinds = [];
        $batch = PreparedImageBatch::fromProject($project, providerEligible: function (array $spec) use (&$seenKinds): bool {
            $seenKinds[] = $spec['image_kind'];
            return ($spec['role'] ?? '') === 'site-logo';
        });
        assert_eq([3], array_keys($batch->requests()));
        assert_eq('provider-ineligible', $batch->omitted()[0]['reason']);
        assert_eq(2, count($seenKinds));
        assert_true($seenKinds[0] !== '');
        assert_true($batch->isCurrent($project));
    });
});

test('an orphan source part cannot restore an image removed from the final page', function () {
    with_project('builder_prepared_orphan_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('plugin/pages/home.html', '<p>The final page has no photograph.</p>');
        $project->writeText('theme/parts/page-home--old.html', '<img src="theme:./assets/removed.jpg">');
        $project->writeText('plugin/pages/orphan.html', '<img src="theme:./assets/hero.jpg">');
        $batch = PreparedImageBatch::fromProject($project);
        assert_eq([3], array_keys($batch->requests()));
        assert_eq('unreferenced', $batch->omitted()[0]['reason']);
        assert_eq('unreferenced', $batch->omitted()[1]['reason']);
        $project->writeText('theme/parts/page-home--old.html', '<img src="theme:./assets/hero.jpg">');
        assert_true($batch->isCurrent($project), 'orphan source changes cannot change the final image inputs');
    });
});

test('image preparation follows recursive template parts and terminates cycles', function () {
    with_project('builder_prepared_part_tree_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('theme/templates/page.html', '<!-- wp:template-part {"slug":"header"} /-->');
        $project->writeText('theme/parts/header.html', '<!-- wp:template-part {"slug":"brand"} /-->');
        $project->writeText('theme/parts/brand.html', '<img src="theme:./assets/removed.jpg"><!-- wp:template-part {"slug":"header"} /-->');
        $batch = PreparedImageBatch::fromProject($project);
        assert_eq([0, 1, 3], array_keys($batch->requests()));
        assert_true($batch->isCurrent($project));
        $project->writeText('theme/parts/brand.html', '<p>The mark is text.</p>');
        assert_true(!$batch->isCurrent($project));
        assert_eq('unreferenced', PreparedImageBatch::fromProject($project)->omitted()[1]['reason']);
    });
});


test('the active image step excludes orphan and unlisted references', function () {
    with_project('builder_active_reference_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('theme/parts/orphan.html', '<img src="theme:./assets/removed.jpg">');
        $project->writeText('plugin/pages/unlisted.html', '<img src="theme:./assets/removed.jpg">');
        $project->writeJson('plugin/images.json', ['images' => [
            ['filename' => 'hero.jpg'], ['filename' => 'removed.jpg'],
        ]]);
        $client = new FakeImageClient();
        $step = new \Automattic\SiteBuild\Steps\GenerateImagesStep($client, inspectImages: false);
        $step->run($project);
        assert_eq(2, count($client->calls));
        assert_eq('unreferenced', $project->readJson('images.json')[1]['status']);
        assert_true(!$project->exists('theme/assets/removed.jpg'));
        assert_eq(['hero.jpg'], array_column($project->readJson('plugin/images.json')['images'], 'filename'));
        $step->run($project);
        assert_eq(2, count($client->calls));
        $project->writeText('plugin/pages/about.html', '<img src="theme:./assets/removed.jpg">');
        $step->run($project);
        assert_eq(3, count($client->calls), 'a restored reference can generate');
        assert_eq('completed', $project->readJson('images.json')[1]['status']);
    });
});


test('a stale orphan cover cannot change the final card slot', function () {
    with_project('builder_active_slot_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('theme/parts/orphan.html', '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} --><div></div><!-- /wp:cover -->');
        $project->writeText('plugin/pages/home.html', '<!-- wp:image {"className":"card-media"} --><figure><img src="theme:./assets/hero.jpg"></figure><!-- /wp:image -->');
        $specs = \Automattic\SiteBuild\ImageSlot::annotate($project, $project->readJson('images.json'));
        assert_eq('card', $specs[0]['image_slot']);
    });
});

test('resumed image collection keeps the planned hero through its final section anchor', function () {
    with_project('builder_resumed_hero_', function ($project) {
        prepared_image_fixture($project);
        $project->writeText('plugin/pages/about.html', '<!-- wp:group {"anchor":"intro"} --><div id="intro"><!-- wp:image --><figure><img src="theme:./assets/room.jpg"></figure><!-- /wp:image --></div><!-- /wp:group -->');
        $specs = \Automattic\SiteBuild\ImageSlot::annotate($project, [[
            'filename' => 'room.jpg', 'src' => 'theme:./assets/room.jpg', 'sources' => ['plugin/pages/about.html'],
        ]]);
        assert_eq('image', $specs[0]['image_slot']);
        assert_eq(true, $specs[0]['hero_slot']);
        assert_eq(true, \Automattic\SiteBuild\ImageQa::applies($specs[0]));
        assert_eq(true, \Automattic\SiteBuild\ImageQa::applies(['filename' => 'hero-room.jpg', 'image_slot' => 'image']));
    });
});
