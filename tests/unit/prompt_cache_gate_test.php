<?php
declare(strict_types=1);
use Automattic\SiteBuild\PromptCacheGate;
use Automattic\SiteBuild\RollingPool;
use Automattic\SiteBuild\AnthropicClient;

test('cache gate releases only after a complete message start or its deadline', function () {
    $now = 0.0;
    $gate = new PromptCacheGate(static function () use (&$now): float { return $now; });
    $gate->observe("event: ping\ndata: {\"type\":\"ping\"}\n\n");
    assert_eq(false, $gate->ready());
    $start = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"x\"}}";
    $gate->observe($start . "\n");
    assert_eq(false, $gate->ready());
    $gate->observe($start . "\n\n");
    assert_eq(true, $gate->ready());
    $other = new PromptCacheGate(static function () use (&$now): float { return $now; });
    $now = 9.9;
    assert_eq(false, $other->ready());
    $now = 10.0;
    assert_eq(true, $other->ready());
});

test('cache gate chooses a useful deepest request without changing payloads or keys', function () {
    $bodies = [];
    foreach (['short' => ['site'], 'deep' => ['site', 'build', 'page'], 4 => []] as $key => $prefixes) {
        $bodies[$key] = AnthropicClient::bodyFor(['prompt' => 'real markup ' . $key, 'cached_prefixes' => $prefixes], 'model', 500);
    }
    $ordered = PromptCacheGate::order($bodies);
    assert_eq(['deep', 'short', 4], array_keys($ordered));
    foreach ($ordered as $key => $body) { assert_eq($bodies[$key], $body); }
    assert_eq(true, PromptCacheGate::applies($bodies));
    assert_eq(false, PromptCacheGate::applies([$bodies['deep']]));
    assert_eq(false, PromptCacheGate::applies([$bodies[4], $bodies[4]]));
});

test('pool starts siblings at cache readiness before the first request finishes', function () {
    $ready = false;
    $events = [];
    $round = 0;
    $results = RollingPool::run(['first' => 1, 'second' => 2, 'third' => 3],
        function ($key) use (&$events): void { $events[] = 'start:' . $key; },
        function () use (&$ready, &$round, &$events): array {
            $round++;
            if ($round === 1) {
                $events[] = 'first:message_start';
                $ready = true;
                return [];
            }
            $events[] = 'complete';
            return ['second' => 2, 'first' => 1, 'third' => 3];
        }, 3, static function () use (&$ready): bool { return $ready; });
    assert_eq(['start:first', 'first:message_start', 'start:second', 'start:third', 'complete'], $events);
    assert_eq(['first' => 1, 'second' => 2, 'third' => 3], $results);
});

test('pool retains the empty completion invariant when readiness does not change', function () {
    assert_throws(fn () => RollingPool::run(['a' => 1], fn () => null, fn () => [], 3, fn () => false));
});

test('router uses the primed capability and preserves keys and degradation notes', function () {
    $transport = new class implements Automattic\SiteBuild\PrefixPrimingLlm {
        public int $primed = 0;
        public function canPrimeBatch(array $requests): bool { return true; }
        public function complete(string $prompt, array $opts = []): string { throw new RuntimeException('Unexpected single request.'); }
        public function completeJson(string $prompt, array $opts = []): array { throw new RuntimeException('Unexpected JSON request.'); }
        public function completeJsonBatch(array $requests): array { throw new RuntimeException('Unexpected JSON batch.'); }
        public function completeBatch(array $requests): Automattic\SiteBuild\TextBatchResult { throw new RuntimeException('Unexpected text batch.'); }

        public function completePrimedBatch(array $requests): Automattic\SiteBuild\TextBatchResult {
            $this->primed++;
            return new Automattic\SiteBuild\TextBatchResult(['part' => '<p>Done</p>'], ['part' => ['Retained output after a limit.']]);
        }
    };
    $router = new Automattic\SiteBuild\RoutingLlm(['a' => $transport]);
    $result = $router->completePrimedBatch(['part' => ['prompt' => 'Make a section.']]);
    assert_eq(1, $transport->primed);
    assert_eq(['part' => '<p>Done</p>'], $result->texts);
    assert_eq(['Retained output after a limit.'], $result->notesFor('part'));
});
