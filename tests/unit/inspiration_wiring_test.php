<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\VisionUrlAnalyzer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SiteBuilder;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Steps\InspirationStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\WpcomUrlAnalyzer;

test('inspiration sits between prompt refinement and its default consumers', function () {
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $composition = StepComposition::default(
            llm: new FakeLlm(),
            renderer: new PromptRenderer(Package::promptsDir()),
        );
        $ids = array_map(static fn (object $step): string => $step->id(), $composition->steps());

        $refine = array_search('refine-prompt', $ids, true);
        $inspiration = array_search('inspiration', $ids, true);
        $siteSpec = array_search('site-spec', $ids, true);
        $transform = array_search('transform-site', $ids, true);

        assert_true($refine !== false, 'refine-prompt must remain in the default composition');
        assert_true($inspiration !== false, 'inspiration must be in the default composition');
        assert_true($siteSpec !== false, 'site-spec must remain in the default composition');
        assert_true($transform !== false, 'transform-site must remain in the default composition');
        assert_true($refine < $inspiration, 'inspiration must run after refine-prompt');
        assert_true($inspiration < $siteSpec, 'inspiration must run before site-spec');
        assert_true($inspiration < $transform, 'inspiration must run before transform-site');
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
    }
});

test('default composition validates the inspiration artifact declaration', function () {
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    try {
        $composition = StepComposition::default(
            llm: new FakeLlm(),
            renderer: new PromptRenderer(Package::promptsDir()),
        );
        StepGraph::validate($composition->steps(), $composition->seeds());

        $steps = array_values(array_filter(
            $composition->steps(),
            static fn (object $step): bool => $step->id() === 'inspiration',
        ));
        assert_eq(1, count($steps), 'default composition must contain one inspiration step');
        assert_eq(
            [InspirationStep::FILE, 'warnings.json'],
            $steps[0]->declaration()->writes,
        );

        foreach ($composition->steps() as $step) {
            if ($step->id() === 'inspiration') {
                break;
            }
            assert_eq(
                false,
                in_array(InspirationStep::FILE, $step->declaration()->writes, true),
                "{$step->id()} must not claim the inspiration artifact first",
            );
        }
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
    }
});

test('production builder factory carries the configured URL analyzer', function () {
    $previous = getenv('WPCOM_API_TOKEN');
    $choice = getenv('INSPIRATION_ANALYZER');
    putenv('WPCOM_API_TOKEN=unit-test-token');
    $property = new ReflectionProperty(SiteBuilder::class, 'urlAnalyzer');

    try {
        // Screenshot + vision is the CLI default: a token alone does not make
        // the remote endpoint reachable.
        putenv('INSPIRATION_ANALYZER');
        assert_true(
            $property->getValue(make_site_builder(new FakeLlm())) instanceof VisionUrlAnalyzer,
            'production factory must default to the local analyzer',
        );

        putenv('INSPIRATION_ANALYZER=wpcom');
        assert_true(
            $property->getValue(make_site_builder(new FakeLlm())) instanceof WpcomUrlAnalyzer,
            'INSPIRATION_ANALYZER=wpcom must select the describe endpoint',
        );

        putenv('INSPIRATION_ANALYZER=off');
        assert_eq(null, $property->getValue(make_site_builder(new FakeLlm())));
    } finally {
        $previous === false
            ? putenv('WPCOM_API_TOKEN')
            : putenv('WPCOM_API_TOKEN=' . $previous);
        $choice === false
            ? putenv('INSPIRATION_ANALYZER')
            : putenv('INSPIRATION_ANALYZER=' . $choice);
    }
});

