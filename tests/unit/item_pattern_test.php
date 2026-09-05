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

test('a section preserves separators with or without an assigned ruled recipe', function (): void {
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
    assert_contains('wp:separator', $plain->markup, 'a free section may use a deliberate divider');
    assert_contains('Archive', $plain->markup);
    assert_contains('Material', $plain->markup);
    $separatorWarnings = array_values(array_filter(
        $plain->warnings,
        static fn (string $warning): bool => str_contains($warning, 'separator'),
    ));
    assert_eq([], $separatorWarnings);

    $ledger = $unit->finish($raw, item_pattern_unit_input('rule-row'));
    assert_contains('wp:separator', $ledger->markup, 'the ruled recipe owns its rules');
    assert_eq([], array_values(array_filter(
        $ledger->warnings,
        static fn (string $warning): bool => str_contains($warning, 'rations lines'),
    )));

    $table = $unit->finish($raw, item_pattern_unit_input('spec-table'));
    assert_contains('wp:separator', $table->markup, 'the spec table owns its rules too');
});

test('a section keeps authored rule styling independently of its item recipe', function (): void {
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
    assert_contains('is-style-rule-row', $card->markup);
    assert_contains('is-style-rule-list', $card->markup);
    assert_contains('item-pattern__item rule-rows hover-lift', $card->markup, 'authored tokens survive in order');
    assert_contains('item-pattern--card', $card->markup, 'the assigned marker is still applied');
    assert_contains('Clock in', $card->markup);
    $ruleRepairs = array_values(array_filter(
        $card->repairs,
        static fn (array $repair): bool => ($repair['code'] ?? '') === 'rule-class-removed',
    ));
    assert_eq([], $ruleRepairs);

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
