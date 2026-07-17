<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategy;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;

$rendererSnapshotPath = dirname(__DIR__) . '/fixtures/block-fixer/renderer-probes.json';

/** @return array<string,mixed> */
$loadRendererSnapshot = static function () use ($rendererSnapshotPath): array {
    $contents = file_get_contents($rendererSnapshotPath);
    if ($contents === false) {
        throw new RuntimeException("Unable to read renderer snapshot: {$rendererSnapshotPath}");
    }
    $snapshot = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || array_is_list($snapshot)) {
        throw new RuntimeException('Renderer snapshot must contain a JSON object');
    }
    return $snapshot;
};

test('renderer snapshot remains a complete immutable PHP regression input', function () use ($loadRendererSnapshot) {
    $snapshot = $loadRendererSnapshot();
    assert_eq(1, $snapshot['schemaVersion'] ?? null);
    assert_true(is_array($snapshot['coverage'] ?? null), 'renderer snapshot has coverage metadata');
    assert_true(is_array($snapshot['cases'] ?? null), 'renderer snapshot has cases');
});

test('PHP save strategies exactly replay every checked renderer probe', function () use ($loadRendererSnapshot) {
    $snapshot = $loadRendererSnapshot();
    $coverage = $snapshot['coverage'] ?? null;
    $cases = $snapshot['cases'] ?? null;
    assert_true(is_array($coverage), 'renderer snapshot has coverage metadata');
    assert_true(is_array($cases) && array_is_list($cases), 'renderer snapshot cases are a list');
    assert_eq($coverage['oracleCases'] ?? null, count($cases));

    $registry = new BlockRegistry();
    $saves = new SaveStrategyRegistry($registry);
    $supportedStaticBlocks = array_values(array_filter(
        $registry->supportedNames(),
        static fn (string $name): bool => $registry->strategy($name) === SaveStrategy::STATIC_RENDERER,
    ));

    $ids = [];
    $probedStaticBlocks = [];
    $staticRendererCases = 0;
    foreach ($cases as $index => $case) {
        assert_true(is_array($case), "probe {$index} is an object");
        $id = $case['id'] ?? null;
        $name = $case['name'] ?? null;
        assert_true(is_string($id) && $id !== '', "probe {$index} has an id");
        assert_true(!isset($ids[$id]), "duplicate renderer probe id: {$id}");
        $ids[$id] = true;
        assert_true(is_string($name) && $name !== '', "probe {$id} has a block name");
        assert_true(!isset($case['error']), "checked renderer probe failed: {$id}");
        assert_true(isset($case['attributes']) && is_array($case['attributes']), "probe {$id} has attributes");
        assert_true(isset($case['innerSerialized']) && is_string($case['innerSerialized']), "probe {$id} has serialized inner blocks");
        assert_true(array_key_exists('expected', $case) && is_string($case['expected']), "probe {$id} has expected bytes");

        try {
            $actual = $saves->save($name, $case['attributes'], $case['innerSerialized']);
        } catch (Throwable $error) {
            throw new RuntimeException(
                "PHP renderer probe {$id} raised " . $error::class . ': ' . $error->getMessage(),
                previous: $error,
            );
        }
        assert_eq($case['expected'], $actual, "renderer probe {$id}");

        if (in_array($name, $supportedStaticBlocks, true)) {
            $probedStaticBlocks[] = $name;
            $staticRendererCases++;
        }
    }

    $probedStaticBlocks = array_values(array_unique($probedStaticBlocks));
    sort($probedStaticBlocks, SORT_STRING);
    $uncoveredStaticBlocks = array_values(array_diff($supportedStaticBlocks, $probedStaticBlocks));
    sort($uncoveredStaticBlocks, SORT_STRING);

    assert_eq($supportedStaticBlocks, $coverage['supportedStaticBlocks'] ?? null);
    assert_eq($probedStaticBlocks, $coverage['probedStaticBlocks'] ?? null);
    assert_eq($uncoveredStaticBlocks, $coverage['uncoveredStaticBlocks'] ?? null);
    assert_eq([], $uncoveredStaticBlocks, 'every supported static renderer has a checked probe');
    assert_eq($staticRendererCases, $coverage['staticRendererCases'] ?? null);
});
