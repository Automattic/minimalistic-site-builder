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
        'card_style'       => 'flush',
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
        'header_contract' => 'UNIT-HEADER-CONTRACT-SENTINEL',
    ];
}

/** Reconstruct the logical prompt text from a layered LLM request. */
function section_unit_request_text(array $request): string
{
    return implode('', $request['cached_prefixes'] ?? []) . $request['prompt'];
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

    $result = $unit->generate(section_unit_input());
    $markup = $result->markup;

    assert_eq(1, $llm->completeCalls, 'one direct unit execution uses one single completion');
    assert_eq(0, $llm->completeBatchCalls, 'the single-unit wrapper does not create a batch');
    assert_eq('unit-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(0.42, $llm->calls[0]['opts']['temperature'] ?? null);
    assert_eq('page-unit-page--unit-section', $llm->calls[0]['opts']['log_label'] ?? null);

    $sent = $llm->calls[0]['opts'] + ['prompt' => $llm->calls[0]['prompt']];
    assert_eq(2, count($sent['cached_prefixes'] ?? []), 'direct execution forwards both cache layers');
    $prompt = section_unit_request_text($sent);
    assert_contains('Role:     hero', $prompt);
    assert_contains('ASSIGNED CARD STYLE (authoritative machine contract): flush', $prompt);
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
    assert_eq([], $result->warnings, 'semantics-preserving normalization is not a durable warning');
    assert_eq(
        ['response-fence-removed', 'preset-reference-canonicalized'],
        array_column($result->repairs, 'code')
    );
    assert_eq($result->toArray(), json_decode((string) json_encode($result), true));
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

    $prompt = section_unit_request_text($request);
    assert_contains('UNIT-TITLE-SENTINEL', $prompt);
    assert_contains('DECODED-SPEC-SENTINEL', $prompt);
    assert_contains('decoded-theme-sentinel', $prompt);
    assert_eq(0, $llm->completeCalls);
    assert_eq(0, $llm->completeBatchCalls);
});

test('SectionUnit documents the nested flush-card body contract', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_unit_input());
    $prompt = section_unit_request_text($request);

    assert_contains(
        'ONE inner `wp:group` with `"className":"card-body"`',
        $prompt,
        'flush cards give their padded text group the stable flex-body hook',
    );
    assert_contains(
        '`"className":"card-body overlap-up"`',
        $prompt,
        'overlap cards retain both the flex-body and overlap hooks',
    );
    assert_contains(
        'a nested `cta-bottom` can align with sibling cards',
        $prompt,
        'the placement requirement explains why the body hook is structural',
    );
    foreach (['flush', 'framed', 'overlap', 'borderless'] as $treatment) {
        assert_contains(
            '`card-style--' . $treatment . '`',
            $prompt,
            "the {$treatment} construction has one universal treatment marker",
        );
    }
    assert_contains(
        '`"className":"card-style--overlap card-flush"`',
        $prompt,
        'overlap cards carry the universal marker and flush behavior hook together',
    );
    assert_contains(
        'ONE uniform literal pixel value for all four padding sides',
        $prompt,
        'framed geometry remains deterministic enough for the delivery contract to verify',
    );
});

test('SectionUnit gives standalone requests the authoritative machine card style', function () {
    $input = section_unit_input();
    $input['design_direction'] = 'Direction prose with no card-treatment commitment.';
    $input['card_style'] = 'framed';

    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request($input);
    $prompt = section_unit_request_text($request);

    assert_contains('ASSIGNED CARD STYLE (authoritative machine contract): framed', $prompt);
    assert_contains(
        'overrides absent or conflicting prose in the DESIGN DIRECTION',
        $prompt,
    );
    assert_true(
        !str_contains($prompt, 'when the direction carries no card-treatment line, default to `flush`'),
        'standalone non-flush input is not contradicted by a prose-derived default',
    );
    assert_contains(
        'ASSIGNED CARD STYLE (authoritative machine contract): framed',
        $request['cached_prefixes'][0] ?? '',
        'the site-wide machine assignment remains in the stable build cache layer',
    );
});

test('SectionUnit keeps front-page hero topology out of the general prompt', function () {
    $input = section_unit_input();
    $input['outline'] = '1. UNIT-OUTLINE-SENTINEL (content)';
    $input['section']['role'] = 'content';
    $input['section']['type'] = 'feature';
    $input['hero_blueprint'] = [
        'recipe' => 'typographic-poster',
        'signature_device_use' => 'SECTION-HERO-BLUEPRINT-LEAK-SENTINEL',
    ];
    $input['above_fold_contract'] = 'SECTION-ABOVE-FOLD-LEAK-SENTINEL';

    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request($input);
    $prompt = section_unit_request_text($request);

    foreach ([
        'SECTION-HERO-BLUEPRINT-LEAK-SENTINEL',
        'SECTION-ABOVE-FOLD-LEAK-SENTINEL',
        'ASSIGNED HERO COMPOSITION',
        'NORMALIZED HERO BLUEPRINT',
        'hero-composition--',
        'Hero notes',
        'hero-entrance',
    ] as $heroOnly) {
        assert_true(!str_contains($prompt, $heroOnly), "general section prompt excludes {$heroOnly}");
    }
    assert_contains('UNIT-HEADER-CONTRACT-SENTINEL', $prompt, 'recipe-free opening relation remains supported');
});

test('SectionUnit layered request loses only cache marker separators', function () {
    $input = section_unit_input();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $request = (new SectionUnit(new FakeLlm(), $renderer))->request($input);

    $composition = $renderer->render('section-composition.md', [
        'layout_archetype' => $input['section']['layout_archetype'],
        'background'       => $input['section']['background'],
        'vertical_density' => $input['section']['vertical_density'],
        'handoff'          => $input['section']['handoff'],
        'neighbors'        => $input['neighbors'],
    ]);
    $rendered = $renderer->render('section.md', [
        'site_spec'         => $input['site_spec'],
        'language'          => $input['language'],
        'theme_json'        => $input['theme_json'],
        'design_direction'  => $input['design_direction'],
        'card_style'        => $input['card_style'],
        'outline'           => $input['outline'],
        'site_pages'        => $input['site_pages'],
        'page_title'        => $input['page']['title'],
        'page_path'         => $input['page']['path'],
        'section_title'     => $input['section']['title'],
        'section_slug'      => $input['section']['slug'],
        'section_role'      => $input['section']['role'],
        'section_type'      => $input['section']['type'],
        'section_purpose'   => $input['section']['purpose'],
        'content_notes'     => $input['section']['content_notes'],
        'composition'       => $composition,
        'header_contract'   => $input['header_contract'],
        'image_instructions' => $renderer->render('image-generation.md', []),
        'block_markup_output_contract' => rtrim(
            $renderer->render('block-markup-output-contract.md', []),
            "\r\n",
        ),
    ]);
    $withoutMarkers = rtrim(str_replace([
        "<!-- section-cache-layer:build -->\n",
        "<!-- section-cache-layer:page -->\n",
        "<!-- section-cache-layer:brief -->\n",
    ], '', $rendered), "\r\n");

    assert_eq($withoutMarkers, section_unit_request_text($request));
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
