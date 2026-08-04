<?php
declare(strict_types=1);

test('with_temp_dir returns the callback result and removes nested files', function () {
    $created = null;
    $result = with_temp_dir('builder_harness_', function (string $dir) use (&$created): string {
        $created = $dir;
        mkdir($dir . '/nested');
        file_put_contents($dir . '/nested/fixture.txt', 'fixture');
        return 'callback result';
    });

    assert_eq('callback result', $result);
    assert_true(is_string($created) && !file_exists($created), 'the scoped tree is removed after success');
});

test('with_temp_dir removes its tree when the callback throws', function () {
    $created = null;
    $error = assert_throws(function () use (&$created) {
        return with_temp_dir(
            'builder_harness_failure_',
            function (string $dir) use (&$created): never {
                $created = $dir;
                mkdir($dir . '/nested');
                file_put_contents($dir . '/nested/fixture.txt', 'fixture');
                throw new RuntimeException('intentional callback failure');
            },
        );
    });

    assert_eq('intentional callback failure', $error->getMessage());
    assert_true(is_string($created) && !file_exists($created), 'the scoped tree is removed after failure');
});

test('with_project removes the project tree when the callback throws', function () {
    $created = null;
    $error = assert_throws(function () use (&$created) {
        return with_project(
            'builder_harness_project_',
            function ($project, string $dir) use (&$created): never {
                $created = $dir;
                $project->writeText('theme/parts/fixture.html', '<!-- wp:paragraph /-->');
                throw new RuntimeException('intentional project failure');
            },
        );
    });

    assert_eq('intentional project failure', $error->getMessage());
    assert_true(is_string($created) && !file_exists($created), 'the project tree is removed after failure');
});

test('remove_tree handles read-only nested directories without following symlinks', function () {
    $suffix = bin2hex(random_bytes(8));
    $root = sys_get_temp_dir() . '/builder_remove_tree_' . $suffix;
    $outside = sys_get_temp_dir() . '/builder_remove_tree_outside_' . $suffix;
    mkdir($root . '/nested', 0775, true);
    mkdir($outside, 0775, true);
    file_put_contents($root . '/nested/fixture.txt', 'fixture');
    file_put_contents($outside . '/keep.txt', 'keep');
    assert_true(symlink($outside, $root . '/nested/outside-link'), 'the symlink fixture was created');
    chmod($root . '/nested', 0555);

    try {
        remove_tree($root);
        assert_true(!file_exists($root), 'the requested tree is removed');
        assert_eq('keep', file_get_contents($outside . '/keep.txt'), 'a linked external tree is untouched');
    } finally {
        remove_tree($root);
        remove_tree($outside);
    }
});
