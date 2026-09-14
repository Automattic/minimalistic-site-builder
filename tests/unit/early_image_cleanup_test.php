<?php
declare(strict_types=1);

use Automattic\SiteBuild\EarlyImageBuild;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\ImageTransportScheduler;
use Automattic\SiteBuild\PreparedImageBatch;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

require_once __DIR__ . '/early_image_build_test.php';
require_once __DIR__ . '/prepared_image_batch_test.php';

test('successful image apply removes complete stages after durable log publication', function () {
    with_project('builder_image_cleanup_', function ($project): void {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $build = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
            $build->afterStep('assemble-pages');
            $directory = $build->directory();
            (new GenerateImagesStep($build->applicationClient(), inspectImages: false))->run($project);
            $assets = $project->readText('theme/assets/hero.jpg');
            $build->finishRaw(publish: true, cleanup: true);
            assert_eq(null, $build->directory());
            assert_true(!is_dir($directory));
            assert_true(!is_dir($storage));
            assert_eq($assets, $project->readText('theme/assets/hero.jpg'));
            assert_eq('completed', $project->readJson('images.generated.json')['status']);
            $log = $project->readText('logs/images/attempts.jsonl');
            assert_eq(4, count(array_filter(explode("\n", $log))));
            $build->finishRaw(publish: true, cleanup: true);
            assert_eq($log, $project->readText('logs/images/attempts.jsonl'));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('failed apply retains paid results and a successful resume removes complete stages', function () {
    with_project('builder_image_cleanup_resume_', function ($project): void {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $first = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
            $first->afterStep('assemble-pages');
            $directory = $first->directory();
            assert_throws(fn () => $first->applicationClient()->generateBatch(
                PreparedImageBatch::fromProject($project)->requests(),
                static fn () => throw new RuntimeException('Apply failed'),
            ));
            $first->finishRaw(publish: true);
            assert_true(is_file($directory . '/0.jpg'));
            $log = $project->readText('logs/images/attempts.jsonl');

            $client = new EarlyImageCountingClient();
            $resume = new EarlyImageBuild($project, $client, ['assemble-pages', 'page-styles'], false, $storage);
            $resume->beforeStep('page-styles');
            (new GenerateImagesStep($resume->applicationClient(), inspectImages: false))->run($project);
            assert_eq(0, count($client->fake->calls));
            $resume->finishRaw(publish: true, cleanup: true);
            assert_true(!is_dir($storage));
            assert_eq($log, $project->readText('logs/images/attempts.jsonl'));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('cleanup retains partial stages and stages outside the initial snapshot', function () {
    with_project('builder_image_cleanup_partial_', function ($project): void {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $first = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
            $first->afterStep('assemble-pages');
            $first->finishRaw();
            $partial = $first->directory();
            $manifest = json_decode(file_get_contents($partial . '/results.json'), true);
            $manifest['complete'] = false;
            file_put_contents($partial . '/results.json', json_encode($manifest));
            $before = file_get_contents($partial . '/0.jpg');

            $client = new EarlyImageCountingClient();
            $resume = new EarlyImageBuild($project, $client, ['assemble-pages'], false, $storage);
            $resume->afterStep('assemble-pages');
            $newer = $storage . '/newer';
            mkdir($newer);
            file_put_contents($newer . '/keep.txt', 'A separate stage');
            (new GenerateImagesStep($resume->applicationClient(), inspectImages: false))->run($project);
            assert_eq(0, count($client->fake->calls));
            $resume->finishRaw(publish: true, cleanup: true);
            assert_eq($before, file_get_contents($partial . '/0.jpg'));
            assert_eq('A separate stage', file_get_contents($newer . '/keep.txt'));
            assert_eq(null, $resume->directory());
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('failed log publication retains raw files for a later cleanup attempt', function () {
    with_project('builder_image_cleanup_log_', function ($project): void {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $build = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
            $build->afterStep('assemble-pages');
            (new GenerateImagesStep($build->applicationClient(), inspectImages: false))->run($project);
            $directory = $build->directory();
            $log = file_get_contents($directory . '/logs/attempts.jsonl');
            file_put_contents($directory . '/logs/attempts.jsonl', "invalid record\n");
            $error = assert_throws(fn () => $build->finishRaw(publish: true, cleanup: true));
            assert_contains('invalid record', $error->getMessage());
            assert_true(is_file($directory . '/0.jpg'));
            assert_true(is_file($directory . '/results.json'));
            file_put_contents($directory . '/logs/attempts.jsonl', $log);
            $build->finishRaw(publish: true, cleanup: true);
            assert_true(!is_dir($storage));
            assert_eq(4, count(array_filter(explode("\n", $project->readText('logs/images/attempts.jsonl')))));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('a resume with completed image assets removes the earlier complete stage', function () {
    with_project('builder_image_cleanup_finished_', function ($project): void {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $first = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
            $first->afterStep('assemble-pages');
            $directory = $first->directory();
            (new GenerateImagesStep($first->applicationClient(), inspectImages: false))->run($project);
            $first->finishRaw(publish: true);
            assert_true(is_file($directory . '/0.jpg'));
            $asset = $project->readText('theme/assets/hero.jpg');
            $log = $project->readText('logs/images/attempts.jsonl');

            $client = new EarlyImageCountingClient();
            $resume = new EarlyImageBuild($project, $client, ['assemble-pages', 'page-styles'], false, $storage);
            $resume->beforeStep('page-styles');
            assert_eq(null, $resume->directory());
            (new GenerateImagesStep($resume->applicationClient(), inspectImages: false))->run($project);
            $resume->finishRaw(publish: true, cleanup: true);
            assert_true(!is_dir($storage));
            assert_eq(0, count($client->fake->calls));
            assert_eq($asset, $project->readText('theme/assets/hero.jpg'));
            assert_eq($log, $project->readText('logs/images/attempts.jsonl'));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('cleanup preserves stages with unknown files or symlinks', function () {
    with_project('builder_image_cleanup_scope_', function ($project): void {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $build = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
            $build->afterStep('assemble-pages');
            (new GenerateImagesStep($build->applicationClient(), inspectImages: false))->run($project);
            $directory = $build->directory();
            $raw = file_get_contents($directory . '/0.jpg');
            file_put_contents($directory . '/keep.txt', 'Keep this file');
            $build->finishRaw(publish: true, cleanup: true);
            assert_eq($raw, file_get_contents($directory . '/0.jpg'));
            assert_eq('Keep this file', file_get_contents($directory . '/keep.txt'));

            unlink($directory . '/keep.txt');
            symlink($project->path('theme/assets/hero.jpg'), $directory . '/99.jpg');
            $build->finishRaw(publish: true, cleanup: true);
            assert_true(is_link($directory . '/99.jpg'));
            assert_eq($raw, file_get_contents($directory . '/0.jpg'));
            assert_true($project->exists('theme/assets/hero.jpg'));
            unlink($directory . '/99.jpg');
            $build->finishRaw(publish: true, cleanup: true);
            assert_true(!is_dir($storage));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('an interrupted predecessor log preserves its stage without failing a successful resume', function () {
    foreach ([false, true] as $complete) {
        with_project('builder_image_cleanup_old_log_', function ($project) use ($complete): void {
            prepared_image_fixture($project);
            $storage = early_image_storage();
            try {
                $first = new EarlyImageBuild($project, new EarlyImageCountingClient(), ['assemble-pages'], false, $storage);
                $first->afterStep('assemble-pages');
                $first->finishRaw();
                $directory = $first->directory();
                $manifest = json_decode(file_get_contents($directory . '/results.json'), true);
                $manifest['complete'] = $complete;
                file_put_contents($directory . '/results.json', json_encode($manifest));
                file_put_contents($directory . '/logs/attempts.jsonl', '{"partial":', FILE_APPEND);
                $raw = file_get_contents($directory . '/0.jpg');
                $originalLog = file_get_contents($directory . '/logs/attempts.jsonl');

                $client = new EarlyImageCountingClient();
                $resume = new EarlyImageBuild($project, $client, ['assemble-pages'], false, $storage);
                $resume->afterStep('assemble-pages');
                (new GenerateImagesStep($resume->applicationClient(), inspectImages: false))->run($project);
                $resume->finishRaw(publish: true, cleanup: true);
                assert_eq(0, count($client->fake->calls));
                assert_eq(null, $resume->directory());
                assert_eq($raw, file_get_contents($directory . '/0.jpg'));
                assert_eq($originalLog, file_get_contents($directory . '/logs/attempts.jsonl'));
                $publishedLog = $project->readText('logs/images/attempts.jsonl');
                assert_eq(4, count(array_filter(explode("\n", $publishedLog))));
                $resume->finishRaw(publish: true, cleanup: true);
                assert_eq($publishedLog, $project->readText('logs/images/attempts.jsonl'));
            } finally {
                ImageTransportScheduler::current()?->cancel();
                ImageLogger::setDir(null);
                remove_tree($storage);
            }
        });
    }
});
