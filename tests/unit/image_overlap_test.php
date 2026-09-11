<?php
declare(strict_types=1);

use Automattic\SiteBuild\CooperativeTransport;
use Automattic\SiteBuild\ImageClient;
use Automattic\SiteBuild\ImageTransportScheduler;
use Automattic\SiteBuild\Steps\GenerateImagesStep;
use Automattic\SiteBuild\Tests\FakeImageClient;
use Automattic\SiteBuild\TextBatchResult;
use Automattic\SiteBuild\VisionBatchLlm;

require_once __DIR__ . '/image_qa_queue_test.php';

final class OverlapImageClient implements ImageClient, CooperativeTransport
{
    public array $events = [];
    public array $attempts = [];
    public array $delays = [];
    public int $active = 0;
    public int $peak = 0;
    public ?string $bytes = null;
    public bool $failReplacement = false;
    public function __construct(public bool $cooperative = true) {}
    public function supportsCooperativeRequests(array $opts = []): bool { return $this->cooperative; }
    public function model(): string { return 'fake-image'; }
    public function generate(string $prompt, array $opts = []): string { return (new FakeImageClient())->generate($prompt, $opts); }
    public function generateBatch(array $specs, ?callable $onResult = null): array
    {
        $results = [];
        $left = count($specs);
        foreach ($specs as $index => $spec) {
            $work = function () use ($index, $spec, $onResult, &$results, &$left): void {
                while ($this->active >= 10) {
                    ImageTransportScheduler::pause();
                }
                $this->peak = max($this->peak, ++$this->active);
                $asset = $spec['asset'];
                $attempt = $this->attempts[$asset] = ($this->attempts[$asset] ?? 0) + 1;
                $this->events[] = 'start-' . $asset . '-' . $attempt;
                ImageTransportScheduler::pause($this->delays[$asset] ?? 0.003);
                $result = $this->failReplacement && $attempt > 1
                    ? ['ok' => false, 'error' => 'replacement failed']
                    : ['ok' => true, 'bytes' => $this->bytes ?? $this->generate($spec['prompt'], $spec)];
                $this->events[] = 'done-' . $asset . '-' . $attempt;
                if ($onResult !== null) {
                    $onResult($index, $result);
                    unset($result['bytes']);
                }
                $results[$index] = $result;
                $left--;
                $this->active--;
            };
            if (ImageTransportScheduler::current() !== null) {
                ImageTransportScheduler::current()->spawn($work);
            } else {
                $work();
            }
        }
        while ($left > 0) {
            ImageTransportScheduler::pause();
        }
        return $results;
    }
}

final class OverlapVisionLlm implements VisionBatchLlm, CooperativeTransport
{
    public array $attempts = [];
    public array $fail = [];
    public array $unavailable = [];
    public int $activeBytes = 0;
    public int $peakBytes = 0;
    public int $active = 0;
    public int $peak = 0;
    public function __construct(public OverlapImageClient $images) {}
    public function supportsCooperativeRequests(array $opts = []): bool { return true; }
    public function completeImageBatch(array $requests): array
    {
        $this->peak = max($this->peak, ++$this->active);
        $bytes = array_sum(array_map(fn ($request) => strlen($request['image_bytes']), $requests));
        $this->peakBytes = max($this->peakBytes, $this->activeBytes += $bytes);
        $answers = [];
        foreach ($requests as $index => $request) {
            $asset = substr($request['log_label'], strlen('image-qa-'));
            $attempt = $this->attempts[$asset] = ($this->attempts[$asset] ?? 0) + 1;
            $this->images->events[] = 'qa-' . $asset . '-' . $attempt;
            $answers[$index] = in_array($asset, $this->unavailable, true) ? null
                : ($attempt === 1 && in_array($asset, $this->fail, true) ? GI_QA_ROTATED : GI_QA_PASS);
        }
        ImageTransportScheduler::pause(0.015);
        $this->activeBytes -= $bytes;
        $this->active--;
        return $answers;
    }
    public function complete(string $prompt, array $opts = []): string { throw new RuntimeException('Unexpected request'); }
    public function completeJson(string $prompt, array $opts = []): array { throw new RuntimeException('Unexpected request'); }
    public function completeJsonBatch(array $requests): array { throw new RuntimeException('Unexpected request'); }
    public function completeBatch(array $requests): TextBatchResult { throw new RuntimeException('Unexpected request'); }
    public function completeWithImage(string $prompt, string $imageBytes, string $mime, array $opts = []): string { throw new RuntimeException('Unexpected request'); }
}

