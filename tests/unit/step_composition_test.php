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

test('StepComposition default matches CLI step order and validates', function () {
    $d = composition_deps();
    $c = StepComposition::default(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    );
    $steps = $c->steps();
    assert_eq([
        'scaffold-theme', 'scaffold-plugin', 'refine-prompt', 'site-spec', 'apply-identity', 'design-direction',
        'design-preview', 'homepage-design', 'theme-json', 'inner-pages-design', 'assign-image-sources', 'transform-site', 'section-rhythm',
        'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks',
        'assemble-pages', 'fix-pages', 'page-styles', 'custom-motion', 'fonts-php', 'finalize-theme', 'validate-theme',
    ], array_map(static fn (Step $s) => $s->id(), $steps));
});

test('StepComposition legacy env preserves the full legacy graph byte-for-byte', function () {
    $previous = getenv('SITE_BUILD_LEGACY');
    putenv('SITE_BUILD_LEGACY=1');
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
            'theme-json+page-plan', 'sections', 'section-rhythm',
            'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks',
            'assemble-pages', 'page-styles', 'custom-motion', 'fonts-php', 'finalize-theme', 'validate-theme',
        ], $ids);
        assert_true(!in_array('homepage-design', $ids, true));
        assert_true(!in_array('transform-site', $ids, true));
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_LEGACY')
            : putenv('SITE_BUILD_LEGACY=' . $previous);
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

test('StepComposition describe marks only HTML design fan-outs concurrent', function () {
    $d = composition_deps();
    $rows = StepGraph::describe(StepComposition::default(
        llm: $d['llm'],
        renderer: $d['renderer'],
        blockFixer: $d['blockFixer'],
    )->steps());
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }
    assert_eq(true, $byId['homepage-design']['concurrent']);
    assert_eq(true, $byId['inner-pages-design']['concurrent']);
    assert_eq(false, $byId['theme-json']['concurrent']);
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
