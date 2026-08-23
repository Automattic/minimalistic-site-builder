<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Tests\FakeLlm;

/**
 * @return array{llm: FakeLlm, renderer: PromptRenderer, blockFixer: BlockFixer}
 */
function composition_deps(): array
{
    $fixer = new class implements BlockFixer {
        public function fix(string $themeDir): string
        {
            return '[fix-templates] noop';
        }
    };

    return [
        'llm' => new FakeLlm(),
        'renderer' => new PromptRenderer(Package::promptsDir()),
        'blockFixer' => $fixer,
    ];
}

test('postImages names the phase every image entry point has to run', function () {
    $images = new class implements Step {
        public function id(): string { return 'generate-images'; }
        public function label(): string { return 'Generate images'; }
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration(id: $this->id(), label: $this->label(), reads: [], writes: []);
        }
        public function run(Project $project): void {}
    };

    // bin/build.php and bin/images.php both run this list, so the screenshot
    // cannot be added to one and forgotten in the other — and a host that
    // generates images mirrors one name instead of inferring the set.
    assert_eq(
        ['generate-images', 'theme-screenshot', 'cover-contrast', 'extract-patterns'],
        array_map(static fn (Step $s) => $s->id(), StepComposition::postImages($images)),
    );
});

test('StepComposition htmlFirst matches the HTML-first step order and validates', function () {
    $d = composition_deps();
    $c = StepComposition::htmlFirst(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    );
    $steps = $c->steps();
    assert_eq([
        'scaffold-theme', 'scaffold-plugin', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'design-preview', 'theme-json', 'inner-pages-design', 'splice-home-design', 'assign-image-sources', 'transform-site', 'resolve-nav-links', 'section-rhythm', 'section-layout',
        'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks',
        'assemble-pages', 'fix-pages', 'page-styles', 'custom-motion', 'fonts-php', 'extract-patterns', 'finalize-theme', 'theme-screenshot', 'validate-theme',
    ], array_map(static fn (Step $s) => $s->id(), $steps));
    StepGraph::validate($steps, $c->seeds());

    $byId = [];
    foreach ($steps as $index => $step) {
        $byId[$step->id()] = ['index' => $index, 'declaration' => $step->declaration()];
    }
    assert_true(!isset($byId['homepage-design']), 'retired homepage generator is absent');
    $fontsPhp = $steps[array_search('fonts-php', array_map(static fn (Step $s) => $s->id(), $steps), true)];
    $htmlFirstMode = new ReflectionProperty($fontsPhp, 'htmlFirst');
    assert_true($htmlFirstMode->getValue($fontsPhp), 'HTML-first composition preserves design fallback fonts');
    assert_true(in_array('design/site.css', $byId['design-preview']['declaration']->writes, true));
    assert_eq(
        ['meta.json', 'siteSpec.json', 'designDirection.json', 'design/site.css', 'design/preview.html'],
        $byId['inner-pages-design']['declaration']->reads,
    );
    assert_true(in_array('design/home.html', $byId['splice-home-design']['declaration']->writes, true));
    assert_true($byId['design-preview']['index'] < $byId['theme-json']['index']);
    assert_true($byId['theme-json']['index'] < $byId['inner-pages-design']['index']);
    assert_true($byId['inner-pages-design']['index'] < $byId['splice-home-design']['index']);
    assert_true($byId['splice-home-design']['index'] < $byId['assign-image-sources']['index']);
    assert_true($byId['splice-home-design']['index'] < $byId['transform-site']['index']);
    assert_eq($byId['section-rhythm']['index'] + 1, $byId['section-layout']['index']);
    assert_eq($byId['section-layout']['index'] + 1, $byId['collect-images']['index']);
});

