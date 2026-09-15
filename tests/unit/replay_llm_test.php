<?php
declare(strict_types=1);

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\Patterns\RecordingLlm;
use Automattic\SiteBuild\Patterns\ReplayLlm;
use Automattic\SiteBuild\TextBatchResult;

/** A personalize prompt as the step renders it: the slots block names the ids. */
function replay_page_prompt(array $ids): string
{
    $slots = json_encode(array_map(static fn (string $id): array => ['id' => $id, 'block' => 'core/paragraph'], $ids), JSON_PRETTY_PRINT);

    return "<page>\nSlug: x\n</page>\n<slots>\n{$slots}\n</slots>";
}

function replay_page_opts(): array
{
    return ['json_schema' => ['name' => 'page_content', 'schema' => []]];
}

function replay_plan_opts(): array
{
    return ['json_schema' => ['name' => 'site_plan', 'schema' => []]];
}

/**
 * Site Foundry writes the recording as a flat map of slot id to text. A page
 * is answered from the ids its prompt asks for; an id the map lacks is left
 * out, which the build treats as the model skipping it.
 */
test('a page is answered from the slot map by the ids its prompt names', function () {
    $llm = new ReplayLlm(['version' => 2, 'slots' => ['home-1' => 'Olá', 'contact-heading' => 'Vamos conversar']]);

    $answer = $llm->completeJson(replay_page_prompt(['home-1', 'home-2']), replay_page_opts());

    assert_eq([['id' => 'home-1', 'text' => 'Olá']], $answer['content']);
});

test('the same page asked twice replays the same answer', function () {
    $llm = new ReplayLlm(['version' => 2, 'slots' => ['a' => 'x']]);

    assert_eq(
        $llm->completeJson(replay_page_prompt(['a']), replay_page_opts()),
        $llm->completeJson(replay_page_prompt(['a']), replay_page_opts()),
    );
});

test('a plan replays from the recording, retitled by page_titles', function () {
    $llm = new ReplayLlm([
        'version' => 2,
        'slots' => [],
        'page_titles' => ['home' => 'Início'],
        'plan' => ['pages' => [['slug' => 'home', 'title' => 'Home', 'description' => '', 'sections' => []]]],
    ]);

    $plan = $llm->completeJson('plan me', replay_plan_opts());

    assert_eq('Início', $plan['pages'][0]['title']);
});

test('a recording with no plan cannot answer a planning call, and says so', function () {
    $llm = new ReplayLlm(['version' => 2, 'slots' => []]);

    assert_contains('no plan', assert_throws(static fn () => $llm->completeJson('plan me', replay_plan_opts()))->getMessage());
});

test('a recording in another shape is refused up front', function () {
    assert_contains('"version": 2', assert_throws(static fn () => new ReplayLlm(['site_plan' => []]))->getMessage());
    assert_contains('"version": 2', assert_throws(static fn () => new ReplayLlm([['a' => 1]]))->getMessage());
});

test('raw text is refused', function () {
    $llm = new ReplayLlm(['version' => 2, 'slots' => []]);

    assert_contains('raw-text', assert_throws(static fn () => $llm->complete('nope'))->getMessage());
});

/**
 * A live run recorded, then replayed, answers the same slots. A retry's
 * answer lands beside the first call's, not over it.
 */
test('a recording made from a live run replays as itself, retries merged', function () {
    $live = new class implements Llm {
        private int $calls = 0;
        public function complete(string $prompt, array $opts = []): string { return ''; }
        public function completeJson(string $prompt, array $opts = []): array
        {
            if (($opts['json_schema']['name'] ?? '') === 'site_plan') {
                return ['pages' => [['slug' => 'home', 'title' => 'Home', 'description' => '', 'sections' => []]]];
            }
            ++$this->calls;
            return $this->calls === 1
                ? ['content' => [['id' => 'home-1', 'text' => 'First']]]
                : ['content' => [['id' => 'home-2', 'text' => 'Second']]];
        }
        public function completeJsonBatch(array $requests): array { return []; }
        public function completeBatch(array $requests): TextBatchResult { throw new LogicException('unused'); }
    };
    $recorder = new RecordingLlm($live);
    $recorder->completeJson('plan me', replay_plan_opts());
    $recorder->completeJson(replay_page_prompt(['home-1', 'home-2']), replay_page_opts());
    $recorder->completeJson(replay_page_prompt(['home-2']), replay_page_opts());

    $recording = $recorder->recording();
    assert_eq(2, $recording['version']);
    assert_eq(['home-1' => 'First', 'home-2' => 'Second'], $recording['slots']);

    $replay = new ReplayLlm($recording);
    assert_eq(
        [['id' => 'home-1', 'text' => 'First'], ['id' => 'home-2', 'text' => 'Second']],
        $replay->completeJson(replay_page_prompt(['home-1', 'home-2']), replay_page_opts())['content'],
    );
    assert_eq('home', $replay->completeJson('plan me', replay_plan_opts())['pages'][0]['slug']);
});
