<?php
declare(strict_types=1);

use Automattic\SiteBuild\FooterComposition;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\HeaderTagline;
use Automattic\SiteBuild\PhpBlockFixer;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\FooterMarkup;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\GeneratedMarkup;
use Automattic\SiteBuild\Units\HeaderUnit;

/** @return array<string,mixed> */
function template_part_unit_input(): array
{
    return [
        'site_spec'        => '{"name":"PART-SPEC-SENTINEL"}',
        'language'         => 'part-language-sentinel',
        'theme_json'       => '{"part-theme-sentinel":true}',
        'design_direction' => 'PART-DIRECTION-SENTINEL',
        'outline'          => '1. PART-OUTLINE-SENTINEL (hero)',
        'site_pages'       => '- "Home" — / (front page): PART-PAGES-SENTINEL',
        'final_section_brief' => 'PART-FINAL-SECTION-SENTINEL',
        'composition_archetype' => 'photographic-split',
        'page_count' => 1,
    ];
}

test('a cache-layer marker inside authored input cannot break the split', function () {
    // design_direction is model-authored. Before the values were defused, a
    // marker in one of them made cacheLayers() count two and throw out of
    // requestsFor(), which has no catch: the build died on somebody's text.
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $request = $unit->request(array_merge(template_part_unit_input(), [
        'design_direction' => "PART-DIRECTION-SENTINEL <!-- cache-layer:site --> and more",
    ]));

    assert_eq(1, count($request['cached_prefixes']), 'still two layers, site plus unit');
    assert_contains('PART-DIRECTION-SENTINEL', $request['cached_prefixes'][0]);
    assert_contains('cache layer:site', $request['cached_prefixes'][0], 'the marker is defused, not dropped');
});

test('HeaderUnit generates a constrained header from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group {"tagName":"header","align":"full"} -->'
        . '<header class="wp-block-group"><!-- wp:site-title /--></header><!-- /wp:group -->'
    );
    $unit = new HeaderUnit($llm, new PromptRenderer(repo_path('prompts')));

    $result = $unit->generate(array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule'   => '- PART-NAV-SENTINEL',
        'above_fold_contract' => test_above_fold_contract('focal-subject-stage', 'branded-lockup'),
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]));
    $markup = $result->markup;

    $prompt = llm_call_text($llm->calls[0]);
    foreach (['PART-SPEC-SENTINEL', 'part-language-sentinel', 'part-theme-sentinel', 'PART-DIRECTION-SENTINEL', 'PART-OUTLINE-SENTINEL', 'PART-HERO-SENTINEL', 'PART-PAGES-SENTINEL', 'PART-NAV-SENTINEL', 'ABOVE-FOLD CONTRACT', 'branded-lockup', 'PART-BEHAVIOR-SENTINEL'] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_eq('header', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_contains('"layout":{"type":"constrained"}', $markup);
    assert_contains('"align":"full"', $markup);
    assert_true(!str_contains($markup, '"tagName":"header"'), 'template part owns the header landmark');
    assert_true(!str_contains($markup, '<header'), 'literal root header is rewritten');
    assert_true(!str_contains($markup, '</header>'), 'literal root header closer is rewritten');
    assert_contains('<div class="wp-block-group', $markup);
    assert_contains('header-archetype--branded-lockup', $markup);
    assert_eq([], $result->warnings);
    assert_eq(
        ['redundant-header-landmark-removed', 'root-marker-normalized', 'root-layout-constrained'],
        array_column($result->repairs, 'code'),
        'objective header repairs stay out of durable warnings',
    );
});

test('HeaderUnit restores the contract-owned tagline beside the site title idempotently', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $contract = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => $contract,
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}}} --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_eq(1, substr_count($first->markup, '<!-- wp:site-tagline'));
    assert_contains('<!-- wp:site-title /--><!-- wp:site-tagline', $first->markup);
    assert_eq(['header-tagline-restored'], array_column($first->repairs, 'code'));
    assert_eq([], $first->warnings);

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('HeaderUnit retains an unanchorable missing tagline and reports the contract defect', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $contract = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => $contract,
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">Identity unavailable</div><!-- /wp:group -->';

    $result = $unit->finish($raw, $input);

    assert_eq($raw, $result->markup);
    assert_eq([], $result->repairs);
    assert_eq(1, count($result->warnings));
    foreach ([
        "file='theme/parts/header.html'",
        "block='wp:site-tagline'",
        'Handmade ceramic lamps from Copenhagen',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $result->warnings[0]);
    }
});

test('HeaderUnit keeps title and tagline one stacked identity item in a flex row', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $contract = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => $contract,
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"flex","justifyContent":"space-between"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . '<!-- wp:site-title /--><!-- wp:navigation /-->'
        . '</div><!-- /wp:group -->';

    $result = $unit->finish($raw, $input);
    $document = BlockMarkup::parse($result->markup);
    $byName = [];
    foreach ($document->indices() as $index) {
        $byName[$document->name($index)][] = $index;
    }
    $title = $byName['site-title'][0] ?? null;
    $tagline = $byName['site-tagline'][0] ?? null;
    $navigation = $byName['navigation'][0] ?? null;
    assert_true(is_int($title) && is_int($tagline) && is_int($navigation));
    assert_eq($document->parent($title), $document->parent($tagline));
    assert_true($document->parent($title) !== $document->parent($navigation));
    $identity = (int) $document->parent($title);
    assert_eq('group', $document->name($identity));
    assert_eq('0', $document->attrs($identity)['style']['spacing']['blockGap'] ?? null);
    assert_eq(
        ['header-tagline-restored', 'header-tagline-stack-normalized'],
        array_column($result->repairs, 'code'),
    );
    assert_eq([], $result->warnings);
});

