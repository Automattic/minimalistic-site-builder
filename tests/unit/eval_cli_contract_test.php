<?php
declare(strict_types=1);

test('eval records and reports per-step output-token usage', function () {
    $source = (string) file_get_contents(repo_path('bin/eval.php'));

    foreach ([
        "'usage'",
        "'output_tokens'",
        '## Output tokens per step',
    ] as $contract) {
        assert_contains($contract, $source);
    }
});
