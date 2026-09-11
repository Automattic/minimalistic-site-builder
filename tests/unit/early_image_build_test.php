<?php
declare(strict_types=1);
use Automattic\SiteBuild\EarlyImageBuild;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\ImageRequestLedger;
use Automattic\SiteBuild\ImageTransportScheduler;
use Automattic\SiteBuild\ImageUsageReporting;
use Automattic\SiteBuild\PreparedImageBatch;
use Automattic\SiteBuild\StagedImageClient;
use Automattic\SiteBuild\Tests\FakeImageClient;

/** A local client records two provider attempts for each final result. */
final class EarlyImageCountingClient implements ImageClient, ImageUsageReporting
{
    public FakeImageClient $fake;
    private ImageRequestLedger $ledger;
    public function __construct() { $this->fake = new FakeImageClient(); $this->ledger = new ImageRequestLedger(); }
    public function model(): string { return $this->fake->model(); }
    public function imageUsageTotals(): array { return $this->ledger->totals(); }
    public function generate(string $prompt, array $opts = []): string { return $this->fake->generate($prompt, $opts); }
    public function generateBatch(array $requests, ?callable $onResult = null): array
    {
        return $this->fake->generateBatch($requests, function ($index, $result) use ($onResult): void {
            foreach ([false, true] as $ok) {
                $record = ['ok' => $ok, 'seconds' => 1.0, 'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3]];
                $this->ledger->record($record);
                ImageLogger::attempt($record);
            }
            $onResult($index, $result);
        });
    }
}

function early_image_storage(): string
{
    $directory = sys_get_temp_dir() . '/early-image-test-' . bin2hex(random_bytes(8));
    mkdir($directory);
    return $directory;
}

function early_image_snapshot($project): array
{
    $out = [];
    foreach (['images.json', 'pages.json', 'siteSpec.json', 'plugin/pages.json', 'plugin/pages/home.html', 'plugin/pages/about.html'] as $file) {
        $out[$file] = $project->readText($file);
    }
    return $out;
}

test('early images start after final references and write project assets only during serial apply', function () {
    with_project('builder_early_images_', function ($project) {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $client = new EarlyImageCountingClient();
            $build = new EarlyImageBuild($project, $client, ['sections', 'assemble-pages', 'page-styles'], false, $storage);
            $before = early_image_snapshot($project);
            $build->beforeStep('sections');
            $build->afterStep('sections');
            assert_eq(0, count($client->fake->calls));
            $build->beforeStep('assemble-pages');
            assert_eq(0, count($client->fake->calls));
            $build->afterStep('assemble-pages');
            assert_eq(2, count($client->fake->calls));
            assert_eq($before, early_image_snapshot($project));
            assert_true(!$project->exists('theme/assets/hero.jpg'));
            assert_true(!$project->exists('logs/images/attempts.jsonl'));
            assert_true(is_file($build->directory() . '/logs/attempts.jsonl'));
            $application = $build->applicationClient();
            $requests = PreparedImageBatch::fromProject($project)->requests();
            $application->generateBatch($requests, function ($index, $result) use ($project): void {
                assert_eq(true, $result['ok']);
                $project->writeText('theme/assets/applied-' . $index . '.jpg', $result['bytes']);
            });
            assert_eq(2, $application->reusedResults());
            assert_eq(2, count($client->fake->calls));
            assert_eq(4, $client->imageUsageTotals()['attempts']);
            $build->finishRaw(publish: true);
            $log = $project->readText('logs/images/attempts.jsonl');
            assert_eq(4, count(array_filter(explode("\n", $log))));
            $build->applicationClient();
            assert_eq($log, $project->readText('logs/images/attempts.jsonl'));
            $application->generateBatch($requests);
            assert_eq(4, count($client->fake->calls), 'a later QA replacement must make a new request');
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('an HTML-first resume waits for fix-pages and preserves paid results after a graph failure', function () {
    with_project('builder_early_resume_', function ($project) {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $client = new EarlyImageCountingClient();
            $ids = ['assemble-pages', 'fix-pages', 'page-styles'];
            $build = new EarlyImageBuild($project, $client, $ids, true, $storage);
            $build->afterStep('assemble-pages');
            assert_eq(0, count($client->fake->calls));
            $build->afterStep('fix-pages');
            $build->finishRaw();
            assert_true(!$project->exists('images.generated.json'));
            assert_true(!$project->exists('theme/assets/hero.jpg'));
            assert_true(is_file($build->directory() . '/results.json'));
            $resumeClient = new EarlyImageCountingClient();
            $resume = new EarlyImageBuild($project, $resumeClient, $ids, true, $storage);
            $resume->beforeStep('page-styles');
            $replay = $resume->applicationClient();
            $results = $replay->generateBatch(PreparedImageBatch::fromProject($project)->requests());
            assert_eq(2, count($results));
            assert_eq(0, count($resumeClient->fake->calls));
            assert_eq(0, $resumeClient->imageUsageTotals()['attempts']);
            $resume->finishRaw(publish: true);
            assert_eq(4, count(array_filter(explode("\n", $project->readText('logs/images/attempts.jsonl')))));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            ImageLogger::setDir(null);
            remove_tree($storage);
        }
    });
});

test('changed metadata retains exact paid requests and removed references receive no result', function () {
    with_project('builder_early_changed_', function ($project) {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $client = new FakeImageClient();
            $build = new EarlyImageBuild($project, $client, ['assemble-pages', 'page-styles'], false, $storage);
            $build->afterStep('assemble-pages');
            $project->writeText('plugin/pages/home.html', '<div class="new-layout"><img src="theme:./assets/hero.jpg"></div>');
            $replay = $build->applicationClient();
            $replay->generateBatch(PreparedImageBatch::fromProject($project)->requests());
            assert_eq(2, count($client->calls));
            assert_eq(2, $replay->reusedResults());
            $project->writeText('plugin/pages/home.html', '<p>The hero was removed.</p>');
            $replay = $build->applicationClient();
            $requests = PreparedImageBatch::fromProject($project)->requests();
            assert_eq([3], array_keys($requests));
            $replay->generateBatch($requests);
            assert_eq(1, $replay->reusedResults());
            assert_eq(2, count($client->calls));
        } finally {
            ImageTransportScheduler::current()?->cancel();
            remove_tree($storage);
        }
    });
});

test('a changed request gets fresh pixels while exact duplicate raw requests share one transfer', function () {
    with_project('builder_early_duplicate_', function ($project) {
        prepared_image_fixture($project);
        $rows = $project->readJson('images.json');
        $rows[5] = array_replace($rows[0], ['filename' => 'copy.jpg', 'src' => 'theme:./assets/copy.jpg']);
        $project->writeJson('images.json', $rows);
        $project->writeText('plugin/pages/home.html', '<img src="theme:./assets/hero.jpg"><img src="theme:./assets/copy.jpg">');
        $directory = early_image_storage();
        try {
            $client = new FakeImageClient();
            $batch = PreparedImageBatch::fromProject($project);
            $batch->stage($client, $directory);
            assert_eq(2, count($client->calls));
            $rows[0]['subject'] = 'A different brass lamp';
            $project->writeJson('images.json', $rows);
            $current = PreparedImageBatch::fromProject($project);
            $replay = new StagedImageClient($client);
            $replay->load($current, $directory);
            $results = $replay->generateBatch($current->requests());
            assert_eq(3, count($results));
            assert_eq(3, count($client->calls));
            assert_eq(2, $replay->reusedResults());
        } finally {
            remove_tree($directory);
        }
    });
});

test('split initial batches retain unused results and consumed assets get fresh QA replacements', function () {
    with_project('builder_early_subsets_', function ($project) {
        prepared_image_fixture($project);
        $directory = early_image_storage();
        try {
            $client = new FakeImageClient();
            $batch = PreparedImageBatch::fromProject($project);
            $batch->stage($client, $directory);
            $replay = new StagedImageClient($client);
            $replay->load($batch, $directory);
            $requests = $batch->requests();
            $replay->generateBatch([0 => $requests[0]]);
            $replay->generateBatch([0 => $requests[3]]);
            assert_eq(2, count($client->calls));
            assert_eq(2, $replay->reusedResults());
            $replay->generateBatch([0 => $requests[0]]);
            assert_eq(3, count($client->calls));
        } finally {
            remove_tree($directory);
        }
    });
});

test('staged results require the same project and provider identity', function () {
    with_project('builder_early_identity_', function ($project) {
        prepared_image_fixture($project);
        $directory = early_image_storage();
        try {
            $client = new FakeImageClient();
            $batch = PreparedImageBatch::fromProject($project);
            $manifest = $batch->stage($client, $directory);
            foreach (['project_root', 'provider'] as $field) {
                $changed = array_replace($manifest, [$field => 'different']);
                file_put_contents($directory . '/results.json', json_encode($changed));
                $replay = new StagedImageClient($client);
                $replay->load($batch, $directory);
                $replay->generateBatch($batch->requests());
                assert_eq(0, $replay->reusedResults());
            }
            assert_eq(6, count($client->calls));
        } finally {
            remove_tree($directory);
        }
    });
});

test('a corrupt completed stage fails before active follow without another provider request', function () {
    with_project('builder_early_corrupt_', function ($project) {
        prepared_image_fixture($project);
        $storage = early_image_storage();
        try {
            $client = new FakeImageClient();
            $build = new EarlyImageBuild($project, $client, ['assemble-pages'], false, $storage);
            $build->afterStep('assemble-pages');
            file_put_contents($build->directory() . '/0.jpg', 'corrupt');
            $error = assert_throws(fn () => $build->applicationClient());
            assert_contains('checksum', $error->getMessage());
            assert_eq(2, count($client->calls));
            $build->finishRaw(publish: true);
            assert_eq(null, ImageTransportScheduler::current());
        } finally {
            ImageTransportScheduler::current()?->cancel();
            remove_tree($storage);
        }
    });
});

test('unchanged manifest polls retain verified metadata and apply still checks file bytes', function () {
    with_project('builder_early_verified_', function ($project) {
        prepared_image_fixture($project);
        $directory = early_image_storage();
        try {
            $client = new FakeImageClient();
            $batch = PreparedImageBatch::fromProject($project);
            $batch->stage($client, $directory);
            $replay = new StagedImageClient($client);
            $replay->follow($batch, $directory);
            file_put_contents($directory . '/0.jpg', 'corrupt after verification');
            $replay->load($batch, $directory, pending: true);
            $error = assert_throws(fn () => $replay->generateBatch([0 => $batch->requests()[0]]));
            assert_contains('changed before serial apply', $error->getMessage());
            assert_eq(2, count($client->calls));
        } finally {
            remove_tree($directory);
        }
    });
});
