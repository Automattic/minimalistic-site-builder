<?php
declare(strict_types=1);

use Automattic\SiteBuild\FinishReasonAwareLlm;
use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\RoutingLlm;
use Automattic\SiteBuild\TextBatchResult;
use Automattic\SiteBuild\UsageReporting;
use Automattic\SiteBuild\VisionLlm;

/**
 * Unit tests for RoutingLlm: which transport serves a request, and that
 * splitting a batch across transports loses nothing. No network.
 */

/**
 * Recording transport. Every answer is prefixed with this transport's name, so
 * a test can tell which one served a given key without inspecting call logs.
 */
final class RecordingTransport implements FinishReasonAwareLlm, UsageReporting
{
    /** @var list<array<array-key,mixed>> one entry per batch call */
    public array $batches = [];
    public int $singleCalls = 0;
    /** @var array<string,mixed> opts of the most recent single completion */
    public array $lastOpts = [];
    /** Keys to omit from the next batch response, modelling a losing transport. */
    public array $dropKeys = [];

    /** @param array<string,int> $usage */
    public function __construct(
        public string $name,
        private array $usage = [],
        private ?string $finishReason = 'stop',
    ) {
    }

    public function complete(string $prompt, array $opts = []): string
    {
        $this->singleCalls++;
        $this->lastOpts = $opts;
        return $this->name . ':' . $prompt;
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        $this->singleCalls++;
        return ['served_by' => $this->name, 'prompt' => $prompt];
    }

    public function completeJsonBatch(array $requests): array
    {
        $this->batches[] = array_keys($requests);
        $out = [];
        foreach ($requests as $key => $request) {
            if (in_array($key, $this->dropKeys, true)) {
                continue;
            }
            $out[$key] = ['served_by' => $this->name, 'model' => $request['model'] ?? null];
        }
        return $out;
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        $this->batches[] = array_keys($requests);
        $texts = [];
        $notes = [];
        foreach ($requests as $key => $request) {
            if (in_array($key, $this->dropKeys, true)) {
                continue;
            }
            $texts[$key] = $this->name . ':' . ($request['prompt'] ?? '');
            $notes[$key] = [$this->name . ' note'];
        }
        return new TextBatchResult($texts, $notes);
    }

    public function lastFinishReason(): ?string
    {
        return $this->finishReason;
    }

    public function usageTotals(): array
    {
        return $this->usage + [
            'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
        ];
    }
}

/** A transport that reports nothing, to prove usage summing skips it. */
final class SilentTransport implements Llm
{
    public function complete(string $prompt, array $opts = []): string
    {
        return '';
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        return [];
    }

    public function completeJsonBatch(array $requests): array
    {
        return array_map(static fn () => [], $requests);
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        return new TextBatchResult(array_map(static fn () => '', $requests));
    }
}

/** @return array{0:RoutingLlm,1:RecordingTransport,2:RecordingTransport} */
function routing_fixture(): array
{
    $baseten = new RecordingTransport('baseten');
    $anthropic = new RecordingTransport('anthropic');
    $router = new RoutingLlm(
        ['baseten' => $baseten, 'anthropic' => $anthropic],
        ['claude-opus-5' => 'anthropic'],
        'baseten',
    );
    return [$router, $baseten, $anthropic];
}

test('RoutingLlm sends each model to its pinned transport and everything else to the default', function () {
    [$router] = routing_fixture();

    assert_eq('anthropic', $router->transportFor('claude-opus-5'), 'the pinned model is routed');
    assert_eq('anthropic', $router->transportFor('CLAUDE-OPUS-5'), 'model ids match case-insensitively');
    assert_eq('baseten', $router->transportFor('moonshotai/Kimi-K3'), 'the large tier stays on the default');
    assert_eq('baseten', $router->transportFor('zai-org/GLM-5.2-Fast'), 'the small tier stays on the default');

    // design-preview, inner-pages-design and transform-site are built with a
    // null model unless the provider pins one, and LlmOptions omits the key
    // entirely rather than sending null — so this is a live path, not a guard.
    assert_eq('baseten', $router->transportFor(null), 'a request naming no model takes the default');
    assert_eq('baseten', $router->transportFor('  '), 'so does a blank one');

    // LLM_MODEL_<STEP> accepts any id. An Anthropic model can only be served by
    // an Anthropic transport, so it routes there rather than 404ing on Baseten.
    assert_eq('anthropic', $router->transportFor('claude-haiku-4-5'), 'unpinned Claude ids still reach Anthropic');
    assert_eq('baseten', $router->transportFor('gpt-5.5'), 'a foreign id is the default transport to reject');
});

