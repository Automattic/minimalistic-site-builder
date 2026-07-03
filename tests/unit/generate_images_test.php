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

test('generate-images injects the persisted image grade into every prompt', function () {
    [$project, $tmp] = generate_fixture();
    $grade = 'monochrome documentary, visible 35mm grain, available light';
    $project->writeText('imageGrade.txt', $grade . "\n");
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    assert_contains("Art direction for all site imagery: {$grade}.", $images->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images requests 2K for landscape images and 1K otherwise', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', [
        [
            'filename' => 'hero.jpg', 'src' => 'theme:./assets/hero.jpg',
            'subject' => 'a hero', 'pageContext' => 'full-bleed hero',
            'style' => 'photorealistic', 'aspectRatio' => 'landscape', 'status' => 'pending',
        ],
        [
            'filename' => 'thumb.jpg', 'src' => 'theme:./assets/thumb.jpg',
            'subject' => 'a thumb', 'pageContext' => 'card thumbnail',
            'style' => 'photorealistic', 'aspectRatio' => 'square', 'status' => 'pending',
        ],
        [
            'filename' => 'tall.jpg', 'src' => 'theme:./assets/tall.jpg',
            'subject' => 'a tall image', 'pageContext' => 'about portrait',
            'style' => 'photorealistic', 'aspectRatio' => 'portrait', 'status' => 'pending',
        ],
    ]);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    assert_eq('2K', $images->calls[0]['opts']['sample_image_size']); // landscape full-bleed
    assert_eq('1K', $images->calls[1]['opts']['sample_image_size']); // square thumb
    assert_eq('1K', $images->calls[2]['opts']['sample_image_size']); // portrait

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images requests PNG and a transparent background for .png assets', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/parts/footer.html',
        '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/grapevine-flourish.png" '
        . 'alt="AI_IMAGE: A small grapevine flourish, thin gold linework | decorative accent under a subheading | illustration | landscape"/></figure>'
        . '<!-- /wp:image -->'
        . '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/vineyard-hills.jpg" '
        . 'alt="AI_IMAGE: Rolling vineyard hills at dusk | wide feature image | photorealistic | landscape"/></figure>'
        . '<!-- /wp:image -->'
    );
    (new CollectImagesStep())->run($project);
    $images = new FakeImageClient('PNGDATA');

    (new GenerateImagesStep($images))->run($project);

    // The .png asset asks the endpoint for PNG bytes and carries the
    // transparency instruction in its prompt; the .jpg asset stays JPEG.
    assert_eq('image/png', $images->calls[0]['opts']['mime']);
    assert_contains('fully transparent background', $images->calls[0]['prompt']);
    assert_eq('image/jpeg', $images->calls[1]['opts']['mime']);
    assert_true(!str_contains($images->calls[1]['prompt'], 'transparent background'), 'jpg prompt has no transparency clause');

    // Both assets are written and wired in under their own extension.
    assert_true($project->exists('theme/assets/grapevine-flourish.png'), 'png asset written');
    $markup = $project->readText('theme/parts/footer.html');
    assert_contains('/wp-content/themes/demo/assets/grapevine-flourish.png', $markup);

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
