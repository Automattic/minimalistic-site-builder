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

test('the ledger ration applies only to the rule-row commitment', function (): void {
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
    assert_eq(['spec-table', 'spec-table', 'spec-table'], array_column($delivered[0]['sections'], 'item_pattern'));

    $repairs = [];
    $cards = PagePlanStep::reconcileItemPatternAssignments($pages, 'card', $repairs);
    assert_eq(['card', 'card', 'card'], array_column($cards[0]['sections'], 'item_pattern'));
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
