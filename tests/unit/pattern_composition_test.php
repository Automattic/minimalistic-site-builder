<?php
declare(strict_types=1);

use Automattic\SiteBuild\Patterns\PatternArtifacts;
use Automattic\SiteBuild\Patterns\PendingExtractionStep;
use Automattic\SiteBuild\Pipeline;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\StepComposition;

/** The stages the pattern graph runs, in order. */
const PATTERN_STAGES = [
    'normalize-inputs',
    'plan-site',
    'compose-layouts',
    'personalize-content',
    'resolve-media',
    'export-bundle',
];

function pattern_composition(): StepComposition
{
    return StepComposition::patterns();
}

test('the pattern graph runs its stages in order', function () {
    $composition = pattern_composition();
    $pipeline = new Pipeline($composition->steps(), $composition->seeds());

    assert_eq(PATTERN_STAGES, $pipeline->stepIds());
});

/**
 * StepGraph only accepts a graph whose every read is satisfied by an earlier
 * write or a seed, so constructing the pipeline is what proves the artifact
 * contract holds together. A stage naming an input nobody produces would fail
 * here rather than halfway through a build.
 */
test('every stage input is produced by a seed or an earlier stage', function () {
    $composition = pattern_composition();

    new Pipeline($composition->steps(), $composition->seeds());

    assert_eq(PatternArtifacts::SEEDS, $composition->seeds());
});

/**
 * The host supplies the inventory and the Brand; generation never reaches for
 * a catalogue of its own. If either stops being a seed, a build could compose
 * from something the customer never approved.
 */
test('the inventory and the Brand are host inputs, not fetched', function () {
    $seeds = pattern_composition()->seeds();

    assert_true(in_array(PatternArtifacts::INVENTORY, $seeds, true), 'inventory is a seed');
    assert_true(in_array(PatternArtifacts::BRAND, $seeds, true), 'Brand is a seed');
});

/**
 * An unimplemented stage has to stop the build. The alternative — omitting it
 * until it lands — produces a bundle with no content and reports success.
 */
test('an unextracted stage refuses to run instead of producing nothing', function () {
    $dir = sys_get_temp_dir() . '/pattern-composition-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);

    $step = new PendingExtractionStep(
        id: 'compose-layouts',
        label: 'Select approved patterns',
        reads: [PatternArtifacts::NORMALIZED],
        writes: [PatternArtifacts::LAYOUTS],
        source: 'class.pattern-utils.php',
    );

    $thrown = assert_throws(
        static fn () => $step->run(new Project($dir, basename($dir))),
        'an unextracted stage throws',
    );

    exec('rm -rf ' . escapeshellarg($dir));

    assert_contains('compose-layouts', $thrown->getMessage());
    assert_contains('class.pattern-utils.php', $thrown->getMessage());
});

/**
 * The pattern graph is a separate composition. Measuring the blocks graph with
 * its theme stages removed showed its content is not portable to a foreign
 * theme, so the two must not converge by accident.
 */
test('the pattern graph shares no stage with the blocks graph', function () {
    $deps = composition_deps();

    $blocks = StepComposition::blocks(
        llm: $deps['llm'],
        renderer: $deps['renderer'],
        blockFixer: $deps['blockFixer'],
    );
    $blockIds = (new Pipeline($blocks->steps(), $blocks->seeds()))->stepIds();

    assert_eq([], array_values(array_intersect(PATTERN_STAGES, $blockIds)));
});
