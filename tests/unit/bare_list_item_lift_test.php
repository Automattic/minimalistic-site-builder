<?php
declare(strict_types=1);

use Automattic\SiteBuild\BareListItemLift;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Steps\FixBlocksStep;

// The authored shape observed on pulso2 (ticket tiers) and atlas3 (schedule
// benefits) in the 2026-08-07 cohort: a wp:list whose <li> children are bare
// HTML with no wp:list-item delimiters, which re-serialization would empty.
function blil_bare_list(string $attrs = '', string $tag = 'ul'): string
{
    $json = $attrs === '' ? '' : ' ' . $attrs;
    return "<!-- wp:list{$json} -->\n<{$tag} class=\"wp-block-list\">"
        . '<li>Entry to both nights</li>'
        . '<li>All immersive installations</li>'
        . '<li>Priority window for <strong>workshop</strong> places</li>'
        . "</{$tag}>\n<!-- /wp:list -->";
}

test('bare <li> children are lifted into wp:list-item blocks', function () {
    $r = BareListItemLift::fix(blil_bare_list());
    assert_eq(3, substr_count($r['markup'], '<!-- wp:list-item -->'));
    assert_eq(3, substr_count($r['markup'], '<!-- /wp:list-item -->'));
    assert_contains('<li>Entry to both nights</li>', $r['markup']);
    assert_contains('<strong>workshop</strong>', $r['markup'], 'inline markup survives');
    assert_contains('lifted 3 bare <li> item(s)', implode("\n", $r['notes']));
});

test('the lift preserves list attributes and handles ordered lists', function () {
    $markup = '<!-- wp:list {"ordered":true,"style":{"spacing":{"margin":{"top":"var:preset|spacing|md"}}}} -->'
        . "\n" . '<ol style="margin-top:var(--wp--preset--spacing--md)" class="wp-block-list">'
        . '<li>First</li><li>Second</li></ol>' . "\n" . '<!-- /wp:list -->';
    $r = BareListItemLift::fix($markup);
    assert_eq(2, substr_count($r['markup'], '<!-- wp:list-item -->'));
    assert_contains('"ordered":true', $r['markup']);
    assert_contains('<ol style="margin-top:var(--wp--preset--spacing--md)" class="wp-block-list">', $r['markup']);
});

test('ordered wrapper semantics omitted from the comment are mirrored before serialization', function () {
    $markup = "<!-- wp:list -->\n"
        . '<ol class="wp-block-list" start="3" reversed><li>Third</li><li>Second</li></ol>'
        . "\n<!-- /wp:list -->";
    $r = BareListItemLift::fix($markup);
    assert_contains('<!-- wp:list {"ordered":true,"start":3,"reversed":true} -->', $r['markup']);
    assert_contains('ordered=true, start=3, reversed=true', implode("\n", $r['notes']));

    $typed = BareListItemLift::fix(str_replace(
        '<!-- wp:list -->',
        '<!-- wp:list {"metadata":{}} -->',
        $markup,
    ));
    assert_contains(
        '<!-- wp:list {"metadata":{},"ordered":true,"start":3,"reversed":true} -->',
        $typed['markup'],
        'mirroring retains empty JSON objects as objects',
    );

    $tmp = sys_get_temp_dir() . '/fix-blocks-bare-ol-' . uniqid();
    $project = new Project($tmp);
    $project->writeText('theme/parts/steps.html', $markup);
    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);
        $fixed = $project->readText('theme/parts/steps.html');
        assert_contains('<!-- wp:list {"ordered":true,"start":3,"reversed":true} -->', $fixed);
        assert_contains('<ol', $fixed, 'ordered list must not degrade to ul');
        assert_contains('start="3"', $fixed, 'authored numbering start survives');
        assert_contains('reversed', $fixed, 'authored reversed ordering survives');
    } finally {
        remove_tree($tmp);
    }
});

test('mixed proper and bare list items retain both items in source order', function () {
    $proper = "<!-- wp:list-item -->\n<li>Structured first</li>\n<!-- /wp:list-item -->";
    $markup = "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$proper}"
        . '<li>Bare second</li></ul>' . "\n<!-- /wp:list -->";
    $r = BareListItemLift::fix($markup);
    assert_eq(2, substr_count($r['markup'], '<!-- wp:list-item -->'));
    assert_contains($proper, $r['markup'], 'existing structured item stays byte-identical');
    assert_true(
        strpos($r['markup'], 'Structured first') < strpos($r['markup'], 'Bare second'),
        'mixed item order is preserved',
    );

    $second = BareListItemLift::fix($r['markup']);
    assert_eq($r['markup'], $second['markup'], 'mixed-list repair reaches a fixed point');
    assert_eq([], $second['notes']);
    assert_eq([], $second['warnings']);
});

test('the lift is idempotent and leaves proper lists byte-identical', function () {
    $lifted = BareListItemLift::fix(blil_bare_list())['markup'];
    $second = BareListItemLift::fix($lifted);
    assert_eq($lifted, $second['markup']);
    assert_eq([], $second['notes']);

    $proper = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->"
        . '<li>Already structured</li><!-- /wp:list-item --></ul>' . "\n<!-- /wp:list -->";
    $r = BareListItemLift::fix($proper);
    assert_eq($proper, $r['markup']);
    assert_eq([], $r['notes']);
});

