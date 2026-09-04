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

    // The menu is the page's one tabular list and keeps the ledger. The
    // workshops and the case studies are prose-led, so they take cards
    // (BIGR-978); the authored card on the workshops is already right.
    assert_eq([null, 'rule-row', 'card', 'card', null], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq(2, count($repairs));
    assert_contains("sections[1].item_pattern", $repairs[0]);
    assert_contains("sections[3].item_pattern", $repairs[1]);
    assert_contains('prose-led', $repairs[1]);

    $fixedPointRepairs = [];
    assert_eq(
        $delivered,
        PagePlanStep::reconcileItemPatternAssignments($delivered, 'rule-row', $fixedPointRepairs),
    );
    assert_eq([], $fixedPointRepairs);
});

test('the rule-row ledger is rationed to one tabular section per page (BIGR-978)', function (): void {
    // Audited plans put rule-row on contact, location, hours and specs
    // sections alike, so every page arrived striped with hairlines.
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero', 'type' => 'hero', 'item_pattern' => null],
            ['slug' => 'team', 'type' => 'team', 'item_pattern' => 'rule-row'],
            ['slug' => 'menu', 'type' => 'menu', 'item_pattern' => null],
            ['slug' => 'hours', 'type' => 'hours', 'item_pattern' => 'rule-row'],
            ['slug' => 'specs', 'type' => 'specs', 'item_pattern' => null],
            ['slug' => 'contact', 'type' => 'contact', 'item_pattern' => 'rule-row'],
            ['slug' => 'story', 'type' => 'story'],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'rule-row', $repairs);

    assert_eq(
        [null, 'card', 'rule-row', 'card', 'card', null, null],
        array_column($delivered[0]['sections'], 'item_pattern'),
    );
    assert_eq(5, count($repairs));
    $joined = implode("\n", $repairs);
    assert_contains("sections[1].item_pattern", $joined);
    assert_contains('prose-led', $joined);
    assert_contains("sections[2].item_pattern", $joined);
    assert_contains('one ruled ledger per page: sections[2] keeps the rule-row idiom', $joined);
    assert_contains("sections[5].item_pattern", $joined);
    assert_contains('this type is not a name/value list', $joined);

    $fixedPointRepairs = [];
    assert_eq(
        $delivered,
        PagePlanStep::reconcileItemPatternAssignments($delivered, 'rule-row', $fixedPointRepairs),
    );
    assert_eq([], $fixedPointRepairs);
});

test('a page without a tabular list keeps the ledger on the first authored non-prose section', function (): void {
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'hero', 'type' => 'hero', 'item_pattern' => null],
            ['slug' => 'benefits', 'type' => 'benefits', 'item_pattern' => 'rule-row'],
            ['slug' => 'what', 'type' => 'value-proposition', 'item_pattern' => 'rule-row'],
            ['slug' => 'concept', 'type' => 'concept', 'item_pattern' => 'rule-row'],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'rule-row', $repairs);

    assert_eq([null, 'card', 'rule-row', null], array_column($delivered[0]['sections'], 'item_pattern'));
    assert_eq(2, count($repairs));

    $fixedPointRepairs = [];
    assert_eq(
        $delivered,
        PagePlanStep::reconcileItemPatternAssignments($delivered, 'rule-row', $fixedPointRepairs),
    );
    assert_eq([], $fixedPointRepairs);
});

test('the ledger ration covers spec-table too, and leaves card and tag-cluster alone', function (): void {
    // The seven-demo rebuild for BIGR-978 showed a spec-table commitment
    // filling the gap the rule-row cap had closed (lumen: two ruled sections).
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'menu', 'type' => 'menu', 'item_pattern' => null],
            ['slug' => 'specs', 'type' => 'specifications', 'item_pattern' => null],
            ['slug' => 'team', 'type' => 'team', 'item_pattern' => null],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'spec-table', $repairs);
    assert_eq(['spec-table', 'card', 'card'], array_column($delivered[0]['sections'], 'item_pattern'));
    $joined = implode("\n", $repairs);
    assert_contains('sections[0] keeps the spec-table idiom', $joined);
    assert_contains("released prose-led section type 'team' from the spec-table ledger", $joined);

    $repairs = [];
    $cards = PagePlanStep::reconcileItemPatternAssignments($pages, 'card', $repairs);
    assert_eq(['card', 'card', 'card'], array_column($cards[0]['sections'], 'item_pattern'));

    $repairs = [];
    $tags = PagePlanStep::reconcileItemPatternAssignments($pages, 'tag-cluster', $repairs);
    assert_eq(['tag-cluster', 'tag-cluster', 'tag-cluster'], array_column($tags[0]['sections'], 'item_pattern'));
});

