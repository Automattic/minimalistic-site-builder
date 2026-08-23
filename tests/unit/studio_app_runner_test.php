<?php
declare(strict_types=1);

use Automattic\SiteBuild\StudioAppRunner;
use Automattic\SiteBuild\StudioCli;
use Automattic\SiteBuild\StudioSiteGuard;

/** Records every studio invocation and answers status with a canned payload. */
function recording_cli(array &$calls, array $statusPayload = null): StudioCli
{
    $statusPayload ??= ['siteUrl' => 'http://localhost:8881/', 'isOnline' => true,
                        'autoLoginUrl' => 'http://localhost:8881/studio-auto-login'];
    return new StudioCli(function (string $cmd, int $t) use (&$calls, $statusPayload): array {
        $calls[] = $cmd;
        if (str_contains($cmd, 'status')) {
            return ['exitCode' => 0, 'stdout' => json_encode($statusPayload), 'stderr' => ''];
        }
        return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
    });
}

test('defaultRoot honours SITE_BUILD_STUDIO_ROOT so tests never touch a real ~/Studio', function () {
    putenv('SITE_BUILD_STUDIO_ROOT=/tmp/sb-test-root');
    assert_eq('/tmp/sb-test-root', StudioAppRunner::defaultRoot());
    putenv('SITE_BUILD_STUDIO_ROOT');
});

test('a refused directory aborts before any studio command runs', function () {
    with_temp_dir('runner_', function (string $root) {
        $calls = [];
        mkdir($root . '/demo');
        file_put_contents($root . '/demo/wp-config.php', '<?php');   // real site, no marker
        $runner = new StudioAppRunner(recording_cli($calls), $root, '/repo');
        with_project('runner_proj_', function ($project) use ($runner, &$calls) {
            $e = assert_throws(fn () => $runner->start($project));
            assert_contains('not created by site-builder', $e->getMessage());
            assert_eq([], $calls, 'no studio command ran');
        });
    });
});

test('activation happens in ONE studio wp eval, not six invocations', function () {
    with_temp_dir('runner_', function (string $root) {
        $calls = [];
        $runner = new StudioAppRunner(recording_cli($calls), $root, '/repo');
        with_project('runner_proj_', function ($project) use ($runner, $root, &$calls) {
            mkdir($project->themePath(), 0775, true);
            file_put_contents($project->themePath('style.css'), "/*\nTheme Name: Demo\n*/");
            $runner->start($project);

            $evals = array_filter($calls, fn ($c) => str_contains($c, 'wp') && str_contains($c, 'eval'));
            assert_eq(1, count($evals), 'exactly one eval');
            $themeActivates = array_filter($calls, fn ($c) => str_contains($c, 'theme') && str_contains($c, 'activate'));
            assert_eq(0, count($themeActivates), 'no separate theme activate call');
        });
    });
});

test('the marker is written so a rebuild can recreate the site', function () {
    with_temp_dir('runner_', function (string $root) {
        $calls = [];
        $runner = new StudioAppRunner(recording_cli($calls), $root, '/repo');
        with_project('runner_proj_', function ($project) use ($runner, $root) {
            mkdir($project->themePath(), 0775, true);
            file_put_contents($project->themePath('style.css'), "/*\nTheme Name: Demo\n*/");
            $runner->start($project);
            $marker = $root . '/' . $project->slug() . '/' . StudioSiteGuard::MARKER;
            assert_true(is_file($marker), 'marker written');
            assert_eq($project->slug(), json_decode(file_get_contents($marker), true)['slug']);
        });
    });
});

test('a status payload missing isOnline is rejected rather than screenshotted', function () {
    with_temp_dir('runner_', function (string $root) {
        $calls = [];
        $cli = recording_cli($calls, ['siteUrl' => 'http://localhost:8881/']);   // no isOnline
        $runner = new StudioAppRunner($cli, $root, '/repo');
        with_project('runner_proj_', function ($project) use ($runner) {
            mkdir($project->themePath(), 0775, true);
            file_put_contents($project->themePath('style.css'), "/*\nTheme Name: Demo\n*/");
            $e = assert_throws(fn () => $runner->start($project));
            assert_contains('isOnline', $e->getMessage());
        });
    });
});

test('a Studio site is persistent, so build.php returns the shell', function () {
    with_temp_dir('runner_', function (string $root) {
        $calls = [];
        $runner = new StudioAppRunner(recording_cli($calls), $root, '/repo');
        with_project('runner_proj_', function ($project) use ($runner) {
            mkdir($project->themePath(), 0775, true);
            file_put_contents($project->themePath('style.css'), "/*\nTheme Name: Demo\n*/");
            assert_true($runner->start($project)->persistent, 'persistent');
        });
    });
});