test('HeaderUnit removes duplicate or contract-forbidden taglines with durable warnings', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $baseInput = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $tagline = '<!-- wp:site-tagline {"fontSize":"caption"} /-->';
    $identity = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /-->' . $tagline . $tagline
        . '</div><!-- /wp:group -->';
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">' . $identity . '</div><!-- /wp:group -->';

    $withTagline = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $withTagline['header']['displays_tagline'] = true;
    $withTagline['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $withTagline['header']['text_rows'] = 2;
    $deduped = $unit->finish($raw, $baseInput + ['above_fold_contract' => $withTagline]);
    assert_eq(1, substr_count($deduped->markup, '<!-- wp:site-tagline'));
    assert_eq(1, count($deduped->warnings));
    assert_contains('duplicate identity row', $deduped->warnings[0]);
    assert_contains('delivered=removed', $deduped->warnings[0]);
    $dedupedAgain = $unit->finish($deduped->markup, $baseInput + ['above_fold_contract' => $withTagline]);
    assert_eq($deduped->markup, $dedupedAgain->markup);
    assert_eq([], $dedupedAgain->warnings);

    $withoutTagline = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $stripped = $unit->finish($raw, $baseInput + ['above_fold_contract' => $withoutTagline]);
    assert_true(!str_contains($stripped->markup, 'wp:site-tagline'));
    assert_eq(2, count($stripped->warnings));
    assert_contains('does not display a tagline', implode("\n", $stripped->warnings));
    assert_contains('delivered=removed', implode("\n", $stripped->warnings));
});

test('HeaderUnit keeps the duplicate tagline already paired with the chosen site title', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $contract = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => $contract,
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . '<!-- wp:site-tagline {"className":"orphan-tagline"} /-->'
        . '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /-->'
        . '<!-- wp:site-tagline {"className":"paired-tagline"} /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_true(!str_contains($first->markup, 'orphan-tagline'));
    assert_contains('paired-tagline', $first->markup);
    assert_eq(1, substr_count($first->markup, '<!-- wp:site-tagline'));
    assert_eq([], $first->repairs);
    assert_eq(1, count($first->warnings));
    assert_contains('duplicate identity row', $first->warnings[0]);
    assert_contains('delivered=removed', $first->warnings[0]);

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs);
    assert_eq([], $second->warnings);
});

test('HeaderUnit preserves raw tagline payload and rebinds its path after removing an earlier duplicate', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $contract = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => $contract,
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $safeOrphan = '<!-- wp:site-tagline {"className":"safe-orphan"} /-->';
    $preferred = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /-->'
        . '<!-- wp:site-tagline {"className":"preferred-tagline"} /--></div><!-- /wp:group -->';
    $rawSurvivor = '<!-- wp:site-tagline {"className":"raw-survivor"} -->'
        . '<div class="wp-block-site-tagline">Handmade ceramic lamps from Copenhagen</div>'
        . '<img src="keep.jpg" alt=""><!-- /wp:site-tagline -->';
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . $safeOrphan . $preferred . $rawSurvivor
        . '</div><!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_true(!str_contains($first->markup, 'safe-orphan'));
    assert_contains('preferred-tagline', $first->markup, 'the title-paired tagline remains authoritative');
    assert_contains($rawSurvivor, $first->markup, 'visible raw media freezes its complete tagline boundary');
    assert_eq(2, count($first->warnings));
    assert_contains('delivered=removed', $first->warnings[0]);
    foreach ([
        "block='wp:site-tagline[2]'",
        'raw/non-block payload',
        'retained transactionally',
    ] as $context) {
        assert_contains($context, $first->warnings[1]);
    }

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq([$first->warnings[1]], $second->warnings, 'delivered path and residual warning are fixed-point stable');
});

test('HeaderUnit retains unsafe nested tagline boundaries and their surviving identity blocks', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => test_above_fold_contract('focal-subject-stage', 'standard-row'),
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $nested = '<!-- wp:site-tagline --><div class="bad-tagline">'
        . '<!-- wp:site-title /--><!-- wp:navigation /-->'
        . '</div><!-- /wp:site-tagline -->';
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">' . $nested . '</div><!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_eq($raw, $first->markup);
    assert_contains('wp:site-title', $first->markup);
    assert_contains('wp:navigation', $first->markup);
    assert_eq(1, count($first->warnings));
    assert_contains('retained transactionally', $first->warnings[0]);
    assert_contains('delivered=', $first->warnings[0]);
    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq($first->repairs, $second->repairs);
    assert_eq($first->warnings, $second->warnings);
});

test('HeaderUnit retains an outer stray tagline that contains an unsafe raw-media tagline', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => test_above_fold_contract('focal-subject-stage', 'standard-row'),
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $inner = '<!-- wp:site-tagline --><div class="inner-tagline">'
        . 'Generated tagline<img src="keep.jpg" alt="Keep">'
        . '</div><!-- /wp:site-tagline -->';
    $nested = '<!-- wp:site-tagline --><div class="outer-tagline">'
        . $inner . '</div><!-- /wp:site-tagline -->';
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . $nested . '</div><!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    assert_eq($raw, $first->markup, 'the nested candidate component stays byte-for-byte intact');
    assert_contains('keep.jpg', $first->markup);
    assert_eq(2, count($first->warnings));
    assert_contains('retained transactionally', implode("\n", $first->warnings));

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq($first->repairs, $second->repairs);
    assert_eq($first->warnings, $second->warnings, 'ancestor and raw-survivor warnings reach one fixed point');
});

