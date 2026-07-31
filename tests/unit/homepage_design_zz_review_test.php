<?php
declare(strict_types=1);

use Automattic\SiteBuild\GeneratedJsonException;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\MalformedDesignException;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\HomepageDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TextBatchResult;

final class HomepageJudgeFailingLlm implements Llm
{
    public function __construct(
        private FakeLlm $delegate,
        private \RuntimeException $judgeError,
    ) {}

    public function complete(string $prompt, array $opts = []): string
    {
        return $this->delegate->complete($prompt, $opts);
    }

    public function completeJson(string $prompt, array $opts = []): array
    {
        if (str_contains($prompt, '## Candidates')) {
            throw $this->judgeError;
        }
        return $this->delegate->completeJson($prompt, $opts);
    }

    public function completeJsonBatch(array $requests): array
    {
        return $this->delegate->completeJsonBatch($requests);
    }

    public function completeBatch(array $requests): TextBatchResult
    {
        return $this->delegate->completeBatch($requests);
    }
}

function homepage_review_warnings(\Automattic\SiteBuild\Project $project): string
{
    if (!$project->exists('warnings.json')) {
        return '';
    }
    return implode(' ', $project->readJson('warnings.json')['homepage-design'] ?? []);
}

/** @return array{prompt:string,opts:array<mixed>} */
function homepage_review_call(FakeLlm $llm, string $needle): array
{
    foreach ($llm->calls as $call) {
        if (str_contains($call['prompt'], $needle)) {
            return $call;
        }
    }
    throw new RuntimeException("No LLM call contained {$needle}");
}

