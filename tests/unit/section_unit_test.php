<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\SectionUnit;

/** @return array<string,mixed> */
function section_unit_input(): array
{
    return [
        'site_spec'        => '{"name":"UNIT-SPEC-SENTINEL"}',
        'language'         => 'unit-language-sentinel',
        'theme_json'       => '{"unit-theme-sentinel":true}',
        'design_direction' => 'UNIT-DIRECTION-SENTINEL',
        'outline'          => '1. UNIT-OUTLINE-SENTINEL (hero)',
        'site_pages'       => '- "Home" — / (front page): UNIT-PAGES-SENTINEL',
        'page'             => [
            'slug'  => 'unit-page',
            'title' => 'UNIT-PAGE-TITLE-SENTINEL',
            'path'  => '/unit-page/',
        ],
        'section'          => [
            'slug'             => 'unit-section',
            'title'            => 'UNIT-TITLE-SENTINEL',
            'role'             => 'hero',
            'type'             => 'hero',
            'purpose'          => 'UNIT-PURPOSE-SENTINEL',
            'content_notes'    => 'UNIT-NOTES-SENTINEL',
            'layout_archetype' => 'full-bleed-cover',
            'background'       => 'image',
            'vertical_density' => 'standard',
            'handoff'          => 'UNIT-HANDOFF-SENTINEL',

        ],
        'neighbors' => 'UNIT-NEIGHBORS-SENTINEL',
    ];
}

test('SectionUnit generates normalized markup from self-contained input', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        "```html\n"
        . '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset--spacing--xl"}}}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->'
        . "\n```"
    );
    $unit = new SectionUnit(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        'unit-model',
        0.42,
    );

    $markup = $unit->generate(section_unit_input());

    assert_eq(1, $llm->completeCalls, 'one direct unit execution uses one single completion');
    assert_eq(0, $llm->completeBatchCalls, 'the single-unit wrapper does not create a batch');
    assert_eq('unit-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.42, $llm->calls[0]['opts']['temperature'] ?? null);
    assert_eq('page-unit-page--unit-section', $llm->calls[0]['opts']['log_label'] ?? null);

    $prompt = $llm->calls[0]['prompt'];
    assert_contains('Role:     hero', $prompt);
    foreach ([
        'UNIT-SPEC-SENTINEL',
        'unit-language-sentinel',
        'unit-theme-sentinel',
        'UNIT-DIRECTION-SENTINEL',
        'UNIT-OUTLINE-SENTINEL',
        'UNIT-TITLE-SENTINEL',
        'UNIT-PURPOSE-SENTINEL',
        'UNIT-NOTES-SENTINEL',
        'UNIT-HANDOFF-SENTINEL',
        'UNIT-NEIGHBORS-SENTINEL',
        'UNIT-PAGES-SENTINEL',
        'UNIT-PAGE-TITLE-SENTINEL',
        'AI_IMAGE:',
    ] as $sentinel) {
        assert_contains($sentinel, $prompt);
    }

    assert_true(!str_contains($markup, '```'), 'code fence removed');
    assert_contains('var:preset|spacing|xl', $markup, 'preset reference normalized');
});

test('SectionUnit rejects a response without block markup', function () {
    $llm = new FakeLlm();
    $llm->queueText('plain text');
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));

    assert_throws(fn () => $unit->generate(section_unit_input()));
});

test('SectionUnit request preparation does not call the LLM', function () {
    $llm = new FakeLlm();
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    $input['site_spec'] = ['name' => 'DECODED-SPEC-SENTINEL'];
    $input['theme_json'] = ['decoded-theme-sentinel' => true];

    $request = $unit->request($input);

    assert_contains('UNIT-TITLE-SENTINEL', $request['prompt']);
    assert_contains('DECODED-SPEC-SENTINEL', $request['prompt']);
    assert_contains('decoded-theme-sentinel', $request['prompt']);
    assert_eq(0, $llm->completeCalls);
    assert_eq(0, $llm->completeBatchCalls);
});

test('SectionUnit rejects malformed nested HTTP input before calling the LLM', function () {
    $llm = new FakeLlm();
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    $input['section']['title'] = ['not', 'text'];

    assert_throws(fn () => $unit->generate($input));
    assert_eq(0, $llm->completeCalls);
});

test('SectionUnit requires an explicit section role before calling the LLM', function () {
    $llm = new FakeLlm();
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
    $input = section_unit_input();
    unset($input['section']['role']);

    $error = null;
    try {
        $unit->generate($input);
    } catch (InvalidArgumentException $e) {
        $error = $e;
    }

    assert_true($error instanceof InvalidArgumentException);
    assert_contains("unit input 'section.role' is required", $error->getMessage());
    assert_eq(0, $llm->completeCalls);
});

test('SectionUnit rejects malformed or unsupported section roles before calling the LLM', function () {
    foreach ([
        'non-string'  => [['hero'], "unit input 'section.role' must be a string"],
        'unsupported' => ['featured', "unit input 'section.role' must be one of: hero, content, closing"],
    ] as $case => [$role, $message]) {
        $llm = new FakeLlm();
        $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));
        $input = section_unit_input();
        $input['section']['role'] = $role;

        $error = null;
        try {
            $unit->generate($input);
        } catch (InvalidArgumentException $e) {
            $error = $e;
        }

        assert_true($error instanceof InvalidArgumentException, "{$case} role should be rejected");
        assert_contains($message, $error->getMessage());
        assert_eq(0, $llm->completeCalls, "{$case} role should fail before calling the LLM");
    }
});