test('ordinary no-URL build makes the declared inspiration artifact directly readable', function () {
    $legacy = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY');
    $tmp = sys_get_temp_dir() . '/builder_empty_inspiration_' . uniqid();

    try {
        $llm = new FakeLlm();
        $llm->queueText('A neighborhood bakery in Lisbon');
        $builder = new SiteBuilder(
            llm: $llm,
            promptsDir: Package::promptsDir(),
            outputRoot: $tmp,
            blockFixer: BlockFixers::default(),
        );
        $project = $builder->createProject('A neighborhood bakery in Lisbon', 'no-reference');

        $builder->pipeline()->runThrough($project, 'inspiration');

        assert_eq(
            ['urls' => [], 'references' => []],
            $project->readJson(InspirationStep::FILE),
        );
        assert_eq(false, $project->exists('warnings.json'));
    } finally {
        remove_tree($tmp);
        $legacy === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $legacy);
    }
});

test('missing WPCOM token degrades URL inspiration to an artifact and warning', function () {
    $token = getenv('WPCOM_API_TOKEN');
    $legacy = getenv('SITE_BUILD_LEGACY');
    $analyzer = getenv('INSPIRATION_ANALYZER');
    putenv('WPCOM_API_TOKEN');
    putenv('SITE_BUILD_LEGACY');
    // The local analyzer is the default now, so reaching the no-analyzer
    // degrade path means explicitly asking for the wpcom one.
    putenv('INSPIRATION_ANALYZER=wpcom');
    $tmp = sys_get_temp_dir() . '/builder_inspiration_wiring_' . uniqid();

    try {
        $llm = new FakeLlm();
        assert_eq(null, make_url_analyzer($llm));

        $llm->queueText('A candy shop like gumroad.com');
        $builder = new SiteBuilder(
            llm: $llm,
            promptsDir: Package::promptsDir(),
            outputRoot: $tmp,
            blockFixer: BlockFixers::default(),
            urlAnalyzer: make_url_analyzer($llm),
        );
        $project = $builder->createProject('A candy shop like gumroad.com', 'missing-token');

        $builder->pipeline()->runThrough($project, 'inspiration');

        assert_eq([
            'urls' => ['https://gumroad.com'],
            'references' => [],
        ], $project->readJson(InspirationStep::FILE));
        $warnings = $project->readJson('warnings.json')['inspiration'] ?? [];
        assert_eq(1, count($warnings));
        assert_contains('https://gumroad.com', $warnings[0]);
        assert_contains('transport_error', $warnings[0]);
        assert_contains('no reference-site analyzer is configured', $warnings[0]);
    } finally {
        remove_tree($tmp);
        $token === false
            ? putenv('WPCOM_API_TOKEN')
            : putenv('WPCOM_API_TOKEN=' . $token);
        $legacy === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $legacy);
        $analyzer === false
            ? putenv('INSPIRATION_ANALYZER')
            : putenv('INSPIRATION_ANALYZER=' . $analyzer);
    }
});

test('createProject records explicit inspiration URLs and preserves seeded URLs for an empty input', function () {
    $tmp = sys_get_temp_dir() . '/builder_inspiration_input_' . uniqid();
    $builder = new SiteBuilder(
        llm: new FakeLlm(),
        promptsDir: Package::promptsDir(),
        outputRoot: $tmp,
        blockFixer: BlockFixers::default(),
    );

    try {
        $project = $builder->createProject(
            prompt: 'A candy shop',
            slug: 'explicit-urls',
            inspirationUrls: ['https://a.com', 'https://b.org'],
        );
        assert_eq(
            ['https://a.com', 'https://b.org'],
            $project->readJson('meta.json')['inspiration_urls'],
        );

        $fresh = $builder->createProject('A cafe', 'empty-urls', inspirationUrls: []);
        assert_eq(false, array_key_exists('inspiration_urls', $fresh->readJson('meta.json')));

        $seeded = $builder->store()->create('seeded-urls');
        $seeded->writeJson('meta.json', ['inspiration_urls' => ['https://seeded.example']]);
        $project = $builder->createProject('A seeded cafe', 'seeded-urls', inspirationUrls: []);
        assert_eq(
            ['https://seeded.example'],
            $project->readJson('meta.json')['inspiration_urls'],
        );
    } finally {
        remove_tree($tmp);
    }
});
