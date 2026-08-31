<?php
declare(strict_types=1);

use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\PagePlanStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\SectionUnit;

test('item-pattern catalog is bounded and owns one recipe per value', function (): void {
    assert_eq(['card', 'rule-row', 'spec-table', 'tag-cluster'], ItemPattern::ALL);
    // 'index' left the catalog with BIGR-949: an identifier column of
    // sequence numbers is banned unless the site brief asks for it.
    assert_true(!ItemPattern::isKnown('index'));
    assert_eq(null, ItemPattern::explicit('index'));
    foreach (ItemPattern::ALL as $pattern) {
        assert_true(ItemPattern::isKnown($pattern));
        assert_contains("item-patterns/{$pattern}.md", ItemPattern::recipeTemplate($pattern));
        assert_eq("item-pattern--{$pattern}", ItemPattern::marker($pattern));
    }
});

test('item-pattern direction normalization is bounded, warned, and rendered', function (): void {
    $warnings = [];
    $repairs = [];
    $direction = DesignDirectionStep::normalize(
        ['description' => 'A precise archive.', 'item_pattern' => ' SPEC-TABLE '],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq('spec-table', $direction['item_pattern']);
    assert_eq([], array_values(array_filter(
        $warnings,
        static fn (string $warning): bool => str_contains($warning, 'item_pattern'),
    )));
    assert_contains('**Measure**: standard', DesignDirectionStep::format($direction));
    assert_contains('**Item pattern**: spec-table', DesignDirectionStep::format($direction));
    assert_contains('label/value pairs', DesignDirectionStep::format($direction));

    $warnings = [];
    $repairs = [];
    $invalid = DesignDirectionStep::normalize(
        ['description' => 'A precise archive.', 'item_pattern' => ['tiles']],
        'cinematic-safe-zone',
        '',
        $repairs,
        $warnings,
    );
    assert_eq(ItemPattern::DEFAULT, $invalid['item_pattern']);
    $itemWarnings = array_values(array_filter(
        $warnings,
        static fn (string $warning): bool => str_contains($warning, 'item_pattern'),
    ));
    assert_eq(1, count($itemWarnings));
    assert_contains('field item_pattern', $itemWarnings[0]);
    assert_contains('authored ', $itemWarnings[0]);
    assert_contains('delivered ', $itemWarnings[0]);
    assert_contains('disposition ', $itemWarnings[0]);
});

test('page plan restores the committed pattern and assigns obvious list types only', function (): void {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero', 'type' => 'hero', 'item_pattern' => null],
            ['slug' => 'menu', 'type' => 'seasonal-menu', 'item_pattern' => null],
            ['slug' => 'workshops', 'type' => 'workshops', 'item_pattern' => 'card'],
            ['slug' => 'cases', 'type' => 'case-studies', 'item_pattern' => null],
            ['slug' => 'story', 'type' => 'story'],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'rule-row', $repairs);

    assert_eq([null, 'rule-row', 'rule-row', 'rule-row', null], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq(3, count($repairs));
    assert_contains("sections[1].item_pattern", $repairs[0]);
    assert_contains("sections[2].item_pattern", $repairs[1]);

    $fixedPointRepairs = [];
    assert_eq(
        $delivered,
        PagePlanStep::reconcileItemPatternAssignments($delivered, 'rule-row', $fixedPointRepairs),
    );
    assert_eq([], $fixedPointRepairs);
});

test('quote-led sections never keep a tabular idiom', function (): void {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'voices', 'type' => 'testimonials', 'item_pattern' => 'spec-table'],
            ['slug' => 'reviews', 'type' => 'customer-reviews', 'item_pattern' => null],
            ['slug' => 'menu', 'type' => 'menu', 'item_pattern' => null],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'spec-table', $repairs);

    assert_eq([null, null, 'spec-table'], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq(2, count($repairs));
    assert_contains('sections[0].item_pattern', $repairs[0]);
    assert_contains('quote-led', $repairs[0]);
    assert_contains('sections[2].item_pattern', $repairs[1]);

    $fixedPointRepairs = [];
    assert_eq(
        $delivered,
        PagePlanStep::reconcileItemPatternAssignments($delivered, 'spec-table', $fixedPointRepairs),
    );
    assert_eq([], $fixedPointRepairs);
});

