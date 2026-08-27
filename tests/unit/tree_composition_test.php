<?php
declare(strict_types=1);

use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\StepGraph;
use Automattic\SiteBuild\Tests\FakeLlm;

test('StepComposition tree matches the tree-graph step order and validates', function () {
    $c = StepComposition::tree(llm: new FakeLlm());
    $steps = $c->steps();
    assert_eq(
        ['sandbox', 'brief', 'read-instance', 'tokens', 'section-trees', 'tree-repair', 'publish', 'tree-images', 'verify'],
        array_map(static fn (Step $s) => $s->id(), $steps),
    );
    StepGraph::validate($steps, $c->seeds());
    assert_eq(['meta.json'], $c->seeds());
});

test('the tree graph is selected by SITE_BUILD_GRAPH and beats the legacy boolean', function () {
    $previousGraph = getenv(StepComposition::GRAPH_ENV);
    $previousHtml = getenv(StepComposition::HTML_FIRST_ENV);
    try {
        putenv(StepComposition::GRAPH_ENV . '=tree');
        putenv(StepComposition::HTML_FIRST_ENV . '=1');
        assert_eq(StepComposition::GRAPH_TREE, StepComposition::selectedGraph());
        assert_eq(StepComposition::GRAPH_TREE, StepComposition::graphName());

        // With no named graph, the legacy boolean still decides.
        putenv(StepComposition::GRAPH_ENV);
        assert_eq(StepComposition::GRAPH_HTML_FIRST, StepComposition::selectedGraph());
        putenv(StepComposition::HTML_FIRST_ENV . '=0');
        assert_eq(StepComposition::GRAPH_BLOCKS, StepComposition::selectedGraph());

        // An unknown named graph is ignored, never honored.
        putenv(StepComposition::GRAPH_ENV . '=nonsense');
        assert_eq(StepComposition::GRAPH_BLOCKS, StepComposition::selectedGraph());
    } finally {
        putenv(StepComposition::GRAPH_ENV . ($previousGraph === false ? '' : '=' . $previousGraph));
        putenv(StepComposition::HTML_FIRST_ENV . ($previousHtml === false ? '' : '=' . $previousHtml));
    }
});

test('resumeGraph honors the record and refuses a contradicting request', function () {
    // Nothing recorded (or unrecognized): the caller's own selection stands.
    assert_eq(null, StepComposition::resumeGraph(null, 'tree'));
    assert_eq(null, StepComposition::resumeGraph('something-else', null));

    // The record wins when the request agrees or is absent.
    assert_eq('tree', StepComposition::resumeGraph('tree', null));
    assert_eq('tree', StepComposition::resumeGraph('tree', 'tree'));
    assert_eq('blocks', StepComposition::resumeGraph('blocks', null));

    // A contradiction is a mistake, not an override.
    $e = assert_throws(static fn () => StepComposition::resumeGraph('tree', 'blocks'));
    assert_true($e instanceof InvalidArgumentException);
    assert_contains('built on the tree graph', $e->getMessage());
});

test('resumeHtmlFirst keeps its legacy two-graph contract', function () {
    assert_eq(true, StepComposition::resumeHtmlFirst('html-first', null));
    assert_eq(false, StepComposition::resumeHtmlFirst('blocks', null));
    assert_eq(null, StepComposition::resumeHtmlFirst('tree', null) ?? null);
});

test('the tree graph declares warnings.json on every step that can warn', function () {
    $c = StepComposition::tree(llm: new FakeLlm());
    foreach ($c->steps() as $step) {
        if (in_array($step->id(), ['publish', 'tree-images', 'verify'], true)) {
            assert_true(
                in_array('warnings.json', $step->declaration()->writes, true),
                "{$step->id()} must declare warnings.json",
            );
        }
    }
});

test('brief palette roles outside the enum are coerced, never fatal', function () {
    $brief = ['palette' => [
        ['name' => 'Ground', 'color' => '#f4ede1', 'role' => 'background'],
        ['name' => 'Roast', 'color' => '#2e2620', 'role' => 'contrast'],
        ['name' => 'Glow', 'color' => '#b5622a', 'role' => 'Highlight'],
        ['name' => 'Odd', 'color' => '#123456', 'role' => 'mystery'],
    ]];
    $notes = [];
    $out = \Automattic\SiteBuild\Steps\TreeBriefStep::coercePaletteRoles($brief, $notes);
    assert_eq('background', $out['palette'][0]['role'], 'valid roles pass untouched');
    assert_eq('text', $out['palette'][1]['role'], 'contrast means the ink');
    assert_eq('accent', $out['palette'][2]['role'], 'synonyms match case-insensitively');
    assert_eq('other', $out['palette'][3]['role'], 'unknown roles land on the catch-all');
    assert_eq(3, count($notes), 'each coercion is recorded');
    // The coerced palette now passes the real schema's enum.
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/tree/brief.schema.json'), true);
    $issues = \Automattic\SiteBuild\TreeGraph\Schema::validate($schema['properties']['palette'], $out['palette']);
    assert_eq([], $issues);
});
