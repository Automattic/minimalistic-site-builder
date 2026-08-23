<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\CoverContrastStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;

require_once __DIR__ . '/../FakeImageClient.php';

function generate_fixture(): array
{
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $markup = '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: A bakery at dawn | full-bleed hero with text overlay | photorealistic | landscape"/></div><!-- /wp:cover -->';
    // Collection runs over the pre-assembly parts. Keep the same placeholder
    // in a template to verify GenerateImagesStep rewrites every shipped scope.
    $project->writeText('theme/parts/hero.html', $markup);
    $project->writeText('theme/templates/page.html', $markup);
    (new CollectImagesStep())->run($project);
    return [$project, $tmp];
}

test('generate-images declaration marks its batched work as concurrent', function () {
    $declaration = (new GenerateImagesStep(new FakeImageClient()))->declaration();

    assert_eq(true, $declaration->concurrent);
    assert_true(in_array(GenerateImagesStep::COMPLETION_ARTIFACT, $declaration->writes, true));
    assert_true(in_array('warnings.json', $declaration->writes, true));
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
        'plugin/images.json',
    ]));
    assert_true(true);
});

test('generate-images writes assets, rewrites src/url, and marks completed', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // Asset written from the returned bytes.
    assert_true($project->exists('theme/assets/hero.jpg'), 'asset written');
    assert_eq('image/jpeg', GeminiImage::mimeFromBytes($project->readText('theme/assets/hero.jpg')));

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

test('I-G9 a preconstructed image client still powers post-build generation', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');

    try {
        $step = make_generate_images_step(null, $images);
        $property = new ReflectionProperty(GenerateImagesStep::class, 'images');
        $property->setAccessible(true);
        assert_true($property->getValue($step) === $images, 'the preflight client instance is reused');

        $step->run($project);

        assert_eq(1, count($images->calls));
        assert_true($project->exists('theme/assets/hero.jpg'));
        assert_eq('completed', $project->readJson('images.json')[0]['status'] ?? null);
    } finally {
        remove_tree($tmp);
    }
});