test('StepComposition default is the full blocks graph byte-for-byte', function () {
    $previous = getenv('SITE_BUILD_HTML_FIRST');
    // Env::get falls back to the .env map bootstrap.php loads, so clearing the
    // process env alone leaves a developer's SITE_BUILD_HTML_FIRST=1 in force.
    putenv('SITE_BUILD_HTML_FIRST=0');
    try {
        $d = composition_deps();
        $ids = array_map(
            static fn (Step $step): string => $step->id(),
            StepComposition::default(
                llm: $d['llm'],
                renderer: $d['renderer'],
                blockFixer: $d['blockFixer'],
            )->steps(),
        );

        assert_eq([
            'scaffold-theme', 'scaffold-plugin', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
            'theme-json+page-plan', 'reconcile-palette', 'sections', 'section-rhythm', 'copy-dedupe',
            'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks',
            'assemble-pages', 'page-styles', 'custom-motion', 'bundle-fonts', 'fonts-php', 'extract-patterns', 'finalize-theme', 'theme-screenshot', 'validate-theme',
        ], $ids);
        assert_true(!in_array('homepage-design', $ids, true));
        assert_true(!in_array('transform-site', $ids, true));
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_HTML_FIRST')
            : putenv('SITE_BUILD_HTML_FIRST=' . $previous);
    }
});

test('the fidelity walk needs no graph position of its own', function () {
    // It runs inside validate-theme, which already sits one line later, is
    // already the documented non-gating advisory pass, and already reads the
    // artifacts these checks read.
    $d = composition_deps();
    $c = StepComposition::blocksTail(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    );
    $ids = array_map(static fn (Step $step): string => $step->id(), $c->steps());

    assert_eq(['theme-screenshot', 'validate-theme'], array_slice($ids, -2));
    assert_true(!in_array('direction-fidelity', $ids, true), 'no separate step exists');
    StepGraph::validate($c->steps(), $c->seeds());
});

test('SITE_BUILD_HTML_FIRST=1 routes the default composition to the HTML-first graph', function () {
    $previous = getenv('SITE_BUILD_HTML_FIRST');
    putenv('SITE_BUILD_HTML_FIRST=1');
    try {
        $d = composition_deps();
        $ids = array_map(
            static fn (Step $step): string => $step->id(),
            StepComposition::default(
                llm: $d['llm'],
                renderer: $d['renderer'],
                blockFixer: $d['blockFixer'],
            )->steps(),
        );

        assert_true(StepComposition::htmlFirstSelected());
        assert_true(in_array('transform-site', $ids, true));
        assert_true(!in_array('sections', $ids, true));
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_HTML_FIRST')
            : putenv('SITE_BUILD_HTML_FIRST=' . $previous);
    }
});

test('StepComposition default uses the bundled PHP fixer when none is injected', function () {
    $d = composition_deps();
    $composition = StepComposition::default(
        llm: $d['llm'],
        renderer: $d['renderer'],
    );

    assert_true(in_array('fix-blocks', array_map(
        static fn (Step $step): string => $step->id(),
        $composition->steps(),
    ), true));
});

test('StepComposition without removes a top-level step and still validates', function () {
    $d = composition_deps();
    $c = StepComposition::default(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    )->without('custom-motion');
    $ids = array_map(static fn (Step $s) => $s->id(), $c->steps());
    assert_true(!in_array('custom-motion', $ids, true));
});

test('StepComposition insertAfter inserts a host step', function () {
    $d = composition_deps();
    $extra = new class implements Step {
        public function id(): string
        {
            return 'host-marker';
        }

        public function label(): string
        {
            return 'Host';
        }

        public function run(Project $project): void {}

        public function declaration(): StepDeclaration
        {
            return new StepDeclaration('host-marker', 'Host', ['meta.json'], ['host-marker.json'], false);
        }
    };
    $ids = array_map(
        static fn (Step $s) => $s->id(),
        StepComposition::default(
            llm: $d['llm'],
            renderer: $d['renderer'],
            blockFixer: $d['blockFixer'],
        )->insertAfter('site-spec', $extra)->steps(),
    );
    $i = array_search('site-spec', $ids, true);
    assert_eq('host-marker', $ids[$i + 1]);
});

