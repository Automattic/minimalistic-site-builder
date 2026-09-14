<?php
declare(strict_types=1);

use Automattic\SiteBuild\ImageRequestLedger;
use Automattic\SiteBuild\ImageLogger;
use Automattic\SiteBuild\WpcomImageClient;
use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\BuildReport;

test('image ledger counts all thirteen Atlas attempts separately from five delivered assets', function () {
    $ledger = new ImageRequestLedger();
    for ($i = 0; $i < 13; $i++) {
        $ledger->record(['ok' => true, 'seconds' => 2.0, 'usage' => null]);
    }
    $totals = $ledger->totals();
    assert_eq(13, $totals['attempts']);
    assert_eq(26.0, $totals['request_seconds']);
    assert_eq(null, $totals['input_tokens']);
    assert_eq(false, $totals['usage_complete']);
    $report = new BuildReport('p', 'atlas', '/tmp/atlas', 'today');
    $report->setImages(5, 0, 5);
    $report->setImageRequests($totals);
    assert_eq(5, $report->stats('model', [])['images']['delivered_assets']);
    assert_eq(13, $report->stats('model', [])['images']['provider_requests']['attempts']);
    assert_contains('13 provider attempts', $report->imagesLine());
});

test('image ledger preserves reported usage and identifies incomplete provider totals', function () {
    $ledger = new ImageRequestLedger();
    $usage = ImageRequestLedger::usage('{"usageMetadata":{"promptTokenCount":11,"candidatesTokenCount":22,"totalTokenCount":35}}');
    $ledger->record(['ok' => true, 'usage' => $usage]);
    assert_eq(35, $ledger->totals()['total_tokens']);
    $ledger->record(['ok' => false, 'usage' => null]);
    assert_eq(2, $ledger->totals()['attempts']);
    assert_eq(1, $ledger->totals()['failed_attempts']);
    assert_eq(null, $ledger->totals()['total_tokens']);
    assert_eq(35, $ledger->totals()['reported_total_tokens']);
    assert_eq(null, ImageRequestLedger::usage('invalid json'));
    assert_eq(null, ImageRequestLedger::usage('{"usageMetadata":{}}'));
});

test('image attempt records include failures, size, asset, times and usage without payload bytes', function () {
    $dir = sys_get_temp_dir() . '/image_attempts_' . uniqid();
    ImageLogger::setDir($dir);
    ImageLogger::setEnabled(true);
    $client = new WpcomImageClient('private-token');
    $method = new ReflectionMethod($client, 'recordAttempt');
    $method->setAccessible(true);
    $ch = curl_init();
    $body = GeminiImage::buildBody('private prompt', ['sample_image_size' => '2K']);
    $raw = '{"usageMetadata":{"promptTokenCount":10,"candidatesTokenCount":20,"totalTokenCount":30},"private":"image bytes"}';
    $method->invoke($client, $ch, $body, $raw, 'filtered', 'hero.jpg', null, $dir);
    $method->invoke($client, $ch, $body, $raw, null, 'hero.jpg', null, $dir);
    assert_eq(2, $client->requestCount());
    assert_eq(1, $client->imageUsageTotals()['failed_attempts']);
    assert_eq(60, $client->imageUsageTotals()['total_tokens']);
    $text = file_get_contents($dir . '/attempts.jsonl');
    $records = array_map(fn ($row) => json_decode($row, true), explode("\n", trim($text)));
    assert_eq(2, count($records));
    assert_eq('hero.jpg', $records[0]['asset']);
    assert_eq('2K', $records[0]['sample_image_size']);
    assert_eq(null, $records[0]['first_response_at']);
    assert_true($records[0]['completed_at'] >= $records[0]['started_at']);
    foreach (['private-token', 'private prompt', 'image bytes'] as $private) {
        assert_true(!str_contains($text, $private));
    }
    unlink($dir . '/attempts.jsonl');
    rmdir($dir);
    ImageLogger::setDir(null);
    ImageLogger::setEnabled(true);
});

test('local UI renders do not inflate provider attempts', function () {
    $report = new BuildReport('p', 'atlas', '/tmp/atlas', 'today');
    $ledger = new ImageRequestLedger();
    $ledger->record(['ok' => true]);
    $report->setImages(5, 0, 5);
    $report->setImageRequests($ledger->totals(), 4);
    $images = $report->stats('model', [])['images'];
    assert_eq(4, $images['local_renders']);
    assert_eq(1, $images['provider_requests']['attempts']);
});
