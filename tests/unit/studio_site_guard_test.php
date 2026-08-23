<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StudioSiteGuard;

test('a missing directory is created', function () {
    with_temp_dir('guard_', function (string $dir) {
        assert_eq('create', StudioSiteGuard::decide($dir . '/absent', 'absent'));
    });
});

test('a directory we marked is recreated', function () {
    with_temp_dir('guard_', function (string $dir) {
        $site = $dir . '/mine';
        mkdir($site);
        StudioSiteGuard::writeMarker($site, 'mine', '/repo');
        assert_eq('recreate', StudioSiteGuard::decide($site, 'mine'));
    });
});

test('a real WordPress directory with no marker survives — this is the whole point', function () {
    with_temp_dir('guard_', function (string $dir) {
        $site = $dir . '/super-coaching';
        mkdir($site . '/wp-content/themes', 0775, true);
        file_put_contents($site . '/wp-config.php', '<?php // someone real');
        assert_eq('refuse', StudioSiteGuard::decide($site, 'super-coaching'));
        assert_true(is_file($site . '/wp-config.php'), 'untouched');
    });
});

test('a symlink is refused whatever the marker says', function () {
    with_temp_dir('guard_', function (string $dir) {
        $real = $dir . '/elsewhere';
        mkdir($real);
        StudioSiteGuard::writeMarker($real, 'linked', '/repo');
        symlink($real, $dir . '/linked');
        assert_eq('refuse', StudioSiteGuard::decide($dir . '/linked', 'linked'));
    });
});

test('a marker naming a different slug is refused', function () {
    with_temp_dir('guard_', function (string $dir) {
        $site = $dir . '/mine';
        mkdir($site);
        StudioSiteGuard::writeMarker($site, 'somebody-else', '/repo');
        assert_eq('refuse', StudioSiteGuard::decide($site, 'mine'));
    });
});

test('an unparseable marker is refused', function () {
    with_temp_dir('guard_', function (string $dir) {
        $site = $dir . '/mine';
        mkdir($site);
        file_put_contents($site . '/' . StudioSiteGuard::MARKER, '{ this is not json');
        assert_eq('refuse', StudioSiteGuard::decide($site, 'mine'));
    });
});

test('a marker that is a directory is refused', function () {
    with_temp_dir('guard_', function (string $dir) {
        $site = $dir . '/mine';
        mkdir($site . '/' . StudioSiteGuard::MARKER, 0775, true);
        assert_eq('refuse', StudioSiteGuard::decide($site, 'mine'));
    });
});

test('the refusal message names the path and both fixes', function () {
    $msg = StudioSiteGuard::refusalMessage('/Users/x/Studio/thing');
    assert_contains('/Users/x/Studio/thing', $msg);
    assert_contains('--slug', $msg);
});

test('slugify blocks traversal — the guard rests on this', function () {
    assert_eq('etc-passwd', ProjectStore::slugify('../../etc/passwd'));
    assert_true(!str_contains(ProjectStore::slugify('a/../../b'), '/'), 'no separators survive');
});