test('nested lists, stray text and unbalanced items are left untouched', function () {
    $nested = "<!-- wp:list -->\n<ul class=\"wp-block-list\">"
        . '<li>Outer<ul><li>Inner</li></ul></li></ul>' . "\n<!-- /wp:list -->";
    assert_eq($nested, BareListItemLift::fix($nested)['markup']);

    $stray = "<!-- wp:list -->\n<ul class=\"wp-block-list\">"
        . '<li>One</li>loose text<li>Two</li></ul>' . "\n<!-- /wp:list -->";
    assert_eq($stray, BareListItemLift::fix($stray)['markup']);

    $unbalanced = "<!-- wp:list -->\n<ul class=\"wp-block-list\">"
        . '<li>One<li>Two</li></ul>' . "\n<!-- /wp:list -->";
    assert_eq($unbalanced, BareListItemLift::fix($unbalanced)['markup']);

    // An authored-empty list is not the lift's case; the existing
    // degrade-and-warn path owns it.
    $empty = "<!-- wp:list -->\n<ul class=\"wp-block-list\"></ul>\n<!-- /wp:list -->";
    assert_eq($empty, BareListItemLift::fix($empty)['markup']);
});

test('markup without any wp:list block is returned as-is', function () {
    $markup = '<!-- wp:paragraph --><p>No lists here</p><!-- /wp:paragraph -->';
    $r = BareListItemLift::fix($markup);
    assert_eq($markup, $r['markup']);
    assert_eq([], $r['notes']);
});

test('empty bare items are removed narrowly and recorded as durable warnings', function () {
    $markup = "<!-- wp:list -->\n<ul class=\"wp-block-list\">"
        . '<li></li><li>Surviving item</li></ul>' . "\n<!-- /wp:list -->";
    $tmp = sys_get_temp_dir() . '/fix-blocks-empty-bare-li-' . uniqid();
    $project = new Project($tmp);
    $project->writeText('theme/parts/list.html', $markup);

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/list.html');
        assert_contains('Surviving item', $fixed);
        assert_eq(1, substr_count($fixed, '<!-- wp:list-item'), 'only the empty item is removed');
        assert_true(!str_contains($fixed, '<li></li>'), 'blank bullet is not delivered');

        $warnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_contains('parts/list.html: wp:list[0]/li[0]', $warnings);
        assert_contains('authored value "<li></li>" -> delivered removed', $warnings);
        assert_contains('disposition: removed empty bare list item', $warnings);
    } finally {
        remove_tree($tmp);
    }
});

test('a failed file rolls back list repairs and warnings without affecting a sibling', function () {
    $failed = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><li></li></ul>\n<!-- /wp:list -->\n"
        . '<!-- wp:query --><div class="wp-block-query"></div><!-- /wp:query -->';
    $tmp = sys_get_temp_dir() . '/fix-blocks-bare-li-rollback-' . uniqid();
    $project = new Project($tmp);
    $project->writeText('theme/parts/failed.html', $failed);
    $project->writeText('theme/parts/sibling.html', blil_bare_list());

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        assert_eq($failed, $project->readText('theme/parts/failed.html'), 'failed unit restores entry bytes');
        assert_contains(
            'Entry to both nights',
            $project->readText('theme/parts/sibling.html'),
            'successful sibling remains repaired',
        );
        $warnings = implode("\n", $project->readJson('warnings.json')['fix-blocks'] ?? []);
        assert_true(!str_contains($warnings, 'empty bare list item'), 'rolled-back removal warning is filtered');
        assert_contains('left parts/failed.html unmodified', $warnings);
    } finally {
        remove_tree($tmp);
    }
});

test('FixBlocksStep delivers lifted list items through the pinned fixer', function () {
    $tmp = sys_get_temp_dir() . '/fix-blocks-bare-li-' . uniqid();
    $project = new Project($tmp);
    // Before the lift, this authored list serialized to an EMPTY <ul> — the
    // items existed only as raw innerHTML and the save regenerates from
    // inner blocks alone (pulso2 shipped all three ticket tiers this way).
    $project->writeText('theme/parts/tiers.html', blil_bare_list());

    try {
        (new FixBlocksStep(new PhpBlockFixer()))->run($project);

        $fixed = $project->readText('theme/parts/tiers.html');
        assert_contains('Entry to both nights', $fixed, 'first item content survives');
        assert_contains('All immersive installations', $fixed, 'second item content survives');
        assert_contains('Priority window for <strong>workshop</strong> places', $fixed, 'third item content survives');
        assert_eq(3, substr_count($fixed, '<!-- wp:list-item'), 'items are canonical blocks');
        assert_true(
            !preg_match('/<ul[^>]*>\s*<\/ul>/', $fixed),
            'no empty <ul> is delivered',
        );
        assert_contains(
            'bare list-item lift(s)',
            $project->readText('logs/fix-blocks.log'),
            'the lift is reported in the step log',
        );
    } finally {
        remove_tree($tmp);
    }
});
