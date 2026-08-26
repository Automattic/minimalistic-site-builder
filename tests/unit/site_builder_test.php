<?php
declare(strict_types=1);

use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\Steps\HomepageDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * SiteBuilder facade: assembles the default pipeline from injected deps and
 * seeds projects the same way bin/build.php does.
 */

test('SiteBuilder pipeline exposes the default blocks step order and stop ids', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $previous = getenv('SITE_BUILD_GRAPH');
    // Env::get falls back to the .env map bootstrap.php loads, so clearing the
    // process env alone leaves a developer's SITE_BUILD_GRAPH=html-first in force.
    putenv('SITE_BUILD_GRAPH=blocks');
    try {
        $builder = make_test_builder(new FakeLlm(), $tmp);

        assert_eq([
            'scaffold-theme', 'scaffold-plugin', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
            'theme-json+page-plan', 'reconcile-palette', 'sections', 'section-rhythm', 'copy-dedupe',
            // normalize-layout MUST precede contrast-fix and motion-sanity: the
            // attribute repair can activate previously-inert color/motion
            // attributes, which those policy passes must be able to see.
            'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks',
            'assemble-pages', 'page-styles', 'custom-motion', 'bundle-fonts', 'fonts-php', 'extract-patterns', 'finalize-theme', 'theme-screenshot', 'validate-theme',
        ], $builder->pipeline()->stepIds());
        assert_true(in_array('site-spec', $builder->pipeline()->stopIds(), true));
        assert_true(in_array('theme-json', $builder->pipeline()->stopIds(), true));
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SITE_BUILD_GRAPH=html-first gives the HTML-first order with the blocks fallback wrapper', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_html_first_' . uniqid();
    $previous = getenv('SITE_BUILD_GRAPH');
    putenv('SITE_BUILD_GRAPH=html-first');
    try {
        $builder = make_test_builder(new FakeLlm(), $tmp);
        $pipeline = $builder->pipeline();

        assert_eq([
            'scaffold-theme', 'scaffold-plugin', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
            'design-preview', 'theme-json', 'inner-pages-design', 'splice-home-design', 'assign-image-sources', 'transform-site', 'resolve-nav-links', 'section-rhythm', 'section-layout',
            'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks', 'assemble-pages', 'fix-pages', 'page-styles', 'custom-motion',
            'fonts-php', 'extract-patterns', 'finalize-theme', 'theme-screenshot', 'validate-theme',
        ], $pipeline->stepIds());
        // Only HTML-first has a design document that can fail, so only it is
        // wrapped for the runtime reroute onto the blocks tail.
        assert_true($pipeline instanceof \Automattic\SiteBuild\FallbackBuildPipeline);
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder createProject uses a free random slug when slug is null', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);

    $project = $builder->createProject('a test cafe');
    $meta = $project->readJson('meta.json');

    assert_eq('a test cafe', $meta['prompt']);
    assert_eq($project->slug(), $meta['provisional_slug']);
    assert_true(isset($meta['created_at']) && $meta['created_at'] !== '');
    // Random slugs are adjective-noun, not a slugify of the full prompt.
    assert_true($project->slug() !== 'a-test-cafe', 'must not slugify the prompt');
    assert_true(is_dir($project->path()));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder createProject respects an explicit slug and merges meta', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);

    $pre = $builder->store()->create('fixed-slug');
    $pre->writeJson('meta.json', ['demo_source' => 'unit-test']);

    $project = $builder->createProject('prompt text', 'fixed-slug');
    $meta = $project->readJson('meta.json');

    assert_eq('fixed-slug', $project->slug());
    assert_eq('prompt text', $meta['prompt']);
    assert_eq('unit-test', $meta['demo_source'], 'pre-seeded meta must survive merge');
    assert_eq('fixed-slug', $meta['provisional_slug']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder createProject records a fixed page list only when given', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);

    $project = $builder->createProject('a test cafe', 'with-pages', true, ['Home', 'Menu']);
    $meta = $project->readJson('meta.json');
    assert_eq(['Home', 'Menu'], $meta['pages']);
    assert_eq(true, $meta['multi_page']);

    // No list -> no `pages` key written, so a pre-seeded one survives the
    // merge (a host whose site spec already names its pages).
    $pre = $builder->store()->create('pre-seeded');
    $pre->writeJson('meta.json', ['pages' => ['Home', 'About']]);
    $project = $builder->createProject('a test cafe', 'pre-seeded', true);
    assert_eq(['Home', 'About'], $project->readJson('meta.json')['pages']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder accepts a host-supplied siteSpec and preserves its page tree by default', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);
    $siteSpec = [
        'name' => 'Host Cafe',
        'language' => 'en',
        'pages' => [
            ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome'],
            ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'Food and drinks'],
        ],
    ];

    $project = $builder->createProject(
        prompt: 'A cafe website',
        slug: 'host-cafe',
        siteSpec: $siteSpec,
    );
    $meta = $project->readJson('meta.json');

    assert_eq($siteSpec, $meta['site_spec']);
    assert_eq(true, $meta['multi_page'], 'an omitted scope must preserve a supplied page tree');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder fixed pages override a host-supplied siteSpec tree', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $llm = new FakeLlm();
    $builder = make_test_builder($llm, $tmp);
    $project = $builder->createProject(
        prompt: 'A cafe website',
        slug: 'fixed-host-pages',
        pages: ['Home', 'Contact'],
        siteSpec: [
            'name' => 'Host Cafe',
            'language' => 'en',
            'pages' => [
                ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome'],
                ['title' => 'Menu', 'slug' => 'menu', 'purpose' => 'Show the menu'],
            ],
        ],
    );

    (new \Automattic\SiteBuild\Steps\SiteSpecStep(
        $llm,
        new \Automattic\SiteBuild\PromptRenderer(Package::promptsDir()),
    ))->run($project);

    assert_eq(0, $llm->completeJsonCalls);
    assert_eq(['home', 'contact'], array_column($project->readJson('siteSpec.json')['pages'], 'slug'));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder supplied siteSpec bypasses the site-spec LLM in the default pipeline', function () {
    $llm = new FakeLlm();
    $llm->queueText('A refined brief for the host-provided cafe.'); // refine-prompt only
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder($llm, $tmp);

    $project = $builder->createProject(
        prompt: 'A cafe website',
        slug: 'provided-cafe',
        siteSpec: [
            'name' => 'Provided Cafe',
            'language' => 'en',
            'pages' => [
                ['title' => 'Home', 'slug' => 'home', 'purpose' => 'Welcome'],
                ['title' => 'Visit', 'slug' => 'visit', 'purpose' => 'Hours and location'],
            ],
        ],
    );
    $builder->pipeline()->runThrough($project, 'site-spec');

    assert_eq(1, $llm->completeCalls, 'refine-prompt still consumes the user prompt');
    assert_eq(0, $llm->completeJsonCalls, 'site-spec candidate generation is bypassed');
    assert_eq('Provided Cafe', $project->readJson('siteSpec.json')['name']);
    assert_eq(2, count($project->readJson('siteSpec.json')['pages']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder explicit single-page scope still overrides a supplied siteSpec tree', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);
    $project = $builder->createProject(
        prompt: 'A cafe website',
        slug: 'one-page-host-cafe',
        multiPage: false,
        siteSpec: [
            'name' => 'Host Cafe',
            'language' => 'en',
            'pages' => [
                ['title' => 'Home', 'slug' => 'home', 'children' => []],
                ['title' => 'Menu', 'slug' => 'menu', 'children' => []],
            ],
        ],
    );

    (new \Automattic\SiteBuild\Steps\SiteSpecStep(
        new FakeLlm(),
        new \Automattic\SiteBuild\PromptRenderer(Package::promptsDir()),
    ))->run($project);

    assert_eq(false, $project->readJson('meta.json')['multi_page']);
    assert_eq(1, count($project->readJson('siteSpec.json')['pages']));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder runs through site-spec via injected FakeLlm', function () {
    $llm = new FakeLlm();
    // refine-prompt (text), then site-spec (json) — same order as the integration harness
    $llm->queueText('A cozy neighborhood bakery selling artisan bread and pastries.');
    $llm->queueJson([
        'name' => 'Test Cafe', 'slug' => 'test-cafe',
        'title' => 'Test Cafe', 'description' => 'A test cafe',
        'site_type' => 'cafe', 'topic' => 'coffee', 'area' => 'cafe',
        'audience' => 'locals', 'visual_vibe' => 'warm',
        'language' => 'en', 'persona_name' => '',
        'email_domain' => 'testcafe.example', 'invented' => ['name'],
        'sections' => ['Hero', 'About'],
    ]);

    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder($llm, $tmp);

    $project = $builder->createProject('a test cafe', 'test-cafe');
    $builder->pipeline()->runThrough($project, 'site-spec');

    assert_true($project->exists('siteSpec.json'), 'ran through site-spec to disk');
    assert_eq('Test Cafe', $project->readJson('siteSpec.json')['name']);
    assert_true($project->exists('theme/style.css'), 'scaffold-theme ran first');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder accepts a retired homepage model override without restoring the step', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp, models: ['homepage-design' => 'claude-haiku-4-5']);
    assert_true(!in_array('homepage-design', $builder->pipeline()->stepIds(), true));
    assert_true(class_exists(HomepageDesignStep::class), 'retired implementation remains available and tested');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder validates and seeds caller-owned hero constraints and writing direction', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);
    $project = $builder->createProject(
        'a test cafe',
        'structured-inputs',
        designConstraints: [
            'hero_canvas' => 'FRAMED',
            'allowed_hero_media_modes' => ['cover-image', 'foreground-image', 'cover-image'],
            'max_hero_images' => 1,
            'hero_copy_capacity' => 'standard',
        ],
        writingDirection: 'RTL',
    );
    $meta = $project->readJson('meta.json');
    assert_eq([
        'hero_canvas' => 'framed',
        'allowed_hero_media_modes' => ['cover-image', 'foreground-image'],
        'max_hero_images' => 1,
        'hero_copy_capacity' => 'standard',
    ], $meta['design_constraints']);
    assert_eq('rtl', $meta['writing_direction']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder invalid structured inputs fail before creating a project directory', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);
    assert_throws(fn () => $builder->createProject(
        'a test cafe',
        'invalid-inputs',
        designConstraints: ['allowed_hero_media_modes' => ['none'], 'hero_copy_capacity' => 'standard'],
    ));
    assert_true(!is_dir($tmp . '/invalid-inputs'));

    assert_throws(fn () => $builder->createProject(
        'a test cafe',
        'invalid-direction',
        writingDirection: 'auto',
    ));
    assert_true(!is_dir($tmp . '/invalid-direction'));

    assert_throws(fn () => $builder->createProject(
        'a test cafe',
        'invalid-graph',
        graph: 'not-a-graph',
    ));
    assert_true(!is_dir($tmp . '/invalid-graph'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('SiteBuilder leaves pre-seeded structured inputs untouched when optional arguments are absent', function () {
    $tmp = sys_get_temp_dir() . '/builder_sb_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);
    $pre = $builder->store()->create('pre-seeded-hero');
    $pre->writeJson('meta.json', [
        'design_constraints' => ['max_hero_images' => 0],
        'writing_direction' => 'rtl',
    ]);
    $project = $builder->createProject('a test cafe', 'pre-seeded-hero');
    $meta = $project->readJson('meta.json');
    assert_eq(['max_hero_images' => 0], $meta['design_constraints']);
    assert_eq('rtl', $meta['writing_direction']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('createProject rejects pages when multiPage is explicitly false', function () {
    $tmp = sys_get_temp_dir() . '/builder_guard_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);

    assert_throws(static fn () => $builder->createProject(
        prompt: 'A bakery',
        multiPage: false,
        pages: [['title' => 'About', 'slug' => 'about', 'purpose' => 'About us']],
    ));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('createProject rejects a site spec that cannot serialize to JSON', function () {
    $tmp = sys_get_temp_dir() . '/builder_guard_' . uniqid();
    $builder = make_test_builder(new FakeLlm(), $tmp);

    assert_throws(static fn () => $builder->createProject(
        prompt: 'A bakery',
        siteSpec: ['bytes' => "\xB1\x31"],
    ));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('createProject records which graph built the project', function () {
    // --from reads this back to resume on the same graph, so a wrong or missing
    // value silently reroutes a resume through artifacts nobody wrote.
    $tmp = sys_get_temp_dir() . '/builder_graph_' . uniqid();
    $previous = getenv('SITE_BUILD_GRAPH');
    try {
        $builder = make_test_builder(new FakeLlm(), $tmp);

        putenv('SITE_BUILD_GRAPH=blocks');
        $blocks = $builder->createProject(prompt: 'a bakery', slug: 'rec-blocks');
        assert_eq('blocks', $blocks->readJson('meta.json')['graph'] ?? null);

        putenv('SITE_BUILD_GRAPH=html-first');
        $html = $builder->createProject(prompt: 'a bakery', slug: 'rec-html');
        assert_eq('html-first', $html->readJson('meta.json')['graph'] ?? null);
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('an explicit graph beats the env, so a fixed-composition host records the truth', function () {
    // A host that hands pipeline() its own StepComposition never consults the
    // env key, so deriving the record from it would file the wrong graph.
    $tmp = sys_get_temp_dir() . '/builder_graph_explicit_' . uniqid();
    $previous = getenv('SITE_BUILD_GRAPH');
    try {
        putenv('SITE_BUILD_GRAPH=blocks');
        $builder = make_test_builder(new FakeLlm(), $tmp);

        $project = $builder->createProject(prompt: 'a bakery', slug: 'rec-explicit', graph: 'html-first');
        assert_eq('html-first', $project->readJson('meta.json')['graph'] ?? null);
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }

    exec('rm -rf ' . escapeshellarg($tmp));
});