test('HeaderTagline freezes complete descendants inside an incomplete tagline for either contract state', function () {
    $markup = '<!-- wp:site-tagline --><div class="incomplete-tagline">'
        . '<!-- wp:site-tagline {"className":"complete-child"} /-->';
    $header = test_above_fold_contract('focal-subject-stage', 'standard-row')['header'];

    $first = HeaderTagline::ensure($markup, $header, 'header');

    assert_eq($markup, $first['markup']);
    assert_eq([], $first['repairs']);
    assert_eq(1, count($first['warnings']));
    assert_contains('incomplete boundary', $first['warnings'][0]);
    assert_contains('original header bytes', $first['warnings'][0]);
    assert_eq($first, HeaderTagline::ensure($first['markup'], $header, 'header'));

    $header['displays_tagline'] = true;
    $header['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $header['text_rows'] = 2;
    $promised = HeaderTagline::ensure($markup, $header, 'header');
    assert_eq($markup, $promised['markup']);
    assert_eq(1, count($promised['warnings']));
    assert_contains('final contract must narrow', $promised['warnings'][0]);
});

test('HeaderTagline retains raw payload in an apparent two-row identity stack at a fixed point', function () {
    $header = test_above_fold_contract('focal-subject-stage', 'standard-row')['header'];
    $header['displays_tagline'] = true;
    $header['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $header['text_rows'] = 2;
    $identity = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">Unexpected identity copy'
        . '<!-- wp:site-title /--><!-- wp:site-tagline /-->'
        . '</div><!-- /wp:group -->';
    $markup = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . $identity . '</div><!-- /wp:group -->';

    $first = HeaderTagline::ensure($markup, $header, 'header');

    assert_eq($markup, $first['markup']);
    assert_eq([], $first['repairs']);
    assert_eq(1, count($first['warnings']));
    foreach ([
        "file='theme/parts/header.html'",
        "block='wp:group[2] header identity stack'",
        'Unexpected identity copy',
        'delivered="original identity group bytes"',
        'retained transactionally',
    ] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }
    assert_eq($first, HeaderTagline::ensure($first['markup'], $header, 'header'));
});

test('HeaderTagline removes and reports a painted wrapper emptied with a forbidden tagline', function () {
    $header = test_above_fold_contract('focal-subject-stage', 'standard-row')['header'];
    $tagline = '<!-- wp:site-tagline {"fontSize":"caption"} /-->';
    $painted = '<!-- wp:group {"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px"}}}} -->'
        . '<div class="wp-block-group has-accent-background-color has-background" style="padding-top:20px">'
        . $tagline . '</div><!-- /wp:group -->';
    $markup = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . '<!-- wp:site-title /-->' . $painted
        . '</div><!-- /wp:group -->';

    $first = HeaderTagline::ensure($markup, $header, 'header');

    assert_contains('wp:site-title', $first['markup']);
    assert_true(!str_contains($first['markup'], 'wp:site-tagline'));
    assert_true(!str_contains($first['markup'], 'backgroundColor'));
    assert_eq(2, count($first['warnings']));
    assert_contains("block='wp:site-tagline[1]'", $first['warnings'][0]);
    foreach ([
        "block='wp:group[2]'",
        'backgroundColor',
        'padding-top:20px',
        'delivered=removed',
        'dead header UI',
    ] as $context) {
        assert_contains($context, $first['warnings'][1]);
    }
    assert_eq(
        ['markup' => $first['markup'], 'repairs' => [], 'warnings' => []],
        HeaderTagline::ensure($first['markup'], $header, 'header'),
    );
});

test('HeaderUnit never widens stray-tagline removal through the required header root', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => test_above_fold_contract('focal-subject-stage', 'standard-row'),
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $raw = '<!-- wp:group {"className":"header-archetype--standard-row","backgroundColor":"base","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row has-base-background-color has-background">'
        . '<!-- wp:site-tagline /--></div><!-- /wp:group -->';

    $result = $unit->finish($raw, $input);

    assert_true($result->markup !== '');
    assert_contains('header-archetype--standard-row', $result->markup);
    assert_contains('"backgroundColor":"base"', $result->markup);
    assert_true(!str_contains($result->markup, 'wp:site-tagline'));
    assert_eq(1, count($result->warnings));
});

test('HeaderUnit repairs grid identity pairs but does not move taglines across parent branches', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $contract = test_above_fold_contract('focal-subject-stage', 'standard-row');
    $contract['header']['displays_tagline'] = true;
    $contract['header']['tagline_text'] = 'Handmade ceramic lamps from Copenhagen';
    $contract['header']['text_rows'] = 2;
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => $contract,
        'header_behavior' => 'PART-BEHAVIOR-SENTINEL',
    ]);
    $gridIdentity = '<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"grid","columnCount":2}} -->'
        . '<div class="wp-block-group"><!-- wp:site-title /--><!-- wp:site-tagline /--></div><!-- /wp:group -->';
    $grid = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">' . $gridIdentity . '</div><!-- /wp:group -->';

    $gridResult = $unit->finish($grid, $input);
    assert_true(!str_contains($gridResult->markup, '"type":"grid"'));
    assert_contains('"layout":{"type":"constrained"}', $gridResult->markup);
    assert_eq(['header-tagline-stack-normalized'], array_column($gridResult->repairs, 'code'));
    assert_eq([], $gridResult->warnings);

    $separate = '<!-- wp:group {"className":"header-archetype--standard-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group header-archetype--standard-row">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:group {"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px"}}}} -->'
        . '<div class="wp-block-group has-accent-background-color has-background"><!-- wp:site-tagline /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $separateResult = $unit->finish($separate, $input);
    assert_eq($separate, $separateResult->markup);
    assert_eq([], $separateResult->repairs);
    assert_eq(1, count($separateResult->warnings));
    assert_contains('different structural parents', $separateResult->warnings[0]);
    assert_contains('has-accent-background-color', $separateResult->markup);
});