test('homepage-design removes unsafe unsupported candidate HTML before artifacts and judging', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2]);
    $unsafe = str_replace(
        '<section id="feature">',
        '<section id="feature" onclick="steal()">'
            . '<script>alert(1)</script>'
            . '<form><input name="email"></form>'
            . '<svg><circle></circle></svg>'
            . '<x-card>Unsupported custom element</x-card>'
            . '<a href="javascript:alert(1)" onfocus="steal()">Unsafe link</a>',
        homepage_document('UNSAFE', "\n.safe { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $unsafe,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    $candidate = $project->readText('design/candidate-1.html');
    $judgePrompt = homepage_review_call($llm, '<candidate index="0">')['prompt'];
    $home = $project->readText('design/home.html');
    foreach ([$candidate, $judgePrompt, $home] as $delivered) {
        foreach (['<script', '<form', '<input', '<svg', '<circle', '<x-card', 'onclick=', 'onfocus=', 'javascript:'] as $unsafeNeedle) {
            assert_true(
                !str_contains(strtolower($delivered), strtolower($unsafeNeedle)),
                "{$unsafeNeedle} removed before delivery",
            );
        }
    }

    $warnings = homepage_review_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('design/candidate-1.html', $warnings);
    assert_contains('authored', $warnings);
    assert_contains('delivered removed', $warnings);
    assert_contains('disposition removed', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design sanitizes unsafe patch fragments before splicing', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 1,
    ]);
    homepage_queue_tournament($llm, [
        homepage_document('A', "\n.a { color: red; }\n"),
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#feature', 'instruction' => 'Add safe detail']],
    ]);
    $llm->queueText(
        "<!-- section: #feature -->\n```html\n"
        . '<section id="feature"><h2>Safe detail</h2><script>alert(1)</script>'
        . '<a href="javascript:alert(1)" onclick="steal()">Unsafe action</a></section>'
        . "\n```"
    );

    homepage_run($project, $llm);

    $home = $project->readText('design/home.html');
    assert_contains('<h2>Safe detail</h2>', $home);
    assert_true(!str_contains(strtolower($home), '<script'));
    assert_true(!str_contains(strtolower($home), 'javascript:'));
    assert_true(!str_contains(strtolower($home), 'onclick='));
    $warnings = homepage_review_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('design/home.html', $warnings);
    assert_contains('delivered removed', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design rejects judge JSON missing the exact why contract', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2]);
    $first = homepage_document('FIRST', "\n.first { color: red; }\n");
    homepage_queue_tournament(
        $llm,
        [$first, homepage_document('SECOND', "\n.second { color: blue; }\n")],
        ['winner' => 1, 'why' => '', 'score' => 10],
    );
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    assert_eq($first, $project->readText('design/home.html'));
    assert_contains('invalid_judge_verdict', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design skips malformed critique JSON and continues the next round', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 2,
    ]);
    homepage_queue_tournament($llm, [
        homepage_document('A', "\n.a { color: red; }\n"),
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'pass',
        'notes' => [['section' => '#feature', 'instruction' => 'Pass cannot carry notes']],
    ]);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    assert_eq(4, $llm->completeJsonCalls, 'seed + judge + malformed round + next round');
    assert_true($project->exists('design/critique-2.json'));
    assert_eq(['verdict' => 'pass', 'notes' => []], $project->readJson('design/critique-2.json'));
    assert_contains('malformed_critique', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design rejects a patch root that does not match its requested landmark', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 1,
    ]);
    homepage_queue_tournament($llm, [
        homepage_document('A', "\n.a { color: red; }\n"),
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#feature', 'instruction' => 'Replace feature']],
    ]);
    $llm->queueText(
        "<!-- section: #feature -->\n```html\n"
        . '<section id="wrong"><h2>Wrong landmark</h2></section>'
        . "\n```"
    );
    $fullRevision = homepage_document('FULL', "\n.full { color: purple; }\n");
    $llm->queueText($fullRevision);

    homepage_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'mismatched patch triggers one full-document call');
    assert_eq($fullRevision, $project->readText('design/home.html'));
    assert_contains('splice_failure', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design serially closes a truncated batch candidate before artifact and judge', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2]);
    $partial = str_replace(
        "</body>\n</html>",
        '',
        homepage_document('PARTIAL', "\n.partial { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $partial,
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->batchNotes[0] = ['generation was truncated (stop reason: max_tokens)'];

    $recovered = homepage_document('RECOVERED', "\n.recovered { color: green; }\n");
    $split = (int) strpos($recovered, '<h2>Feature');
    $llm->queueText(substr($recovered, 0, $split + 5), 'max_tokens');
    $llm->queueText(substr($recovered, $split + 5), 'end_turn');
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'serial full-document start plus continuation');
    assert_eq($recovered, $project->readText('design/candidate-1.html'));
    $judgePrompt = homepage_review_call($llm, '<candidate index="0">')['prompt'];
    assert_contains('RECOVERED', $judgePrompt);
    assert_true(!str_contains($judgePrompt, 'PARTIAL'), 'judge never sees truncated batch bytes');
    assert_eq($recovered, $project->readText('design/home.html'));
    homepage_cleanup($tmp);
});

test('homepage-design concatenates every style element in document order', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $first = "\n:root { --brand: #a00; }\n";
    $second = "\n.hero { color: var(--brand); }\n";
    $winner = str_replace(
        '</head>',
        "<style>{$second}</style>\n</head>",
        homepage_document('MULTI-STYLE', $first),
    );
    homepage_queue_tournament($llm, [
        $winner,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);

    homepage_run($project, $llm);

    assert_eq($first . $second, $project->readText('design/site.css'));
    homepage_cleanup($tmp);
});

test('homepage-design keeps styles from an implicit head document', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $css = "\n.implicit { color: green; }\n";
    $implicit = "<!doctype html>\n<html>\n<style>{$css}</style>\n<body>"
        . '<header>Implicit header</header><main><section>Content</section></main>'
        . '<footer>Implicit footer</footer></body></html>';
    homepage_queue_tournament($llm, [
        $implicit,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);

    homepage_run($project, $llm);

    assert_eq($css, $project->readText('design/site.css'));
    assert_contains('<style>', $project->readText('design/home.html'));
    homepage_cleanup($tmp);
});

