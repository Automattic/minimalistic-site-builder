<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageRequestReuse;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\BuildReport;

require_once __DIR__ . '/generate_images_test.php';

function reuse_fixture(): array
{
    [$project, $tmp] = generate_fixture();
    $spec = $project->readJson('images.json')[0];
    $copy = array_replace($spec, ['filename' => 'hero-copy.jpg', 'src' => 'theme:./assets/hero-copy.jpg']);
    $project->writeJson('images.json', [$spec, $copy]);
    $markup = $project->readText('theme/parts/hero.html');
    $project->writeText('theme/parts/copy.html', str_replace('hero.jpg', 'hero-copy.jpg', $markup));
    return [$project, $tmp];
}

test('equivalent images share one request and retain separate files and URLs on resume', function () {
    [$project, $tmp] = reuse_fixture();
    $images = new FakeImageClient('JPEGDATA');
    $llm = new FakeLlm();
    $llm->queueText(GI_QA_PASS);
    $step = new GenerateImagesStep($images, $llm);
    $step->run($project);
    assert_eq(1, count($images->calls));
    assert_eq(1, count($llm->imageCalls));
    assert_eq($project->readText('theme/assets/hero.jpg'), $project->readText('theme/assets/hero-copy.jpg'));
    $specs = $project->readJson('images.json');
    assert_eq('hero.jpg', $specs[1]['reused_from']);
    assert_true($specs[0]['url'] !== $specs[1]['url']);
    assert_contains('/assets/hero-copy.jpg', $project->readText('theme/parts/copy.html'));
    $before = $project->readText('images.json');
    $step->run($project);
    assert_eq(1, count($images->calls));
    assert_eq($before, $project->readText('images.json'));
    $report = new BuildReport('p', 'demo', '/tmp/demo', 'today');
    $report->setImages(2, 0, 2);
    $report->setImageReuse(1);
    assert_eq(1, $report->stats('m', [])['images_reused']);
    assert_contains('1 reused assets', $report->imagesLine());
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('equivalent images share the final QA replacement and residual warnings', function () {
    [$project, $tmp] = reuse_fixture();
    $images = new FakeImageClient('JPEGDATA');
    $llm = new FakeLlm();
    $llm->queueText(GI_QA_ROTATED);
    $llm->queueText(GI_QA_ROTATED);
    (new GenerateImagesStep($images, $llm))->run($project);
    assert_eq(2, count($images->calls));
    assert_eq(2, count($llm->imageCalls));
    $specs = $project->readJson('images.json');
    assert_eq(true, $specs[1]['qa']['regenerated']);
    assert_eq($project->readText('theme/assets/hero.jpg'), $project->readText('theme/assets/hero-copy.jpg'));
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images']);
    assert_contains('theme/assets/hero.jpg', $warnings);
    assert_contains('theme/assets/hero-copy.jpg', $warnings);
    assert_contains('still failing after one regeneration', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a failed equivalent request removes only its references and preserves a distinct sibling', function () {
    [$project, $tmp] = reuse_fixture();
    $specs = $project->readJson('images.json');
    $specs[] = array_replace($specs[0], ['filename' => 'other.jpg', 'src' => 'theme:./assets/other.jpg', 'subject' => 'A lake']);
    $project->writeJson('images.json', $specs);
    $sentinel = '<!-- wp:paragraph --><p class="sentinel">Keep  this sibling.</p><!-- /wp:paragraph -->';
    $project->writeText('theme/parts/copy.html', $project->readText('theme/parts/copy.html') . $sentinel);
    $images = new FakeImageClient('JPEGDATA');
    $images->failPromptSubstrings = ['A bakery at dawn'];
    (new GenerateImagesStep($images, inspectImages: false))->run($project);
    assert_eq(2, count($images->calls));
    $specs = $project->readJson('images.json');
    assert_eq(['failed', 'failed', 'completed'], array_column($specs, 'status'));
    $delivered = $project->readText('theme/parts/copy.html');
    assert_true(str_ends_with($delivered, $sentinel));
    assert_true(!str_contains($delivered, 'hero-copy.jpg'));
    assert_true(!str_contains($delivered, '<img'));
    assert_true($project->exists('theme/assets/other.jpg'));
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images']);
    assert_contains('theme/assets/hero-copy.jpg', $warnings);
    assert_contains('delivered removed', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('different subjects and crops keep independent image requests', function () {
    foreach (['subject' => 'A lake', 'aspectRatio' => 'portrait', 'pageContext' => 'A close crop of the same scene'] as $key => $value) {
        [$project, $tmp] = reuse_fixture();
        $specs = $project->readJson('images.json');
        $specs[1][$key] = $value;
        $project->writeJson('images.json', $specs);
        $images = new FakeImageClient('JPEGDATA');
        (new GenerateImagesStep($images, inspectImages: false))->run($project);
        assert_eq(2, count($images->calls), $key);
        assert_true(!isset($project->readJson('images.json')[1]['reused_from']));
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('equivalent request keys retain size MIME kind and the strongest QA policy', function () {
    $specs = [['filename' => 'card.jpg', 'subject' => 'A lamp'], ['filename' => 'hero.jpg', 'subject' => 'A lamp']];
    $request = ['prompt' => 'A lamp', 'aspect_ratio' => '16:9', 'sample_image_size' => '2K', 'mime' => 'image/jpeg'];
    assert_eq([0 => 1], ImageRequestReuse::aliases($specs, [$request, $request]));
    foreach (['prompt' => 'Other', 'aspect_ratio' => '4:3', 'sample_image_size' => '1K', 'mime' => 'image/png'] as $key => $value) {
        assert_eq([], ImageRequestReuse::aliases($specs, [$request, array_replace($request, [$key => $value])]), $key);
    }
    $specs[1]['image_kind'] = 'ui-mockup';
    assert_eq([], ImageRequestReuse::aliases($specs, [$request, $request]));
});


function deferred_reuse_fixture(): array
{
    [$project, $tmp] = reuse_fixture();
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [['slug' => 'photo', 'role' => 'content']]],
        ['slug' => 'about', 'front' => false, 'sections' => [['slug' => 'photo', 'role' => 'content']]],
    ]]);
    $rows = $project->readJson('images.json');
    $rows[0]['sources'] = ['parts/page-home--photo.html'];
    $rows[1]['sources'] = ['parts/page-about--photo.html'];
    $project->writeJson('images.json', $rows);
    return [$project, $tmp];
}

test('a deferred interior asset reuses final homepage pixels without another request', function () {
    [$project, $tmp] = deferred_reuse_fixture();
    try {
        $client = new FakeImageClient();
        $llm = new FakeLlm();
        $llm->queueText(GI_QA_ROTATED);
        $llm->queueText(GI_QA_PASS);
        $step = new GenerateImagesStep($client, $llm);
        $step->run($project);
        assert_eq(2, count($client->calls), 'one original request and one QA replacement');
        $rows = $project->readJson('images.json');
        assert_eq('completed', $rows[1]['status']);
        assert_eq('hero.jpg', $rows[1]['reused_from']);
        assert_eq($project->readText('theme/assets/hero.jpg'), $project->readText('theme/assets/hero-copy.jpg'));
        assert_eq(true, $rows[1]['qa']['regenerated']);
        $step->run($project);
        assert_eq(2, count($client->calls));
    } finally {
        remove_tree($tmp);
    }
});

test('an exact completed source supplies a later interior asset at no provider cost', function () {
    [$project, $tmp] = deferred_reuse_fixture();
    try {
        $rows = $project->readJson('images.json');
        $project->writeJson('images.json', [$rows[0]]);
        $client = new FakeImageClient();
        $llm = new FakeLlm();
        $llm->queueText(GI_QA_PASS);
        $step = new GenerateImagesStep($client, $llm);
        $step->run($project);
        $complete = $project->readJson('images.json')[0];
        $project->writeJson('images.json', [$complete, $rows[1]]);
        $step->run($project);
        assert_eq(1, count($client->calls));
        assert_eq('hero.jpg', $project->readJson('images.json')[1]['reused_from']);
        $different = array_replace($rows[1], ['filename' => 'different.jpg', 'src' => 'theme:./assets/different.jpg', 'subject' => 'A different table']);
        $project->writeJson('images.json', [$complete, $different]);
        $step->run($project);
        assert_eq(1, count($client->calls));
        assert_eq('placeholder', $project->readJson('images.json')[1]['status']);
    } finally {
        remove_tree($tmp);
    }
});

test('a failed shared request preserves the deferred image slot with a local placeholder', function () {
    [$project, $tmp] = deferred_reuse_fixture();
    try {
        $client = new FakeImageClient(fail: true);
        $step = new GenerateImagesStep($client, inspectImages: false);
        $step->run($project);
        assert_eq(1, count($client->calls));
        $rows = $project->readJson('images.json');
        assert_eq('failed', $rows[0]['status']);
        assert_eq('placeholder', $rows[1]['status']);
        assert_true($project->exists('theme/assets/hero-copy.jpg'));
        assert_contains('hero-copy.jpg', $project->readText('theme/parts/copy.html'));
        assert_contains('equivalent image request failed', implode(' ', $project->readJson('warnings.json')['generate-images']));
    } finally {
        remove_tree($tmp);
    }
});
