<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\VisionBatchLlm;
use Automattic\SiteBuild\TextBatchResult;

require_once __DIR__ . '/generate_images_test.php';

final class QueueImageLlm implements VisionBatchLlm
{
    public array $counts = [];
    public array $bytes = [];
    public ?Closure $answer = null;

    public function completeImageBatch(array $requests): array
    {
        $this->counts[] = count($requests);
        $this->bytes[] = array_sum(array_map(fn ($request) => strlen($request['image_bytes']), $requests));
        return $this->answer !== null ? ($this->answer)($requests) : array_fill_keys(array_keys($requests), GI_QA_PASS);
    }
    public function complete(string $prompt, array $opts = []): string { throw new RuntimeException('Unexpected request'); }
    public function completeJson(string $prompt, array $opts = []): array { throw new RuntimeException('Unexpected request'); }
    public function completeJsonBatch(array $requests): array { throw new RuntimeException('Unexpected request'); }
    public function completeBatch(array $requests): TextBatchResult { throw new RuntimeException('Unexpected request'); }
    public function completeWithImage(string $prompt, string $imageBytes, string $mime, array $opts = []): string { throw new RuntimeException('Unexpected request'); }
}

function queue_image_fixture(int $count): array
{
    [$project, $tmp] = batch_fixture($count);
    $rows = $project->readJson('images.json');
    foreach ($rows as &$row) {
        $row['pageContext'] = 'full-bleed hero cover';
    }
    unset($row);
    $project->writeJson('images.json', $rows);
    return [$project, $tmp];
}

test('the QA queue preserves pooled replacements after bounded checks', function () {
    [$project, $tmp] = queue_image_fixture(12);
    try {
        $client = new FakeImageClient();
        $client->failPromptSubstrings = ['The camera is upright and level:'];
        $events = [];
        $client->afterEachResult = function ($index) use (&$events, $client): void {
            if (count($client->batches) > 1) {
                $events[] = 'replacement';
            }
        };
        $llm = new QueueImageLlm();
        $llm->answer = function ($requests) use (&$events): array {
            $events[] = 'qa-' . array_key_first($requests);
            $answers = array_fill_keys(array_keys($requests), GI_QA_PASS);
            if (isset($requests[0])) {
                $answers[0] = GI_QA_ROTATED;
                $answers[2] = null;
            }
            return $answers;
        };
        (new GenerateImagesStep($client, $llm))->run($project);
        assert_eq([10, 2], $llm->counts);
        assert_eq(['qa-0', 'qa-10', 'replacement'], $events);
        assert_eq([12, 1], array_map('count', $client->batches));
        assert_eq(array_fill(0, 12, 'completed'), array_column($project->readJson('images.json'), 'status'));
        assert_contains('regeneration failed', implode(' ', $project->readJson('warnings.json')['generate-images']));
        assert_true($project->exists('theme/assets/img-11.jpg'));
    } finally {
        remove_tree($tmp);
    }
});

test('the QA queue bounds raw payload bytes as well as image count', function () {
    [$project, $tmp] = queue_image_fixture(12);
    try {
        $jpeg = (new FakeImageClient())->generate('fixture');
        $client = new FakeImageClient(substr($jpeg, 0, 2) . str_repeat("\xff\xfe\xff\xff" . str_repeat('x', 65533), 49) . substr($jpeg, 2));
        $llm = new QueueImageLlm();
        (new GenerateImagesStep($client, $llm))->run($project);
        assert_eq([5, 5, 2], $llm->counts);
        foreach ($llm->bytes as $bytes) {
            assert_true($bytes <= GenerateImagesStep::MAX_QA_BYTES);
        }
        assert_eq(12, count($client->calls));
    } finally {
        remove_tree($tmp);
    }
});

test('an image above the QA byte limit survives with an actionable warning', function () {
    [$project, $tmp] = queue_image_fixture(1);
    try {
        $jpeg = (new FakeImageClient())->generate('fixture');
        $client = new FakeImageClient(substr($jpeg, 0, 2) . str_repeat("\xff\xfe\xff\xff" . str_repeat('x', 65533), 257) . substr($jpeg, 2));
        $llm = new QueueImageLlm();
        (new GenerateImagesStep($client, $llm))->run($project);
        assert_eq([], $llm->counts);
        assert_eq('completed', $project->readJson('images.json')[0]['status']);
        $warnings = implode(' ', $project->readJson('warnings.json')['generate-images']);
        foreach (['img-0.jpg', 'QA payload exceeds the byte limit', 'delivered as generated', 'QA skipped'] as $expected) {
            assert_contains($expected, $warnings);
        }
    } finally {
        remove_tree($tmp);
    }
});

test('twenty failed checks keep all replacements in one provider pool', function () {
    [$project, $tmp] = queue_image_fixture(20);
    try {
        $client = new FakeImageClient();
        $llm = new QueueImageLlm();
        $seen = [];
        $llm->answer = function ($requests) use (&$seen): array {
            $answers = [];
            foreach ($requests as $index => $_request) {
                $answers[$index] = isset($seen[$index]) ? GI_QA_PASS : GI_QA_ROTATED;
                $seen[$index] = true;
            }
            return $answers;
        };
        (new GenerateImagesStep($client, $llm))->run($project);
        assert_eq([20, 20], array_map('count', $client->batches));
        assert_eq([10, 10, 10, 10], $llm->counts);
        assert_eq(20, count($seen));
        assert_true(!$project->exists('warnings.json'));
    } finally {
        remove_tree($tmp);
    }
});

test('failed checks from different groups share one replacement pool', function () {
    [$project, $tmp] = queue_image_fixture(20);
    try {
        $client = new FakeImageClient();
        $llm = new QueueImageLlm();
        $seen = [];
        $llm->answer = function ($requests) use (&$seen): array {
            $answers = [];
            foreach ($requests as $index => $_request) {
                $answers[$index] = !isset($seen[$index]) && in_array($index, [0, 10], true)
                    ? GI_QA_ROTATED : GI_QA_PASS;
                $seen[$index] = true;
            }
            return $answers;
        };
        (new GenerateImagesStep($client, $llm))->run($project);
        assert_eq([20, 2], array_map('count', $client->batches));
        assert_eq([0, 10], array_keys($client->batches[1]));
        assert_eq([10, 10, 2], $llm->counts);
        assert_eq(22, count($client->calls));
    } finally {
        remove_tree($tmp);
    }
});
