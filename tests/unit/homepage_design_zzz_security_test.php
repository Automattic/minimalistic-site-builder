<?php
declare(strict_types=1);

function homepage_comment_attack_document(string $comment): string
{
    return str_replace(
        '</body>',
        $comment . '<script>alert("comment-bypass")</script><p>After comment</p></body>',
        homepage_document('COMMENT', "\n.safe { color: red; }\n"),
    );
}

function homepage_assert_malformed_comment_removed(string $comment): void
{
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    homepage_queue_tournament($llm, [
        homepage_comment_attack_document($comment),
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);

    homepage_run($project, $llm);

    $candidate = $project->readText('design/candidate-1.html');
    assert_true(!str_contains($candidate, $comment), 'malformed comment removed');
    assert_true(!str_contains(strtolower($candidate), '<script'), 'script after malformed comment removed');
    $warnings = homepage_review_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('design/candidate-1.html', $warnings);
    assert_contains('delivered removed', $warnings);
    homepage_cleanup($tmp);
}

test('homepage-design removes an abrupt empty comment close without ending sanitation', function () {
    homepage_assert_malformed_comment_removed('<!-->');
});

test('homepage-design removes an abrupt dash comment close without ending sanitation', function () {
    homepage_assert_malformed_comment_removed('<!--->');
});

test('homepage-design removes a bang comment close without ending sanitation', function () {
    homepage_assert_malformed_comment_removed('<!--comment--!>');
});

test('homepage-design removes an unterminated comment remainder with a warning', function () {
    homepage_assert_malformed_comment_removed('<!--unterminated');
});

test('homepage-design merges nested removal spans without exposing following markup', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $attack = '<svg><a=></svg><!--<script>alert("PWNED")</script>-->';
    $survivor = '<footer>KEEPME-FOOTER</footer>';
    $unsafe = str_replace(
        '<footer><p>Footer OVERLAP-ONE</p></footer>',
        $attack . $survivor,
        homepage_document('OVERLAP-ONE', "\n.safe { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $unsafe,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);

    homepage_run($project, $llm);

    $candidate = $project->readText('design/candidate-1.html');
    assert_true(!str_contains(strtolower($candidate), '<script'), 'nested removal never exposes script');
    assert_contains($survivor, $candidate, 'trailing landmark survives byte-intact');
    assert_contains('delivered removed', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design merges overlapping removals across both sanitation passes', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $attack = '<svg><a=></svg><!--<svg><a=></svg><!--<script>alert("PWNED")</script>-->';
    $unsafe = str_replace(
        '<footer><p>Footer OVERLAP-TWO</p></footer>',
        $attack . '<footer><p>Footer OVERLAP-TWO</p></footer>',
        homepage_document('OVERLAP-TWO', "\n.safe { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $unsafe,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);

    homepage_run($project, $llm);

    $home = $project->readText('design/home.html');
    assert_true(!str_contains(strtolower($home), '<script'), 'second sanitation pass never exposes script');
    assert_contains('delivered removed', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design ends doctype declarations at the first greater-than byte', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 0,
    ]);
    $unsafe = '<!doctype html><html><head><style>.safe{}</style></head><body>'
        . '<header>H</header><main>M</main><footer>F</footer>'
        . '<!DOCTYPE html PUBLIC "><script>document.title=\'PWNED\'</script>">'
        . '</body></html>';
    homepage_queue_tournament($llm, [
        $unsafe,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);

    homepage_run($project, $llm);

    $home = $project->readText('design/home.html');
    assert_true(!str_contains(strtolower($home), '<script'), 'doctype cannot hide a live script');
    $warnings = homepage_review_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('delivered removed', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design text normalization fails closed on invalid UTF-8', function () {
    $method = new ReflectionMethod(HomepageDesignStep::class, 'normalizedText');

    assert_eq(null, $method->invoke(null, "unsafe\xC3"));
});

test('homepage-design removes active head markup and unsafe URL schemes before delivery', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2]);
    $unsafe = str_replace(
        ["<head>\n", '<section id="feature">'],
        [
            "<head>\n"
                . '<base href="https://evil.example/">'
                . '<meta http-equiv="refresh" content="0;url=https://evil.example/">'
                . '<link rel="stylesheet" href="https://evil.example/evil.css">',
            '<section id="feature">'
                . '<a href="vbscript:msgbox(1)">VBScript</a>'
                . '<a href="data:text/html,%3Cscript%3Ealert(1)%3C/script%3E">Data HTML</a>',
        ],
        homepage_document('UNSAFE-HEAD', "\n.safe { color: red; }\n"),
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
        foreach (['<base', 'http-equiv="refresh"', '<link', 'vbscript:', 'data:text/html'] as $needle) {
            assert_true(
                !str_contains(strtolower($delivered), strtolower($needle)),
                "{$needle} removed before delivery",
            );
        }
    }

    $warnings = homepage_review_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('design/candidate-1.html', $warnings);
    assert_contains('delivered removed', $warnings);
    homepage_cleanup($tmp);
});

test('homepage-design patch replacement preserves the resolved landmark tag', function () {
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
        . '<div id="feature"><h2>Wrong root type</h2></div>'
        . "\n```"
    );
    $fullRevision = homepage_document('FULL', "\n.full { color: purple; }\n");
    $llm->queueText($fullRevision);

    homepage_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'wrong root tag triggers one full-document call');
    assert_eq($fullRevision, $project->readText('design/home.html'));
    assert_contains('splice_failure', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});

test('homepage-design never splices a patch into the wrong section after a self-closing non-void tag', function () {
    [$project, $llm, $tmp] = homepage_fixture([
        'design_candidates' => 2,
        'critique_rounds' => 1,
    ]);
    $winner = str_replace(
        '<section id="hero">',
        '<section id="spacer"/><section id="hero">',
        homepage_document('SELF-CLOSE', "\n.safe { color: red; }\n"),
    );
    homepage_queue_tournament($llm, [
        $winner,
        homepage_document('SAFE', "\n.safe { color: blue; }\n"),
    ]);
    $llm->queueJson([
        'verdict' => 'revise',
        'notes' => [['section' => '#feature', 'instruction' => 'Replace correct feature only']],
    ]);
    $replacement = '<section id="feature"><h2>Correct feature replacement</h2></section>';
    $llm->queueText("<!-- section: #feature -->\n```html\n{$replacement}\n```");
    $fullRevision = str_replace(
        '<section id="feature"><h2>Feature FULL</h2><p>Keep FULL</p></section>',
        $replacement,
        homepage_document('FULL', "\n.full { color: purple; }\n"),
    );
    $llm->queueText($fullRevision);

    homepage_run($project, $llm);

    $home = $project->readText('design/home.html');
    assert_contains($replacement, $home);
    assert_true(!str_contains($home, '<h2>Feature SELF-CLOSE</h2>'), 'original target replaced');
    assert_contains(
        '<section id="untouched" data-tone="quiet"><h2>Untouched SELF-CLOSE</h2></section>',
        $home,
        'direct splice leaves later sibling byte-identical',
    );
    assert_true(
        in_array($llm->completeCalls, [1, 2], true),
        'correct direct splice or one declared full-document fallback',
    );
    homepage_cleanup($tmp);
});

test('homepage-design rejects a sanitized patch whose raw root is unclosed', function () {
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
        . '<section id="feature"><h2>Broken safe content</h2><script>alert(1)'
        . "\n```"
    );
    $fullRevision = homepage_document('FULL', "\n.full { color: purple; }\n");
    $llm->queueText($fullRevision);

    homepage_run($project, $llm);

    assert_eq(2, $llm->completeCalls, 'unclosed sanitized root triggers one full-document call');
    assert_eq($fullRevision, $project->readText('design/home.html'));
    assert_contains('splice_failure', homepage_review_warnings($project));
    homepage_cleanup($tmp);
});
