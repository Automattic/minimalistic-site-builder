<?php
declare(strict_types=1);

test('design-preview sanitizes a removable script without an LLM repair', function () {
    [$project, $llm, $tmp] = design_preview_fixture();
    $authored = str_replace(
        '</header>',
        '</header><script>globalThis.previewAttack = true;</script>',
        design_preview_document('AUTHORED-SCRIPT-MARKER'),
    );
    $llm->queueText($authored);

    design_preview_run($project, $llm);

    $delivered = $project->readText('design/preview.html');
    $warnings = design_preview_warnings($project);
    $completeCalls = $llm->completeCalls;
    $allCalls = count($llm->calls);
    design_preview_cleanup($tmp);

    assert_eq(1, $completeCalls, 'removable script uses generation call only');
    assert_eq(1, $allCalls, 'removable script does not trigger repair');
    assert_contains('AUTHORED-SCRIPT-MARKER', $delivered, 'sanitizer preserves authored content');
    assert_true(!str_contains(strtolower($delivered), '<script'), 'script is removed');
    assert_contains('disposition repaired', $warnings, 'sanitizer repair is recorded');
    assert_true(!str_contains($warnings, 'disposition degraded'), 'safe scaffold is not used');
    design_preview_assert_shape($delivered);
});

$designPreviewEscapedCssAttacks = [
    'escaped url function' => [
        'css' => '.hero { background-image: u\\72l("https://cdn.example.test/hero.jpg"); }',
        'needle' => 'u\\72l(',
    ],
    'escaped import keyword' => [
        'css' => '@\\69mport "https://cdn.example.test/preview.css";',
        'needle' => '@\\69mport',
    ],
    'escaped font-face keyword' => [
        'css' => '@font\\2d face { font-family: Remote; src: local("Remote"); }',
        'needle' => '@font\\2d face',
    ],
];

foreach ($designPreviewEscapedCssAttacks as $name => $attack) {
    test("design-preview rejects {$name} and accepts one safe repair", function () use ($name, $attack) {
        [$project, $llm, $tmp] = design_preview_fixture();
        $authored = str_replace(
            '</style>',
            $attack['css'] . '</style>',
            design_preview_document('AUTHORED-ESCAPED-CSS'),
        );
        $safeRepair = design_preview_document('SAFE-ESCAPED-CSS-REPAIR');
        assert_contains($attack['needle'], $authored, "{$name} fixture carries escaped syntax");
        $llm->queueText($authored);
        $llm->queueText($safeRepair);

        design_preview_run($project, $llm);

        $delivered = $project->readText('design/preview.html');
        $warnings = design_preview_warnings($project);
        $completeCalls = $llm->completeCalls;
        $allCalls = count($llm->calls);
        design_preview_cleanup($tmp);

        assert_eq(2, $completeCalls, "{$name} triggers exactly one repair call");
        assert_eq(2, $allCalls, "{$name} has generation plus repair only");
        assert_eq($safeRepair, $delivered, "{$name} cannot ship as final output");
        assert_true(!str_contains($delivered, $attack['needle']), "{$name} is absent from delivery");
        assert_contains('SAFE-ESCAPED-CSS-REPAIR', $delivered, "{$name} delivers safe repair");
        assert_contains('disposition repaired', $warnings, "{$name} repair is recorded");
        design_preview_assert_shape($delivered);
    });
}
