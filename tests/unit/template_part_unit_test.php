<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;

/** @return array<string,string> */
function template_part_unit_input(): array
{
    return [
        'site_spec'        => '{"name":"PART-SPEC-SENTINEL"}',
        'language'         => 'part-language-sentinel',
        'theme_json'       => '{"part-theme-sentinel":true}',
        'design_direction' => 'PART-DIRECTION-SENTINEL',
        'outline'          => '1. PART-OUTLINE-SENTINEL (hero)',
        'site_pages'       => '- "Home" — / (front page): PART-PAGES-SENTINEL',
    ];
}

test('HeaderUnit generates a constrained header from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->');
    $unit = new HeaderUnit($llm, new PromptRenderer(repo_path('prompts')));

    $markup = $unit->generate(template_part_unit_input() + [
        'hero_brief' => 'PART-HERO-SENTINEL',
        'nav_rule'   => '- PART-NAV-SENTINEL',
        'archetype_assignment' => 'PART-ARCHETYPE-SENTINEL',
    ]);

    $prompt = $llm->calls[0]['prompt'];
    foreach (['PART-SPEC-SENTINEL', 'part-language-sentinel', 'part-theme-sentinel', 'PART-DIRECTION-SENTINEL', 'PART-OUTLINE-SENTINEL', 'PART-HERO-SENTINEL', 'PART-PAGES-SENTINEL', 'PART-NAV-SENTINEL', 'PART-ARCHETYPE-SENTINEL'] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_eq('header', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_contains('"layout":{"type":"constrained"}', $markup);
});

test('FooterUnit generates a constrained footer from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText('<!-- wp:group {"tagName":"footer"} --><div class="wp-block-group"></div><!-- /wp:group -->');
    $unit = new FooterUnit($llm, new PromptRenderer(repo_path('prompts')));

    $markup = $unit->generate(template_part_unit_input());

    $prompt = $llm->calls[0]['prompt'];
    foreach (['PART-SPEC-SENTINEL', 'part-language-sentinel', 'part-theme-sentinel', 'PART-DIRECTION-SENTINEL', 'PART-OUTLINE-SENTINEL', 'PART-PAGES-SENTINEL'] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }
    assert_eq('footer', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_contains('"tagName":"footer"', $markup, 'existing attributes preserved');
    assert_contains('"layout":{"type":"constrained"}', $markup);
});

test('FooterUnit surfaces precise preset-reference errors', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|missing"}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
    );
    $unit = new FooterUnit($llm, new PromptRenderer(repo_path('prompts')));
    $error = null;

    try {
        $unit->generate(template_part_unit_input());
    } catch (RuntimeException $caught) {
        $error = $caught;
    }

    assert_true($error instanceof RuntimeException);
    assert_contains("part 'footer' failed semantic validation", $error->getMessage());
    assert_contains('preset spacing slug "missing" is not declared', $error->getMessage());
});
