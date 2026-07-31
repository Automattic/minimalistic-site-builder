<?php
declare(strict_types=1);

test('homepage-design removes unsafe URL schemes hidden by semicolonless numeric entities', function () {
    [$project, $llm, $tmp] = homepage_fixture(['design_candidates' => 2]);
    $unsafe = str_replace(
        '<section id="feature">',
        '<section id="feature"><a href="&#106avascript:alert(1)">Unsafe link</a>',
        homepage_document('ENTITY', "\n.safe { color: red; }\n"),
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
        assert_true(!str_contains(strtolower($delivered), '&#106avascript:'));
        assert_true(!str_contains(strtolower($delivered), 'javascript:'));
    }

    $warnings = homepage_review_warnings($project);
    assert_contains('malformed_design', $warnings);
    assert_contains('design/candidate-1.html', $warnings);
    assert_contains('delivered removed', $warnings);
    homepage_cleanup($tmp);
});
