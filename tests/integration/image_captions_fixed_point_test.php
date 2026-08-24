<?php
declare(strict_types=1);

use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

/**
 * The pipeline fixed point (BIGR-867). The block fixer re-serializes after
 * this pass, and core/image round-trips its caption between the <figcaption>
 * element and the `caption` attribute. Removing only one channel lets the
 * fixer put the caption straight back — the defect BIGR-861 shipped twice.
 * This asserts the caption is gone AFTER the fixer has run, not merely after
 * the pass.
 */

function ic_fixture_project(string $tmp, string $rel, string $markup): Project
{
    $project = new Project($tmp);
    $project->writeJson('designDirection.json', ['shape' => 'soft']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeText('theme/' . $rel, $markup);
    return $project;
}

test('a caption removed before the block fixer does not come back after it', function () {
    with_temp_dir('image-captions-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Brunswick, 2 days.</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/hero.html');
        assert_true(!str_contains($delivered, '<figcaption'), 'no figcaption survives the fixer');
        assert_true(!str_contains($delivered, 'Brunswick'), 'no caption text survives the fixer');
        assert_contains('<img', $delivered, 'the image itself survives');
    });
});

test('a gallery caption survives the whole step', function () {
    with_temp_dir('image-captions-gallery-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/work.html',
            '<!-- wp:gallery {"columns":2} --><figure class="wp-block-gallery">'
            . '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/y.jpg" alt="Plate"/>'
            . '<figcaption class="wp-element-caption">Plate 4, 1972.</figcaption>'
            . '</figure><!-- /wp:image -->'
            . '</figure><!-- /wp:gallery -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $delivered = $project->readText('theme/parts/work.html');
        assert_contains('Plate 4, 1972.', $delivered, 'gallery caption survives');
    });
});

test('a removed caption is recorded in warnings.json', function () {
    with_temp_dir('image-captions-warn-', function (string $tmp): void {
        $project = ic_fixture_project($tmp, 'parts/hero.html',
            '<!-- wp:image {"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large">'
            . '<img src="/x.jpg" alt="A yard"/>'
            . '<figcaption class="wp-element-caption">Footscray, 5 days.</figcaption>'
            . '</figure><!-- /wp:image -->'
        );

        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $warnings = $project->readText('warnings.json');
        assert_contains('Footscray, 5 days.', $warnings, 'the removed text is durable, not log-only');
        assert_contains('parts/hero.html', $warnings, 'the row names the file');
    });
});