test('generate-images rewrites theme: placeholders in assembled plugin/pages', function () {
    [$project, $tmp] = generate_fixture();
    // Simulate post-assemble multipage content: section covers live in the
    // content plugin, not only in theme/templates.
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} --><div class="wp-block-cover">'
        . '<img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" '
        . 'alt="AI_IMAGE: A bakery at dawn | full-bleed hero with text overlay | photorealistic | landscape"/>'
        . '</div><!-- /wp:cover -->'
    );
    // Collect from the theme part (fixture); the plugin page reuses the same
    // theme:./assets/hero.jpg placeholder and must rewrite with the same map.
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    $page = $project->readText('plugin/pages/home.html');
    assert_contains('/wp-content/themes/demo/assets/hero.jpg', $page);
    assert_true(!str_contains($page, 'theme:./assets/hero.jpg'), 'plugin page theme: placeholders rewritten');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('recovered cover survives serialization, ships with the plugin, and generates once', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_recovered_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'front' => true, 'menu_order' => 0,
        'sections' => [['slug' => 'hero']],
    ]]]);
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->'
    );
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"AI_IMAGE:coffee \u0026 croissant at dawn|ratio:21:9|role:hero","dimRatio":40} -->'
        . '<!-- wp:paragraph {"content":"Welcome"} /-->'
        . '<!-- /wp:cover -->'
    );

    (new CollectImagesStep())->run($project);
    $specs = $project->readJson('images.json');
    assert_eq(1, count($specs));
    assert_eq('21:9', $specs[0]['aspectRatio']);
    assert_eq('theme:./assets/' . $specs[0]['filename'], $specs[0]['src']);

    (new PhpBlockFixer())->fix($project->themePath());
    $fixed = $project->readText('theme/parts/page-home--hero.html');
    assert_eq(2, substr_count($fixed, $specs[0]['src']), 'url and rendered src use the canonical path');
    assert_true(!str_contains($fixed, 'AI_IMAGE:'), 'serializer never sees the raw prompt');

    (new AssemblePagesStep())->run($project);
    assert_eq([[
        'filename' => $specs[0]['filename'],
        'title' => 'coffee & croissant at dawn',
    ]], $project->readJson('plugin/images.json')['images']);

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls));
    assert_eq('21:9', $images->calls[0]['opts']['aspect_ratio']);
    assert_true($project->exists('theme/assets/' . $specs[0]['filename']));
    assert_true($project->exists('plugin/images/' . $specs[0]['filename']));
    $page = $project->readText('plugin/pages/home.html');
    $served = '/wp-content/themes/demo/assets/' . $specs[0]['filename'];
    assert_eq(2, substr_count($page, $served), 'assembled cover url and img src both resolved');
    assert_true(!str_contains($page, 'AI_IMAGE:'), 'no raw prompt shipped in page sources');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images weaves the site context into each prompt as one sentence', function () {
    [$project, $tmp] = generate_fixture();
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'topic'       => 'artisan sourdough',
        'description' => 'Hearth & Crumb is a neighborhood bakery selling sourdough and pastries.',
    ]);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    $sent = $images->calls[0]['prompt'];
    // The identity-bearing description is rejected whole; the recast page
    // context and clean topic fallback read as adjacent guidance sentences.
    assert_contains(
        'Composition: full-frame editorial photograph with a reserved area kept as open, low-detail negative space.'
        . ' The subject matter is artisan sourdough.',
        $sent
    );
    // The site NAME never reaches the image model: it is what painted-in fake
    // wordmarks stand in for (BIGR-768), and nothing in the prompt may
    // describe the image as part of a website.
    assert_true(!str_contains($sent, 'Hearth & Crumb'), 'site name withheld from the image prompt');
    assert_true(!str_contains(strtolower($sent), 'website'), 'the prompt never mentions a website');
    assert_true(
        !str_contains($sent, 'neighborhood bakery selling'),
        'identity-bearing description is not partially forwarded'
    );
    assert_contains('A bakery at dawn', $sent);               // the image subject is still there

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('siteContext carries only subject matter and never the site name', function () {
    // Empty spec → no context at all.
    assert_eq('', GenerateImagesStep::siteContext([]));

    // The name alone contributes nothing: it is deliberately withheld from
    // image prompts (BIGR-768 — a painted-in fake wordmark is the model
    // typesetting the name the prompt told it about).
    assert_eq('', GenerateImagesStep::siteContext([
        'name' => 'Hearth & Crumb',
    ]));

    // The concise topic is the preferred subject-matter source.
    assert_eq('The subject matter is artisan sourdough.', GenerateImagesStep::siteContext([
        'name' => 'Hearth & Crumb', 'topic' => 'artisan sourdough',
    ]));

    // It remains preferred over a longer description of the site artifact.
    assert_eq(
        'The subject matter is artisan sourdough.',
        GenerateImagesStep::siteContext([
            'name'        => 'Hearth & Crumb',
            'topic'       => 'artisan sourdough',
            'description' => 'A neighborhood bakery selling sourdough and pastries.',
        ])
    );

    // A description remains a fallback. Without terminal punctuation it is
    // closed, so the composer's following guidance never runs on.
    assert_eq('Fresh bread daily.', GenerateImagesStep::siteContext([
        'name' => 'Hearth & Crumb', 'description' => 'Fresh bread daily',
    ]));
    assert_eq('Fresh bread daily!', GenerateImagesStep::siteContext([
        'description' => 'Fresh bread daily!', // already punctuated: untouched
    ]));
    assert_eq('静かな喫茶店です。', GenerateImagesStep::siteContext([
        'description' => '静かな喫茶店です。', // Unicode sentence terminal: untouched
    ]));

    // Canonical descriptions commonly repeat the identity. Reject the whole
    // prose candidate and fall back to a clean factual field rather than
    // deleting the name into an ungrammatical fragment.
    assert_eq('The subject matter is construction management app.', GenerateImagesStep::siteContext([
        'name'        => 'Atlas Field',
        'description' => 'Atlas Field is a mobile app for construction crews.',
        'topic'       => 'construction management app',
        'area'        => 'business software',
    ]));

    // Identity matching is conservative and Unicode-safe; web-artifact prose
    // is rejected, while a real-world use of "site" remains valid subject matter.
    assert_eq('The subject matter is hospitality.', GenerateImagesStep::siteContext([
        'name'        => 'Café C++（東京）',
        'description' => 'The official website for Café C++（東京）.',
        'topic'       => 'Café C++（東京） hospitality',
        'area'        => 'hospitality',
    ]));
    assert_eq('The subject matter is hospitality.', GenerateImagesStep::siteContext([
        'email_domain' => 'cafecpp.example',
        'topic'        => 'Book at cafecpp.example',
        'area'         => 'hospitality',
    ]));
    assert_eq('The subject matter is construction site reporting.', GenerateImagesStep::siteContext([
        'name'  => 'Atlas Field',
        'topic' => 'construction site reporting',
    ]));
    assert_eq('The subject matter is 喫茶店.', GenerateImagesStep::siteContext([
        'name'  => '東京茶房',
        'topic' => '東京茶房は喫茶店です。', // no word boundary before the particle
        'area'  => '喫茶店',
    ]));

    assert_eq('The subject matter is astronomy lodging.', GenerateImagesStep::siteContext([
        'topic'       => 'astronomy lodging',
        'description' => 'A one-page site for an observatory lodge.',
    ]));
    assert_eq('', GenerateImagesStep::siteContext([
        'description' => 'A one-page site for an observatory lodge.',
    ]));
    assert_eq('', GenerateImagesStep::siteContext([
        'description' => 'A minimalist portfolio for a documentary photographer.',
    ]));
    assert_eq('', GenerateImagesStep::siteContext([
        'description' => 'Photography portfolios and landing pages for artists.',
    ]));

    // If every available fact leaks identity or website framing, omit this
    // optional context; never send the trigger or broken prose to the model.
    assert_eq('', GenerateImagesStep::siteContext([
        'name'        => 'Alcorta',
        'description' => 'Alcorta is a portfolio website.',
        'topic'       => 'Alcorta website',
        'area'        => 'official site',
    ]));
});