test('API-capable clients check and replace a fast image before a slow image completes', function () {
    [$project, $tmp] = queue_image_fixture(2);
    try {
        $images = new OverlapImageClient();
        $images->delays = ['img-0.jpg' => 0.003, 'img-1.jpg' => 0.1];
        $llm = new OverlapVisionLlm($images);
        $llm->fail = ['img-0.jpg'];
        (new GenerateImagesStep($images, $llm))->run($project);
        $events = $images->events;
        assert_true(array_search('qa-img-0.jpg-1', $events, true) < array_search('done-img-1.jpg-1', $events, true));
        assert_true(array_search('done-img-0.jpg-2', $events, true) < array_search('done-img-1.jpg-1', $events, true));
        assert_eq(['img-0.jpg' => 2, 'img-1.jpg' => 1], $images->attempts);
        assert_eq(true, $project->readJson('images.json')[0]['qa']['regenerated']);
        assert_eq(null, ImageTransportScheduler::current());
    } finally {
        remove_tree($tmp);
    }
});

test('twenty failed checks keep image and vision concurrency bounded', function () {
    [$project, $tmp] = queue_image_fixture(20);
    try {
        $images = new OverlapImageClient();
        $llm = new OverlapVisionLlm($images);
        $llm->fail = array_column($project->readJson('images.json'), 'filename');
        (new GenerateImagesStep($images, $llm))->run($project);
        assert_eq(array_fill(0, 20, 2), array_values($images->attempts));
        assert_eq(array_fill(0, 20, 2), array_values($llm->attempts));
        assert_true($images->peak <= 10);
        assert_true($llm->peak <= 10);
        assert_true(!$project->exists('warnings.json'));
    } finally {
        remove_tree($tmp);
    }
});

test('concurrent QA reserves one shared raw byte budget and preserves failed replacements', function () {
    [$project, $tmp] = queue_image_fixture(12);
    try {
        $images = new OverlapImageClient();
        $jpeg = (new FakeImageClient())->generate('fixture');
        $images->bytes = substr($jpeg, 0, 2) . str_repeat("\xff\xfe\xff\xff" . str_repeat('x', 65533), 49) . substr($jpeg, 2);
        $images->failReplacement = true;
        $llm = new OverlapVisionLlm($images);
        $llm->fail = ['img-0.jpg'];
        $llm->unavailable = ['img-1.jpg'];
        (new GenerateImagesStep($images, $llm))->run($project);
        assert_true($llm->peakBytes <= GenerateImagesStep::MAX_QA_BYTES);
        assert_eq(array_fill(0, 12, 'completed'), array_column($project->readJson('images.json'), 'status'));
        assert_contains('replacement failed', implode(' ', $project->readJson('warnings.json')['generate-images']));
        assert_eq(13, array_sum($images->attempts));
    } finally {
        remove_tree($tmp);
    }
});

test('a synchronous host keeps the existing batch path', function () {
    [$project, $tmp] = queue_image_fixture(2);
    try {
        $images = new OverlapImageClient(false);
        $llm = new OverlapVisionLlm($images);
        (new GenerateImagesStep($images, $llm))->run($project);
        assert_true(array_search('done-img-1.jpg-1', $images->events, true) < array_search('qa-img-0.jpg-1', $images->events, true));
        assert_eq(null, ImageTransportScheduler::current());
    } finally {
        remove_tree($tmp);
    }
});