test('StepComposition describe keeps theme and splice serial and page generation concurrent', function () {
    $d = composition_deps();
    $rows = StepGraph::describe(StepComposition::htmlFirst(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    )->steps());
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }
    assert_eq(true, $byId['inner-pages-design']['concurrent']);
    assert_eq(false, $byId['design-preview']['concurrent']);
    assert_eq(false, $byId['theme-json']['concurrent']);
    assert_eq(false, $byId['splice-home-design']['concurrent']);
    assert_eq(false, $byId['site-spec']['concurrent']);
});

test('StepComposition configures new seeds before inserting a step that reads them', function () {
    $needsSeed = graph_fake_step('needs-extra-seed', ['plugins.json'], ['out.json']);

    $d = composition_deps();
    $base = StepComposition::default(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    );

    // Each mutation validates immediately, so configure a new seed before
    // inserting a step that reads it.
    assert_throws(fn () => $base->insertAfter('scaffold-theme', $needsSeed));
    $c = $base
        ->withSeeds('meta.json', 'plugins.json')
        ->insertAfter('scaffold-theme', $needsSeed);

    // The composition's seeds must reach Pipeline's own validation.
    $pipeline = new Pipeline($c->steps(), $c->seeds());
    assert_true(in_array('needs-extra-seed', $pipeline->stepIds(), true));

    // Without the extra seed the same list is rightly rejected.
    assert_throws(fn () => new Pipeline($c->steps()));
});

test('graphName maps the two graphs to the names meta.json records', function () {
    assert_eq('html-first', StepComposition::graphName(true));
    assert_eq('blocks', StepComposition::graphName(false));
    assert_eq(StepComposition::GRAPH_HTML_FIRST, StepComposition::graphName(true));
    assert_eq(StepComposition::GRAPH_BLOCKS, StepComposition::graphName(false));
});

test('graphName with no argument reports the currently selected graph', function () {
    $previous = getenv('SITE_BUILD_HTML_FIRST');
    try {
        putenv('SITE_BUILD_HTML_FIRST=1');
        assert_eq('html-first', StepComposition::graphName());
        putenv('SITE_BUILD_HTML_FIRST=0');
        assert_eq('blocks', StepComposition::graphName());
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_HTML_FIRST')
            : putenv('SITE_BUILD_HTML_FIRST=' . $previous);
    }
});

test('a resume with no flag runs whatever graph the record names', function () {
    assert_eq(true, StepComposition::resumeHtmlFirst('html-first', null));
    assert_eq(false, StepComposition::resumeHtmlFirst('blocks', null));
});

test('a resume flag that agrees with the record is accepted', function () {
    assert_eq(true, StepComposition::resumeHtmlFirst('html-first', true));
    assert_eq(false, StepComposition::resumeHtmlFirst('blocks', false));
});

test('a resume flag contradicting the record is refused, not honored', function () {
    $e = assert_throws(static fn () => StepComposition::resumeHtmlFirst('html-first', false));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    // The CLI prints this verbatim behind a "--from: " prefix.
    assert_true(str_contains($e->getMessage(), 'built on the html-first graph'), $e->getMessage());
    assert_true(str_contains($e->getMessage(), 'blocks-first was passed'), $e->getMessage());

    $e = assert_throws(static fn () => StepComposition::resumeHtmlFirst('blocks', true));
    assert_true(str_contains($e->getMessage(), 'built on the blocks graph'), $e->getMessage());
    assert_true(str_contains($e->getMessage(), 'html-first was passed'), $e->getMessage());
});

test('an absent or unreadable record leaves the resume selection to the caller', function () {
    // Projects built before builds recorded the graph, and anything this
    // version does not recognize: degrade to the flag/env choice rather than
    // silently treating an unknown name as the blocks graph.
    assert_eq(null, StepComposition::resumeHtmlFirst(null, null));
    assert_eq(null, StepComposition::resumeHtmlFirst(null, true));
    assert_eq(null, StepComposition::resumeHtmlFirst('', true));
    assert_eq(null, StepComposition::resumeHtmlFirst('some-future-graph', false));
});