test('FooterUnit generates a constrained footer from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="wp-block-group">'
        . '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="nested">Nested</footer><!-- /wp:group -->'
        . '</footer><!-- /wp:group -->'
    );
    $unit = new FooterUnit($llm, new PromptRenderer(repo_path('prompts')));

    $result = $unit->generate(template_part_unit_input());
    $markup = $result->markup;

    $prompt = llm_call_text($llm->calls[0]);
    foreach (['PART-SPEC-SENTINEL', 'part-language-sentinel', 'part-theme-sentinel', 'PART-DIRECTION-SENTINEL', 'PART-OUTLINE-SENTINEL', 'PART-PAGES-SENTINEL', 'PART-FINAL-SECTION-SENTINEL', 'photographic-split'] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_contains('This site is ONE page: NEVER use `wp:page-list`', $prompt);
    assert_contains('AI_IMAGE: subject | page-context | style | aspect-ratio', $prompt);
    assert_eq('footer', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_true(!str_contains($markup, '"tagName":"footer"'), 'template part owns the footer landmark');
    assert_true(!str_contains($markup, '<footer'), 'literal root footer is rewritten');
    assert_true(!str_contains($markup, '</footer>'), 'literal root footer closer is rewritten');
    assert_contains('<div class="wp-block-group', $markup);
    assert_contains('<div class="nested">Nested</div>', $markup);
    assert_contains('"backgroundColor":"contrast"', $markup);
    assert_contains('has-contrast-background-color', $markup);
    assert_contains('"layout":{"type":"constrained"}', $markup);
    assert_eq([], $result->warnings);
    assert_eq(
        ['redundant-footer-landmark-removed', 'footer-surface-enforced', 'root-layout-constrained'],
        array_column($result->repairs, 'code'),
        'objective footer repairs stay out of durable warnings',
    );
});

test('FooterUnit ignores hero-only blueprint and above-fold context', function () {
    $input = template_part_unit_input();
    $input['hero_blueprint'] = [
        'recipe' => 'typographic-poster',
        'media_mode' => 'FOOTER-HERO-BLUEPRINT-LEAK-SENTINEL',
    ];
    $input['above_fold_contract'] = 'FOOTER-ABOVE-FOLD-LEAK-SENTINEL';
    $input['neighbors'] = 'FOOTER-HERO-NEIGHBOR-LEAK-SENTINEL';

    $prompt = (new FooterUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts'))
    ))->request($input)['prompt'];

    foreach ([
        'FOOTER-HERO-BLUEPRINT-LEAK-SENTINEL',
        'FOOTER-ABOVE-FOLD-LEAK-SENTINEL',
        'FOOTER-HERO-NEIGHBOR-LEAK-SENTINEL',
        'hero-composition--typographic-poster',
    ] as $sentinel) {
        assert_true(!str_contains($prompt, $sentinel), "footer excludes hero-only context {$sentinel}");
    }
    assert_contains('PART-FINAL-SECTION-SENTINEL', $prompt, 'footer-specific seam context remains present');
});

test('HeaderUnit normalizes exactly one assigned root archetype marker idempotently', function () {
    $unit = new HeaderUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $input = array_merge(template_part_unit_input(), [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule' => '- PART-NAV-SENTINEL',
        'above_fold_contract' => test_above_fold_contract('focal-subject-stage', 'branded-lockup'),
    ]);
    $raw = '<!-- wp:group {"className":"keep header-archetype--old header-archetype--stale","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group keep header-archetype--old header-archetype--old">Header</div>'
        . '<!-- /wp:group -->';

    $first = $unit->finish($raw, $input);

    $document = BlockMarkup::parse($first->markup);
    $root = $document->topLevel();
    assert_true(is_int($root));
    $attrs = $document->attrs($root);
    assert_true(is_array($attrs));
    $tokens = preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: [];
    assert_eq(1, count(array_filter(
        $tokens,
        static fn (string $token): bool => $token === 'header-archetype--branded-lockup'
    )));
    assert_eq(1, substr_count($document->ownHtml($root), 'header-archetype--branded-lockup'));
    assert_true(!str_contains($first->markup, 'header-archetype--old'));
    assert_true(!str_contains($first->markup, 'header-archetype--stale'));
    assert_eq(2, substr_count($first->markup, 'keep'), 'unrelated comment and saved-HTML classes survive');
    assert_eq(['root-marker-normalized'], array_column($first->repairs, 'code'));
    assert_eq([], $first->warnings);

    $second = $unit->finish($first->markup, $input);
    assert_eq($first->markup, $second->markup);
    assert_eq([], $second->repairs, 'marker repair reaches a clean fixed point');
    assert_eq([], $second->warnings);
});

