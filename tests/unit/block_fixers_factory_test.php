<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\NodeBlockFixer;
use Automattic\SiteBuild\PhpBlockFixer;

test('BlockFixers defaults to the PHP runtime implementation', function () {
    $before = getenv('BLOCK_FIXER');
    putenv('BLOCK_FIXER');
    try {
        assert_true(BlockFixers::default() instanceof PhpBlockFixer);
    } finally {
        $before === false ? putenv('BLOCK_FIXER') : putenv('BLOCK_FIXER=' . $before);
    }
});

test('BlockFixers accepts the explicit PHP implementation', function () {
    $before = getenv('BLOCK_FIXER');
    putenv('BLOCK_FIXER=PHP');
    try {
        assert_true(BlockFixers::default() instanceof PhpBlockFixer);
    } finally {
        $before === false ? putenv('BLOCK_FIXER') : putenv('BLOCK_FIXER=' . $before);
    }
});

test('BlockFixers retains the pinned Node oracle override', function () {
    $before = getenv('BLOCK_FIXER');
    putenv('BLOCK_FIXER=node');
    try {
        assert_true(BlockFixers::default() instanceof NodeBlockFixer);
    } finally {
        $before === false ? putenv('BLOCK_FIXER') : putenv('BLOCK_FIXER=' . $before);
    }
});

test('BlockFixers rejects an invalid implementation name', function () {
    $before = getenv('BLOCK_FIXER');
    putenv('BLOCK_FIXER=definitely-not-a-fixer');
    try {
        try {
            BlockFixers::default();
            throw new RuntimeException('invalid BLOCK_FIXER value was accepted');
        } catch (RuntimeException $error) {
            assert_contains(
                "Invalid BLOCK_FIXER 'definitely-not-a-fixer'",
                $error->getMessage(),
                'invalid configuration must fail with a useful diagnostic',
            );
        }
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
