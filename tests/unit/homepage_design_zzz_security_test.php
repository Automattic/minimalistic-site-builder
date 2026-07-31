<?php
declare(strict_types=1);

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