test('FooterUnit renders exactly one reviewed recipe and image instructions only when needed', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $markers = [
        'typographic-billboard' => 'ONE viewport-filling brand line',
        'photographic-split' => 'deliberately unequal 60/40 or 65/35',
        'image-plinth' => 'Treat ONE foreground wp:image as the focal object',
        'conversion-panel' => 'Build a bold, offset invitation',
        'editorial-colophon' => 'final plate of a book or',
        'split-ledger' => 'Build a strong 65/35 or 70/30 split',
    ];

    assert_eq(FooterComposition::ARCHETYPES, array_keys($markers));
    foreach ($markers as $archetype => $marker) {
        $prompt = (new FooterUnit(new FakeLlm(), $renderer))->request(array_merge(
            template_part_unit_input(),
            ['composition_archetype' => $archetype]
        ))['prompt'];

        assert_contains("ASSIGNED FOOTER COMPOSITION for this build: **{$archetype}**", $prompt);
        assert_contains(
            'ASSIGNED FOOTER SURFACE: **' . FooterComposition::surface($archetype) . '**',
            $prompt
        );
        assert_contains($marker, $prompt);
        foreach ($markers as $otherArchetype => $otherMarker) {
            if ($otherArchetype !== $archetype) {
                assert_true(
                    !str_contains($prompt, $otherMarker),
                    "{$otherArchetype} recipe is absent when {$archetype} is assigned"
                );
            }
        }
        assert_eq(
            FooterComposition::usesGeneratedImage($archetype),
            str_contains($prompt, 'AI_IMAGE: subject | page-context | style | aspect-ratio'),
            "{$archetype} receives the right image-instruction contract"
        );
        assert_eq(
            FooterComposition::usesGeneratedImage($archetype),
            str_contains($prompt, 'never `portrait`'),
            "{$archetype} image recipe forbids portrait footer images"
        );
        assert_contains(
            'FIT-TEXT IDENTITY LINE',
            $prompt,
            "{$archetype} receives the shared fit-text identity device"
        );
        assert_contains('"fitText":true', $prompt);
    }
});

test('FooterUnit caps portrait image placeholders to square in alt and mirrored block JSON', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"base"} --><div class="wp-block-group">'
        . '<!-- wp:image {"sizeSlug":"large","alt":"AI_IMAGE: A carpenter\'s clay pitcher | footer plinth | photorealistic | portrait"} -->'
        . '<figure class="wp-block-image size-large">'
        . '<img src="theme:./assets/footer-pitcher.jpg" alt="AI_IMAGE: A carpenter\'s clay pitcher | footer plinth | photorealistic | portrait"/>'
        . '</figure><!-- /wp:image -->'
        . '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="theme:./assets/footer-room.jpg" alt="AI_IMAGE: A dining room | footer image field | photorealistic | landscape"/>'
        . '</figure><!-- /wp:image --></div><!-- /wp:group -->';
    $input = array_merge(template_part_unit_input(), ['composition_archetype' => 'image-plinth']);

    $result = $unit->finish($raw, $input);
    $once = $result->markup;
    $notes = $result->warnings;

    assert_true(!str_contains($once, 'portrait'), 'no portrait placeholder survives');
    assert_eq(2, substr_count($once, 'photorealistic | square"'), 'HTML alt and mirrored JSON alt are both capped');
    assert_contains('| photorealistic | landscape', $once);
    $joined = implode("\n", $notes);
    assert_contains('authored aspect-ratio=portrait', $joined);
    assert_contains('delivered=square', $joined);
    assert_contains('disposition=', $joined);
    assert_eq(2, count(array_filter($notes, fn ($n) => str_contains($n, 'AI_IMAGE placeholder'))), 'alt and mirrored JSON each record their rewrite');
    assert_true(
        !in_array('AI_IMAGE placeholder', array_column($result->repairs, 'code'), true),
        'authored portrait loss remains a warning rather than a successful repair',
    );
    assert_eq($once, $unit->finish($once, $input)->markup, 'portrait capping reaches a fixed point');
});

test('portrait cap covers card-portrait and tall numeric ratios, leaves wide ones alone', function () {
    $markup = '<img src="theme:./assets/a.jpg" alt="AI_IMAGE: A potter | footer | photorealistic | card-portrait"/>'
        . '<img src="theme:./assets/b.jpg" alt="AI_IMAGE: A kiln | footer | photorealistic | 9:16"/>'
        . '<img src="theme:./assets/c.jpg" alt="AI_IMAGE: A studio | footer | photorealistic | 16:9"/>'
        . '<img src="theme:./assets/d.jpg" alt="AI_IMAGE: A wheel | footer | photorealistic | card-landscape"/>';

    $notes = [];
    $out = FooterMarkup::withoutPortraitImagePlaceholders($markup, $notes);

    assert_eq(2, substr_count($out, '| square"'), 'card-portrait and 9:16 are both capped');
    assert_contains('| 16:9', $out);
    assert_contains('| card-landscape', $out);
    $joined = implode("\n", $notes);
    assert_contains('authored aspect-ratio=card-portrait', $joined);
    assert_contains('authored aspect-ratio=9:16', $joined);
    assert_eq(2, count($notes), 'wide ratios record no rewrite');
    assert_eq($out, FooterMarkup::withoutPortraitImagePlaceholders($out), 'capping reaches a fixed point');
});

