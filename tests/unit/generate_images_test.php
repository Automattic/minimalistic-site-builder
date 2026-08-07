<?php
declare(strict_types=1);

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Steps\AssemblePagesStep;
use Automattic\SiteBuild\Steps\CollectImagesStep;
use Automattic\SiteBuild\Steps\CoverContrastStep;
use Automattic\SiteBuild\Steps\FixBlocksStep;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Steps\HeaderHeroStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\GeneratedMarkup;

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

function stage_texture_markup_fixture(string $source, string $surface): string
{
    return '<!-- wp:group {"className":"has-stage-texture-backdrop","backgroundColor":"' . $surface
        . '","textColor":"contrast","style":{"spacing":{"padding":{"top":"7px"}},"background":{'
        . '"backgroundImage":{"url":"' . $source . '"},"backgroundPosition":"0% 0%",'
        . '"backgroundSize":"420px","backgroundRepeat":"repeat","backgroundAttachment":"fixed"}}} -->'
        . '<div class="wp-block-group has-stage-texture-backdrop has-' . $surface
        . '-background-color has-contrast-color" style="--proof:\'a;b\';padding-top:7px;'
        . 'background-image:url(' . $source . ');background-position:0% 0%;background-size:420px;'
        . 'background-repeat:repeat;background-attachment:fixed">'
        . '<!-- wp:paragraph --><p class="keep  spacing">Keep this copy.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}

function unsafe_stage_texture_markup_fixture(string $source, string $surface = 'base'): string
{
    return '<!-- wp:group {"className":"has-stage-texture-backdrop","backgroundColor":"' . $surface
        . '","style":{"background":{"backgroundImage":{"url":"' . $source
        . '"},"backgroundPosition":"0% 0%","backgroundSize":"420px","backgroundRepeat":"repeat",'
        . '"backgroundAttachment":"fixed"}}} --><div class="wp-block-group has-stage-texture-backdrop" '
        . 'style="background-image:url(' . $source . ');background-position:0% 0%;background-size:420px;'
        . 'background-repeat:repeat;background-attachment:fixed">unsafe';
}

function stage_texture_checker_jpeg(string $dark = '#000000', string $light = '#FFFFFF'): string
{
    if (!extension_loaded('imagick')) {
        skip_test('stage texture pixel validation needs Imagick');
    }
    $image = new Imagick();
    $image->newImage(32, 32, new ImagickPixel('#FFFFFF'));
    $iterator = $image->getPixelIterator();
    foreach ($iterator as $rowIndex => $row) {
        foreach ($row as $columnIndex => $pixel) {
            $pixel->setColor((($rowIndex + $columnIndex) % 2) === 0 ? $dark : $light);
        }
        $iterator->syncIterator();
    }
    $image->setImageFormat('jpeg');
    $image->setImageCompressionQuality(100);
    return $image->getImagesBlob();
}

function stage_texture_solid_jpeg(string $color): string
{
    if (!extension_loaded('imagick')) {
        skip_test('stage texture pixel validation needs Imagick');
    }
    $image = new Imagick();
    $image->newImage(32, 32, new ImagickPixel($color));
    $image->setImageFormat('jpeg');
    $image->setImageCompressionQuality(100);
    return $image->getImagesBlob();
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
    $joined = implode("\n", $warnings);
    assert_contains('file="theme/parts/hero.html"', $joined);
    assert_contains('block="unsafe generated media owner"', $joined);
    assert_contains('authored source="theme:./assets/unsafe-cover.jpg"', $joined);
    assert_contains('delivered="pre-cleanup media bytes retained"', $joined);
    assert_contains('disposition=', $joined);

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

test('generate-images refuses completion when an uncollected AI_IMAGE source remains', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText(
        'plugin/pages/home.html',
        '<img src="AI_IMAGE:an unrecognized raw source|ratio:16:9|role:hero" alt="">'
    );
    $images = new FakeImageClient();

    assert_throws(
        fn () => (new GenerateImagesStep($images))->run($project),
        'empty/absent manifests must not bypass the final source gate',
    );
    assert_eq([], $images->calls);
    assert_true(!$project->exists(GenerateImagesStep::COMPLETION_ARTIFACT));

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

test('generate-images synthesizes the stage-texture spec painted after collect-images (BIGR-776)', function () {
    // The default graph order: collect-images writes images.json, THEN
    // HeaderHeroStep paints the canonical texture path onto the header and
    // hero roots. The generator must synthesize the code-owned spec for the
    // reference it finds in markup, generate the tile, and rewrite the URL.
    [$project, $tmp] = generate_fixture();
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'secondary', 'color' => '#F8F8F8'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $texturedHeader = stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'base',
    );
    $project->writeText('theme/parts/header.html', $texturedHeader);
    $texturedHero = stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'secondary',
    );
    $project->writeText('plugin/pages/home.html', $texturedHero);

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    assert_true(
        $project->exists('theme/assets/stage_backdrop-texture.jpg'),
        'the texture tile is generated from the synthesized spec',
    );
    $specs = $project->readJson('images.json');
    $texture = array_values(array_filter(
        $specs,
        static fn (array $spec): bool => ($spec['filename'] ?? '') === 'stage_backdrop-texture.jpg',
    ));
    assert_eq(1, count($texture), 'exactly one synthesized texture spec');
    assert_eq('completed', $texture[0]['status']);
    assert_eq('#F8F8F8', $texture[0]['targetColor'], 'post-assemble tinted hero owns the tile target');
    assert_true(in_array('#000000', $texture[0]['foregroundColors'], true));
    assert_eq(['parts/header.html', 'plugin/pages/home.html'], $texture[0]['sources']);

    $header = $project->readText('theme/parts/header.html');
    assert_contains('/wp-content/themes/demo/assets/stage_backdrop-texture.jpg', $header);
    assert_true(
        !str_contains($header, 'theme:./assets/stage_backdrop-texture.jpg'),
        'no unresolved texture placeholder remains in the header',
    );

    // A re-run with the spec now on file must not duplicate it.
    $rerunImages = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($rerunImages))->run($project);
    $again = array_values(array_filter(
        $project->readJson('images.json'),
        static fn (array $spec): bool => ($spec['filename'] ?? '') === 'stage_backdrop-texture.jpg',
    ));
    assert_eq(1, count($again), 'backstop is idempotent');
    assert_eq(0, count($rerunImages->calls), 'valid completed texture bytes are revalidated, not regenerated');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the stage-texture request carries no pictorial scene guidance (BIGR-776)', function () {
    // The subject alone is the tile's complete render instruction. The
    // page/site context guidance composes into "editorial photograph …
    // negative space" scene direction, which fights the flat tone-on-tone
    // tile and trips the busyness gate — ordinary imagery keeps it, the
    // texture spec must not send any of it.
    [$project, $tmp] = generate_fixture();
    $project->writeJson('siteSpec.json', ['description' => 'A studio selling handmade ceramics.']);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'secondary', 'color' => '#F8F8F8'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'base',
    ));
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'secondary',
    ));

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    $prompts = array_column($images->calls, 'prompt');
    $texture = array_values(array_filter(
        $prompts,
        static fn (string $prompt): bool => str_contains($prompt, 'photographed head-on in perfectly even diffuse light'),
    ));
    assert_eq(1, count($texture), 'exactly one texture generation request');
    foreach (['Purely pictorial', 'Composition:', 'editorial photograph', 'Art direction'] as $sceneClause) {
        assert_true(
            !str_contains($texture[0], $sceneClause),
            "texture prompt carries no scene guidance clause \"{$sceneClause}\"",
        );
    }

    $ordinary = array_values(array_diff($prompts, $texture));
    assert_true($ordinary !== [], 'the fixture also generated ordinary imagery');
    assert_contains('Purely pictorial', $ordinary[0]);
    assert_contains('handmade ceramics', $ordinary[0]);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a rejected stage-texture attempt retries with the next material and delivers quietly (BIGR-776)', function () {
    // Recitation-filter and busyness-gate failures are stochastic per
    // material, so the ladder rotates the code-owned subject instead of
    // resubmitting the same prompt — and a delivered retry must leave no
    // stale "texture rejected" warning behind.
    [$project, $tmp] = generate_fixture();
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'secondary', 'color' => '#F8F8F8'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'base',
    ));
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'secondary',
    ));

    $firstSubject = CollectImagesStep::stageTextureSpec(['parts/header.html'], '#F8F8F8')['subject'];
    $firstMaterial = explode(' photographed', explode('A flat expanse of ', $firstSubject)[1])[0];
    $images = new FakeImageClient('JPEGDATA');
    $images->filterPromptSubstrings = [$firstMaterial];
    (new GenerateImagesStep($images))->run($project);

    $texture = array_values(array_filter(
        $project->readJson('images.json'),
        static fn (array $spec): bool => ($spec['filename'] ?? '') === 'stage_backdrop-texture.jpg',
    ));
    assert_eq('completed', $texture[0]['status'], 'the rotated material delivers');
    assert_true(
        !str_contains((string) $texture[0]['subject'], $firstMaterial),
        'the delivered subject rotated past the filtered material',
    );
    assert_true($project->exists('theme/assets/stage_backdrop-texture.jpg'));
    assert_contains(
        '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg',
        $project->readText('theme/parts/header.html'),
    );
    $warnings = $project->exists('warnings.json')
        ? ($project->readJson('warnings.json')['generate-images'] ?? [])
        : [];
    assert_eq([], $warnings, 'a delivered retry leaves no stale texture warning');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a near-miss stage tile is tone-aligned onto the delivered surface instead of rejected (BIGR-776)', function () {
    [$project, $tmp] = generate_fixture();
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'secondary', 'color' => '#EFE9E0'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'base',
    ));
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'secondary',
    ));

    // Solid #D8D2C8 against the #EFE9E0 target is a max channel drift of 24:
    // past the gate's 20 bound (rejected before alignment existed), inside
    // the 48 alignment bound.
    $images = new FakeImageClient(stage_texture_solid_jpeg('#D8D2C8'));
    (new GenerateImagesStep($images))->run($project);

    $texture = array_values(array_filter(
        $project->readJson('images.json'),
        static fn (array $spec): bool => ($spec['filename'] ?? '') === 'stage_backdrop-texture.jpg',
    ));
    assert_eq('completed', $texture[0]['status'], 'the near-miss tile is aligned and delivered');
    $texturePrompts = array_filter(
        array_column($images->calls, 'prompt'),
        static fn (string $prompt): bool => str_contains($prompt, 'photographed head-on'),
    );
    assert_eq(1, count($texturePrompts), 'alignment delivers on the first attempt, no retry spent');

    $delivered = new Imagick();
    $delivered->readImageBlob($project->readText('theme/assets/stage_backdrop-texture.jpg'));
    $delivered->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    $quantum = (float) (Imagick::getQuantumRange()['quantumRangeLong'] ?? 65535);
    foreach ([Imagick::CHANNEL_RED => 0xEF, Imagick::CHANNEL_GREEN => 0xE9, Imagick::CHANNEL_BLUE => 0xE0] as $channel => $expected) {
        $mean = 255.0 * (float) $delivered->getImageChannelMean($channel)['mean'] / $quantum;
        assert_true(abs($mean - $expected) <= 3, 'delivered tile mean sits on the surface color');
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('borderline contrast headroom skips model attempts and synthesizes directly (BIGR-776)', function () {
    // #6D6D6D against a #F8F8F8 stage is ~4.85:1 — above the 4.5:1 gate
    // bound but inside the headroom band where a generated tile's grain
    // dips below it. No model calls should be spent; the procedural tile
    // (whose grain is tight by construction) delivers.
    [$project, $tmp] = generate_fixture();
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#F8F8F8'],
        ['slug' => 'secondary', 'color' => '#F8F8F8'],
        ['slug' => 'contrast', 'color' => '#6D6D6D'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'base',
    ));
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'secondary',
    ));

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    $texturePrompts = array_filter(
        array_column($images->calls, 'prompt'),
        static fn (string $prompt): bool => str_contains($prompt, 'photographed head-on'),
    );
    assert_eq(0, count($texturePrompts), 'no model attempts spent inside the headroom band');
    $texture = array_values(array_filter(
        $project->readJson('images.json'),
        static fn (array $spec): bool => ($spec['filename'] ?? '') === 'stage_backdrop-texture.jpg',
    ));
    assert_eq('completed', $texture[0]['status'], 'the procedural tile delivers');
    assert_contains('model generation skipped',
        implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('stage-texture foregrounds skip text hosted on its own opaque surface (BIGR-776)', function () {
    // A cream button label on a dark button never touches the texture, yet
    // demanding 4.5:1 against it makes every light texture impossible for
    // the palette. Only text actually sitting on the stage counts.
    [$project, $tmp] = generate_fixture();
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#F8F8F8'],
        ['slug' => 'secondary', 'color' => '#F8F8F8'],
        ['slug' => 'accent', 'color' => '#5A2D0C'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $button = '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button {"backgroundColor":"accent","textColor":"base"} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link has-base-color'
        . ' has-accent-background-color">Shop the kiln</a></div>'
        . '<!-- /wp:button --></div><!-- /wp:buttons -->';
    $textured = str_replace(
        '</div><!-- /wp:group -->',
        $button . '</div><!-- /wp:group -->',
        stage_texture_markup_fixture(Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET, 'base'),
    );
    $project->writeText('theme/parts/header.html', $textured);
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture(
        Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET,
        'secondary',
    ));

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    $texture = array_values(array_filter(
        $project->readJson('images.json'),
        static fn (array $spec): bool => ($spec['filename'] ?? '') === 'stage_backdrop-texture.jpg',
    ));
    assert_true(!in_array('#F8F8F8', $texture[0]['foregroundColors'], true),
        'the button label surface-hosted color is not a stage foreground');
    assert_true(in_array('#000000', $texture[0]['foregroundColors'], true),
        'text actually on the stage still gates the texture');
    assert_eq('completed', $texture[0]['status'], 'the light texture delivers for the light palette');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the real header-hero to fixer and assembly flow delivers a generated stage tile (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_flow_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $theme = ['version' => 3, 'settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'name' => 'Base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111111'],
        ['slug' => 'primary', 'name' => 'Primary', 'color' => '#274C77'],
        ['slug' => 'secondary', 'name' => 'Secondary', 'color' => '#E5E7EB'],
        ['slug' => 'accent', 'name' => 'Accent', 'color' => '#C2410C'],
    ]]]];
    $sections = [[
        'slug' => 'hero',
        'role' => 'hero',
        'layout_archetype' => 'asymmetric-split',
        'background' => 'base',
    ]];
    $pages = [[
        'slug' => 'home',
        'title' => 'Home',
        'front' => true,
        'sections' => $sections,
    ]];
    $blueprint = HeroBlueprint::defaultFor('focal-subject-stage');
    $blueprint['stage_backdrop'] = 'texture';
    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    $project->writeJson('theme/theme.json', $theme);
    $project->writeJson('designDirection.json', [
        'canvas' => 'full-bleed',
        'motion' => 'calm',
        'hero_blueprint' => $blueprint,
    ]);
    $project->writeJson('pages.json', ['pages' => $pages]);
    $project->writeJson('aboveFold.json', AboveFoldContract::resolve(
        $pages,
        $blueprint,
        'full-bleed',
        $theme,
        ['stable_id' => 'stage-flow', 'writing_direction' => 'ltr', 'page_count' => 1],
        ['archetype' => 'minimal-columns', 'surface' => 'base'],
    ));
    // This is the durable result of collect-images, which intentionally runs
    // before HeaderHero paints the code-owned texture contract.
    $project->writeJson('images.json', []);
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
            . '<!-- wp:site-title /--></div><!-- /wp:group -->',
    );
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<!-- wp:group {"backgroundColor":"base","anchor":"hero",'
            . '"className":"hero-composition--focal-subject-stage hero-mobile--stack-media-first",'
            . '"layout":{"type":"constrained"}} -->'
            . '<div id="hero" class="wp-block-group has-base-background-color has-background '
            . 'hero-composition--focal-subject-stage hero-mobile--stack-media-first">'
            . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Exhibit</h1><!-- /wp:heading -->'
            . '</div><!-- /wp:group -->',
    );
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
    );

    (new HeaderHeroStep())->run($project);
    assert_true(GeneratedMarkup::hasStageTextureSavedHtml($project->readText('theme/parts/header.html')));
    assert_true(GeneratedMarkup::hasStageTextureSavedHtml(
        $project->readText('theme/parts/page-home--hero.html'),
    ));

    quietly(fn () => (new FixBlocksStep(new PhpBlockFixer()))->run($project));
    assert_true(
        GeneratedMarkup::hasStageTextureSavedHtml($project->readText('theme/parts/header.html')),
        'the real group serializer must re-assert the trusted saved paint',
    );
    assert_true(GeneratedMarkup::hasStageTextureSavedHtml(
        $project->readText('theme/parts/page-home--hero.html'),
    ));

    (new AssemblePagesStep())->run($project);
    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls));
    $served = '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg';
    assert_true(GeneratedMarkup::hasExactStageTextureContract(
        $project->readText('theme/parts/header.html'),
        $served,
    ));
    assert_true(GeneratedMarkup::hasExactStageTextureContract(
        $project->readText('plugin/pages/home.html'),
        $served,
    ));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images scopes stale stage aliases to safe roots and retains unsafe and ordinary sentinels (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_scope_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $oldA = '/wp-content/themes/old-a/assets/stage_backdrop-texture.jpg';
    $oldB = '/wp-content/themes/old-b/assets/stage_backdrop-texture.jpg';
    $safe = str_replace($oldA, $oldB, stage_texture_markup_fixture($oldA, 'base'));
    $safe = (string) preg_replace('~' . preg_quote($oldB, '~') . '~', $oldA, $safe, 1);
    $safe = str_replace('<div class=', '<div data-proof="' . $oldA . '" class=', $safe);
    $safe = str_replace(
        'Keep this copy.',
        'Keep this copy. ' . $oldA . '<img src="' . $oldA . '" alt="ordinary sentinel"/>',
        $safe,
    );
    $unsafe = unsafe_stage_texture_markup_fixture($oldA);
    $project->writeText('plugin/pages/home.html', $safe . $unsafe);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls));
    $delivered = $project->readText('plugin/pages/home.html');
    $current = '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg';
    assert_eq(2, substr_count($delivered, $current), 'only safe root comment + saved paint are served');
    assert_contains('data-proof="' . $oldA . '"', $delivered);
    assert_contains('Keep this copy. ' . $oldA, $delivered);
    assert_contains('<img src="' . $oldA . '" alt="ordinary sentinel"/>', $delivered);
    assert_contains($unsafe, $delivered, 'unsafe sibling remains byte-for-byte');
    assert_true(!str_contains($delivered, $oldB));
    assert_contains('unsafe block boundary', implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images ignores an attrs-only stage contract and warns without an image call (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_incomplete_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
    ]]]]);
    $old = '/wp-content/themes/old-theme/assets/stage_backdrop-texture.jpg';
    $incomplete = stage_texture_markup_fixture($old, 'base');
    $incomplete = (string) preg_replace('~\sstyle="[^"]*"~', '', $incomplete, 1);
    $project->writeText('plugin/pages/home.html', $incomplete);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls));
    assert_eq($incomplete, $project->readText('plugin/pages/home.html'));
    assert_eq([], $project->readJson('images.json'));
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('pre-canonicalization bytes retained', $warnings);
    assert_contains('unique class and style', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images retains a wrapperless stage marker and warns without an image call (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_wrapperless_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
    ]]]]);
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $wrapperless = '<!-- wp:group {"className":"has-stage-texture-backdrop","backgroundColor":"base",'
        . '"style":{"background":{"backgroundImage":{"url":"' . $source . '"},'
        . '"backgroundPosition":"0% 0%","backgroundSize":"420px","backgroundRepeat":"repeat",'
        . '"backgroundAttachment":"fixed"}}} -->Keep wrapperless copy.<!-- /wp:group -->';
    $project->writeText('plugin/pages/home.html', $wrapperless);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls));
    assert_eq($wrapperless, $project->readText('plugin/pages/home.html'));
    assert_eq([], $project->readJson('images.json'));
    $warning = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains("file='plugin/pages/home.html'", $warning);
    assert_contains("block='stage-texture block 0'", $warning);
    assert_contains('no isolated saved wrapper', $warning);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images removes safe stage paint while retaining an unsafe failed sibling (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_mixed_failure_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'contrast', 'color' => '#111111'],
    ]]]]);
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $unsafe = unsafe_stage_texture_markup_fixture($source);
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture($source, 'base') . $unsafe);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls));
    $delivered = $project->readText('plugin/pages/home.html');
    assert_eq(2, substr_count($delivered, $source), 'only the unsafe comment + saved source remain');
    assert_eq(2, substr_count($delivered, GeneratedMarkup::STAGE_TEXTURE_CLASS));
    assert_contains($unsafe, $delivered);
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('safe stage roots were cleaned independently', $warnings);
    assert_contains("file=\"plugin/pages/home.html\"", $warnings);
    assert_contains('block="unsafe stage-texture root"', $warnings);
    assert_eq(2, substr_count($warnings, 'authored source="' . $source . '"'));
    assert_true(!str_contains(
        $warnings,
        'authored source="/wp-content/themes/demo/assets/stage_backdrop-texture.jpg"',
    ));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('failed stage cleanup keeps an uncleanable child without rolling back its cleanable parent (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_sibling_isolation_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'contrast', 'color' => '#111111'],
    ]]]]);
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $safe = stage_texture_markup_fixture($source, 'base');
    $uncleanable = str_replace(
        '<div class="wp-block-group',
        '<div class="duplicate-class-proof" class="wp-block-group',
        stage_texture_markup_fixture($source, 'base'),
    );
    $nested = (string) preg_replace(
        '~</div><!-- /wp:group -->$~',
        $uncleanable . '</div><!-- /wp:group -->',
        $safe,
        1,
    );
    $project->writeText('plugin/pages/home.html', $nested);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls), 'unresolved hero surface rejects before the image call');
    $delivered = $project->readText('plugin/pages/home.html');
    assert_contains($uncleanable, $delivered, 'only the uncleanable unit retains its pre-cleanup bytes');
    assert_eq(2, substr_count($delivered, $source), 'safe sibling comment and saved sources were removed');
    assert_eq(2, substr_count($delivered, GeneratedMarkup::STAGE_TEXTURE_CLASS));
    assert_eq(2, substr_count($delivered, 'Keep this copy.'), 'both roots retain visible copy');
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('safe stage roots were cleaned independently', $warnings);
    assert_eq(2, substr_count($warnings, 'authored source="' . $source . '"'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images serves nested exact stage roots without overlap rollback (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_nested_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'color' => '#000000'],
    ]]]]);
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $outer = stage_texture_markup_fixture($source, 'base');
    $inner = stage_texture_markup_fixture($source, 'base');
    $nested = (string) preg_replace(
        '~</div><!-- /wp:group -->$~',
        $inner . '</div><!-- /wp:group -->',
        $outer,
        1,
    );
    $project->writeText('plugin/pages/home.html', $nested);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls));
    $delivered = $project->readText('plugin/pages/home.html');
    assert_eq(4, substr_count($delivered, '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg'));
    assert_true(!str_contains($delivered, $source));
    assert_true(!$project->exists('warnings.json'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images removes both nested exact stage roots after tile rejection (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_nested_failure_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    // contrast is close enough to the white stage that the synthesized
    // fallback fails the foreground bound too — rejection must clean up.
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'color' => '#777777'],
    ]]]]);
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $outer = stage_texture_markup_fixture($source, 'base');
    $inner = stage_texture_markup_fixture($source, 'base');
    $nested = (string) preg_replace(
        '~</div><!-- /wp:group -->$~',
        $inner . '</div><!-- /wp:group -->',
        $outer,
        1,
    );
    $project->writeText('plugin/pages/home.html', $nested);

    $images = new FakeImageClient(stage_texture_checker_jpeg());
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls), 'an infeasible palette is caught before any generation');
    $delivered = $project->readText('plugin/pages/home.html');
    assert_true(!str_contains($delivered, $source));
    assert_true(!str_contains($delivered, GeneratedMarkup::STAGE_TEXTURE_CLASS));
    assert_eq(2, substr_count($delivered, 'Keep this copy.'), 'both nested roots retain their copy');
    $warning = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('generated stage texture rejected', $warning);
    assert_true(!str_contains($warning, 'unsafe stage-texture root'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images keeps one stage manifest writer and degrades malformed collisions (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_manifest_owner_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $stage = CollectImagesStep::stageTextureSpec(['parts/header.html'], '#FFFFFF');
    $project->writeJson('images.json', [
        $stage,
        [
            'filename' => basename($source),
            'src' => $source,
            'subject' => 'An ordinary image that must not overwrite the tile',
            'pageContext' => 'card',
            'style' => 'photorealistic',
            'aspectRatio' => 'square',
            'status' => 'pending',
        ],
        $stage,
        'malformed-row',
    ]);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'color' => '#111111'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText('theme/parts/page-home--hero.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Keep sibling.</p><!-- /wp:paragraph -->'
            . '<!-- wp:image {"url":"' . $source . '"} --><figure class="wp-block-image">'
            . '<img src="' . $source . '" alt=""/></figure><!-- /wp:image -->'
            . '</div><!-- /wp:group -->',
    );

    $bytes = stage_texture_solid_jpeg('#FFFFFF');
    $images = new FakeImageClient($bytes);
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls), 'only the single trusted stage writer generates');
    $specs = $project->readJson('images.json');
    assert_eq(1, count($specs));
    assert_true(CollectImagesStep::isStageTextureSpec($specs[0]));
    assert_eq('completed', $specs[0]['status']);
    assert_eq($bytes, $project->readText('theme/assets/stage_backdrop-texture.jpg'));
    $page = $project->readText('plugin/pages/home.html');
    assert_contains('Keep sibling.', $page);
    assert_true(!str_contains($page, 'wp:image'));
    assert_true(!str_contains($page, $source));
    assert_true(GeneratedMarkup::hasExactStageTextureContract(
        $project->readText('theme/parts/header.html'),
        '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg',
    ));
    $warning = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains("block='images.json[1]'", $warning);
    assert_contains("block='images.json[2]'", $warning);
    assert_contains("block='images.json[3]'", $warning);
    assert_contains("block='ordinary reserved media owner'", $warning);
    assert_contains('delivered="ordinary media reference removed"', $warning);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('reserved ordinary manifest cleanup ignores a nested exact stage child (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_nested_media_owner_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $project->writeJson('images.json', [[
        'filename' => basename($source),
        'src' => $source,
        'subject' => 'Ambiguous ordinary manifest row',
        'pageContext' => 'card',
        'style' => 'photorealistic',
        'aspectRatio' => 'square',
        'status' => 'pending',
    ]]);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'color' => '#111111'],
    ]]]]);
    $nestedStage = stage_texture_markup_fixture($source, 'base');
    $cover = '<!-- wp:cover {"dimRatio":40} --><div class="wp-block-cover media-ancestor-proof">'
        . '<span aria-hidden="true" class="wp-block-cover__background has-background-dim-40 has-background-dim"></span>'
        . '<div class="wp-block-cover__inner-container">' . $nestedStage . '</div></div><!-- /wp:cover -->';
    $project->writeText('plugin/pages/home.html', $cover);

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls), 'the nested stage backstop still owns one generated tile');
    $delivered = $project->readText('plugin/pages/home.html');
    assert_contains('media-ancestor-proof', $delivered);
    $document = BlockMarkup::parse($delivered);
    $stageBlocks = [];
    foreach ($document->indices() as $index) {
        $end = $document->endOffset($index);
        if ($document->name($index) !== 'group' || $end === null) {
            continue;
        }
        $block = substr($delivered, $document->openingOffset($index), $end - $document->openingOffset($index));
        if (GeneratedMarkup::hasExactStageTextureContract(
            $block,
            '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg',
        )) {
            $stageBlocks[] = $block;
        }
    }
    assert_eq(1, count($stageBlocks));
    assert_contains(GeneratedMarkup::STAGE_TEXTURE_CLASS, $delivered);
    $warning = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains("block='images.json[0]'", $warning);
    assert_true(!str_contains($warning, "block='ordinary reserved media owner'"));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images repairs a reserved filename on a distinct ordinary source (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_manifest_rename_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $ordinarySource = 'theme:./assets/portrait.jpg';
    $project->writeJson('images.json', [
        CollectImagesStep::stageTextureSpec(['parts/header.html'], '#FFFFFF'),
        [
            'filename' => basename($source),
            'src' => $ordinarySource,
            'subject' => 'A quiet portrait',
            'pageContext' => 'card',
            'style' => 'photorealistic',
            'aspectRatio' => 'portrait',
            'status' => 'completed',
            'url' => '/wp-content/themes/old/assets/stage_backdrop-texture.jpg',
        ],
        [
            'filename' => 'portrait.jpg',
            'src' => 'theme:./assets/other.jpg',
            'subject' => 'A later ordinary owner of portrait.jpg',
            'pageContext' => 'card',
            'style' => 'photorealistic',
            'aspectRatio' => 'square',
            'status' => 'pending',
        ],
    ]);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'contrast', 'color' => '#111111'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText('theme/parts/page-home--hero.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:image {"url":"' . $ordinarySource . '"} --><figure class="wp-block-image">'
            . '<img src="' . $ordinarySource . '" alt="Portrait"/></figure><!-- /wp:image -->',
    );

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(3, count($images->calls), 'renamed completed state is reset and every unique writer generates');
    $specs = $project->readJson('images.json');
    $ordinary = array_values(array_filter(
        $specs,
        static fn (array $spec): bool => !CollectImagesStep::isStageTextureSpec($spec),
    ));
    assert_eq(2, count($ordinary));
    $repaired = array_values(array_filter(
        $ordinary,
        static fn (array $spec): bool => ($spec['src'] ?? null) === 'theme:./assets/portrait.jpg',
    ))[0];
    assert_true($repaired['filename'] !== basename($source));
    assert_true($repaired['filename'] !== 'portrait.jpg', 'later filename owner forces a unique deterministic name');
    assert_eq('completed', $repaired['status']);
    assert_true($project->exists('theme/assets/' . $repaired['filename']));
    assert_true($project->exists('theme/assets/portrait.jpg'));
    assert_contains(
        '/wp-content/themes/demo/assets/' . $repaired['filename'],
        $project->readText('plugin/pages/home.html'),
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images degrades a residual reserved AI collision and persists the filtered manifest (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_collision_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $source = GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $project->writeJson('images.json', [CollectImagesStep::stageTextureSpec(
        ['parts/header.html', 'parts/page-home--hero.html'],
        '#FFFFFF',
    )]);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
    ]]]]);
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText('theme/parts/page-home--hero.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText(
        'theme/parts/content.html',
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Keep sibling.</p><!-- /wp:paragraph -->'
            . '<!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="' . $source . '" '
            . 'alt="AI_IMAGE: Reserved collision | card | photorealistic | portrait"/></figure><!-- /wp:image -->'
            . '</div><!-- /wp:group -->',
    );

    $images = new FakeImageClient(stage_texture_solid_jpeg('#FFFFFF'));
    (new GenerateImagesStep($images))->run($project);

    assert_eq(1, count($images->calls));
    $specs = $project->readJson('images.json');
    assert_eq(1, count($specs));
    assert_true(CollectImagesStep::isStageTextureSpec($specs[0]));
    assert_eq('completed', $specs[0]['status']);
    $served = '/wp-content/themes/demo/assets/stage_backdrop-texture.jpg';
    foreach (['theme/parts/header.html', 'theme/parts/page-home--hero.html'] as $file) {
        assert_true(GeneratedMarkup::hasExactStageTextureContract($project->readText($file), $served));
    }
    $content = $project->readText('theme/parts/content.html');
    assert_contains('Keep sibling.', $content);
    assert_true(!str_contains($content, 'wp:image'));
    assert_true(!str_contains($content, 'AI_IMAGE:'));
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('theme/parts/content.html', $warnings);
    assert_contains('media removed', $warnings);
    assert_true(!str_contains($warnings, "block='stage-texture root'"));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images rejects a busy stage tile and transactionally delivers the solid roots (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    // contrast sits too close to the #808080 hero surface for ANY tile —
    // generated or synthesized — to satisfy the foreground bound, so the
    // texture must degrade to the transactional solid cleanup under test.
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'base', 'color' => '#FFFFFF'],
        ['slug' => 'secondary', 'color' => '#808080'],
        ['slug' => 'contrast', 'color' => '#777777'],
    ]]]]);
    $canonical = Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $served = '/wp-content/themes/old-theme/assets/stage_backdrop-texture.jpg';
    $sibling = '<!-- wp:group {"className":"other-shell","style":{"background":{'
        . '"backgroundImage":{"url":"theme:./assets/other.jpg"},"backgroundPosition":"right top",'
        . '"backgroundSize":"88px","backgroundRepeat":"no-repeat"}}} -->'
        . '<div class="other-shell  keep-spacing" style="background-image:url(theme:./assets/other.jpg);'
        . 'background-position:right top;background-size:88px;background-repeat:no-repeat"></div>'
        . '<!-- /wp:group -->';
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture($canonical, 'base') . $sibling);
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture($served, 'secondary'));

    $images = new FakeImageClient(stage_texture_checker_jpeg());
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls), 'an infeasible palette is caught before any generation');
    $header = $project->readText('theme/parts/header.html');
    $hero = $project->readText('plugin/pages/home.html');
    foreach ([$header, $hero] as $delivered) {
        assert_true(!str_contains($delivered, 'stage_backdrop-texture.jpg'), 'failed source removed');
        assert_true(!str_contains($delivered, 'has-stage-texture-backdrop'), 'failed marker removed');
        assert_contains('Keep this copy.', $delivered, 'root children survive');
        assert_contains('"backgroundColor":', $delivered, 'solid block surface survives');
        assert_contains("--proof:'a;b';padding-top:7px", $delivered, 'unrelated complex inline declarations survive');
    }
    assert_contains($sibling, $header, 'unrelated sibling background bytes survive exactly');
    assert_eq('failed', $project->readJson('images.json')[0]['status']);
    assert_true($project->exists(GenerateImagesStep::COMPLETION_ARTIFACT), 'rejection continues the build');
    $warningsBefore = $project->readJson('warnings.json')['generate-images'] ?? [];
    assert_eq(1, count($warningsBefore));
    assert_contains("path=\"hero_blueprint.stage_backdrop\"", $warningsBefore[0]);
    assert_contains('authored="texture"', $warningsBefore[0]);
    assert_contains('solid where safely isolated', $warningsBefore[0]);

    $before = [$header, $hero];
    $rerun = new FakeImageClient(stage_texture_checker_jpeg());
    (new GenerateImagesStep($rerun))->run($project);
    assert_eq(0, count($rerun->calls), 'an orphan failed stage spec is not retried');
    assert_eq($before, [
        $project->readText('theme/parts/header.html'),
        $project->readText('plugin/pages/home.html'),
    ], 'cleanup reaches a fixed point');
    assert_eq($warningsBefore, $project->readJson('warnings.json')['generate-images'] ?? []);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images revalidates a completed served stage asset before trusting it (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'secondary', 'color' => '#C00000'],
    ]]]]);
    $served = '/wp-content/themes/old-theme/assets/stage_backdrop-texture.jpg';
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture($served, 'secondary'));
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture($served, 'base'));
    $project->writeText(
        'theme/assets/stage_backdrop-texture.jpg',
        stage_texture_checker_jpeg('#800000', '#FF0000'),
    );

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    assert_eq(2, count($images->calls), 'rejected cached bytes send the retry ladder back to generation');
    $delivered = $project->readJson('images.json')[0];
    assert_eq('completed', $delivered['status'], 'the synthesized fallback delivers after model attempts fail');
    $warning = implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []);
    assert_contains('code-synthesized', $warning);
    assert_contains('drifted too far from target', $warning, 'the last model failure stays on record');
    assert_contains('/wp-content/themes/demo/assets/stage_backdrop-texture.jpg',
        $project->readText('plugin/pages/home.html'), 'a copied project receives its current theme slug');
    assert_true(!str_contains($project->readText('plugin/pages/home.html'), '/old-theme/'));

    // The synthesized tile is a trusted completed asset: a further re-run
    // revalidates the cached bytes and does not regenerate.
    $again = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($again))->run($project);
    assert_eq(0, count($again->calls), 'valid cached synthesized bytes are revalidated, not regenerated');
    assert_eq('completed', $project->readJson('images.json')[0]['status']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images degrades an unresolved textured hero target without an image call (BIGR-776)', function () {
    $tmp = sys_get_temp_dir() . '/builder_gi_stage_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('images.json', []);
    $project->writeJson('theme/theme.json', ['settings' => ['color' => ['palette' => [
        ['slug' => 'contrast', 'color' => '#111111'],
    ]]]]);
    $source = Automattic\SiteBuild\Units\GeneratedMarkup::STAGE_TEXTURE_ASSET;
    $project->writeText('theme/parts/header.html', stage_texture_markup_fixture($source, 'base'));
    $project->writeText('plugin/pages/home.html', stage_texture_markup_fixture($source, 'missing-tone'));

    $images = new FakeImageClient('JPEGDATA');
    (new GenerateImagesStep($images))->run($project);

    assert_eq(0, count($images->calls));
    assert_eq('failed', $project->readJson('images.json')[0]['status']);
    assert_true(!str_contains($project->readText('plugin/pages/home.html'), 'stage_backdrop-texture.jpg'));
    assert_contains('no single resolvable delivered hero surface color',
        implode("\n", $project->readJson('warnings.json')['generate-images'] ?? []));

    exec('rm -rf ' . escapeshellarg($tmp));
});
