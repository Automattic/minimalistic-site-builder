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
        'theme-json+page-plan', 'sections', 'section-rhythm', 'copy-dedupe',
        'collect-images', 'normalize-layout', 'header-hero', 'contrast-fix', 'motion-sanity', 'fix-blocks',
        'assemble-pages', 'page-styles', 'custom-motion', 'bundle-fonts', 'fonts-php', 'finalize-theme',
        'direction-fidelity', 'validate-theme',
    ], array_map(static fn (Step $s) => $s->id(), $steps));
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

test('StepComposition describe concurrent flags on sections and group', function () {
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
    assert_eq(true, $byId['sections']['concurrent']);
    assert_eq(true, $byId['theme-json+page-plan']['concurrent']);
    assert_eq(false, $byId['site-spec']['concurrent']);
    assert_eq(['theme-json', 'page-plan'], $byId['theme-json+page-plan']['members']);
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