test('RoutingLlm single completions go to the routed transport and expose its finish reason', function () {
    [$router, $baseten, $anthropic] = routing_fixture();

    assert_eq('baseten:a', $router->complete('a'));
    assert_eq('anthropic:b', $router->complete('b', ['model' => 'claude-opus-5']));
    assert_eq(1, $baseten->singleCalls);
    assert_eq(1, $anthropic->singleCalls);

    $json = $router->completeJson('c', ['model' => 'claude-opus-5']);
    assert_eq('anthropic', $json['served_by'], 'completeJson routes on the same key');

    // The finish reason must come from whoever actually served the last call.
    $router->complete('d', ['model' => 'claude-opus-5']);
    assert_eq('stop', $router->lastFinishReason());

    $quiet = new RoutingLlm(['only' => new SilentTransport()]);
    $quiet->complete('x');
    assert_eq(null, $quiet->lastFinishReason(), 'a transport without the capability reports nothing');
});

test('a batch on one model stays one batch, keeping its concurrency intact', function () {
    [$router, $baseten, $anthropic] = routing_fixture();

    $requests = [
        'hero' => ['prompt' => 'h', 'model' => 'moonshotai/Kimi-K3'],
        'about' => ['prompt' => 'a', 'model' => 'moonshotai/Kimi-K3'],
        'contact' => ['prompt' => 'c', 'model' => 'moonshotai/Kimi-K3'],
    ];
    $out = $router->completeJsonBatch($requests);

    assert_eq(1, count($baseten->batches), 'one call, not one per member');
    assert_eq(['hero', 'about', 'contact'], $baseten->batches[0], 'the whole batch arrives together');
    assert_eq([], $anthropic->batches, 'the idle transport is not called at all');
    assert_eq(['hero', 'about', 'contact'], array_keys($out), 'keys and order survive');
});

test('a mixed batch splits by transport and comes back keyed and ordered like the input', function () {
    [$router, $baseten, $anthropic] = routing_fixture();

    $requests = [
        'hero' => ['prompt' => 'h', 'model' => 'moonshotai/Kimi-K3'],
        'body' => ['prompt' => 'b', 'model' => 'claude-opus-5'],
        'faq' => ['prompt' => 'f', 'model' => 'zai-org/GLM-5.2-Fast'],
        'cta' => ['prompt' => 'c', 'model' => 'claude-opus-5'],
    ];
    $out = $router->completeJsonBatch($requests);

    assert_eq(['hero', 'faq'], $baseten->batches[0], 'the default transport gets its own members');
    assert_eq(['body', 'cta'], $anthropic->batches[0], 'the pinned transport gets its own members');

    // Order follows the REQUEST, not the order the groups were dispatched in.
    assert_eq(['hero', 'body', 'faq', 'cta'], array_keys($out), 'request order is restored');
    assert_eq('baseten', $out['hero']['served_by']);
    assert_eq('anthropic', $out['body']['served_by']);
    assert_eq('baseten', $out['faq']['served_by']);
    assert_eq('anthropic', $out['cta']['served_by']);
});

test('completeBatch merges text and keeps every note attached to its own member', function () {
    [$router] = routing_fixture();

    $result = $router->completeBatch([
        'hero' => ['prompt' => 'h', 'model' => 'moonshotai/Kimi-K3'],
        'body' => ['prompt' => 'b', 'model' => 'claude-opus-5'],
    ]);

    assert_eq(['hero', 'body'], array_keys($result->texts), 'texts keep request order');
    assert_eq('baseten:h', $result->texts['hero']);
    assert_eq('anthropic:b', $result->texts['body']);
    assert_eq(['baseten note'], $result->notesFor('hero'), 'notes follow their own member across the split');
    assert_eq(['anthropic note'], $result->notesFor('body'));
});

