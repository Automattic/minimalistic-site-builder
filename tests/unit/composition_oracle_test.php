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

test('narrow-heading oracle flags display H1s that cannot fit their column', function () {
    // portfolio4's shipped shape: a display H1 in a 36% column whose longest
    // word ("photographing", 13 chars) broke mid-word at masthead scale.
    $midWordBreak = '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column {"width":"36%"} --><div class="wp-block-column" style="flex-basis:36%">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} -->'
        . '<h1 class="wp-block-heading has-display-font-size">Twenty years photographing what Argentina decided in the street.</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"64%"} --><div class="wp-block-column" style="flex-basis:64%"><p>img</p></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
    $risks = ThemeValidator::narrowHeadingRisks($midWordBreak);
    assert_eq(1, count($risks));
    assert_contains('36% column', $risks[0]);
    assert_contains('break mid-word', $risks[0]);

    // An extreme sliver column is flagged regardless of word length.
    $sliver = str_replace(['"width":"36%"', 'flex-basis:36%'], ['"width":"20%"', 'flex-basis:20%'], $midWordBreak);
    $risks = ThemeValidator::narrowHeadingRisks($sliver);
    assert_eq(1, count($risks));
    assert_contains('one word', $risks[0]);
});

test('narrow-heading oracle passes wide columns, short words, and non-display headings', function () {
    // atlas5's shape: same 36% column but the longest word is 8 chars — the
    // headline wraps into whole lines without breaking; not flagged.
    $shortWords = '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column {"width":"36%"} --><div class="wp-block-column">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} -->'
        . '<h1 class="wp-block-heading has-display-font-size">The job ran clean because everyone knew where to be.</h1>'
        . '<!-- /wp:heading --></div><!-- /wp:column --></div><!-- /wp:columns -->';
    assert_eq([], ThemeValidator::narrowHeadingRisks($shortWords));

    // A 60% column holds a display heading fine.
    $wide = str_replace('"width":"36%"', '"width":"60%"', $shortWords);
    assert_eq([], ThemeValidator::narrowHeadingRisks($wide));

    // Section-title scale in a narrow column is the sanctioned alternative.
    $capped = str_replace('"fontSize":"display"', '"fontSize":"section-title"', $shortWords);
    assert_eq([], ThemeValidator::narrowHeadingRisks($capped));

    // A display heading outside any column is judged elsewhere.
    assert_eq([], ThemeValidator::narrowHeadingRisks(
        '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1 class="wp-block-heading">Extraordinarily long words everywhere</h1><!-- /wp:heading -->'
    ));
});

test('hero text budget flags an opening section with a caption pileup', function () {
    // naturaleza10's shape (BIGR-738 follow-up): H1 + lead + a dish-caption
    // cluster (eyebrow, caption) + CTA + hours microcopy — 4+ paragraphs.
    $tmp = sys_get_temp_dir() . '/builder_htb_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $pileup = '<!-- wp:group {"anchor":"hero","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group" id="hero">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1 class="wp-block-heading has-display-font-size">Smoke remembers the garden</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">Argentine table without meat.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">ASADO</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Coal-blistered chard and cauliflower</p><!-- /wp:paragraph -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#visit">Reserve a table</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">Dinner from 8 pm, Tuesday to Sunday</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"anchor":"story","layout":{"type":"constrained"}} --><div class="wp-block-group" id="story">'
        . '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>c</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>d</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('plugin/pages/home.html', $pileup);

    $warnings = ThemeValidator::heroTextBudgetWarnings($project);
    // Only the OPENING section is judged; later sections may be text-heavy.
    assert_eq(1, count($warnings));
    assert_contains('4 paragraph blocks', $warnings[0]);
    assert_contains('plugin/pages/home.html', $warnings[0]);

    // A budget-compliant hero passes.
    $lean = '<!-- wp:group {"anchor":"hero","layout":{"type":"constrained"}} --><div class="wp-block-group" id="hero">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">H</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>lead</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>eyebrow</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>microcopy</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $project->writeText('plugin/pages/home.html', $lean);
    assert_eq([], ThemeValidator::heroTextBudgetWarnings($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('narrow-heading oracle flags a display H1 boxed in a panel at half width', function () {
    // atlas10's shape: cover > alignwide columns > 46% column > background
    // panel group > display H1 with SHORT words — the old thresholds missed
    // it, but the pixel-capped panel wraps one word per line at 1920.
    $panel = '<!-- wp:cover {"url":"x.jpg","minHeight":94,"minHeightUnit":"vh"} --><div class="wp-block-cover">'
        . '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
        . '<!-- wp:column {"width":"46%"} --><div class="wp-block-column" style="flex-basis:46%">'
        . '<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} --><div class="wp-block-group has-contrast-background-color has-background">'
        . '<!-- wp:heading {"level":1,"fontSize":"display"} --><h1 class="wp-block-heading has-display-font-size">Run the job from the truck.</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"54%"} --><div class="wp-block-column"><p>x</p></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns --></div><!-- /wp:cover -->';
    $risks = ThemeValidator::narrowHeadingRisks($panel);
    assert_eq(1, count($risks));
    assert_contains('boxed panel inside a 46% column', $risks[0]);
    assert_contains('section-title', $risks[0]);

    // The same column WITHOUT a panel keeps the old (word-length) judgment.
    $noPanel = str_replace(
        ['<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} --><div class="wp-block-group has-contrast-background-color has-background">', '</div><!-- /wp:group --></div><!-- /wp:column -->'],
        ['', '</div><!-- /wp:column -->'],
        $panel,
    );
    assert_eq([], ThemeValidator::narrowHeadingRisks($noPanel));
});
