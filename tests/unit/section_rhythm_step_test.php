<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\SectionRhythmStep;

function rhythm_step_part(): string
{
    return '<!-- wp:group {"style":{"spacing":{"padding":{"top":"12rem","bottom":"12rem"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" style="padding-top:12rem;padding-bottom:12rem">Content</div><!-- /wp:group -->';
}

test('section rhythm step rewrites every planned part atomically in page order', function () {
    $tmp = sys_get_temp_dir() . '/builder_rhythm_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    assert_eq(
        ['theme/parts/*', 'warnings.json'],
        (new SectionRhythmStep())->declaration()->writes,
        'degradations are durable project warnings',
    );
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'one', 'background' => 'base', 'vertical_density' => 'compact'],
            ['slug' => 'two', 'background' => 'base', 'vertical_density' => 'standard'],
        ]],
    ]]);
    $project->writeText('theme/parts/page-home--one.html', rhythm_step_part());
    $project->writeText('theme/parts/page-home--two.html', rhythm_step_part());
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:group {"backgroundColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|lg"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--lg)">Footer</div><!-- /wp:group -->'
    );

    (new SectionRhythmStep())->run($project);

    $one = BlockMarkup::parse($project->readText('theme/parts/page-home--one.html'));
    $two = BlockMarkup::parse($project->readText('theme/parts/page-home--two.html'));
    $oneAttrs = $one->attrs($one->indices()[0]);
    $twoAttrs = $two->attrs($two->indices()[0]);
    assert_eq('var:preset|spacing|lg', $oneAttrs['style']['spacing']['padding']['top']);
    assert_eq('0', $oneAttrs['style']['spacing']['padding']['bottom']);
    assert_eq('var:preset|spacing|xl', $twoAttrs['style']['spacing']['padding']['top']);
    assert_eq('0', $twoAttrs['style']['spacing']['padding']['bottom'], 'same-surface footer owns the final seam');
    assert_true(!$project->exists('warnings.json'), 'ordinary spacing adjustments are not warnings');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section rhythm step records only image degradations as durable warnings', function () {
    $tmp = sys_get_temp_dir() . '/builder_rhythm_degraded_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'image-band', 'background' => 'image', 'vertical_density' => 'compact'],
            ['slug' => 'ordinary', 'background' => 'base', 'vertical_density' => 'standard'],
        ]],
    ]]);
    $coverless = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph --></div>'
        . '<!-- /wp:group -->';
    $project->writeText('theme/parts/page-home--image-band.html', $coverless);
    $project->writeText('theme/parts/page-home--ordinary.html', rhythm_step_part());

    (new SectionRhythmStep())->run($project);

    $warnings = $project->readJson('warnings.json');
    assert_eq(['section-rhythm'], array_keys($warnings));
    assert_eq(1, count($warnings['section-rhythm']), 'the ordinary spacing adjustment is not recorded');
    $warning = $warnings['section-rhythm'][0];
    assert_contains("page 'home'", $warning);
    assert_contains("section 'image-band'", $warning);
    assert_contains('missing-direct-cover', $warning);
    assert_contains('solid-band rhythm', $warning);
    $attrs = BlockMarkup::parse(
        $project->readText('theme/parts/page-home--image-band.html')
    )->attrs(0);
    assert_contains('site-build-section-rhythm-degraded-image', $attrs['className'] ?? '');

    (new SectionRhythmStep())->run($project);
    assert_eq($warnings, $project->readJson('warnings.json'), 'a repeated pass does not duplicate degradation warnings');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section rhythm step writes nothing when one section root is invalid', function () {
    $tmp = sys_get_temp_dir() . '/builder_rhythm_bad_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $original = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:paragraph --><p>No cover</p><!-- /wp:paragraph --></div>'
        . '<!-- /wp:group -->';
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'degradable', 'background' => 'image', 'vertical_density' => 'standard'],
        ]],
        ['slug' => 'visit', 'front' => false, 'sections' => [
            ['slug' => 'bad', 'background' => 'image', 'vertical_density' => 'compact'],
        ]],
    ]]);
    $project->writeText('theme/parts/page-home--degradable.html', $original);
    $project->writeText(
        'theme/parts/page-visit--bad.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
            . '<!-- wp:cover [] --><div class="wp-block-cover"></div><!-- /wp:cover -->'
            . '</div><!-- /wp:group -->',
    );

    assert_throws(static fn () => (new SectionRhythmStep())->run($project));
    assert_eq(
        $original,
        $project->readText('theme/parts/page-home--degradable.html'),
        'an earlier degradable part remains untouched when a later root is fatal',
    );
    assert_true(
        !$project->exists('warnings.json'),
        'the staged degradation warning is not committed when a later root is fatal',
    );

    exec('rm -rf ' . escapeshellarg($tmp));
});
