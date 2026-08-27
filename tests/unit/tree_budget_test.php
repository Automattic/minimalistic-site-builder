<?php
declare(strict_types=1);

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\TreeGraph\Budget;
use Automattic\SiteBuild\TreeGraph\BudgetMeter;
use Automattic\SiteBuild\TreeGraph\Ledger;
use Automattic\SiteBuild\TreeGraph\TreeGraphException;

/** @return array<string,mixed> */
function tree_budget_brief(): array
{
    return [
        'pages' => [
            [
                'slug'     => 'home',
                'sections' => [
                    ['id' => 'hero', 'image_intent' => 'a wide hero image of warm bread'],
                    ['id' => 'gallery', 'role' => 'gallery', 'image_intent' => ['one', 'two', 'three']],
                    ['id' => 'cta'],
                ],
            ],
            [
                'slug'     => 'about',
                'sections' => [
                    ['id' => 'story'],
                ],
            ],
        ],
        'custom_blocks'   => [],
        'schema_packages' => [],
    ];
}

test('Budget: computeBudget derives S, I, base and ceiling from the brief', function () {
    $budget = Budget::computeBudget(tree_budget_brief(), true);
    assert_eq(4, $budget['S']);
    assert_eq(0, $budget['B']);
    assert_eq(0, $budget['P']);
    assert_eq(4, $budget['I'], 'one string intent + a three-intent gallery');
    assert_eq(2, $budget['F']);
    assert_eq(8, $budget['base'], '1 brief + 1 tokens + 2 furniture + 4 sections');
    assert_eq(20, $budget['ceiling'], '2*base + I');
    assert_true($budget['with_images']);
});

test('Budget: without images the image calls leave the bill entirely', function () {
    $budget = Budget::computeBudget(tree_budget_brief(), false);
    assert_eq(0, $budget['I'], 'placeholders still ship, but no metered image call remains');
    assert_eq(16, $budget['ceiling'], '2*base only');
    assert_true(!$budget['with_images']);
});

test('Budget: sectionImageIntents normalizes string, array and absent', function () {
    assert_eq(['x'], Budget::sectionImageIntents(['image_intent' => 'x']));
    assert_eq(['a', 'b'], Budget::sectionImageIntents(['image_intent' => ['a', 'b']]));
    assert_eq([], Budget::sectionImageIntents([]));
    assert_eq([], Budget::sectionImageIntents(['image_intent' => '']));
});

test('BudgetMeter: the pre-ceiling allowance is exactly two calls', function () {
    $meter = new BudgetMeter();
    $meter->spend('brief', 'brief');
    $meter->spend('brief', 'brief');
    $e = assert_throws(static fn () => $meter->spend('tokens', 'tokens'));
    assert_true($e instanceof TreeGraphException);
    assert_eq('budget_exceeded', $e->errorCode);
    assert_eq(2, $meter->spent());
});

test('BudgetMeter: the ceiling binds and the breach names the call', function () {
    $meter = new BudgetMeter();
    $meter->setCeiling(3);
    $meter->spend('brief', 'brief');
    $meter->spend('tokens', 'tokens');
    $meter->spend('tree', 'home/hero');
    $e = assert_throws(static fn () => $meter->spend('tree', 'home/cta'));
    assert_true($e instanceof TreeGraphException);
    assert_contains('call 4 (tree:home/cta) would exceed the ceiling of 3', $e->getMessage());
});

test('BudgetMeter: a ceiling above the hard cap is refused before any call', function () {
    $meter = new BudgetMeter(10);
    $e = assert_throws(static fn () => $meter->setCeiling(11));
    assert_true($e instanceof TreeGraphException);
    assert_eq('budget_exceeded', $e->errorCode);
    assert_contains('hard cap is 10', $e->getMessage());
});

test('BudgetMeter: rehydrate continues the same bill', function () {
    $meter = new BudgetMeter();
    $meter->setCeiling(5);
    $meter->rehydrate(4);
    $meter->spend('tree', 'home/hero');
    $e = assert_throws(static fn () => $meter->spend('tree', 'home/cta'));
    assert_true($e instanceof TreeGraphException);
    assert_eq(5, $meter->spent());
});

test('Ledger: appends, survives a reload, and rehydrates the meter', function () {
    $root = sys_get_temp_dir() . '/tree-ledger-test-' . getmypid() . '-' . random_int(1000, 9999);
    mkdir($root, 0775, true);
    try {
        $project = new Project($root);
        $ledger = new Ledger($project);
        assert_eq(0, $ledger->count());
        $ledger->record(['task_type' => 'tree', 'label' => 'home/hero', 'attempt' => 1, 'outcome' => 'ok']);
        $ledger->record(['task_type' => 'brief', 'label' => 'brief', 'attempt' => 1, 'outcome' => 'ok']);

        $reloaded = new Ledger($project);
        assert_eq(2, $reloaded->count());
        assert_eq('tree', $reloaded->entries()[0]['task_type']);

        $reloaded->flush();
        $flushed = $project->readJson('logs/tree-ledger.json');
        assert_eq('brief', $flushed[0]['task_type'], 'flush sorts by task_type');
    } finally {
        foreach (glob("{$root}/logs/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir("{$root}/logs");
        @rmdir($root);
    }
});
