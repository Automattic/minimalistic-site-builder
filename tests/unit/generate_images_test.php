<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\CoverContrastStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;

require_once __DIR__ . '/../FakeImageClient.php';
require_once __DIR__ . '/../FakeLlm.php';

function generate_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/templates/page.html',
        '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: A bakery at dawn | full-bleed hero with text overlay | photorealistic | landscape"/></div><!-- /wp:cover -->'
    );
    (new CollectImagesStep())->run($project);
    return [$project, $tmp];
}

test('generate-images declaration marks its batched work as concurrent', function () {
    $declaration = (new GenerateImagesStep(new FakeImageClient()))->declaration();

    assert_eq(true, $declaration->concurrent);
    assert_true(in_array(GenerateImagesStep::COMPLETION_ARTIFACT, $declaration->writes, true));
});

test('cover-contrast graph requires generate-images even when scaffold assets exist', function () {
    $cover = new CoverContrastStep(new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return 'block-fixer: ok';
        }
    });
    $scaffolded = [
        'theme/theme.json',
        'theme/assets/motion/runtime.js',
        'theme/parts/header.html',
        'theme/templates/page.html',
        'plugin/pages/*',
    ];

    assert_throws(
        fn () => StepGraph::validate([$cover], seeds: $scaffolded),
        'motion assets must not satisfy the image-generation dependency',
    );

    StepGraph::validate([
        new GenerateImagesStep(new FakeImageClient()),
        $cover,
    ], seeds: array_merge($scaffolded, [
        'images.json',
        'siteSpec.json',
        'designDirection.json',
    ]));
    assert_true(true);
});

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
    $markup = $project->readText('theme/templates/page.html');
    assert_contains('/wp-content/themes/demo/assets/hero.jpg', $markup);
    assert_true(!str_contains($markup, 'theme:./assets/hero.jpg'), 'no theme: placeholder left');

    // Status recorded.
    $specs = $project->readJson('images.json');
    assert_eq('completed', $specs[0]['status']);
    assert_eq('/wp-content/themes/demo/assets/hero.jpg', $specs[0]['url']);
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images weaves the site context into each prompt as one sentence', function () {
    [$project, $tmp] = generate_fixture();
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'topic'       => 'artisan sourdough',
        'description' => 'A neighborhood bakery selling sourdough and pastries.',
    ]);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    $sent = $images->calls[0]['prompt'];
    // Page context and site name read as one grammatical sentence, with the
    // description following — and the topic NOT repeated beside it.
    assert_contains(
        'This image is used as full-bleed hero with text overlay on the website “Hearth & Crumb”.',
        $sent
    );
    assert_contains('A neighborhood bakery selling sourdough and pastries.', $sent);
    assert_true(!str_contains($sent, 'artisan sourdough'), 'topic not repeated when the description covers it');
    assert_contains('A bakery at dawn', $sent);               // the image subject is still there

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('siteContext leads with a noun phrase and never stutters the topic', function () {
    // Empty spec → no context at all.
    assert_eq('', GenerateImagesStep::siteContext([]));

    // Name only.
    assert_eq('the website “Hearth & Crumb”.', GenerateImagesStep::siteContext([
        'name' => 'Hearth & Crumb',
    ]));

    // The topic is included only while there is no description to cover it…
    assert_eq('the website “Hearth & Crumb”, about artisan sourdough.', GenerateImagesStep::siteContext([
        'name' => 'Hearth & Crumb', 'topic' => 'artisan sourdough',
    ]));

    // …and folds away once a description exists (it restates the topic).
    assert_eq(
        'the website “Hearth & Crumb”. A neighborhood bakery selling sourdough and pastries.',
        GenerateImagesStep::siteContext([
            'name'        => 'Hearth & Crumb',
            'topic'       => 'artisan sourdough',
            'description' => 'A neighborhood bakery selling sourdough and pastries.',
        ])
    );

    // Specs without a name still read after "on".
    assert_eq('a website about artisan sourdough.', GenerateImagesStep::siteContext([
        'topic' => 'artisan sourdough',
    ]));
    assert_eq('a website. A neighborhood bakery.', GenerateImagesStep::siteContext([
        'description' => 'A neighborhood bakery.',
    ]));

    // A description without terminal punctuation is closed, so the composer's
    // guidance sentence (which relies on this phrase ending one) never runs on.
    assert_eq(
        'the website “Hearth & Crumb”. Fresh bread daily.',
        GenerateImagesStep::siteContext([
            'name' => 'Hearth & Crumb', 'description' => 'Fresh bread daily',
        ])
    );
    assert_eq('a website. Fresh bread daily!', GenerateImagesStep::siteContext([
        'description' => 'Fresh bread daily!', // already punctuated: untouched
    ]));
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
    $project->writeJson('designDirection.json', [
        'title'       => 'Archivo Silencioso',
        'description' => 'Full-bleed black-and-white photography.',
        'image_grade' => $grade,
    ]);
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
        [
            'filename' => 'divider.png', 'src' => 'theme:./assets/divider.png',
            'subject' => 'a vine flourish', 'pageContext' => 'section divider',
            'style' => 'line art', 'aspectRatio' => 'landscape', 'status' => 'pending',
        ],
    ]);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    assert_eq('2K', $images->calls[0]['opts']['sample_image_size']); // landscape full-bleed
    assert_eq('1K', $images->calls[1]['opts']['sample_image_size']); // square thumb
    assert_eq('1K', $images->calls[2]['opts']['sample_image_size']); // portrait
    assert_eq('1K', $images->calls[3]['opts']['sample_image_size']); // wide but transparent decorative

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

    // The .png asset asks the endpoint for PNG bytes and prompts for the flat
    // white background that gets keyed out; the .jpg asset stays JPEG.
    assert_eq('image/png', $images->calls[0]['opts']['mime']);
    assert_contains('solid pure white background', $images->calls[0]['prompt']);
    assert_eq('image/jpeg', $images->calls[1]['opts']['mime']);
    assert_true(!str_contains($images->calls[1]['prompt'], 'white background'), 'jpg prompt has no isolation clause');

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
    $markup = $project->readText('theme/templates/page.html');
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

