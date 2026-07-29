<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\ThemeValidator;

// Condensed from projects/atlas3/plugin/pages/home.html:20-35 (BIGR-738): the
// text column stacks heading → buttons → caption in one group, then the
// "Today — 3 jobs assigned" card follows as the NEXT SIBLING carrying
// overlap-up, whose negative margin swallows both CTAs mid-height.
function atlas3_overlap_column(): string
{
    return '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1 class="wp-block-heading has-display-font-size">Run the crew from your truck</h1><!-- /wp:heading -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="mailto:trial@atlasfield.com">Start your free trial</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">14 days, full access. No card, no contract.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"backgroundColor":"base","className":"overlap-up","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group overlap-up has-base-background-color has-background">'
        . '<!-- wp:paragraph --><p>Today — 3 jobs assigned</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}

test('overlap oracle flags a negative-margin card following a CTA stack', function () {
    $risks = ThemeValidator::overlapRisks(atlas3_overlap_column());
    assert_eq(1, count($risks));
    assert_contains("'overlap-up' utility class", $risks[0]);
    assert_contains('wp:button', $risks[0]);
});

test('overlap oracle allows overlap over imagery and non-overlapping cards', function () {
    // overlap-up breaking into a cover image above it — the designed use.
    $overImage = '<!-- wp:cover {"url":"theme:./assets/hero.jpg"} -->'
        . '<div class="wp-block-cover"><img class="wp-block-cover__image-background" src="theme:./assets/hero.jpg" alt=""/></div>'
        . '<!-- /wp:cover -->'
        . '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up"><p>card</p></div><!-- /wp:group -->';
    assert_eq([], ThemeValidator::overlapRisks($overImage));

    // No negative margin at all → nothing to judge.
    $plain = '<!-- wp:buttons --><div class="wp-block-buttons"></div><!-- /wp:buttons -->'
        . '<!-- wp:group --><div class="wp-block-group"><p>card</p></div><!-- /wp:group -->';
    assert_eq([], ThemeValidator::overlapRisks($plain));
});

test('overlap oracle flags an authored negative margin-top after a heading', function () {
    $markup = '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->'
        . '<!-- wp:group {"style":{"spacing":{"margin":{"top":"-4rem"}}}} -->'
        . '<div class="wp-block-group" style="margin-top:-4rem"><p>card</p></div><!-- /wp:group -->';
    $risks = ThemeValidator::overlapRisks($markup);
    assert_eq(1, count($risks));
    assert_contains('margin-top -4rem', $risks[0]);
    assert_contains('wp:heading', $risks[0]);
});

test('heading orphan oracle flags the atlas1 br-pinned period', function () {
    // Exact markup from projects/atlas1/plugin/pages/home.html:16 (BIGR-738):
    // "PAPERWORK." exactly fills the column at display size, the "." wraps
    // alone, and the geometric heading font renders it as a white square.
    $markup = '<!-- wp:heading {"level":1,"textColor":"base","fontFamily":"heading","fontSize":"display"} -->' . "\n"
        . '<h1 class="wp-block-heading has-base-color has-text-color has-heading-font-family has-display-font-size">Run the job.<br>Not the<br>paperwork.</h1>' . "\n"
        . '<!-- /wp:heading -->';
    $risks = ThemeValidator::headingOrphanRisks($markup);
    assert_eq(1, count($risks));
    assert_contains('paperwork.', $risks[0]);
    assert_contains('orphan', $risks[0]);
});

test('heading orphan oracle passes safe headings', function () {
    // <br>-segmented but no trailing punctuation.
    assert_eq([], ThemeValidator::headingOrphanRisks(
        '<!-- wp:heading --><h1 class="wp-block-heading">Run the job<br>not the paperwork</h1><!-- /wp:heading -->'
    ));
    // Punctuation but no <br> — the browser wraps freely, no pinned line.
    assert_eq([], ThemeValidator::headingOrphanRisks(
        '<!-- wp:heading --><h1 class="wp-block-heading">Run the job, not the paperwork.</h1><!-- /wp:heading -->'
    ));
});

test('composition oracles report per file through the project pass', function () {
    $tmp = sys_get_temp_dir() . '/builder_comp_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('plugin/pages/home.html', atlas3_overlap_column());
    $warnings = ThemeValidator::compositionOracleWarnings($project);
    assert_eq(1, count($warnings));
    assert_contains('plugin/pages/home.html:', $warnings[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});
