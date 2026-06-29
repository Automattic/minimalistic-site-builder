<?php
declare(strict_types=1);

require_once __DIR__ . '/../FakeImageClient.php';

function generate_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/templates/front-page.html',
        '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: A bakery at dawn | full-bleed hero with text overlay | photorealistic | landscape"/></div><!-- /wp:cover -->'
    );
    (new CollectImagesStep())->run($project);
    return [$project, $tmp];
}

test('generate-images writes assets, rewrites src/url, and marks completed', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // Asset written from the returned bytes.
    assert_true($project->exists('theme/assets/hero.jpg'), 'asset written');
    assert_eq('JPEGDATA', $project->readText('theme/assets/hero.jpg'));

    // Aspect ratio mapped landscape -> 16:9 for the proxy.
    assert_eq('16:9', $images->calls[0]['opts']['aspect_ratio']);

    // Both the cover url and the img src are rewritten to the served path; no
    // theme: placeholder remains.
    $markup = $project->readText('theme/templates/front-page.html');
    assert_contains('/wp-content/themes/demo/assets/hero.jpg', $markup);
    assert_true(!str_contains($markup, 'theme:./assets/hero.jpg'), 'no theme: placeholder left');

    // Status recorded.
    $specs = $project->readJson('images.json');
    assert_eq('completed', $specs[0]['status']);
    assert_eq('/wp-content/themes/demo/assets/hero.jpg', $specs[0]['url']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images prepends site name/topic/description context to each prompt', function () {
    [$project, $tmp] = generate_fixture();
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'topic'       => 'artisan sourdough',
        'description' => 'A neighborhood bakery selling sourdough and pastries.',
    ]);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    $sent = $images->calls[0]['prompt'];
    assert_contains('Hearth & Crumb', $sent);                 // site name
    assert_contains('artisan sourdough', $sent);              // topic
    assert_contains('A neighborhood bakery', $sent);          // description
    assert_contains('A bakery at dawn', $sent);               // the image subject is still there

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images leads with the subject + style and adds the page context', function () {
    [$project, $tmp] = generate_fixture(); // fixture writes no siteSpec.json
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    $sent = $images->calls[0]['prompt'];
    assert_contains('A bakery at dawn. Style: photorealistic', $sent);   // subject leads, style appended
    assert_contains('full-bleed hero with text overlay', $sent);         // page context is included as guidance

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images marks failed and leaves the placeholder on error', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('', true); // throws

    (new GenerateImagesStep($images))->run($project);

    assert_true(!$project->exists('theme/assets/hero.jpg'), 'no asset on failure');
    $markup = $project->readText('theme/templates/front-page.html');
    assert_contains('theme:./assets/hero.jpg', $markup); // placeholder untouched

    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

/** Write an images.json with $n pending placeholders and return [project, tmp]. */
function batch_fixture(int $n): array
{
    $tmp = sys_get_temp_dir() . '/builder_gib_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $specs = [];
    for ($k = 0; $k < $n; $k++) {
        $specs[] = [
            'filename'    => "img-{$k}.jpg",
            'src'         => "theme:./assets/img-{$k}.jpg",
            'subject'     => "image {$k}",
            'pageContext' => 'content image',
            'style'       => 'photorealistic',
            'aspectRatio' => 'landscape',
            'status'      => 'pending',
        ];
    }
    $project->writeJson('images.json', $specs);
    return [$project, $tmp];
}

test('generate-images processes pending images in concurrent batches of 5', function () {
    [$project, $tmp] = batch_fixture(7); // 7 -> batches of 5 + 2
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // Two batches were issued, sized 5 then 2 (not 7 single calls).
    assert_eq(2, count($images->batches), 'two batches');
    assert_eq(5, count($images->batches[0]), 'first batch has 5');
    assert_eq(2, count($images->batches[1]), 'second batch has 2');

    // All 7 assets written and marked completed.
    $specs = $project->readJson('images.json');
    foreach ($specs as $s) {
        assert_eq('completed', $s['status']);
        assert_true($project->exists('theme/assets/' . $s['filename']), "{$s['filename']} written");
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images tolerates a partial failure within a batch', function () {
    [$project, $tmp] = batch_fixture(3);
    $images = new FakeImageClient('JPEGDATA');
    $images->failPromptSubstrings = ['image 1.']; // only the middle one fails

    (new GenerateImagesStep($images))->run($project);

    $specs = $project->readJson('images.json');
    assert_eq('completed', $specs[0]['status']);
    assert_eq('failed', $specs[1]['status']);          // the failed image is isolated
    assert_eq('completed', $specs[2]['status']);       // others still succeed
    assert_true($project->exists('theme/assets/img-0.jpg'));
    assert_true(!$project->exists('theme/assets/img-1.jpg'), 'no asset for failed image');
    assert_true($project->exists('theme/assets/img-2.jpg'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images is a no-op when there are no placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $images = new FakeImageClient();

    (new GenerateImagesStep($images))->run($project);

    assert_eq([], $images->calls);

    exec('rm -rf ' . escapeshellarg($tmp));
});