test('generate-images processes pending images in concurrent batches of 10', function () {
    [$project, $tmp] = batch_fixture(12); // 12 -> batches of 10 + 2
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // Two batches were issued, sized 10 then 2 (not 12 single calls).
    assert_eq(2, count($images->batches), 'two batches');
    assert_eq(10, count($images->batches[0]), 'first batch has 10');
    assert_eq(2, count($images->batches[1]), 'second batch has 2');

    // All 12 assets written and marked completed.
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
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
        'the step completed even though one image failed softly',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images repairs a safety-filtered prompt with the small model and retries', function () {
    [$project, $tmp] = generate_fixture(); // subject: "A bakery at dawn"
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['A bakery at dawn']; // original subject is "filtered"
    $llm = new FakeLlm();
    $llm->queueText('a warm bread display at sunrise');

    (new GenerateImagesStep($images, $llm, 'small-model'))->run($project);

    // The rewrite went to the configured small model, with the original
    // subject and the filter's reason in the repair prompt.
    assert_eq(1, count($llm->calls), 'one rewrite request');
    assert_eq('small-model', $llm->calls[0]['opts']['model']);
    assert_contains('A bakery at dawn', $llm->calls[0]['prompt']);
    assert_contains('fake rai', $llm->calls[0]['prompt']);

    // A second generation batch was issued with the recomposed prompt.
    assert_eq(2, count($images->batches), 'original batch + repair batch');
    assert_contains('a warm bread display at sunrise', $images->batches[1][0]['prompt']);

    // The image completed like any other.
    $specs = $project->readJson('images.json');
    assert_eq('completed', $specs[0]['status']);
    assert_true($project->exists('theme/assets/hero.jpg'), 'asset written after repair');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images marks failed when the repaired prompt is filtered too', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['A bakery at dawn', 'still blocked'];
    $llm = new FakeLlm();
    $llm->queueText('still blocked subject'); // the rewrite trips the filter again

    (new GenerateImagesStep($images, $llm, 'small-model'))->run($project);

    // One repair round only: rewritten once, regenerated once, then failed.
    assert_eq(1, count($llm->calls), 'no second rewrite round');
    assert_eq(2, count($images->batches));
    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);
    assert_contains('safety filter', $specs[0]['error']);
    assert_true(!$project->exists('theme/assets/hero.jpg'), 'no asset for failed image');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images without an llm marks a filtered image failed', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['A bakery at dawn'];

    (new GenerateImagesStep($images))->run($project); // no Llm: no repair pass

    assert_eq(1, count($images->batches), 'no repair batch without an llm');
    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images falls back to the original failure when the rewrite errors', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['A bakery at dawn'];
    $llm = new FakeLlm(); // nothing queued: completeBatch throws

    (new GenerateImagesStep($images, $llm, 'small-model'))->run($project); // must not throw

    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);
    assert_contains('fake rai', $specs[0]['error']); // the original filter error is kept

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images repairs the sibling images when one rewrite fails permanently', function () {
    [$project, $tmp] = batch_fixture(2); // subjects "image 0" and "image 1"
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['image 0', 'image 1']; // both filtered
    $llm = new FakeLlm();
    $llm->failPromptSubstrings = ['image 0'];  // img-0's rewrite fails for good,
    $llm->queueText('a calm rooftop garden');  // img-1's succeeds

    (new GenerateImagesStep($images, $llm, 'small-model'))->run($project);

    // The aborted batch was retried one request at a time — one complete()
    // call per image — so img-0's permanent failure didn't sink img-1.
    assert_eq(2, count($llm->calls), 'one fallback rewrite call per image');
    assert_eq(2, count($images->batches), 'original batch + repair batch');
    assert_contains('a calm rooftop garden', $images->batches[1][0]['prompt']);

    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);
    assert_contains('fake rai', $specs[0]['error']); // original filter error kept
    assert_eq('completed', $specs[1]['status']);
    assert_true($project->exists('theme/assets/img-1.jpg'), 'sibling asset written after repair');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images is a no-op when there are no placeholders', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $images = new FakeImageClient();

    (new GenerateImagesStep($images))->run($project);

    assert_eq([], $images->calls);
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images publishes completion when the image manifest is absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $images = new FakeImageClient();

    (new GenerateImagesStep($images))->run($project);

    assert_eq([], $images->calls);
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images clears a stale completion artifact before a failed re-run', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson(GenerateImagesStep::COMPLETION_ARTIFACT, ['status' => 'completed']);
    $project->writeText('images.json', '{invalid');

    assert_throws(fn () => (new GenerateImagesStep(new FakeImageClient()))->run($project));
    assert_true(!$project->exists(GenerateImagesStep::COMPLETION_ARTIFACT));

    exec('rm -rf ' . escapeshellarg($tmp));
});
