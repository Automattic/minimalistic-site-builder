<?php
declare(strict_types=1);

use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Package;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\InspirationStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\UrlAnalyzer;

/**
 * Coverage for the intent filter over scanned URLs.
 *
 * URL detection is a syntax scan: "do NOT copy example.com" produces the same
 * match as "like example.com". Adopting one is expensive and opinionated — it
 * becomes the binding visual reference, rides every image prompt, and turns off
 * seed variety — so a cheap model reads the sentence around it first.
 */

/** Records the URLs it was asked to analyze; returns no references. */
final class IntentRecordingAnalyzer implements UrlAnalyzer
{
    /** @var list<string> */
    public array $analyzed = [];

    public function analyze(array $urls): array
    {
        $this->analyzed = array_values($urls);
        return ['references' => [], 'failures' => []];
    }
}

/** @return array{0:list<string>,1:FakeLlm,2:string} */
function intent_run(string $prompt, ?array $useDecision, ?IntentRecordingAnalyzer $analyzer = null): array
{
    $llm = new FakeLlm();
    if ($useDecision !== null) {
        $llm->queueJson(['use' => $useDecision, 'reason' => 'test']);
    }
    $analyzer ??= new IntentRecordingAnalyzer();

    $narrated = '';
    with_project('inspiration-intent-', function (Project $project) use ($prompt, $llm, $analyzer, &$narrated): void {
        $project->writeJson('meta.json', ['prompt' => $prompt]);
        $sink = fopen('php://memory', 'w+');
        Narrator::setStream($sink);
        try {
            (new InspirationStep(
                $analyzer,
                $llm,
                new PromptRenderer(Package::promptsDir()),
            ))->run($project);
            rewind($sink);
            $narrated = (string) stream_get_contents($sink);
        } finally {
            Narrator::reset();
            fclose($sink);
        }
    });

    return [$analyzer->analyzed, $llm, $narrated];
}

test('a URL the brief points at as inspiration is analyzed', function () {
    [$analyzed, $llm] = intent_run(
        'a candy shop like gumroad.com',
        ['https://gumroad.com'],
    );

    assert_eq(['https://gumroad.com'], $analyzed);
    assert_contains('a candy shop like gumroad.com', $llm->calls[0]['prompt']);
    assert_contains('https://gumroad.com', $llm->calls[0]['prompt']);
});

test('a URL the brief tells us to avoid is never fetched', function () {
    [$analyzed, , $narrated] = intent_run(
        'a candy shop, but do NOT copy stripe.com',
        [],
    );

    // The whole point: this URL used to become the binding visual reference.
    assert_eq([], $analyzed, 'a rejected URL must not reach the analyzer at all');
    assert_contains('not referenced as design inspiration', $narrated);
});

test('the filter keeps only the intended URL when a brief mixes both', function () {
    [$analyzed] = intent_run(
        'like gumroad.com, nothing like stripe.com, and our docs at example.com',
        ['https://gumroad.com'],
    );

    assert_eq(['https://gumroad.com'], $analyzed);
});

test('a URL the model invents is never fetched', function () {
    // The model returns strings; only URLs the scan produced may be fetched.
    [$analyzed] = intent_run(
        'a candy shop like gumroad.com',
        ['https://gumroad.com', 'http://169.254.169.254/latest/meta-data/'],
    );

    assert_eq(['https://gumroad.com'], $analyzed);
});

test('a failed intent check keeps every detected URL rather than dropping them', function () {
    $llm = new FakeLlm();
    $llm->failPromptSubstrings = ['visual design inspiration'];
    $analyzer = new IntentRecordingAnalyzer();

    $narrated = '';
    with_project('inspiration-intent-', function (Project $project) use ($llm, $analyzer, &$narrated): void {
        $project->writeJson('meta.json', ['prompt' => 'a candy shop like gumroad.com']);
        $sink = fopen('php://memory', 'w+');
        Narrator::setStream($sink);
        try {
            (new InspirationStep($analyzer, $llm, new PromptRenderer(Package::promptsDir())))->run($project);
            rewind($sink);
            $narrated = (string) stream_get_contents($sink);
        } finally {
            Narrator::reset();
            fclose($sink);
        }
    });

    // Failing closed would silently discard a reference the author did ask for.
    assert_eq(['https://gumroad.com'], $analyzer->analyzed);
    assert_contains('could not check reference intent', $narrated);
});

test('a malformed intent response keeps every detected URL', function () {
    [$analyzed] = intent_run('a candy shop like gumroad.com', null, null);
    // No queued JSON: completeJson throws, and the step degrades to using all.
    assert_eq(['https://gumroad.com'], $analyzed);
});

test('without an llm the step keeps every detected URL and makes no call', function () {
    $analyzer = new IntentRecordingAnalyzer();
    with_project('inspiration-intent-', function (Project $project) use ($analyzer): void {
        $project->writeJson('meta.json', ['prompt' => 'a candy shop like gumroad.com']);
        quietly(static function () use ($project, $analyzer): void {
            (new InspirationStep($analyzer))->run($project);
        });
    });

    assert_eq(['https://gumroad.com'], $analyzer->analyzed);
});

test('host-supplied URLs bypass the filter entirely', function () {
    $llm = new FakeLlm();
    $analyzer = new IntentRecordingAnalyzer();

    with_project('inspiration-intent-', function (Project $project) use ($llm, $analyzer): void {
        $project->writeJson('meta.json', [
            'prompt' => 'a candy shop, but do NOT copy stripe.com',
            'inspiration_urls' => ['https://stripe.com'],
        ]);
        quietly(static function () use ($project, $llm, $analyzer): void {
            (new InspirationStep($analyzer, $llm, new PromptRenderer(Package::promptsDir())))->run($project);
        });
    });

    // The host already decided; second-guessing it would ignore an explicit
    // instruction, and the prose contradicts the supplied list here on purpose.
    assert_eq(['https://stripe.com'], $analyzer->analyzed);
    assert_eq(0, $llm->completeJsonCalls, 'no intent call for host-supplied URLs');
});

test('every URL rejected means no analyzer call and an empty artifact', function () {
    $analyzer = new IntentRecordingAnalyzer();
    $llm = new FakeLlm();
    $llm->queueJson(['use' => [], 'reason' => 'mentioned as a counter-example']);

    with_project('inspiration-intent-', function (Project $project) use ($llm, $analyzer): void {
        $project->writeJson('meta.json', ['prompt' => 'nothing like stripe.com please']);
        quietly(static function () use ($project, $llm, $analyzer): void {
            (new InspirationStep($analyzer, $llm, new PromptRenderer(Package::promptsDir())))->run($project);
        });

        assert_eq(
            ['urls' => [], 'references' => []],
            $project->readJson(InspirationStep::FILE),
        );
        assert_eq(false, $project->exists('warnings.json'), 'declining a URL is not a failure');
    });

    assert_eq([], $analyzer->analyzed);
});
