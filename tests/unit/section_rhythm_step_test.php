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
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'one', 'background' => 'base', 'vertical_density' => 'compact'],
        ['slug' => 'two', 'background' => 'base', 'vertical_density' => 'standard'],
    ]]);
    $project->writeText('theme/parts/section-one.html', rhythm_step_part());
    $project->writeText('theme/parts/section-two.html', rhythm_step_part());
    $project->writeText(
        'theme/parts/footer.html',
        '<!-- wp:group {"backgroundColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|lg"}}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--lg)">Footer</div><!-- /wp:group -->'
    );

    (new SectionRhythmStep())->run($project);

    $one = BlockMarkup::parse($project->readText('theme/parts/section-one.html'));
    $two = BlockMarkup::parse($project->readText('theme/parts/section-two.html'));
    $oneAttrs = $one->attrs($one->indices()[0]);
    $twoAttrs = $two->attrs($two->indices()[0]);
    assert_eq('var:preset|spacing|lg', $oneAttrs['style']['spacing']['padding']['top']);
    assert_eq('0', $oneAttrs['style']['spacing']['padding']['bottom']);
    assert_eq('var:preset|spacing|xl', $twoAttrs['style']['spacing']['padding']['top']);
    assert_eq('0', $twoAttrs['style']['spacing']['padding']['bottom'], 'same-surface footer owns the final seam');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section rhythm step writes nothing when one section root is invalid', function () {
    $tmp = sys_get_temp_dir() . '/builder_rhythm_bad_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $original = rhythm_step_part();
    $project->writeJson('sections.json', ['sections' => [
        ['slug' => 'good', 'background' => 'base', 'vertical_density' => 'standard'],
        ['slug' => 'bad', 'background' => 'contrast', 'vertical_density' => 'compact'],
    ]]);
    $project->writeText('theme/parts/section-good.html', $original);
    $project->writeText('theme/parts/section-bad.html', '<!-- wp:heading --><h2>Bad</h2><!-- /wp:heading -->');

    assert_throws(static fn () => (new SectionRhythmStep())->run($project));
    assert_eq($original, $project->readText('theme/parts/section-good.html'));

    exec('rm -rf ' . escapeshellarg($tmp));
});
