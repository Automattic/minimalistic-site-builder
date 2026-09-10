<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\StudioAppRunner;
use Automattic\SiteBuild\StudioCli;

/**
 * Fail rather than skip or pass-empty when `studio` is on PATH but this
 * test performed no assertions. A green skip on a machine that could have
 * tested is a lie (the slice-1 available() failure mode).
 */
function studio_present_requires_assertions(bool $asserted): void
{
    if (command_exists('studio') && !$asserted) {
        throw new RuntimeException('studio is present but the assertion path did not run');
    }
}

function studio_http_code(string $url): int
{
    $ch = curl_init($url);
    if ($ch === false) {
        return 0;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
        CURLOPT_TIMEOUT_MS => 5000,
        CURLOPT_USERAGENT => 'site-builder-studio-integration',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

test('a real Studio site boots, activates and serves', function () {
    $asserted = false;
    try {
        if (!command_exists('studio')) {
            skip_test('studio CLI not available; Studio is macOS/Windows only');
        }

        with_temp_dir('studio_int_root_', function (string $root) use (&$asserted): void {
            $prevRoot = getenv('SITE_BUILD_STUDIO_ROOT');
            putenv("SITE_BUILD_STUDIO_ROOT={$root}");
            $cli = new StudioCli(null, 180);
            $runner = new StudioAppRunner($cli, $root, repo_path());
            $site = null;
            $slug = 'sb-int-' . getmypid();
            try {
                with_temp_dir('studio_int_proj_', function (string $projRoot) use ($cli, $runner, &$asserted, &$site, $slug): void {
                    // A fresh checkout must be able to run this without a paid,
                    // untracked generated project in projects/.
                    $project = (new ProjectStore($projRoot))->create($slug);
                    $project->writeText('theme/style.css', "/*\nTheme Name: Studio integration fixture\n*/\n");
                    $project->writeJson('theme/theme.json', ['version' => 3]);
                    $project->writeText('theme/templates/index.html',
                        '<!-- wp:paragraph --><p>Studio integration fixture.</p><!-- /wp:paragraph -->');
                    $site = $runner->start($project);

                    $code = studio_http_code($site->url);
                    echo "HTTP {$code}\n";
                    assert_true($code >= 200 && $code < 400, "URL answers HTTP {$code}");

                    $eval = $cli->run([
                        'wp',
                        '--path',
                        $runner->siteDir($slug),
                        'eval',
                        'echo get_stylesheet();',
                    ]);
                    assert_eq(0, $eval['exitCode'], 'wp eval stylesheet');
                    assert_eq($slug, trim($eval['stdout']), 'theme is active');

                    $asserted = true;
                });
            } finally {
                if ($site !== null) {
                    try {
                        ($site->stop)();
                    } catch (Throwable) {
                    }
                }
                try {
                    $runner->stopSite($slug);
                } catch (Throwable) {
                }
                try {
                    $runner->pruneSites();
                } catch (Throwable) {
                }
                if ($prevRoot === false || $prevRoot === '') {
                    putenv('SITE_BUILD_STUDIO_ROOT');
                } else {
                    putenv("SITE_BUILD_STUDIO_ROOT={$prevRoot}");
                }
            }
        });
    } catch (TestSkipped $e) {
        studio_present_requires_assertions($asserted);
        throw $e;
    }
    studio_present_requires_assertions($asserted);
});