test('a card commitment may dress a testimonial but is never forced onto one', function (): void {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'voices', 'type' => 'testimonials', 'item_pattern' => 'card'],
            ['slug' => 'praise', 'type' => 'testimonials', 'item_pattern' => null],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'card', $repairs);

    assert_eq(['card', null], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq([], $repairs);
});

test('an unknown authored value on a quote-led section is released with a repair line', function (): void {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'voices', 'type' => 'testimonials', 'item_pattern' => 'ledger'],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'spec-table', $repairs);

    assert_eq([null], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq(1, count($repairs));
    assert_contains('sections[0].item_pattern', $repairs[0]);
    assert_contains('quote-led', $repairs[0]);
    assert_contains('ledger', $repairs[0]);
});

test('a compound type with both a quote-led and a list-like token takes the quote-led path', function (): void {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'reviews', 'type' => 'reviews-index', 'item_pattern' => 'spec-table'],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'spec-table', $repairs);

    assert_eq([null], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq(1, count($repairs));
    assert_contains('sections[0].item_pattern', $repairs[0]);
    assert_contains('quote-led', $repairs[0]);
    assert_contains("'reviews-index'", $repairs[0]);
});

test('section request sees exactly the assigned item recipe', function (): void {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $unit = new SectionUnit(new FakeLlm(), $renderer);
    $input = item_pattern_unit_input('rule-row');
    $request = $unit->request($input);
    $prompt = implode("\n", $request['cached_prefixes']) . "\n" . $request['prompt'];

    assert_contains('ASSIGNED ITEM PATTERN', $prompt);
    assert_contains('### rule-row', $prompt);
    assert_contains('item-pattern--rule-row', $prompt);
    foreach (array_diff(ItemPattern::ALL, ['rule-row']) as $other) {
        assert_true(!str_contains($prompt, "### {$other}"), "the {$other} recipe stays out of the request");
    }
});

test('item-pattern delivery repairs only the root marker and advises on missing repeated hooks', function (): void {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $unit = new SectionUnit(new FakeLlm(), $renderer);
    $input = item_pattern_unit_input('spec-table');
    $raw = '<!-- wp:group {"className":"section-composition--centered-stack"} -->'
        . '<div class="section-composition--centered-stack">'
        . '<!-- wp:group {"className":"item-pattern__item"} --><div class="item-pattern__item">'
        . '<!-- wp:paragraph --><p>Material</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"item-pattern__item"} --><div class="item-pattern__item">'
        . '<!-- wp:paragraph --><p>Weight</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $result = $unit->finish($raw, $input);
    assert_contains('item-pattern--spec-table', $result->markup);
    assert_eq([], array_values(array_filter(
        $result->warnings,
        static fn (string $warning): bool => str_contains($warning, 'item-pattern'),
    )));

    $missingHooks = str_replace('item-pattern__item', 'plain-item', $raw);
    $warned = $unit->finish($missingHooks, $input);
    assert_contains('repeated-item recipe', implode("\n", $warned->warnings));
    assert_contains('minimum', implode("\n", $warned->warnings));
    assert_contains('disposition=', implode("\n", $warned->warnings));
});

/** @return array<string,mixed> */
function item_pattern_unit_input(?string $pattern): array
{
    return [
        'site_spec' => '{"name":"Demo","language":"en"}',
        'language' => 'en',
        'theme_json' => '{"version":3}',
        'design_direction' => 'An archival system.',
        'card_style' => 'flush',
        'outline' => '- Archive [#archive]',
        'site_pages' => '- "Home" — / (front page): Welcome',
        'page' => ['slug' => 'home', 'title' => 'Home', 'path' => '/'],
        'section' => [
            'slug' => 'archive',
            'title' => 'Archive',
            'role' => 'content',
            'type' => 'archive',
            'purpose' => 'Help readers scan the archive.',
            'content_notes' => 'Three real entries.',
            'layout_archetype' => 'centered-stack',
            'background' => 'base',
            'vertical_density' => 'compact',
            'text_placement' => 'centered',
            'item_pattern' => $pattern,
            'handoff' => 'Sits between the hero and closing band.',
        ],
        'neighbors' => '- Above: hero\n- Below: closing',
        'header_contract' => '',
    ];
}
