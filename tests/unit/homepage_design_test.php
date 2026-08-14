<?php
declare(strict_types=1);

use Automattic\SiteBuild\MalformedDesignException;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\HomepageDesignStep;
use Automattic\SiteBuild\Tests\FakeLlm;

/** @return array{0:Project,1:FakeLlm,2:string} */
function homepage_fixture(array $meta = []): array
{
    $tmp = sys_get_temp_dir() . '/builder_homepage_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', array_merge([
        'prompt' => 'A neighborhood bakery with seasonal bread and classes',
    ], $meta));
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb',
        'sections' => ['Bread', 'Classes', 'Visit'],
    ]);
    $project->writeJson('designDirection.json', [
        'title' => 'Flour Archive',
        'description' => 'Warm editorial layouts with flour-dusted documentary imagery.',
        'palette' => ['base' => '#FFF8EA', 'contrast' => '#251D16'],
    ]);
    return [$project, new FakeLlm(), $tmp];
}

/** @return list<string> */
function homepage_seeds(): array
{
    return [
        'Seed Alpha — archival bakery ledger',
        'Seed Beta — kinetic flour workshop',
        'Seed Gamma — quiet neighborhood journal',
        'Seed Delta — bold bread taxonomy',
    ];
}

function homepage_document(string $id, ?string $css = null): string
{
    $style = $css === null ? '' : "<style>{$css}</style>\n";
    return "<!doctype html>\n"
        . "<html>\n"
        . "<head>\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . "\n{$style}</head>\n"
        . "<body>\n"
        . "<header id=\"site-header\"><p>Header {$id}</p></header>\n"
        . "<section id=\"hero\"><h1>Hero {$id}</h1></section>\n"
        . "<section id=\"feature\"><h2>Feature {$id}</h2><p>Keep {$id}</p></section>\n"
        . "<section id=\"untouched\" data-tone=\"quiet\"><h2>Untouched {$id}</h2></section>\n"
        . "<footer><p>Footer {$id}</p></footer>\n"
        . "</body>\n"
        . "</html>";
}

/** @param list<string> $documents */
function homepage_queue_tournament(
    FakeLlm $llm,
    array $documents,
    array $judge = ['winner' => 0, 'why' => 'Strongest hierarchy'],
): void {
    $llm->queueJson(['seeds' => homepage_seeds()]);
    foreach ($documents as $document) {
        $llm->queueText($document);
    }
    $llm->queueJson($judge);
}

function homepage_run(Project $project, FakeLlm $llm): HomepageDesignStep
{
    $step = new HomepageDesignStep($llm, new PromptRenderer(repo_path('prompts')));
    $step->run($project);
    return $step;
}

function homepage_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

test('homepage-design defaults to one candidate with no judge and no critique', function () {
    [$project, $llm, $tmp] = homepage_fixture();
    $only = homepage_document('ONLY', "\n.only { color: red; }\n");
    $llm->queueJson(['seeds' => homepage_seeds()]);
    $llm->queueText($only);

    homepage_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls, 'one batch');
    assert_eq(1, count(array_filter(
        $llm->calls,
        static fn (array $call): bool => str_contains($call['prompt'], 'Seed '),
    )), 'exactly one candidate generated');
    assert_eq(1, $llm->completeJsonCalls, 'seeds only — no judge and no critique call');
    assert_eq(0, $llm->completeCalls, 'no revision call');
    assert_eq($only, $project->readText('design/candidate-1.html'));
    assert_eq($only, $project->readText('design/home.html'));
    assert_true(!$project->exists('design/candidate-2.html'));
    assert_true(!$project->exists('design/judge.json'), 'single candidate skips the judge verdict file');
    assert_true(!$project->exists('design/critique-1.json'));
    homepage_cleanup($tmp);
});

test('homepage-design fans out the configured tournament and judges every candidate', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 3, 'critique_rounds' => 1]);
    $documents = [
        homepage_document('A', "\n.a { color: red; }\n"),
        homepage_document('B', "\n.b { color: blue; }\n"),
        homepage_document('C', "\n.c { color: green; }\n"),
    ];
    homepage_queue_tournament($llm, $documents, ['winner' => 1, 'why' => 'Best composition']);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    $step = homepage_run($project, $llm);

    assert_eq(1, $llm->completeBatchCalls, 'all candidates use one concurrent text batch');
    assert_eq(3, count(array_filter(
        $llm->calls,
        static fn (array $call): bool => str_contains($call['prompt'], 'Seed '),
    )), 'each candidate receives one distinct seed');
    assert_contains('Seed Alpha', $llm->calls[1]['prompt']);
    assert_contains('Seed Beta', $llm->calls[2]['prompt']);
    assert_contains('Seed Gamma', $llm->calls[3]['prompt']);
    assert_contains($documents[0], $llm->calls[4]['prompt'], 'judge sees candidate zero');
    assert_contains($documents[1], $llm->calls[4]['prompt'], 'judge sees candidate one');
    assert_contains($documents[2], $llm->calls[4]['prompt'], 'judge sees candidate two');
    assert_eq($documents[1], $project->readText('design/home.html'), 'zero-based winner index selects candidate one');
    assert_eq($documents[0], $project->readText('design/candidate-1.html'));
    assert_eq($documents[2], $project->readText('design/candidate-3.html'));
    assert_eq(['winner' => 1, 'why' => 'Best composition'], $project->readJson('design/judge.json'));

    $declaration = $step->declaration();
    assert_eq('homepage-design', $step->id());
    assert_eq(['meta.json', 'siteSpec.json', 'designDirection.json'], $declaration->reads);
    assert_eq(['design/*', 'warnings.json'], $declaration->writes);
    assert_true($declaration->concurrent);
    homepage_cleanup($tmp);
});

