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

test('section rhythm step skips a page whose section root is invalid and normalizes the rest', function () {
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

    (new SectionRhythmStep())->run($project);

    // The malformed page keeps its authored spacing (skip recorded durably);
    // the healthy page is still normalized — per-page isolation, not a
    // whole-build rejection.
    assert_true(
        $original !== $project->readText('theme/parts/page-home--degradable.html'),
        'the healthy page is still normalized when a sibling page is malformed',
    );
    $warnings = $project->readJson('warnings.json')['section-rhythm'] ?? [];
    $joined = implode(' ', $warnings);
    assert_contains("page 'visit': section rhythm skipped", $joined);
    assert_contains('authored section spacing delivered', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section rhythm step skips invalid generated plan values per page', function () {
    $tmp = sys_get_temp_dir() . '/builder_rhythm_bad_plan_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $original = rhythm_step_part();
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'bad-density', 'background' => 'base', 'vertical_density' => 'gigantic'],
        ]],
        ['slug' => 'visit', 'front' => false, 'sections' => [
            ['slug' => 'healthy', 'background' => 'base', 'vertical_density' => 'compact'],
        ]],
    ]]);
    $project->writeText('theme/parts/page-home--bad-density.html', $original);
    $project->writeText('theme/parts/page-visit--healthy.html', $original);

    (new SectionRhythmStep())->run($project);

    assert_eq(
        $original,
        $project->readText('theme/parts/page-home--bad-density.html'),
        'the malformed page remains byte-for-byte authored',
    );
    assert_true(
        $original !== $project->readText('theme/parts/page-visit--healthy.html'),
        'a healthy sibling page is still normalized',
    );
    $joined = implode(' ', $project->readJson('warnings.json')['section-rhythm'] ?? []);
    assert_contains("page 'home': section rhythm skipped", $joined);
    assert_contains("invalid density 'gigantic'", $joined);
    assert_contains('authored section spacing delivered', $joined);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section rhythm step leaves HTML-first parts untouched', function () {
    $tmp = sys_get_temp_dir() . '/builder_rhythm_html_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true, 'sections' => [
            ['slug' => 'one', 'background' => 'base', 'vertical_density' => 'standard'],
            ['slug' => 'two', 'background' => 'base', 'vertical_density' => 'standard'],
        ]],
    ]]);
    $original = rhythm_step_part();
    $project->writeText('theme/parts/page-home--one.html', $original);
    $project->writeText('theme/parts/page-home--two.html', $original);

    (new SectionRhythmStep(htmlFirst: true))->run($project);

    assert_eq($original, $project->readText('theme/parts/page-home--one.html'));
    assert_eq($original, $project->readText('theme/parts/page-home--two.html'));
    $skipped = implode(' ', $project->readJson('warnings.json')['fixup_skipped'] ?? []);
    assert_contains('step=section-rhythm', $skipped);
    assert_contains('html-first', $skipped);

    exec('rm -rf ' . escapeshellarg($tmp));
});