test('portrait cap covers recovered AI_IMAGE source forms before collection', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group --><div class="wp-block-group">'
        . '<img src="AI_IMAGE:A potter|ratio:card-portrait|role:footer" alt=""/>'
        . '<img src=\' AI_IMAGE:A kiln|role:footer|ratio:9:16\' alt=""/>'
        . '<!-- wp:cover {"url":"AI_IMAGE:A studio|ratio:3:4|role:footer"} -->'
        . '<div class="wp-block-cover"></div><!-- /wp:cover -->'
        . '<img src=AI_IMAGE:A-vase|ratio:portrait|role:footer alt=""/>'
        . '<img src="AI_IMAGE:A loom|footer context|photorealistic|portrait" alt=""/>'
        . '<img src="AI_IMAGE:A mural|portrait|photorealistic|square" alt=""/>'
        . '<img src="AI_IMAGE:A wheel|ratio:16:9|role:footer" alt=""/>'
        . '</div><!-- /wp:group -->';
    $input = array_merge(template_part_unit_input(), ['composition_archetype' => 'image-plinth']);

    $result = $unit->finish($raw, $input);
    $once = $result->markup;
    $notes = $result->warnings;

    assert_contains('AI_IMAGE:A potter|ratio:square|role:footer', $once);
    assert_contains('AI_IMAGE:A kiln|role:footer|ratio:square', $once);
    assert_contains('AI_IMAGE:A studio|ratio:square|role:footer', $once);
    assert_contains('AI_IMAGE:A-vase|ratio:square|role:footer', $once);
    assert_contains('AI_IMAGE:A loom|footer context|photorealistic|square', $once);
    assert_contains('AI_IMAGE:A mural|portrait|photorealistic|square', $once);
    assert_contains('AI_IMAGE:A wheel|ratio:16:9|role:footer', $once);
    $joined = implode("\n", $notes);
    foreach (['card-portrait', '9:16', '3:4', 'portrait'] as $authored) {
        assert_contains("authored aspect-ratio={$authored}", $joined);
    }
    assert_eq(5, count(array_filter(
        $notes,
        static fn (string $note): bool => str_contains($note, 'AI_IMAGE placeholder'),
    )), 'each recovered portrait source records one actionable rewrite');
    assert_eq($once, $unit->finish($once, $input)->markup, 'source-form capping reaches a fixed point');
});

test('FooterUnit rejects an unknown composition before generation', function () {
    $llm = new FakeLlm();
    $unit = new FooterUnit($llm, new PromptRenderer(repo_path('prompts')));

    assert_throws(
        fn () => $unit->request(array_merge(
            template_part_unit_input(),
            ['composition_archetype' => 'generic-columns']
        )),
        'unknown footer archetype'
    );
    assert_eq([], $llm->calls, 'invalid adapter input never reaches the model');
});

test('FooterUnit derives navigation from typed page count and rejects invalid counts', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $multi = $unit->request(array_merge(template_part_unit_input(), ['page_count' => 2]))['prompt'];

    assert_contains('SITE PAGES except the front page', $multi);
    assert_contains('NEVER include Home', $multi);
    assert_true(!str_contains($multi, 'This site is ONE page'));
    assert_throws(
        fn () => $unit->request(array_merge(template_part_unit_input(), ['page_count' => '2'])),
        "unit input 'page_count' must be an integer"
    );
    assert_throws(
        fn () => $unit->request(array_merge(template_part_unit_input(), ['page_count' => 0])),
        'footer page_count must be at least 1'
    );
});

test('FooterUnit repairs a wrong root surface and stale saved classes to the assigned surface', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"tagName":"section","backgroundColor":"primary","gradient":"sunset","className":"keep has-primary-background-color has-sunset-gradient-background has-background-gradient","style":{"background":{"backgroundImage":{"url":"theme:./assets/footer.jpg"}},"color":{"background":"#ffffff","gradient":"linear-gradient(90deg, red, blue)"},"spacing":{"padding":{"top":"var:preset|spacing|sm"}},"css":"& { background:red !important; }","variation":"section-1"}} -->'
        . '<section class="wp-block-group keep has-primary-background-color has-sunset-gradient-background has-background-gradient has-background" style="padding-top:1px;background:linear-gradient(90deg, red, blue);color:red;background-image:url(theme:./assets/footer.jpg)">Footer</section>'
        . '<!-- /wp:group -->';
    $input = template_part_unit_input(); // photographic-split maps to contrast.

    $result = $unit->finish($raw, $input);
    $once = $result->markup;
    $notes = $result->warnings;
    assert_contains('"backgroundColor":"contrast"', $once);
    assert_contains('"tagName":"section"', $once);
    assert_contains('"className":"keep"', $once);
    assert_contains('"spacing":{"padding":{"top":"var:preset|spacing|sm"}}', $once);
    assert_contains('<section class="wp-block-group keep has-contrast-background-color has-background"', $once);
    assert_contains('style="padding-top:1px;color:red"', $once);
    assert_true(!str_contains($once, 'has-primary-background-color'));
    assert_true(!str_contains($once, 'has-sunset-gradient-background'));
    assert_true(!str_contains($once, 'has-background-gradient'));
    assert_true(!str_contains($once, '"gradient":'));
    assert_true(!str_contains($once, '"background":'));
    assert_true(!str_contains($once, 'background-image:'));
    assert_true(!str_contains($once, 'background:linear-gradient'));
    assert_true(!str_contains($once, '"css":'));
    assert_true(!str_contains($once, '"variation":'));
    assert_eq(2, count($notes), 'each opaque root style removal is durable');
    assert_contains("file='parts/footer.html'", implode("\n", $notes));
    assert_contains("authored style.css=", implode("\n", $notes));
    assert_contains("authored style.variation=", implode("\n", $notes));
    assert_contains('delivered=removed', implode("\n", $notes));
    assert_contains('disposition=', implode("\n", $notes));
    assert_eq(
        ['footer-surface-enforced', 'root-layout-constrained'],
        array_column($result->repairs, 'code'),
        'enforcing the assigned surface/layout remains separate from opaque style loss',
    );
    assert_eq($once, $unit->finish($once, $input)->markup, 'surface repair reaches a fixed point');

    $tmp = sys_get_temp_dir() . '/builder_footer_surface_' . bin2hex(random_bytes(6));
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/parts/footer.html', $once);
        (new PhpBlockFixer())->fix($project->themePath());
        $fixed = $project->readText('theme/parts/footer.html');
        assert_contains('"backgroundColor":"contrast"', $fixed);
        assert_contains('has-contrast-background-color', $fixed);
        assert_true(!str_contains($fixed, 'has-primary-background-color'));
        assert_true(!str_contains($fixed, '"css":'));
        assert_true(!str_contains($fixed, '"variation":'));
        assert_true(!str_contains($fixed, 'background:red'));
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    $base = $unit->finish(
        '<!-- wp:group --><div class="wp-block-group">Footer</div><!-- /wp:group -->',
        array_merge($input, ['composition_archetype' => 'typographic-billboard'])
    )->markup;
    assert_contains('"backgroundColor":"base"', $base);
    assert_contains('has-base-background-color', $base);
});