test('RoutingLlm refuses a split that lost a member instead of returning a short batch', function () {
    [$router, $baseten] = routing_fixture();
    $baseten->dropKeys = ['faq'];

    $requests = [
        'hero' => ['prompt' => 'h', 'model' => 'moonshotai/Kimi-K3'],
        'faq' => ['prompt' => 'f', 'model' => 'moonshotai/Kimi-K3'],
        'body' => ['prompt' => 'b', 'model' => 'claude-opus-5'],
    ];

    $threw = null;
    try {
        $router->completeJsonBatch($requests);
    } catch (RuntimeException $e) {
        $threw = $e->getMessage();
    }
    assert_true($threw !== null, 'a dropped section must not ship silently');
    assert_true(str_contains($threw, 'faq'), "the message names the lost member: {$threw}");

    // Same guarantee on the raw-text path.
    [$router2, $baseten2] = routing_fixture();
    $baseten2->dropKeys = ['hero'];
    $threw2 = null;
    try {
        $router2->completeBatch(['hero' => ['prompt' => 'h'], 'body' => ['prompt' => 'b', 'model' => 'claude-opus-5']]);
    } catch (RuntimeException $e) {
        $threw2 = $e->getMessage();
    }
    assert_true($threw2 !== null, 'completeBatch is guarded too');
});

test('RoutingLlm sums usage across transports and reports cache fields only when given', function () {
    $a = new RecordingTransport('a', [
        'requests' => 2, 'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110,
        'cache_read_input_tokens' => 40,
    ]);
    $b = new RecordingTransport('b', [
        'requests' => 3, 'input_tokens' => 5, 'output_tokens' => 1, 'total_tokens' => 6,
        'cache_read_input_tokens' => 2,
    ]);
    $totals = (new RoutingLlm(['a' => $a, 'b' => $b], [], 'a'))->usageTotals();

    assert_eq(5, $totals['requests']);
    assert_eq(105, $totals['input_tokens']);
    assert_eq(11, $totals['output_tokens']);
    assert_eq(116, $totals['total_tokens']);
    assert_eq(42, $totals['cache_read_input_tokens'], 'reported cache figures are summed');
    assert_true(
        !array_key_exists('cache_creation_input_tokens', $totals),
        'a field no transport reported stays absent rather than becoming a misleading zero',
    );

    // A transport that reports nothing contributes nothing and breaks nothing.
    $mixed = new RoutingLlm(['a' => $a, 'quiet' => new SilentTransport()], [], 'a');
    assert_eq(2, $mixed->usageTotals()['requests'], 'a non-reporting transport is skipped');
});

test('a nameless request is given the default model and routed by it', function () {
    $baseten = new RecordingTransport('baseten');
    $anthropic = new RecordingTransport('anthropic');
    $transports = ['baseten' => $baseten, 'anthropic' => $anthropic];
    $routes = ['claude-opus-5' => 'anthropic'];

    // LLM_MODEL=claude-opus-5 makes the default model an Anthropic one. The
    // steps built with a null model must follow it rather than asking Baseten
    // for a model it does not serve.
    $claude = new RoutingLlm($transports, $routes, 'baseten', 'claude-opus-5');
    assert_eq('anthropic', $claude->transportFor(null), 'the nameless request follows the default model');
    $claude->complete('x');
    assert_eq(1, $anthropic->singleCalls, 'and is actually served there');
    assert_eq('claude-opus-5', $anthropic->lastOpts['model'], 'the transport is told which model to use');

    // The reported transport and the serving transport must never disagree.
    $baseten2 = new RecordingTransport('baseten');
    $kimi = new RoutingLlm(
        ['baseten' => $baseten2, 'anthropic' => new RecordingTransport('anthropic')],
        $routes,
        'baseten',
        'moonshotai/Kimi-K3',
    );
    assert_eq('baseten', $kimi->transportFor(null));
    $kimi->complete('x');
    assert_eq('moonshotai/Kimi-K3', $baseten2->lastOpts['model'], 'the default model is named explicitly');

    // A batch member without a model gets the same treatment.
    $mixed = new RoutingLlm($transports, $routes, 'baseten', 'claude-opus-5');
    $out = $mixed->completeJsonBatch(['a' => ['prompt' => 'p'], 'b' => ['prompt' => 'q', 'model' => 'moonshotai/Kimi-K3']]);
    assert_eq('anthropic', $out['a']['served_by'], 'a nameless batch member follows the default model too');
    assert_eq('baseten', $out['b']['served_by']);

    // With no default model configured, nothing is invented.
    $bare = new RoutingLlm($transports, $routes, 'baseten');
    $bare->complete('x');
    assert_true(!array_key_exists('model', $baseten->lastOpts), 'no model is fabricated when none is configured');
});

