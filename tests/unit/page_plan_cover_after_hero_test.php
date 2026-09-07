<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PagePlanStep;

function cover_after_hero_page(bool $front, array $rows): array
{
    $sections = [];
    foreach ($rows as [$archetype, $background]) {
        $sections[] = [
            'slug' => 'section-' . count($sections),
            'layout_archetype' => $archetype,
            'background' => $background,
            'handoff' => 'Above: the previous band. Below: the next band.',
        ];
    }
    return ['slug' => $front ? 'home' : 'about', 'front' => $front, 'sections' => $sections];
}

test('a cover band planned right after the front hero becomes a level row on the base surface (frm PR-3t)', function () {
    $warnings = [];
    $pages = PagePlanStep::withCoverOffTheSlotAfterHero(
        [cover_after_hero_page(true, [['asymmetric-split', 'base'], ['full-bleed-cover', 'image'], ['feature-row-hairlines', 'base']])],
        false,
        $warnings
    );
    $slot = $pages[0]['sections'][1];
    assert_eq('list-with-thumbnails', $slot['layout_archetype'], 'a guessed layout never assumes the cover content fits a centered stack');
    assert_eq('base', $slot['background']);
    assert_contains('Build correction: this section is now a "list-with-thumbnails" on the "base" surface, not a cover band', $slot['handoff']);
    assert_eq('asymmetric-split', $pages[0]['sections'][0]['layout_archetype'], 'the hero is untouched');
    assert_eq(2, count($warnings));
    assert_contains("[slug='home'].sections[1].layout_archetype", $warnings[0]);
    assert_contains('opens the page on two stages', $warnings[0]);
    assert_contains("[slug='home'].sections[1].background", $warnings[1]);
});

test('the replacement row clears both neighbours and never lands on a no-image archetype (frm PR-3t)', function () {
    $warnings = [];
    $pages = PagePlanStep::withCoverOffTheSlotAfterHero(
        [cover_after_hero_page(true, [['centered-stack', 'contrast'], ['full-bleed-cover', 'image'], ['asymmetric-split', 'base']])],
        false,
        $warnings
    );
    $picked = $pages[0]['sections'][1]['layout_archetype'];
    assert_true(!in_array($picked, ['full-bleed-cover', 'centered-stack', 'asymmetric-split', 'statement-lines', 'feature-row-hairlines', 'stat-ledger', 'faq-split'], true), $picked);
});

test('a stated cover band, an interior page and a cover further down are left as planned (frm PR-3t)', function () {
    $stated = [cover_after_hero_page(true, [['asymmetric-split', 'base'], ['full-bleed-cover', 'image']])];
    $warnings = [];
    assert_eq($stated, PagePlanStep::withCoverOffTheSlotAfterHero($stated, true, $warnings), 'the brief asked for it');
    $interior = [cover_after_hero_page(false, [['centered-stack', 'base'], ['full-bleed-cover', 'image']])];
    assert_eq($interior, PagePlanStep::withCoverOffTheSlotAfterHero($interior, false, $warnings), 'interior pages have their own opening rule');
    $later = [cover_after_hero_page(true, [['asymmetric-split', 'base'], ['centered-stack', 'base'], ['full-bleed-cover', 'image']])];
    assert_eq($later, PagePlanStep::withCoverOffTheSlotAfterHero($later, false, $warnings), 'a cover further down is page rhythm');
    assert_eq([], $warnings);
});

test('a cover band the brief states is read as a bounded phrase outside the hero clause (frm PR-3t)', function () {
    assert_true(PagePlanStep::statedCoverBand('a light hero, then a full-bleed cover with a quote'));
    assert_true(PagePlanStep::statedCoverBand('a cover band with the studio photo below the intro'));
    assert_true(!PagePlanStep::statedCoverBand('Dark hero with a full-bleed high-contrast portrait, a 2x2 full-bleed project grid'), 'the hero clause and a grid are not a cover band');
    assert_true(!PagePlanStep::statedCoverBand('Create a website for a Georgian restaurant.'));
    assert_true(PagePlanStep::statedCoverBandFor(['original_prompt' => 'hero, then a photo band', 'prompt' => 'a portfolio']));
    assert_true(!PagePlanStep::statedCoverBandFor([]));
});
