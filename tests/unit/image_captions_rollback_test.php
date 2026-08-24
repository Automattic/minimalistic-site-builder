<?php
declare(strict_types=1);

use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

/**
 * File-level fixer rollback must not leave a caption-removed warnings.json
 * row for a file restored to step-entry bytes (BIGR-867 D5).
 */

test('a rolled-back file emits no caption-removed warning', function () {
    with_temp_dir('image-captions-rollback-', function (string $tmp): void {
        $project = new Project($tmp);
        $project->writeJson('designDirection.json', ['shape' => 'soft']);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        // A root text-alignment conflict still fails the WHOLE file (an
        // unsupported block no longer does — that is isolated to its own
        // block), so it is the device for exercising file-level rollback.
        $markup = '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Footscray, 5 days.</figcaption>'
            . '</figure><!-- /wp:image -->'
            . '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:right">Conflicting title</h2><!-- /wp:heading -->';
        $project->writeText('theme/parts/failed.html', $markup);

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        assert_eq($markup, $project->readText('theme/parts/failed.html'), 'failed unit restores entry bytes');
        $warnings = $project->readText('warnings.json');
        assert_true(
            !str_contains($warnings, 'Footscray, 5 days.'),
            'rolled-back caption removal is not recorded as delivered removed',
        );
        assert_contains('parts/failed.html', $warnings, 'the file-level rollback itself is still warned');
    });
});
