<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\ListItemFixer;
use Automattic\SiteBuild\BlockSerializer\Serializer;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\ThemeValidator;

// The exact failure shape from projects/atlas3/logs/llms/10-page-home--hero.log:925
// (BIGR-738): a wp:list whose <ul> holds raw <li> children with no wp:list-item
// comments. The re-serializer rebuilt innerBlocks from block comments, found
// zero list-item children, and shipped an EMPTY <ul>.
function atlas3_raw_list(): string
{
    return '<!-- wp:list {"className":"is-style-default","fontSize":"caption"} -->' . "\n"
        . '<ul class="wp-block-list is-style-default has-caption-font-size">'
        . '<li>07:00 — Hartley Ave. slab pour · Crew A · confirmed</li>'
        . '<li>11:30 — Rebar delivery, 2.4t · signed for on site</li>'
        . '<li>15:00 — Client walkthrough, Unit 12 · report filed</li></ul>' . "\n"
        . '<!-- /wp:list -->';
}

test('list-item fixer wraps raw li children into wp:list-item blocks', function () {
    $result = (new ListItemFixer())->fix(atlas3_raw_list());
    assert_eq(3, $result->count);
    assert_eq([0], $result->repairedListOrdinals);
    assert_eq(3, substr_count($result->html, '<!-- wp:list-item -->'));
    assert_eq(3, substr_count($result->html, '<!-- /wp:list-item -->'));
    assert_contains('Hartley Ave. slab pour', $result->html);
});

test('serializer keeps raw list items instead of emptying the list', function () {
    $result = (new Serializer())->transform(atlas3_raw_list());
    assert_true(!preg_match('/<ul[^>]*><\/ul>/', $result->html), 'no empty <ul> shipped');
    foreach (['07:00 — Hartley Ave. slab pour', '11:30 — Rebar delivery', '15:00 — Client walkthrough'] as $item) {
        assert_contains($item, $result->html);
    }
    assert_eq(3, substr_count($result->html, '<!-- wp:list-item -->'));
    $codes = array_map(static fn ($r) => $r->code, $result->repairs);
    assert_true(in_array('raw-list-item-wrapped', $codes, true), 'repair row reported');

    // Fixed point: transforming the repaired output again changes nothing.
    $again = (new Serializer())->transform($result->html);
    assert_eq($result->html, $again->html);
});

test('list-item fixer leaves canonical and ambiguous list bodies alone', function () {
    $fixer = new ListItemFixer();

    $canonical = '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item -->'
        . '<li>ok</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
    assert_eq(0, $fixer->fix($canonical)->count);
    assert_eq($canonical, $fixer->fix($canonical)->html);

    // Mixed wrapped + raw items: too ambiguous for a mechanical wrap.
    $mixed = '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item -->'
        . '<li>wrapped</li><!-- /wp:list-item --><li>raw</li></ul><!-- /wp:list -->';
    assert_eq(0, $fixer->fix($mixed)->count);

    // A nested raw list could make the non-greedy item match cut wrong.
    $nested = '<!-- wp:list --><ul class="wp-block-list"><li>outer<ul><li>inner</li></ul></li></ul><!-- /wp:list -->';
    assert_eq(0, $fixer->fix($nested)->count);
});

test('list-item fixer wraps raw items of an ordered list', function () {
    $markup = '<!-- wp:list {"ordered":true} --><ol class="wp-block-list"><li>one</li><li>two</li></ol><!-- /wp:list -->';
    $result = (new Serializer())->transform($markup);
    assert_contains('<li>one</li>', $result->html);
    assert_contains('<li>two</li>', $result->html);
    assert_contains('<ol', $result->html);
});

test('block fixer repairs a theme file with a raw-li list end to end', function () {
    $tmp = sys_get_temp_dir() . '/builder_li_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/parts/page-home--hero.html', atlas3_raw_list());

    $report = (new PhpBlockFixer())->fix($project->themePath());
    assert_contains('FIXED', $report);
    assert_contains('raw-list-item-wrapped', $report);

    $fixed = $project->readText('theme/parts/page-home--hero.html');
    assert_contains('Hartley Ave. slab pour', $fixed);
    assert_true(!preg_match('/<ul[^>]*><\/ul>/', $fixed), 'no empty <ul> in the delivered file');
    assert_eq([], ThemeValidator::emptyContainers($fixed));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('empty-container oracle names hollowed-out containers by file and path', function () {
    // What atlas3 actually shipped: the card's list emptied to <ul></ul>.
    $shipped = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:list {"fontSize":"caption"} -->'
        . '<ul class="wp-block-list has-caption-font-size"></ul>'
        . '<!-- /wp:list --></div><!-- /wp:group -->';
    $empty = ThemeValidator::emptyContainers($shipped);
    assert_eq(1, count($empty));
    assert_eq(['0/0', 'list'], $empty[0]);

    // A group with real content is not flagged; a contentless one is.
    assert_eq([], ThemeValidator::emptyContainers(
        '<!-- wp:group --><div class="wp-block-group"><p>text</p></div><!-- /wp:group -->'
    ));
    assert_eq([['0', 'group']], ThemeValidator::emptyContainers(
        '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->'
    ));
    // Media-only content counts as content.
    assert_eq([], ThemeValidator::emptyContainers(
        '<!-- wp:group --><div class="wp-block-group"><img src="/x.jpg" alt=""/></div><!-- /wp:group -->'
    ));

    // And the project-level pass names the file.
    $tmp = sys_get_temp_dir() . '/builder_ec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('plugin/pages/home.html', $shipped);
    $warnings = ThemeValidator::emptyContainerWarnings($project);
    assert_eq(1, count($warnings));
    assert_contains('plugin/pages/home.html block 0/0 (wp:list)', $warnings[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});
