<?php
declare(strict_types=1);

test('no prompt template carries a merge-conflict marker', function () {
    // PR #367 shipped a stray ">>>>>>> origin/trunk" line inside
    // prompts/design-direction.md, and every direction call sent it to the
    // model verbatim (BIGR-917). Prompt files are rendered as-is, so a
    // leftover marker is not a cosmetic defect: it is prompt content.
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(repo_path('prompts'), FilesystemIterator::SKIP_DOTS),
    );
    $seen = 0;
    foreach ($dir as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }
        $seen++;
        $body = (string) file_get_contents($file->getPathname());
        foreach (['<<<<<<< ', '>>>>>>> ', "\n=======\n"] as $marker) {
            assert_true(
                !str_contains($body, $marker),
                sprintf('%s contains a merge-conflict marker (%s)', $file->getPathname(), trim($marker)),
            );
        }
    }
    assert_true($seen > 30, 'the prompts directory scan found the template files');
});