test('FooterUnit wraps multiple generated roots in one assigned-surface group without losing content', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"primary"} -->'
        . '<div class="wp-block-group has-primary-background-color has-background">Identity</div>'
        . '<!-- /wp:group -->'
        . '<!-- wp:paragraph --><p>Credit survives.</p><!-- /wp:paragraph -->';
    $input = template_part_unit_input();

    $result = $unit->finish($raw, $input);
    $once = $result->markup;

    assert_true(str_starts_with($once, '<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} -->'));
    assert_contains('class="wp-block-group has-contrast-background-color has-background"', $once);
    assert_contains('Identity', $once);
    assert_contains('Credit survives.', $once);
    assert_eq([], $result->warnings, 'safe root wrapping preserves every authored byte');
    assert_eq(
        ['footer-surface-enforced', 'root-layout-constrained'],
        array_column($result->repairs, 'code'),
    );
    assert_eq($once, $unit->finish($once, $input)->markup, 'multiple-root repair reaches a fixed point');
});

test('FooterUnit wraps a single non-group root instead of discarding it for the fallback', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:paragraph --><p>Credit survives.</p><!-- /wp:paragraph -->';
    $input = template_part_unit_input(); // photographic-split maps to contrast.

    $once = $unit->finish($raw, $input)->markup;

    assert_true(str_starts_with($once, '<!-- wp:group {"backgroundColor":"contrast","layout":{"type":"constrained"}} -->'));
    assert_contains('class="wp-block-group has-contrast-background-color has-background"', $once);
    assert_contains('Credit survives.', $once);
    assert_eq($once, $unit->finish($once, $input)->markup, 'single non-group-root repair reaches a fixed point');
});

test('FooterUnit preserves a fit-text billboard heading through finish and block re-serialization', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"base","align":"full","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignfull has-base-background-color has-background">'
        . '<!-- wp:heading {"align":"full","fitText":true} -->'
        . '<h2 class="wp-block-heading alignfull has-fit-text">Hola</h2>'
        . '<!-- /wp:heading --></div><!-- /wp:group -->';
    $input = array_merge(template_part_unit_input(), ['composition_archetype' => 'typographic-billboard']);

    $once = $unit->finish($raw, $input)->markup;

    assert_contains('"fitText":true', $once);
    assert_contains('has-fit-text', $once);
    assert_contains('"backgroundColor":"base"', $once);
    assert_eq($once, $unit->finish($once, $input)->markup, 'fit-text footer reaches a fixed point');

    $tmp = sys_get_temp_dir() . '/builder_footer_fit_text_' . bin2hex(random_bytes(6));
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/parts/footer.html', $once);
        (new PhpBlockFixer())->fix($project->themePath());
        $fixed = $project->readText('theme/parts/footer.html');
        assert_contains('"fitText":true', $fixed);
        assert_contains('has-fit-text', $fixed);
    } finally {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('FooterUnit removes a malformed root style attribute instead of discarding usable content', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group -->'
        . '<div class="wp-block-group" style="color:red;background:url(theme:./asset.jpg">Footer stays.</div>'
        . '<!-- /wp:group -->';
    $result = $unit->finish($raw, template_part_unit_input());
    $out = $result->markup;
    $notes = $result->warnings;

    assert_contains('Footer stays.', $out);
    assert_true(!str_contains($out, 'style='));
    assert_contains('saved HTML style attribute', implode("\n", $notes));
    assert_contains('delivered=removed', implode("\n", $notes));
});

