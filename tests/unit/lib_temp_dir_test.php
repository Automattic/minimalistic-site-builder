<?php
declare(strict_types=1);

test('with_temp_dir includes the pid so concurrent processes cannot collide', function () {
    $seen = with_temp_dir('collision_probe_', static fn (string $dir): string => $dir);
    assert_contains(
        (string) getmypid(),
        $seen,
        'temp dir must carry the pid; uniqid() alone collides across processes'
    );
});

test('with_temp_dir returns a different path on every call', function () {
    $a = with_temp_dir('collision_probe_', static fn (string $dir): string => $dir);
    $b = with_temp_dir('collision_probe_', static fn (string $dir): string => $dir);
    assert_true($a !== $b, "two calls returned the same path: {$a}");
});