test('a repaired item pattern corrects the planner notes the section author reads (BIGR-978)', function (): void {
    // The planner writes notes and item_pattern together, so "a rule-row list
    // with four steps" outlives the repair that released the section to card.
    $pages = [[
        'slug' => 'home',
        'sections' => [
            ['slug' => 'menu', 'type' => 'menu', 'item_pattern' => 'rule-row', 'content_notes' => 'Six dishes as a ledger.'],
            ['slug' => 'steps', 'type' => 'process', 'item_pattern' => 'rule-row', 'content_notes' => 'A rule-row list with four steps.'],
            ['slug' => 'contact', 'type' => 'contact', 'item_pattern' => 'rule-row', 'content_notes' => 'Hours and address as rows.'],
            ['slug' => 'hours', 'type' => 'hours', 'item_pattern' => null, 'content_notes' => 'Opening hours.'],
        ],
    ]];
    $repairs = [];
    $delivered = PagePlanStep::reconcileItemPatternAssignments($pages, 'rule-row', $repairs);
    $sections = $delivered[0]['sections'];
    assert_eq(['rule-row', 'card', null, 'card'], array_column($sections, 'item_pattern'));
    assert_eq('Six dishes as a ledger.', $sections[0]['content_notes'], 'the keeper is untouched');
    assert_eq(
        'A rule-row list with four steps. Build correction: this section\'s item pattern is now "card" '
        . '(the planner authored "rule-row"); follow the assigned card recipe and draw no rule-row rows, '
        . 'hairlines, separators, or ruled block styles.',
        $sections[1]['content_notes'],
    );
    assert_contains('has no assigned item pattern (the planner authored "rule-row"); compose it freely', $sections[2]['content_notes']);
    assert_eq('Opening hours.', $sections[3]['content_notes'], 'an assignment the planner never made needs no correction');

    $again = [];
    assert_eq($delivered, PagePlanStep::reconcileItemPatternAssignments($delivered, 'rule-row', $again));
    assert_eq([], $again);
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

test('a section without a ruled recipe loses its separators; a rule-row section keeps them (BIGR-978)', function (): void {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $unit = new SectionUnit(new FakeLlm(), $renderer);
    $raw = '<!-- wp:group {"className":"section-composition--centered-stack"} -->'
        . '<div class="section-composition--centered-stack">'
        . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Archive</h2><!-- /wp:heading -->'
        . '<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator is-style-wide"/>'
        . '<!-- /wp:separator -->'
        . '<!-- wp:group {"className":"item-pattern__item"} --><div class="item-pattern__item">'
        . '<!-- wp:paragraph --><p>Material</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"item-pattern__item"} --><div class="item-pattern__item">'
        . '<!-- wp:paragraph --><p>Weight</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $plain = $unit->finish($raw, item_pattern_unit_input(null));
    assert_true(!str_contains($plain->markup, 'wp:separator'), 'the free section loses the rule under its heading');
    assert_contains('Archive', $plain->markup);
    assert_contains('Material', $plain->markup);
    $separatorWarnings = array_values(array_filter(
        $plain->warnings,
        static fn (string $warning): bool => str_contains($warning, 'separator'),
    ));
    assert_eq(1, count($separatorWarnings));
    assert_contains('prompts/section.md rations lines', $separatorWarnings[0]);
    assert_contains('delivered=removed', $separatorWarnings[0]);

    $ledger = $unit->finish($raw, item_pattern_unit_input('rule-row'));
    assert_contains('wp:separator', $ledger->markup, 'the ruled recipe owns its rules');
    assert_eq([], array_values(array_filter(
        $ledger->warnings,
        static fn (string $warning): bool => str_contains($warning, 'rations lines'),
    )));

    $table = $unit->finish($raw, item_pattern_unit_input('spec-table'));
    assert_contains('wp:separator', $table->markup, 'the spec table owns its rules too');
});

test('a section without a ruled recipe loses model-invented rule classes; a ledger keeps them (BIGR-978)', function (): void {
    // The atlas rebuild shipped `is-style-rule-row` on every item of a section
    // planned as card, bound to a border in the model's own theme.json css.
    $renderer = new PromptRenderer(repo_path('prompts'));
    $unit = new SectionUnit(new FakeLlm(), $renderer);
    $raw = '<!-- wp:group {"className":"section-composition--centered-stack is-style-rule-list"} -->'
        . '<div class="wp-block-group section-composition--centered-stack is-style-rule-list">'
        . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Steps</h2><!-- /wp:heading -->'
        . '<!-- wp:group {"className":"item-pattern__item is-style-rule-row"} -->'
        . '<div class="wp-block-group item-pattern__item is-style-rule-row">'
        . '<!-- wp:paragraph --><p>Clock in</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"item-pattern__item rule-rows hover-lift"} -->'
        . '<div class="wp-block-group item-pattern__item rule-rows hover-lift">'
        . '<!-- wp:paragraph --><p>Open the job</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';

    $card = $unit->finish($raw, item_pattern_unit_input('card'));
    assert_true(!str_contains($card->markup, 'is-style-rule-row'), 'the item rule style is gone');
    assert_true(!str_contains($card->markup, 'is-style-rule-list'), 'the root rule style is gone');
    assert_true(!str_contains($card->markup, 'rule-rows'), 'the bare rule token is gone');
    assert_contains('item-pattern__item hover-lift', $card->markup, 'other tokens survive in order');
    assert_contains('item-pattern--card', $card->markup, 'the assigned marker is still applied');
    assert_contains('Clock in', $card->markup);
    $ruleRepairs = array_values(array_filter(
        $card->repairs,
        static fn (array $repair): bool => ($repair['code'] ?? '') === 'rule-class-removed',
    ));
    assert_eq(3, count($ruleRepairs));
    assert_eq('is-style-rule-row', $ruleRepairs[1]['authored']);

    $ledger = $unit->finish($raw, item_pattern_unit_input('rule-row'));
    assert_contains('is-style-rule-row', $ledger->markup, 'the ruled recipe keeps its own classes');
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