test('homepage-design defaults an invalid judge verdict to the first candidate and warns', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2, 'critique_rounds' => 1]);
    $first = homepage_document('FIRST', "\n.first { color: red; }\n");
    $second = homepage_document('SECOND', "\n.second { color: blue; }\n");
    homepage_queue_tournament($llm, [$first, $second], ['winner' => 9, 'why' => 'Out of range']);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    assert_eq($first, $project->readText('design/home.html'));
    assert_eq(['winner' => 9, 'why' => 'Out of range'], $project->readJson('design/judge.json'));
    $warnings = implode(' ', $project->readJson('warnings.json')['homepage-design'] ?? []);
    assert_contains('invalid_judge_verdict', $warnings);
    assert_contains('winner 9', $warnings);
    assert_contains('candidate 0', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design critique pass makes no revision call', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2, 'critique_rounds' => 2]);
    homepage_queue_tournament($llm, [
        homepage_document('A', "\n.a { color: red; }\n"),
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    assert_eq(0, $llm->completeCalls, 'pass performs no patch or full-document revision');
    assert_eq(1, $llm->completeJsonCalls - 2, 'one critique after seed and judge');
    assert_eq(['verdict' => 'pass', 'notes' => []], $project->readJson('design/critique-1.json'));
    assert_true(!$project->exists('design/critique-2.json'));
    homepage_cleanup($tmp);
});

test('homepage-design patch revision replaces one landmark and preserves untouched bytes', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 2,
    ]);
    $winner = homepage_document('A', "\n.a { color: red; }\n");
    homepage_queue_tournament($llm, [
        $winner,
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#feature', 'instruction' => 'Make this concrete']],
    ]);
    $replacement = '<section id="feature"><h2>Fresh bread calendar</h2><p>Tuesday rye.</p></section>';
    $llm->queueText("<!-- section: #feature -->\n```html\n{$replacement}\n```");
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    $actual = $project->readText('design/home.html');
    assert_contains($replacement, $actual);
    assert_true(!str_contains($actual, '<h2>Feature A</h2>'), 'old target removed');
    foreach ([
        '<header id="site-header"><p>Header A</p></header>',
        '<section id="hero"><h1>Hero A</h1></section>',
        '<section id="untouched" data-tone="quiet"><h2>Untouched A</h2></section>',
        '<footer><p>Footer A</p></footer>',
    ] as $untouchedBytes) {
        assert_contains($untouchedBytes, $actual, 'untouched landmark remains byte-identical');
    }
    assert_eq(1, $llm->completeCalls, 'one patch generation only');
    homepage_cleanup($tmp);
});

test('homepage-design splice miss performs exactly one full-document revision', function () {
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
        'notes' => [['section' => '#missing', 'instruction' => 'Add missing proof']],
    ]);
    $llm->queueText("<!-- section: #missing -->\n```html\n<section id=\"missing\">Patch</section>\n```");
    $fullRevision = homepage_document('FULL', "\n.full { color: purple; }\n");
    $llm->queueText($fullRevision);

    homepage_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'one patch call plus exactly one full-document call');
    assert_eq($fullRevision, $project->readText('design/home.html'));
    $warnings = implode(' ', $project->readJson('warnings.json')['homepage-design'] ?? []);
    assert_contains('splice_failure', $warnings);
    assert_contains('#missing', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design honors the configured critique round cap', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 2,
    ]);
    homepage_queue_tournament($llm, [
        homepage_document('A', "\n.a { color: red; }\n"),
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#feature', 'instruction' => 'First pass']],
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#untouched', 'instruction' => 'Second pass']],
    ]);
    $llm->queueText(
        "<!-- section: #feature -->\n```html\n"
        . '<section id="feature"><h2>Round one</h2></section>'
        . "\n```"
    );
    $llm->queueText(
        "<!-- section: #untouched -->\n```html\n"
        . '<section id="untouched" data-tone="loud"><h2>Round two</h2></section>'
        . "\n```"
    );

    homepage_run($project, $llm);

    assert_eq(4, $llm->completeJsonCalls, 'seed + judge + exactly two critiques');
    assert_eq(2, $llm->completeCalls, 'one patch per allowed round');
    assert_true($project->exists('design/critique-1.json'));
    assert_true($project->exists('design/critique-2.json'));
    assert_true(!$project->exists('design/critique-3.json'));
    assert_contains('Round one', $project->readText('design/home.html'));
    assert_contains('Round two', $project->readText('design/home.html'));
    homepage_cleanup($tmp);
});

