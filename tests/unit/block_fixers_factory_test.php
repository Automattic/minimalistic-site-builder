<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\PhpBlockFixer;

test('BlockFixers always constructs the PHP runtime implementation', function () {
    assert_true(BlockFixers::default() instanceof PhpBlockFixer);
});

test('legacy BLOCK_FIXER configuration cannot select another runtime', function () {
    $before = getenv('BLOCK_FIXER');
    putenv('BLOCK_FIXER=node');
    try {
        assert_true(BlockFixers::default() instanceof PhpBlockFixer);
    } finally {
        $before === false ? putenv('BLOCK_FIXER') : putenv('BLOCK_FIXER=' . $before);
    }
});

test('the test harness has an explicit skip result', function () {
    try {
        skip_test('sentinel');
    } catch (TestSkipped $e) {
        assert_eq('sentinel', $e->getMessage());
        return;
    }
    throw new RuntimeException('skip_test did not raise TestSkipped');
});
