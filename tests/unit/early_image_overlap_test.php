<?php
declare(strict_types=1);

use Automattic\SiteBuild\CooperativeTransport;
use Automattic\SiteBuild\EarlyImageBuild;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\ImageRequestLedger;
use Automattic\SiteBuild\ImageTransportScheduler;
use Automattic\SiteBuild\ImageUsageReporting;
use Automattic\SiteBuild\Steps\GenerateImagesStep;

require_once __DIR__ . '/early_image_build_test.php';
require_once __DIR__ . '/image_overlap_test.php';

final class LedgerOverlapImageClient implements ImageClient, CooperativeTransport, ImageUsageReporting
{
    public OverlapImageClient $inner;
    private ImageRequestLedger $ledger;
    public function __construct() { $this->inner = new OverlapImageClient(); $this->ledger = new ImageRequestLedger(); }
    public function supportsCooperativeRequests(array $opts = []): bool { return true; }
    public function model(): string { return $this->inner->model(); }
    public function imageUsageTotals(): array { return $this->ledger->totals(); }
    public function generate(string $prompt, array $opts = []): string { return $this->inner->generate($prompt, $opts); }
    public function generateBatch(array $requests, ?callable $onResult = null): array
    {
        $directory = ImageLogger::dir();
        return $this->inner->generateBatch($requests, function ($index, $result) use ($requests, $onResult, $directory): void {
            $record = ['asset' => $requests[$index]['asset'], 'ok' => $result['ok'], 'seconds' => 0.01];
            $this->ledger->record($record);
            ImageLogger::attemptIn($directory, $record);
            $onResult($index, $result);
        });
    }
}

foreach ([false, true] as $failReplacement) {
$case = $failReplacement ? 'failed replacement' : 'successful replacement';
test('the default early stage permits fast QA before the slow raw result: ' . $case, function () use ($failReplacement) {
    [$project, $tmp] = queue_image_fixture(2);
    $storage = early_image_storage();
    try {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'path' => '/', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero']]]]]);
        $project->writeJson('plugin/pages.json', ['pages' => [['slug' => 'home']]]);
        $project->writeText('plugin/pages/home.html', '<img src="theme:./assets/img-0.jpg"><img src="theme:./assets/img-1.jpg">');
        seed_test_design_direction($project);
        $client = new LedgerOverlapImageClient();
        $client->inner->delays = ['img-0.jpg' => 0.003, 'img-1.jpg' => 0.1];
        $client->inner->failReplacement = $failReplacement;
        $llm = new OverlapVisionLlm($client->inner);
        $llm->fail = ['img-0.jpg'];
        $build = new EarlyImageBuild($project, $client, ['assemble-pages', 'page-styles'], false, $storage);
        $build->afterStep('assemble-pages');
        assert_true(!$project->exists('theme/assets/img-0.jpg'));
        assert_true(!in_array('done-img-1.jpg-1', $client->inner->events, true));
        $replay = $build->applicationClient();
        (new GenerateImagesStep($replay, $llm))->run($project);
        $build->finishRaw(publish: true);
        $events = $client->inner->events;
        assert_true(in_array('qa-img-0.jpg-1', $events, true));
        assert_true(array_search('qa-img-0.jpg-1', $events, true) < array_search('done-img-1.jpg-1', $events, true));
        assert_true(array_search('done-img-0.jpg-2', $events, true) < array_search('done-img-1.jpg-1', $events, true));
        if (!$failReplacement) {
            assert_true(in_array('qa-img-0.jpg-2', $events, true));
            assert_true(array_search('qa-img-0.jpg-2', $events, true) < array_search('done-img-1.jpg-1', $events, true));
        }
        assert_eq(['img-0.jpg' => 2, 'img-1.jpg' => 1], $client->inner->attempts);
        assert_eq(2, $replay->reusedResults());
        assert_eq(3, $client->imageUsageTotals()['attempts']);
        $rawRows = array_filter(explode("\n", file_get_contents($build->directory() . '/logs/attempts.jsonl')));
        assert_eq(2, count($rawRows));
        $published = $project->readText('logs/images/attempts.jsonl');
        assert_eq(3, count(array_filter(explode("\n", $published))));
        $build->finishRaw(publish: true);
        assert_eq($published, $project->readText('logs/images/attempts.jsonl'));
        assert_eq(['completed', 'completed'], array_column($project->readJson('images.json'), 'status'));
        assert_true($project->exists('images.generated.json'));
        assert_eq(null, ImageTransportScheduler::current());
    } finally {
        ImageTransportScheduler::current()?->cancel();
        ImageLogger::setDir(null);
        remove_tree($tmp);
        remove_tree($storage);
    }
});
}

test('serial apply failure retains paid raw results and publishes their attempts in finally', function () {
    [$project, $tmp] = queue_image_fixture(2);
    $storage = early_image_storage();
    try {
        $project->writeJson('siteSpec.json', ['name' => 'Demo']);
        $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'path' => '/', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero']]]]]);
        $project->writeJson('plugin/pages.json', ['pages' => [['slug' => 'home']]]);
        $project->writeText('plugin/pages/home.html', '<img src="theme:./assets/img-0.jpg"><img src="theme:./assets/img-1.jpg">');
        seed_test_design_direction($project);
        $client = new LedgerOverlapImageClient();
        $client->inner->delays = ['img-0.jpg' => 0.003, 'img-1.jpg' => 0.03];
        $build = new EarlyImageBuild($project, $client, ['assemble-pages'], false, $storage);
        $build->afterStep('assemble-pages');
        $project->writeText('theme/assets', 'This file prevents the asset directory.');
        $error = assert_throws(function () use ($build, $project): void {
            try {
                (new GenerateImagesStep($build->applicationClient()))->run($project);
            } finally {
                $build->finishRaw(publish: true);
            }
        });
        assert_contains('assets directory', $error->getMessage());
        assert_eq(2, $client->imageUsageTotals()['attempts']);
        assert_eq(['img-0.jpg' => 1, 'img-1.jpg' => 1], $client->inner->attempts);
        assert_eq(2, count(array_filter(explode("\n", $project->readText('logs/images/attempts.jsonl')))));
        assert_eq(2, count(json_decode(file_get_contents($build->directory() . '/results.json'), true)['results']));
        assert_true(!$project->exists('images.generated.json'));
        assert_eq(null, ImageTransportScheduler::current());
    } finally {
        ImageTransportScheduler::current()?->cancel();
        ImageLogger::setDir(null);
        remove_tree($tmp);
        remove_tree($storage);
    }
});