test('generate-images leads with the subject + style and adds the page context', function () {
    [$project, $tmp] = generate_fixture(); // fixture writes no siteSpec.json
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    $sent = $images->calls[0]['prompt'];
    assert_contains('A bakery at dawn. Style: photorealistic', $sent);   // subject leads, style appended
    // The page context is included as guidance, recast photographically.
    assert_contains(
        'full-frame editorial photograph with a reserved area kept as open, low-detail negative space',
        $sent
    );

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

test('generate-images marks failed and removes only its media block on error', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('', true); // throws

    (new GenerateImagesStep($images))->run($project);

    assert_true(!$project->exists('theme/assets/hero.jpg'), 'no asset on failure');
    $markup = $project->readText('theme/templates/page.html');
    assert_true(!str_contains($markup, 'theme:./assets/hero.jpg'), 'dead asset reference removed');

    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('images.json[0] / theme/assets/hero.jpg', $warnings[0]);
    assert_contains('authored MIME image/jpeg', $warnings[0]);
    assert_contains('delivered removed', $warnings[0]);
    assert_contains('container media', $warnings[0]);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images records caption text removed with a failed image', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_caption_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText(
        'theme/parts/content.html',
        '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:image --><figure class="wp-block-image">'
            . '<img src="theme:./assets/failed.jpg" '
            . 'alt="AI_IMAGE: A failed scene | content image | photorealistic | landscape">'
            . '</figure><!-- /wp:image -->'
            . '<!-- wp:paragraph {"fontSize": "caption"} -->'
            . '<p>The unavailable scene at dawn.</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->'
    );
    (new CollectImagesStep())->run($project);

    (new GenerateImagesStep(new FakeImageClient('', true)))->run($project);

    $markup = $project->readText('theme/parts/content.html');
    assert_true(!str_contains($markup, 'failed.jpg'));
    assert_true(!str_contains($markup, 'unavailable scene'));
    $warnings = implode(' ', $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('authored caption "The unavailable scene at dawn."', $warnings);
    assert_contains('delivered removed', $warnings);
    assert_contains('orphaned description', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images removal keeps failed-image siblings byte-for-byte intact', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $before = '<!-- wp:paragraph --><p>Before theme:./assets/failed.jpg as text.</p><!-- /wp:paragraph -->';
    $image = '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/failed.jpg" '
        . 'alt="AI_IMAGE: A failed scene | content image | photorealistic | landscape"/>'
        . '</figure><!-- /wp:image -->';
    $after = '<!-- wp:paragraph --><p>After.</p><!-- /wp:paragraph -->';
    $project->writeText('theme/parts/content.html', $before . $image . $after);
    (new CollectImagesStep())->run($project);

    (new GenerateImagesStep(new FakeImageClient('', true)))->run($project);

    assert_eq(
        $before . $after,
        $project->readText('theme/parts/content.html'),
        'only the failed media block is cut; sibling bytes and plain text survive',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images removes a bare failed img without deleting its group', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $opening = '<!-- wp:group --><div class="wp-block-group">';
    $image = '<img src="theme:./assets/failed-logo.jpg" '
        . 'alt="AI_IMAGE: A failed logo | header | photorealistic | landscape"/>';
    $copy = '<!-- wp:paragraph --><p>Keep group copy.</p><!-- /wp:paragraph -->';
    $closing = '</div><!-- /wp:group -->';
    $project->writeText('theme/parts/header.html', $opening . $image . $copy . $closing);
    (new CollectImagesStep())->run($project);

    (new GenerateImagesStep(new FakeImageClient('', true)))->run($project);

    assert_eq(
        $opening . $copy . $closing,
        $project->readText('theme/parts/header.html'),
        'a non-media container and its child blocks survive bare-img removal',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images strips failed cover media while retaining headline and CTA bytes', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $heading = '<!-- wp:heading --><h2 class="wp-block-heading">Keep this headline</h2><!-- /wp:heading -->';
    $buttons = '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Keep CTA</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->';
    $cover = '<!-- wp:cover {"url":"theme:./assets/failed-cover.jpg","dimRatio":40} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" '
        . 'src="theme:./assets/failed-cover.jpg" '
        . 'alt="AI_IMAGE: A failed cover | hero | photorealistic | landscape"/>'
        . '<div class="wp-block-cover__inner-container">' . $heading . $buttons . '</div></div>'
        . '<!-- /wp:cover -->';
    $project->writeText('theme/parts/hero.html', $cover);
    (new CollectImagesStep())->run($project);

    (new GenerateImagesStep(new FakeImageClient('', true)))->run($project);

    $expected = '<!-- wp:cover {"dimRatio":40} -->'
        . '<div class="wp-block-cover"><div class="wp-block-cover__inner-container">'
        . $heading . $buttons . '</div></div><!-- /wp:cover -->';
    assert_eq($expected, $project->readText('theme/parts/hero.html'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images cleans each shared failed-source block at its own boundary', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $heading = '<!-- wp:heading --><h2>Cover copy survives.</h2><!-- /wp:heading -->';
    $cover = '<!-- wp:cover {"url":"theme:./assets/shared-failure.jpg"} -->'
        . '<div class="wp-block-cover"><img src="theme:./assets/shared-failure.jpg" '
        . 'alt="AI_IMAGE: Shared failure | cover | photorealistic | landscape"/>'
        . $heading . '</div><!-- /wp:cover -->';
    $image = '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/shared-failure.jpg" '
        . 'alt="AI_IMAGE: Shared failure | content | photorealistic | landscape"/>'
        . '</figure><!-- /wp:image -->';
    $project->writeText('theme/parts/content.html', $cover . $image);
    (new CollectImagesStep())->run($project);

    (new GenerateImagesStep(new FakeImageClient('', true)))->run($project);

    $delivered = $project->readText('theme/parts/content.html');
    assert_contains($heading, $delivered);
    assert_true(!str_contains($delivered, 'theme:./assets/shared-failure.jpg'));
    assert_true(!str_contains($delivered, '<!-- wp:image -->'), 'standalone image block removed whole');
    assert_true(!str_contains($delivered, '<figure class="wp-block-image"></figure>'), 'no empty media UI');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images keeps an unsafe failed cover unchanged and reports the residual', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $unclosed = '<!-- wp:cover {"url":"theme:./assets/unsafe-cover.jpg"} -->'
        . '<div class="wp-block-cover"><img src="theme:./assets/unsafe-cover.jpg" '
        . 'alt="AI_IMAGE: An unsafe cover | hero | photorealistic | landscape"/>'
        . '<!-- wp:heading --><h2>Retain me</h2><!-- /wp:heading -->';
    $project->writeText('theme/parts/hero.html', $unclosed);
    (new CollectImagesStep())->run($project);

    (new GenerateImagesStep(new FakeImageClient('', true)))->run($project);

    assert_eq($unclosed, $project->readText('theme/parts/hero.html'));
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_contains('theme/parts/hero.html: authored media source theme:./assets/unsafe-cover.jpg', implode("\n", $warnings));
    assert_contains('pre-cleanup bytes kept', implode("\n", $warnings));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images never persists PNG bytes under a JPEG filename', function () {
    [$project, $tmp] = generate_fixture();
    $png = (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
    // A deliberately non-WPCOM client ignores the requested JPEG MIME.
    $images = new FakeImageClient($png);

    (new GenerateImagesStep($images))->run($project);

    assert_true(!$project->exists('theme/assets/hero.jpg'), 'PNG was not written as .jpg');
    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status'], 'the mismatch is isolated to this image');
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_contains('requested image/jpeg', implode("\n", $warnings));
    assert_contains('detected image/png', implode("\n", $warnings));
    assert_true(
        !str_contains($project->readText('theme/templates/page.html'), 'theme:./assets/hero.jpg'),
        'failed media block removed instead of shipping dead UI',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images isolates a MIME mismatch and preserves its valid sibling', function () {
    [$project, $tmp] = batch_fixture(2);
    $png = (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
    $images = new FakeImageClient('JPEGDATA');
    $images->bytesByPromptSubstring = ['image 0.' => $png];

    (new GenerateImagesStep($images))->run($project);

    $specs = $project->readJson('images.json');
    assert_eq('failed', $specs[0]['status']);
    assert_eq('completed', $specs[1]['status']);
    assert_true(!$project->exists('theme/assets/img-0.jpg'));
    assert_eq(
        'image/jpeg',
        GeminiImage::mimeFromBytes($project->readText('theme/assets/img-1.jpg')),
        'the valid sibling reaches disk in its requested format',
    );
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
        'one bad generated image does not abort the build',
    );
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_contains('images.json[0] / theme/assets/img-0.jpg', implode("\n", $warnings));
    assert_contains('detected image/png', implode("\n", $warnings));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images keeps asset persistence failures fatal', function () {
    [$project, $tmp] = generate_fixture();
    $blockingPath = $project->path('theme/assets/hero.jpg');
    mkdir($blockingPath, 0775, true);

    set_error_handler(static fn (): bool => true);
    try {
        $error = assert_throws(fn () => (new GenerateImagesStep(new FakeImageClient('JPEGDATA')))->run($project));
    } finally {
        restore_error_handler();
    }

    assert_contains('Could not write file', $error->getMessage());
    assert_true(!$project->exists('warnings.json'), 'asset I/O is not mislabeled as generated content');

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

test('generate-images hands every pending image to one pooled batch', function () {
    // Concurrency is bounded by the CLIENT's rolling pool, not by step-level
    // chunks: one generateBatch call carries all 12, so a slow image never
    // blocks a barrier between chunks.
    [$project, $tmp] = batch_fixture(12);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->batches), 'one pooled batch, no step-level chunking');
    assert_eq(12, count($images->batches[0]), 'the batch carries every pending image');

    // All 12 assets written and marked completed.
    $specs = $project->readJson('images.json');
    foreach ($specs as $s) {
        assert_eq('completed', $s['status']);
        assert_true($project->exists('theme/assets/' . $s['filename']), "{$s['filename']} written");
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images persists each completed image as it lands, not only at the end', function () {
    // The step's interruption-survival property, kept without barrier chunks:
    // an image's bytes and completed status reach disk via onResult while the
    // rest of the batch is still generating.
    [$project, $tmp] = batch_fixture(3);
    $images = new FakeImageClient('JPEGDATA');
    $snapshots = [];
    $images->afterEachResult = function (int $i) use ($project, &$snapshots): void {
        $onDisk = $project->readJson('images.json');
        $snapshots[$i] = [
            'completed_on_disk' => count(array_filter($onDisk, fn (array $s): bool => ($s['status'] ?? '') === 'completed')),
            'asset_written'     => $project->exists('theme/assets/img-' . $i . '.jpg'),
        ];
    };

    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, $snapshots[0]['completed_on_disk'], 'first image persisted while two are still pending');
    assert_true($snapshots[0]['asset_written'], 'first asset bytes on disk before the batch ends');
    assert_eq(2, $snapshots[1]['completed_on_disk'], 'second image persisted incrementally');
    assert_eq(3, $snapshots[2]['completed_on_disk'], 'third image persisted incrementally');

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

test('generate-images uses an injected PromptRenderer for the repair prompt', function () {
    [$project, $tmp] = generate_fixture(); // subject: "A bakery at dawn"
    $promptsDir = $tmp . '/prompts';
    mkdir($promptsDir, 0775, true);
    file_put_contents(
        $promptsDir . '/image-prompt-repair.md',
        'CUSTOM TEMPLATE {{subject}} — {{reason}}'
    );
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['A bakery at dawn'];
    $llm = new FakeLlm();
    $llm->queueText('a warm bread display at sunrise');

    (new GenerateImagesStep($images, $llm, 'small-model', new PromptRenderer($promptsDir)))->run($project);

    assert_eq(1, count($llm->calls), 'one rewrite request');
    assert_contains('CUSTOM TEMPLATE A bakery at dawn', $llm->calls[0]['prompt']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images persists each safety-filter repair as it lands', function () {
    [$project, $tmp] = batch_fixture(2);
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['image 0', 'image 1'];
    $llm = new FakeLlm();
    $llm->queueText('a sunlit reading nook');
    $llm->queueText('a quiet garden terrace');
    $repairSnapshots = [];
    $images->afterEachResult = function (int $pos) use ($project, $images, &$repairSnapshots): void {
        if (count($images->batches) !== 2) {
            return; // Ignore the original filtered batch.
        }
        $onDisk = $project->readJson('images.json');
        $repairSnapshots[$pos] = [
            'completed_on_disk' => count(array_filter(
                $onDisk,
                fn (array $spec): bool => ($spec['status'] ?? '') === 'completed'
            )),
            'asset_written' => $project->exists("theme/assets/img-{$pos}.jpg"),
        ];
    };

    (new GenerateImagesStep($images, $llm, 'small-model'))->run($project);

    assert_eq(1, $repairSnapshots[0]['completed_on_disk'], 'first repair persisted before the second landed');
    assert_true($repairSnapshots[0]['asset_written'], 'first repaired asset written during the batch');
    assert_eq(2, $repairSnapshots[1]['completed_on_disk'], 'second repair persisted incrementally');
    assert_true($repairSnapshots[1]['asset_written'], 'second repaired asset written during the batch');

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

test('generate-images degrades, not fails, when an uncollected AI_IMAGE source remains', function () {
    // Degrade-don't-fail: an unresolved source is a real defect, but the build
    // (its sections already paid for) must deliver through with a loud warning
    // rather than abort. The completion stamp is still written.
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText(
        'plugin/pages/home.html',
        '<img src="AI_IMAGE:an unrecognized raw source|ratio:16:9|role:hero" alt="">'
    );
    $images = new FakeImageClient();

    (new GenerateImagesStep($images))->run($project);

    assert_eq([], $images->calls);
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
    );
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_true($warnings !== [], 'unresolved source recorded as a warning');
    assert_contains('AI_IMAGE', implode(' ', $warnings));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images degrades, not fails, when a design src was never collected', function () {
    // The HTML-first silent failure this once threw on: the design invented
    // "hero.jpg", nothing matched the AI_IMAGE marker. Now the build completes
    // with the defect surfaced as a warning (no longer silent, no longer fatal).
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="hero.jpg" alt="A roaster tilting a cooling tray"/></figure><!-- /wp:image -->'
    );
    $images = new FakeImageClient();

    (new GenerateImagesStep($images))->run($project);

    assert_eq([], $images->calls);
    assert_eq(
        ['status' => 'completed'],
        $project->readJson(GenerateImagesStep::COMPLETION_ARTIFACT),
    );
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_true($warnings !== [], 'uncollected design src recorded as a warning');
    assert_contains('hero.jpg', implode(' ', $warnings));

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

test('generate-images ships manifest-listed content images with the plugin', function () {
    [$project, $tmp] = generate_fixture();
    // The hero is referenced by page content (in the plugin manifest); a
    // second, chrome-only image is not.
    $project->writeText('theme/parts/header.html',
        '<img src="theme:./assets/wordmark.png" alt="AI_IMAGE: wordmark | header | flat | square">');
    (new CollectImagesStep())->run($project);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'hero.jpg', 'title' => 'A bakery at dawn'],
    ]]);
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // Content image: generated into the theme AND copied into the plugin.
    assert_true($project->exists('plugin/images/hero.jpg'), 'content image shipped with the plugin');
    assert_eq('image/jpeg', GeminiImage::mimeFromBytes($project->readText('plugin/images/hero.jpg')));
    // Chrome-only image stays theme-only.
    assert_true(!$project->exists('plugin/images/wordmark.png'), 'chrome image not shipped with the plugin');
    assert_true($project->exists('theme/assets/wordmark.png'), 'chrome image in the theme');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images re-run copies an already-completed content image to the plugin', function () {
    [$project, $tmp] = generate_fixture();
    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project); // completes hero.jpg, no manifest yet

    // The manifest appears later (e.g. assemble ran in a newer build) — a
    // re-run must ship the completed asset without regenerating it.
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'hero.jpg', 'title' => 'A bakery at dawn'],
    ]]);
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls), 'completed image not regenerated');
    assert_true($project->exists('plugin/images/hero.jpg'), 'completed content image shipped');

    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * Build a project whose only image carries the given SUBJECT, plus an optional
 * site-wide grade. The grade pass reads both, so the two together are the whole
 * input to the warnings and the request log.
 */
function grade_subject_fixture(string $subject, string $grade, string $file = 'hero.jpg'): array
{
    $tmp = sys_get_temp_dir() . '/builder_gi_grade_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText(
        'theme/parts/hero.html',
        '<!-- wp:image --><figure class="wp-block-image">'
            . '<img src="theme:./assets/' . $file . '" '
            . 'alt="AI_IMAGE: ' . $subject . ' | wide feature image | photorealistic | landscape"/>'
            . '</figure><!-- /wp:image -->'
    );
    (new CollectImagesStep())->run($project);
    if ($grade !== '') {
        $project->writeJson('designDirection.json', [
            'title'       => 'Archivo',
            'description' => 'Photography.',
            'image_grade' => $grade,
        ]);
    }
    return [$project, $tmp];
}

/** The one image transcript this run wrote. */
function grade_image_log(object $project): string
{
    $files = glob($project->logPath('images') . '/*') ?: [];
    return $files === [] ? '' : (string) file_get_contents($files[0]);
}

test('generate-images records a dropped grade clause and what it delivered instead', function () {
    [$project, $tmp] = grade_subject_fixture(
        'A loaf on a linen cloth, fine 35mm grain',
        'clean digital product shots, studio white'
    );

    (new GenerateImagesStep(new FakeImageClient('JPEGDATA')))->run($project);

    // Rung 4: the removal changed delivered output, so it owes an actionable
    // row — the file, what was cut, and the disposition.
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains("images.json 'hero.jpg'", $warnings[0]);
    assert_contains('authored subject clause(s) "fine 35mm grain"', $warnings[0]);
    assert_contains('delivered removed', $warnings[0]);
    assert_contains('prompts/image-generation.md:63', $warnings[0]);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images writes a readable row for a subject longer than the value cap', function () {
    // Warnings::value caps a value at 160 characters. Subjects are specced as
    // 1-3 sentences and the grade tag sits at the end, so reporting the whole
    // authored and delivered subject printed the same truncated head twice —
    // "authored X; delivered X" — with the entire difference cut off.
    $long = 'Portrait of a woman in her forties in a linen work shirt standing beside a tall studio'
        . ' shelf of paper samples and bound specimen books, arms loosely crossed, calm direct gaze'
        . ' slightly off-camera, soft north-facing window light from the left, fine 35mm grain';
    [$project, $tmp] = grade_subject_fixture($long, 'clean digital product shots, studio white');

    (new GenerateImagesStep(new FakeImageClient('JPEGDATA')))->run($project);

    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('"fine 35mm grain"', $warnings[0], 'the clause that was cut survives the cap');
    // The defect: the row must not carry two renderings of a value long enough
    // to truncate, because they come out identical and say nothing.
    assert_true(
        !str_contains($warnings[0], 'Portrait of a woman'),
        'the row reports the clause, not two truncated copies of the whole subject'
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images sends the stripped subject and logs it beside the authored one', function () {
    [$project, $tmp] = grade_subject_fixture(
        'A loaf on a linen cloth, fine 35mm grain',
        'clean digital product shots, studio white'
    );
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // The prompt the endpoint actually received carries the stripped subject.
    assert_contains('A loaf on a linen cloth. Style: photorealistic', $images->calls[0]['prompt']);
    assert_true(
        !str_contains($images->calls[0]['prompt'], 'fine 35mm grain'),
        'the competing clause never reaches the image model'
    );

    // And the transcript says the two differ, rather than showing an authored
    // SUBJECT beside a PROMPT built from a different one.
    $log = grade_image_log($project);
    assert_contains('A loaf on a linen cloth, fine 35mm grain', $log);   // authored, still recorded
    assert_contains('SUBJECT DELIVERED', $log);
    assert_contains('A loaf on a linen cloth', $log);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images reports a grade clause it could not remove and delivers it whole', function () {
    [$project, $tmp] = grade_subject_fixture(
        'A loaf on a studio white sweep',
        'warm Portra 400, visible 35mm grain'
    );
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    // Isolated-loss side: nothing is cut, the scene ships byte-for-byte, and
    // the surviving conflict is still recorded.
    assert_contains('A loaf on a studio white sweep. Style: photorealistic', $images->calls[0]['prompt']);
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_eq(1, count($warnings));
    assert_contains('subject clause "A loaf on a studio white sweep"', $warnings[0]);
    assert_contains('delivered unchanged', $warnings[0]);
    assert_contains('names photographic grade but also names the scene', $warnings[0]);
    // Nothing was rewritten, so the log must not claim a different delivery.
    assert_true(!str_contains(grade_image_log($project), 'SUBJECT DELIVERED'), 'no receipt without a change');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images leaves a transparent asset out of the grade pass entirely', function () {
    [$project, $tmp] = grade_subject_fixture(
        'A badge on studio white, fine 35mm grain',
        'warm Portra 400, visible 35mm grain',
        'badge.png'
    );
    $images = new FakeImageClient('PNGDATA');

    (new GenerateImagesStep($images))->run($project);

    // The isolation clause owns the backdrop and no grade is appended, so
    // there is nothing to compete with and nothing to warn about.
    assert_contains('A badge on studio white, fine 35mm grain', $images->calls[0]['prompt']);
    assert_true(!$project->exists('warnings.json'), 'a bypassed asset writes no grade row');
    assert_true(!str_contains(grade_image_log($project), 'SUBJECT DELIVERED'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images runs no grade pass when the direction committed to no grade', function () {
    [$project, $tmp] = grade_subject_fixture('A loaf on a linen cloth, fine 35mm grain', '');
    $images = new FakeImageClient('JPEGDATA');

    (new GenerateImagesStep($images))->run($project);

    assert_contains('A loaf on a linen cloth, fine 35mm grain', $images->calls[0]['prompt']);
    assert_true(!$project->exists('warnings.json'), 'no grade means no conflict to report');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images records a grade clause cut from a repaired subject', function () {
    // The repair pass hands back a brand new subject after the first batch has
    // already been reported. A rewrite that reintroduces grade wording is
    // stripped before it ships, so it owes the same receipt the authored one
    // does — it was going out with only a log line behind it.
    [$project, $tmp] = grade_subject_fixture('A protester at a barricade', 'clean digital product shots, studio white');
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = ['A protester at a barricade'];
    $llm = new FakeLlm();
    $llm->queueText('A crowd in a public square, fine 35mm grain, catalog-lit');

    (new GenerateImagesStep($images, $llm, 'small-model'))->run($project);

    // The authored subject was clean, so the only rows come from the rewrite.
    $warnings = $project->readJson('warnings.json')['generate-images'] ?? [];
    $rows = implode(' | ', $warnings);
    assert_contains('fine 35mm grain', $rows, 'the clause cut from the rewrite is recorded');
    assert_contains('catalog-lit', $rows);
    assert_contains('delivered removed', $rows);

    // And what actually shipped is the stripped rewrite.
    $repaired = $images->batches[1][0]['prompt'];
    assert_contains('A crowd in a public square', $repaired);
    assert_true(!str_contains($repaired, 'fine 35mm grain'), 'the competing clause never reaches the model');

    exec('rm -rf ' . escapeshellarg($tmp));
});