test('FooterUnit cleans a root wrapper after an HTML comment and handles unquoted attributes', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group {"backgroundColor":"primary","className":"keep&#32;has-primary-background-color"} -->'
        . '<!--keep-note--><div data-note=\' class="decoy" style="color:blue"\' '
        . 'class="wp-block-group&#32;has-primary-background-color&#32;has-background" class="ignored-duplicate" '
        . 'style=background:red style="background:blue;padding-top:1px">'
        . 'Footer stays.</div><!-- /wp:group -->';

    $out = $unit->finish($raw, template_part_unit_input())->markup;

    assert_contains('<!--keep-note-->', $out);
    assert_contains('data-note=\' class="decoy" style="color:blue"\'', $out);
    assert_contains('Footer stays.', $out);
    assert_contains('"className":"keep"', $out);
    assert_contains('class="wp-block-group has-contrast-background-color has-background"', $out);
    assert_true(!str_contains($out, 'ignored-duplicate'));
    assert_true(!str_contains($out, 'has-primary-background-color'));
    assert_true(!str_contains($out, '&#32;'));
    assert_true(!str_contains($out, ' style=background:red'));
    assert_contains('style="padding-top:1px"', $out);
    assert_true(!str_contains($out, 'background:blue'));
    assert_true(!str_contains($out, '"backgroundColor":"primary"'));
    assert_contains('"backgroundColor":"contrast"', $out);
});

test('FooterUnit disables dynamic site-title self-links only for one-page footers', function () {
    $unit = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $raw = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:site-title /-->'
        . '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title {"isLink":true} /--></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $single = $unit->finish($raw, template_part_unit_input())->markup;
    assert_eq(2, substr_count($single, '"isLink":false'), 'every one-page footer identity is non-linked');
    assert_true(!str_contains($single, '"isLink":true'));

    $multi = $unit->finish(
        $raw,
        array_merge(template_part_unit_input(), ['page_count' => 2])
    )->markup;
    assert_true(!str_contains($multi, '"isLink":false'));
    assert_contains('"isLink":true', $multi, 'an authored multi-page home link is preserved');
});

test('template-part units reject an unrepaired matching landmark for fallback delivery', function () {
    $footer = new FooterUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));
    $malformed = '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="wp-block-group">Broken</div><!-- /wp:group -->';

    assert_throws(
        fn () => $footer->finish($malformed, template_part_unit_input()),
        'unrepaired nested footer landmark'
    );
});

test('GeneratedMarkup removes every matching landmark and reaches a fixed point', function () {
    $markup = '<!-- wp:group {"tagName":"footer","align":"full","layout":{"type":"flex"}} -->'
        . '<footer class="wp-block-group" data-label="keep > this">'
        . '<!-- wp:paragraph --><p>Keep every child byte.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"tagName":"footer"} --><footer class="nested">Nested</footer><!-- /wp:group -->'
        . '</footer><!-- /wp:group -->';
    $expected = '<!-- wp:group {"align":"full","layout":{"type":"flex"}} -->'
        . '<div class="wp-block-group" data-label="keep > this">'
        . '<!-- wp:paragraph --><p>Keep every child byte.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group --><div class="nested">Nested</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $once = GeneratedMarkup::withoutRedundantLandmark($markup, 'footer');
    assert_eq($expected, $once);
    assert_eq($once, GeneratedMarkup::withoutRedundantLandmark($once, 'footer'));
});

test('GeneratedMarkup normalizes a nested-only landmark under a default div root', function () {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Keep.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"tagName":"footer","className":"nested"} -->'
        . '<footer class="wp-block-group nested">Nested</footer><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $expected = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Keep.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"className":"nested"} -->'
        . '<div class="wp-block-group nested">Nested</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $once = GeneratedMarkup::withoutRedundantLandmark($markup, 'footer');
    assert_eq($expected, $once);
    assert_eq($once, GeneratedMarkup::withoutRedundantLandmark($once, 'footer'));
});

test('GeneratedMarkup leaves mismatched landmark wrapper pairs byte-identical', function () {
    $landmarkToDiv = '<!-- wp:group {"tagName":"footer","className":"keep"} -->'
        . '<footer class="wp-block-group keep">Keep</div><!-- /wp:group -->';
    $divToLandmark = '<!-- wp:group {"tagName":"footer","className":"keep"} -->'
        . '<div class="wp-block-group keep">Keep</footer><!-- /wp:group -->';

    assert_eq(
        $landmarkToDiv,
        GeneratedMarkup::withoutRedundantLandmark($landmarkToDiv, 'footer')
    );
    assert_eq(
        $divToLandmark,
        GeneratedMarkup::withoutRedundantLandmark($divToLandmark, 'footer')
    );
});

test('GeneratedMarkup leaves nonmatching and unknown landmark contexts unchanged', function () {
    $mismatch = '<!-- wp:group {"tagName":"aside"} -->'
        . '<aside class="wp-block-group">Keep</aside><!-- /wp:group -->';
    $nonGroup = '<!-- wp:cover {"tagName":"footer"} -->'
        . '<div class="wp-block-cover">Keep</div><!-- /wp:cover -->';
    $footer = '<!-- wp:group {"tagName":"footer"} -->'
        . '<footer class="wp-block-group">Keep</footer><!-- /wp:group -->';

    assert_eq($mismatch, GeneratedMarkup::withoutRedundantLandmark($mismatch, 'footer'));
    assert_eq($nonGroup, GeneratedMarkup::withoutRedundantLandmark($nonGroup, 'footer'));
    assert_eq($footer, GeneratedMarkup::withoutRedundantLandmark($footer, 'main'));
});

test('GeneratedMarkup removes a matching tagName when generated HTML already uses div', function () {
    $markup = '<!-- wp:group {"tagName":"header","className":"keep"} -->'
        . '<div class="wp-block-group keep">Header</div><!-- /wp:group -->';

    $out = GeneratedMarkup::withoutRedundantLandmark($markup, 'header');

    assert_contains('"className":"keep"', $out);
    assert_true(!str_contains($out, '"tagName"'));
    assert_contains('<div class="wp-block-group keep">Header</div>', $out);
});
