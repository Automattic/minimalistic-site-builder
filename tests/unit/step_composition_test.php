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
        ['generate-images', 'theme-screenshot', 'cover-contrast', 'extract-patterns', 'validate-theme'],
        array_map(static fn (Step $s) => $s->id(), StepComposition::postImages($images)),
    );
});

test('postImages validates against the graph it is handed, not the env selector', function () {
    $images = new class implements Step {
        public function id(): string { return 'generate-images'; }
        public function label(): string { return 'Generate images'; }
        public function declaration(): StepDeclaration
        {
            return new StepDeclaration(id: $this->id(), label: $this->label(), reads: [], writes: []);
        }
        public function run(Project $project): void {}
    };
    $validateReads = static function (array $steps): array {
        foreach ($steps as $step) {
            if ($step->id() === 'validate-theme') {
                return $step->declaration()->reads;
            }
        }
        throw new RuntimeException('postImages lost its closing validate-theme');
    };

    // bin/images.php finishes projects it did not build, so the env key says
    // nothing about which graph did. The closing re-validation dry-runs the
    // build's width normalization: handed the wrong graph it reports the very
    // rules that path deliberately skips, and only the HTML-first path has a
    // design stylesheet to consult.
    assert_true(
        in_array('design/site.css', $validateReads(StepComposition::postImages($images, htmlFirst: true)), true),
        'an HTML-first project is re-validated with the HTML-first width rules',
    );
    assert_true(
        !in_array('design/site.css', $validateReads(StepComposition::postImages($images, htmlFirst: false)), true),
        'a blocks project is not',
    );
});

test('images CLI reads the graph off the project instead of the env selector', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/images.php');
    assert_contains('StepComposition::resumeGraph(', $source);
    assert_contains('htmlFirst: $htmlFirst', $source);
    assert_true(
        !str_contains($source, 'selectedGraph'),
        'the entry point that did not build the project never asks the env selector',
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
    $previous = getenv('SITE_BUILD_GRAPH');
    // Env::get falls back to the .env map bootstrap.php loads, so clearing the
    // process env alone leaves a developer's SITE_BUILD_GRAPH=html-first in force.
    putenv('SITE_BUILD_GRAPH=blocks');
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
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
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

test('SITE_BUILD_GRAPH=html-first routes the default composition to the HTML-first graph', function () {
    $previous = getenv('SITE_BUILD_GRAPH');
    putenv('SITE_BUILD_GRAPH=html-first');
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

        assert_eq('html-first', StepComposition::selectedGraph());
        assert_true(in_array('transform-site', $ids, true));
        assert_true(!in_array('sections', $ids, true));
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
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

test('graphName maps known graphs to the names meta.json records', function () {
    assert_eq('html-first', StepComposition::graphName('html-first'));
    assert_eq('blocks', StepComposition::graphName('blocks'));
    assert_eq('html-islands', StepComposition::graphName('html-islands'));
    assert_eq(StepComposition::GRAPH_HTML_FIRST, StepComposition::graphName('html-first'));
    assert_eq(StepComposition::GRAPH_BLOCKS, StepComposition::graphName('blocks'));
    assert_eq(StepComposition::GRAPH_HTML_ISLANDS, StepComposition::graphName('html-islands'));
});

test('graphName with no argument reports the currently selected graph', function () {
    $previous = getenv('SITE_BUILD_GRAPH');
    try {
        putenv('SITE_BUILD_GRAPH=html-first');
        assert_eq('html-first', StepComposition::graphName());
        putenv('SITE_BUILD_GRAPH=blocks');
        assert_eq('blocks', StepComposition::graphName());
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }
});

test('a resume with no flag runs whatever graph the record names', function () {
    assert_eq('html-first', StepComposition::resumeGraph('html-first', null));
    assert_eq('blocks', StepComposition::resumeGraph('blocks', null));
    assert_eq('html-islands', StepComposition::resumeGraph('html-islands', null));
});

test('a resume flag that agrees with the record is accepted', function () {
    assert_eq('html-first', StepComposition::resumeGraph('html-first', 'html-first'));
    assert_eq('blocks', StepComposition::resumeGraph('blocks', 'blocks'));
    assert_eq('html-islands', StepComposition::resumeGraph('html-islands', 'html-islands'));
});

test('a resume flag contradicting the record is refused, not honored', function () {
    $e = assert_throws(static fn () => StepComposition::resumeGraph('html-first', 'blocks'));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    // The CLI prints this verbatim behind a "--from: " prefix.
    assert_true(str_contains($e->getMessage(), 'built on the html-first graph'), $e->getMessage());
    assert_true(str_contains($e->getMessage(), 'blocks-first was passed'), $e->getMessage());

    $e = assert_throws(static fn () => StepComposition::resumeGraph('blocks', 'html-first'));
    assert_true(str_contains($e->getMessage(), 'built on the blocks graph'), $e->getMessage());
    assert_true(str_contains($e->getMessage(), 'html-first was passed'), $e->getMessage());
});

test('an absent or unreadable record leaves the resume selection to the caller', function () {
    // Projects built before builds recorded the graph: degrade to the flag/env
    // choice rather than silently treating a missing name as the blocks graph.
    assert_eq(null, StepComposition::resumeGraph(null, null));
    assert_eq(null, StepComposition::resumeGraph(null, 'html-first'));
    assert_eq(null, StepComposition::resumeGraph('', 'html-first'));
});

test('an unknown recorded graph is refused by name, not treated as the caller\'s selection', function () {
    $e = assert_throws(static fn () => StepComposition::resumeGraph('some-future-graph', 'blocks'));
    assert_true($e instanceof InvalidArgumentException, get_class($e));
    assert_true(str_contains($e->getMessage(), 'some-future-graph'), $e->getMessage());
});

test('SITE_BUILD_GRAPH=html-islands is recognized and refuses to build', function () {
    $previous = getenv('SITE_BUILD_GRAPH');
    putenv('SITE_BUILD_GRAPH=html-islands');
    try {
        assert_eq('html-islands', StepComposition::selectedGraph());
        $d = composition_deps();
        $e = assert_throws(static fn () => StepComposition::default(
            llm: $d['llm'],
            renderer: $d['renderer'],
            blockFixer: $d['blockFixer'],
        ));
        assert_true($e instanceof RuntimeException, get_class($e));
        assert_eq(\Automattic\SiteBuild\Graph::NOT_IMPLEMENTED, $e->getMessage());
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }
});

test('an unknown SITE_BUILD_GRAPH value is refused by name', function () {
    $previous = getenv('SITE_BUILD_GRAPH');
    putenv('SITE_BUILD_GRAPH=not-a-graph');
    try {
        $e = assert_throws(static fn () => StepComposition::selectedGraph());
        assert_true($e instanceof InvalidArgumentException, get_class($e));
        assert_true(str_contains($e->getMessage(), 'not-a-graph'), $e->getMessage());
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GRAPH')
            : putenv('SITE_BUILD_GRAPH=' . $previous);
    }
});