test('homepage-design missing style throws MalformedDesignException after one repair attempt', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 1,
    ]);
    homepage_queue_tournament($llm, [
        homepage_document('NO-STYLE'),
        homepage_document('VALID', "\n.valid { color: green; }\n"),
    ]);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);
    $llm->queueText(homepage_document('STILL-NO-STYLE'));

    $caught = null;
    try {
        homepage_run($project, $llm);
    } catch (Throwable $error) {
        $caught = $error;
    }

    assert_true($caught instanceof MalformedDesignException, 'missing style surfaces fallback-routing exception');
    assert_eq(1, $llm->completeCalls, 'one repair attempt only');
    $warnings = implode(' ', $project->readJson('warnings.json')['homepage-design'] ?? []);
    assert_contains('malformed_design', $warnings);
    assert_contains('<style>', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design extracts style contents byte-for-byte', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2, 'critique_rounds' => 1]);
    $css = "\n:root { --brand: #a00; }\n.hero > p::after { content: \"A > B\"; }\n";
    $winner = homepage_document('CSS', $css);
    homepage_queue_tournament($llm, [
        $winner,
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson(['verdict' => 'pass', 'notes' => []]);

    homepage_run($project, $llm);

    assert_eq($winner, $project->readText('design/home.html'));
    assert_eq($css, $project->readText('design/site.css'));
    homepage_cleanup($tmp);
});

test('homepage-design skips a malformed critique round and warns', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 2,
    ]);
    $winner = homepage_document('A', "\n.a { color: red; }\n");
    homepage_queue_tournament($llm, [
        $winner,
        homepage_document('B', "\n.b { color: blue; }\n"),
    ]);
    $llm->queueJson(['verdict' => 'revise', 'notes' => [['section' => '', 'instruction' => '']]]);

    homepage_run($project, $llm);

    assert_eq(0, $llm->completeCalls, 'malformed critique cannot trigger revision');
    assert_eq($winner, $project->readText('design/home.html'));
    $warnings = implode(' ', $project->readJson('warnings.json')['homepage-design'] ?? []);
    assert_contains('malformed_critique', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design full-document fallback stitches a truncated revision serially', function () {
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
        'notes' => [['section' => '#missing', 'instruction' => 'Add missing proof']],
    ]);
    $llm->queueText("<!-- section: #missing -->\n```html\n<section id=\"missing\">Patch</section>\n```");
    $llm->queueText(
        "<!doctype html><html><head><style>\n.full { color: purple; }\n</style></head>"
        . '<body><header>Full</header><section id="hero"><h1>Full',
        'max_tokens',
    );
    $llm->queueText('</h1></section><footer>End</footer></body></html>', 'end_turn');

    homepage_run($project, $llm);

    assert_eq(3, $llm->completeCalls, 'patch, full-document start, then serial continuation');
    assert_contains('<h1>Full</h1>', $project->readText('design/home.html'));
    assert_contains('Continue EXACTLY where', $llm->calls[array_key_last($llm->calls)]['prompt']);
    homepage_cleanup($tmp);
});

test('homepage-design prompts freeze the supported HTML and JSON contracts', function () {
    $homepage = (string) file_get_contents(repo_path('prompts/homepage-design.md'));
    foreach ([
        '{{brief}}',
        '{{site_spec}}',
        '{{design_direction}}',
        '{{seed}}',
        'mobile-first',
        'viewport',
        'fluid type',
        'alt',
        'generation prompt',
        '<header>',
        '<footer>',
        'no forms',
        'no SVG',
        'no custom elements',
        'no JavaScript',
    ] as $required) {
        assert_contains($required, $homepage);
    }

    $judge = (string) file_get_contents(repo_path('prompts/design-judge.md'));
    assert_contains('{"winner":', $judge);
    assert_contains('"why":', $judge);

    $critique = (string) file_get_contents(repo_path('prompts/design-critique.md'));
    foreach (['responsiveness', 'heading hierarchy', '"verdict"', '"notes"', '"section"', '"instruction"'] as $required) {
        assert_contains($required, $critique);
    }

    $revise = (string) file_get_contents(repo_path('prompts/design-revise.md'));
    assert_contains('ONLY replacement sections', $revise);
    assert_contains('<!-- section: <selector> -->', $revise);
});