test('homepage-design persists accumulated warnings when a later transport call throws', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 1,
    ]);
    $unsafe = str_replace(
        '<section id="feature">',
        '<section id="feature" onclick="removeMe()">',
        homepage_document('WARN-THEN-THROW', "\n.safe { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $unsafe,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#feature', 'instruction' => 'Trigger patch transport']],
    ]);

    $caught = null;
    try {
        homepage_run($project, $llm);
    } catch (Throwable $error) {
        $caught = $error;
    }

    assert_true($caught instanceof RuntimeException, 'transport failure remains fatal');
    $warnings = homepage_review_warnings($project);
    assert_contains('design/candidate-1.html', $warnings);
    assert_contains('onclick', $warnings);
    assert_contains('delivered removed', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design degrades a judge transport RuntimeException to the first candidate', function () {
    [$project, $delegate, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $first = homepage_document('FIRST', "\n.first { color: red; }\n");
    homepage_queue_tournament($delegate, [
        $first,
        homepage_document('SECOND', "\n.second { color: blue; }\n"),
    ]);
    $llm = new HomepageJudgeFailingLlm(
        $delegate,
        new RuntimeException('judge transport unavailable'),
    );

    (new HomepageDesignStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq($first, $project->readText('design/home.html'));
    assert_contains('invalid_judge_verdict', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design degrades a thrown GeneratedJsonException judge result to the first candidate', function () {
    [$project, $delegate, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $first = homepage_document('FIRST', "\n.first { color: red; }\n");
    homepage_queue_tournament($delegate, [
        $first,
        homepage_document('SECOND', "\n.second { color: blue; }\n"),
    ]);
    $llm = new HomepageJudgeFailingLlm(
        $delegate,
        new GeneratedJsonException(['judge' => 'invalid JSON after repair']),
    );

    (new HomepageDesignStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq($first, $project->readText('design/home.html'));
    assert_contains('invalid_judge_verdict', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design routes final sanitation style loss as MalformedDesignException', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 1,
    ]);
    $winner = '<!doctype html><html>'
        . '<title id="design-title">Original title</title>'
        . '<style>.implicit { color: blue; }</style>'
        . '<body><header id="site-header"><p>Original header</p></header>'
        . '<main><section id="feature"><h2>Feature</h2></section></main>'
        . '<footer>Footer</footer></body></html>';
    homepage_queue_tournament($llm, [
        $winner,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [[
            'section' => 'title#design-title',
            'instruction' => 'Update the authored title',
        ]],
    ]);
    $llm->queueText(
        "<!-- section: title#design-title -->\n```html\n"
        . '<head><title id="design-title">Updated title</title></head>'
        . "\n```"
    );

    $caught = null;
    try {
        homepage_run($project, $llm);
    } catch (Throwable $error) {
        $caught = $error;
    }

    assert_true(
        $caught instanceof MalformedDesignException,
        'final sanitation loss uses fallback-routing exception',
    );
    assert_contains('delivered removed', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design reports candidate recovery indexes as one-based', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $partial = str_replace(
        "</body>\n</html>",
        '',
        homepage_document('PARTIAL', "\n.partial { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $partial,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);
    $recovered = homepage_document('RECOVERED', "\n.recovered { color: green; }\n");
    $llm->queueText($recovered);

    homepage_run($project, $llm);

    $recovery = homepage_review_call($llm, 'not a closed HTML document');
    assert_contains('Tournament candidate 1', $recovery['prompt']);
    assert_true(!str_contains($recovery['prompt'], 'Tournament candidate 0'));
    $warnings = homepage_review_warnings($project);
    assert_contains('tournament candidate 1', $warnings);
    assert_true(!str_contains($warnings, 'tournament candidate 0'));
    homepage_cleanup($tmp);
});
