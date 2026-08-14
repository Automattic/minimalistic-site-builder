<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\SectionLayoutStep;

/**
 * End-to-end section-layout check: a wide wrapper authored against
 * design/site.css's var(--wide-size) reaches align:wide on its block, while a
 * content-tier section stays inset. Exercises the real step over real Project
 * I/O (pages.json + design/site.css + parts).
 */
test('section layout binds a var(--wide-size) wrapper to align:wide and leaves content sections inset', function () {
    $tmp = sys_get_temp_dir() . '/builder_section_layout_wide_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'sections' => [
                ['slug' => 'band', 'background' => 'base', 'vertical_density' => 'standard'],
                ['slug' => 'read', 'background' => 'base', 'vertical_density' => 'standard'],
            ],
        ]]]);
        $project->writeText(
            'design/site.css',
            ':root{--content-size:800px;--wide-size:1280px}'
                . '.wrap{max-width:var(--wide-size)}'
                . '.reading{max-width:var(--content-size)}',
        );
        $project->writeText(
            'theme/parts/page-home--band.html',
            '<!-- wp:group --><div class="wp-block-group wrap">'
                . '<!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );
        $project->writeText(
            'theme/parts/page-home--read.html',
            '<!-- wp:group --><div class="wp-block-group reading">'
                . '<!-- wp:paragraph --><p>Read</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        );

        (new SectionLayoutStep())->run($project);

        $band = BlockMarkup::parse($project->readText('theme/parts/page-home--band.html'));
        $bandRoot = (int) $band->topLevel();
        assert_eq('wide', ($band->attrs($bandRoot) ?? [])['align'] ?? null, 'wide wrapper reaches the block');
        assert_eq(['type' => 'constrained'], ($band->attrs($bandRoot) ?? [])['layout'] ?? null);

        $read = BlockMarkup::parse($project->readText('theme/parts/page-home--read.html'));
        $readRoot = (int) $read->topLevel();
        assert_true(!isset(($read->attrs($readRoot) ?? [])['align']), 'content-tier section carries no align');
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
