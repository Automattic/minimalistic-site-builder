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
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