test('RoutingLlm rejects a route naming a transport it does not have', function () {
    $threw = null;
    try {
        new RoutingLlm(['baseten' => new SilentTransport()], ['claude-opus-5' => 'anthropic'], 'baseten');
    } catch (InvalidArgumentException $e) {
        $threw = $e->getMessage();
    }
    assert_true($threw !== null, 'a typo in the config must not route silently to the default');
    assert_true(str_contains($threw, 'anthropic'), "the message names the missing transport: {$threw}");

    $threwDefault = null;
    try {
        new RoutingLlm(['baseten' => new SilentTransport()], [], 'nope');
    } catch (InvalidArgumentException $e) {
        $threwDefault = $e->getMessage();
    }
    assert_true($threwDefault !== null, 'the default transport is validated too');
});

/** A transport that can carry pixels, to stand in for AnthropicClient. */
final class SeeingTransport implements VisionLlm
{
    public function complete(string $prompt, array $opts = []): string { return 'text'; }
    public function completeJson(string $prompt, array $opts = []): array { return []; }
    public function completeJsonBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $_) {
            $out[$key] = ['served' => $key];
        }
        return $out;
    }
    public function completeBatch(array $requests): TextBatchResult { return new TextBatchResult([], []); }
    public function completeWithImage(string $p, string $bytes, string $mime, array $opts = []): string
    {
        return "saw {$mime}";
    }
}

/** A text-only transport whose JSON batch always fails the generated-content way. */
final class BlindFailingTransport implements Llm
{
    public function complete(string $prompt, array $opts = []): string { return 'text'; }
    public function completeJson(string $prompt, array $opts = []): array { return []; }
    public function completeJsonBatch(array $requests): array
    {
        throw new GeneratedJsonException(['page-plan' => 'malformed page-plan'], []);
    }
    public function completeBatch(array $requests): TextBatchResult { return new TextBatchResult([], []); }
}

test('RoutingLlm carries the vision capability through instead of silently dropping it', function () {
    // GenerateImagesStep gates image QA on `instanceof VisionLlm` and skips
    // WITHOUT narrating when it is false, so a router that does not declare the
    // capability turned hero inspection off for any run using a transport
    // prefix — including a same-provider one.
    $router = new RoutingLlm(
        ['anthropic' => new SeeingTransport(), 'baseten' => new BlindFailingTransport()],
        ['zai-org/glm-5.3-flash' => 'baseten'],
        'anthropic',
        'claude-opus-5',
    );

    assert_true($router instanceof VisionLlm, 'the router must still look vision-capable');
    assert_eq('saw image/jpeg', $router->completeWithImage('p', 'bytes', 'image/jpeg'));

    // A route to a transport that cannot carry pixels raises rather than
    // pretending. inspect() catches Throwable and narrates the image as
    // delivered unverified, so this degrades loudly instead of silently.
    $threw = null;
    try {
        $router->completeWithImage('p', 'bytes', 'image/jpeg', ['model' => 'zai-org/GLM-5.3-Flash']);
    } catch (RuntimeException $e) {
        $threw = $e->getMessage();
    }
    assert_true($threw !== null, 'a text-only route must not silently answer an image request');
    assert_true(str_contains($threw, 'baseten'), "the message names the transport: {$threw}");
});

test('a failing group does not discard the results other transports already returned', function () {
    // ConcurrentGroup runs ThemeJsonStep + PagePlanStep through one
    // completeJsonBatch and reads $e->partialResults to keep the survivor. When
    // a prefix makes that batch mixed, letting the first group's exception
    // escape threw away a sibling the run had already paid for, and handed the
    // step an empty result set that it misreported as its own missing output.
    $router = new RoutingLlm(
        ['anthropic' => new SeeingTransport(), 'baseten' => new BlindFailingTransport()],
        ['zai-org/glm-5.3-flash' => 'baseten'],
        'anthropic',
        'claude-opus-5',
    );

    $threw = null;
    try {
        $router->completeJsonBatch([
            'theme-json' => ['prompt' => 't', 'model' => 'claude-opus-5'],
            'page-plan'  => ['prompt' => 'p', 'model' => 'zai-org/GLM-5.3-Flash'],
        ]);
    } catch (GeneratedJsonException $e) {
        $threw = $e;
    }

    assert_true($threw !== null, 'the failure still reaches the caller');
    assert_eq(['page-plan' => 'malformed page-plan'], $threw->failures, 'the diagnostic survives');
    assert_eq(
        ['theme-json' => ['served' => 'theme-json']],
        $threw->partialResults,
        'the sibling that succeeded is preserved, not discarded with the group that failed',
    );
});
