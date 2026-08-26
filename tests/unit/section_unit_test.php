<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionComposition;
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

test('section prompt keeps dash-free headings semantically lossless', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('no em or en dashes', $prompt, 'all section heading levels reject dash-joined labels');
    assert_contains('preserve both endpoints', $prompt, 'semantic ranges cannot lose a bound');
    assert_contains('From 2004 to 2024', $prompt, 'ranges have a dash-free heading form');
    assert_contains('move the intact range into supporting copy', $prompt, 'ranges may move without losing meaning');
});

test('section prompt keeps unbreakable contact tokens out of display type', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('email addresses, long URLs', $prompt, 'the affected token shapes are named');
    assert_contains('never take a display or heading scale', $prompt, 'large-type prohibition covers paragraphs and headings');
    assert_contains('`lead`-at-most mailto link or a button labeled with words', $prompt, 'safe contact treatments remain available');
    assert_true(!str_contains($prompt, 'inquiries@alcortaph'), 'malformed cohort identity copy is excluded');
});

test('centered-stack prompts keep wrapping copy aligned to the writing-direction start', function () {
    $section = (string) file_get_contents(repo_path('prompts/section.md'));
    $composition = (string) file_get_contents(
        repo_path('prompts/section-compositions/centered-stack.md')
    );
    $pagePlan = (string) file_get_contents(repo_path('prompts/page-plan.md'));

    assert_contains('center display type only', $section, 'short display lines may remain centered');
    assert_contains('writing direction\'s start edge', $section, 'reading copy follows language direction');
    assert_contains('`"align":"left"` for LTR', $section, 'LTR Gutenberg mapping is explicit');
    assert_contains('`"align":"right"` for RTL', $section, 'RTL Gutenberg mapping is explicit');
    foreach ([$composition, $pagePlan] as $centeredStackContract) {
        assert_contains('start-aligned', $centeredStackContract);
        assert_contains('left for LTR', $centeredStackContract);
        assert_contains('right for RTL', $centeredStackContract);
    }
});

test('section prompt balances media rows without padding or detaching copy', function () {
    $prompt = (string) file_get_contents(repo_path('prompts/section.md'));

    assert_contains('at the desktop side-by-side state', $prompt, 'the blank-quadrant rule targets the affected layout');
    assert_contains('NEVER add, repeat, or invent copy', $prompt, 'content cannot be padded to satisfy geometry');
    assert_contains('smallest composition-only change', $prompt, 'repairs stay in the layout domain');
    assert_contains('directly UNDER its own media', $prompt, 'stacking retains ownership');
    assert_contains('preserving its media association and reading order', $prompt, 'moves remain semantics-safe');
    assert_contains('Stacked mobile columns need no artificial height matching', $prompt, 'mobile stacking is exempt');
    assert_true(!str_contains($prompt, 'fold the lines into a neighboring column'), 'ambiguous reassignment is absent');
});

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
    assert_eq(3, count($sent['cached_prefixes'] ?? []), 'direct execution forwards every cache layer');
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
    assert_contains(
        'section-composition--full-bleed-cover',
        $markup,
        'the delivered root carries the assigned archetype marker',
    );
    assert_eq([], $result->warnings, 'semantics-preserving normalization is not a durable warning');
    assert_eq(
        ['response-fence-removed', 'preset-reference-canonicalized', 'root-marker-normalized'],
        array_column($result->repairs, 'code')
    );
    assert_eq($result->toArray(), json_decode((string) json_encode($result), true));
});

test('SectionUnit deterministically normalizes list-thumb delivery', function () {
    $llm = new FakeLlm();
    // The row sits under the section's one top-level group, as the section
    // contract requires, so the archetype's root marker lands on that group.
    $llm->queueText(
        '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
        . '<!-- wp:columns {"className":"list-thumb-flush"} -->'
        . '<div class="wp-block-columns list-thumb-flush">'
        . '<!-- wp:column {"width":"18%"} --><div class="wp-block-column" style="flex-basis:18%">'
        . '<!-- wp:image {"className":"card-media-thumb"} -->'
        . '<figure class="wp-block-image card-media-thumb"><img src="thumb.jpg" alt=""/></figure>'
        . '<!-- /wp:image --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"82%"} --><div class="wp-block-column" style="flex-basis:82%">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Item</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One line.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->'
        . '</div><!-- /wp:group -->',
    );
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));

    $result = $unit->generate(section_unit_input());

    assert_contains('"isStackedOnMobile":false', $result->markup);
    assert_contains('is-not-stacked-on-mobile', $result->markup);
    assert_contains('"blockGap":"var:preset|spacing|xs"', $result->markup);
    assert_true(in_array('list-thumb-row-normalized', array_column($result->repairs, 'code'), true));
    assert_eq([], $result->warnings);
});

test('SectionUnit executes a tinted plan with the committed band surface', function () {
    $llm = new FakeLlm();
    $llm->queueText(
        '<!-- wp:group {"backgroundColor":"secondary"} -->'
        . '<div class="wp-block-group has-secondary-background-color has-background">'
        . '<!-- wp:paragraph --><p>Band content survives.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->',
    );
    $input = section_unit_input();
    $input['section']['background'] = 'tinted';
    $unit = new SectionUnit($llm, new PromptRenderer(repo_path('prompts')));

    $first = $unit->generate($input);
    assert_contains('"backgroundColor":"band"', $first->markup);
    assert_contains('has-band-background-color', $first->markup);
    assert_true(!str_contains($first->markup, 'secondary-background'));
    assert_contains('Band content survives.', $first->markup);
    assert_true(in_array('tinted-band-surface-enforced', array_column($first->repairs, 'code'), true));
    assert_eq([], $first->warnings);
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

test('SectionUnit teaches the attribute-light markup contract without sacrificing authored design data', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_unit_input());
    $prompt = section_unit_request_text($request);

    foreach ([
        'ATTRIBUTE-LIGHT BLOCK SAVE MARKUP',
        'Omit serializer-generated wrapper classes and inline styles',
        'copy exactly those custom class tokens onto the block wrapper',
        'Preserve RichText inline markup and attributes exactly',
        'Keep source-critical HTML',
        'AI_IMAGE',
    ] as $required) {
        assert_contains($required, $prompt);
    }

    foreach ([
        '<h2 class="wp-block-heading has-heading-font-family has-primary-color has-text-color">',
        '<figure class="wp-block-image size-large"><img',
        '<div class="wp-block-cover alignfull" style="min-height:80vh">',
    ] as $redundantExample) {
        assert_true(
            !str_contains($prompt, $redundantExample),
            "section prompt must not teach redundant save markup: {$redundantExample}",
        );
    }
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
    assert_contains(
        'put ALL of that content in ONE such wrapper and give it `"className":"card-body"` regardless of treatment',
        $prompt,
        'every optional nested card text wrapper receives the shared structural hook',
    );
    assert_contains(
        'REQUIRED for `flush` and `overlap`; it is OPTIONAL for `framed` and `borderless`',
        $prompt,
        'framed and borderless cards may stay flat but cannot create an unhooked nested body',
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

test('SectionUnit documents the complete list-thumb delivery contract', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_unit_input());
    $prompt = section_unit_request_text($request);

    assert_contains(
        'One `wp:columns` per row with `"isStackedOnMobile":false`',
        $prompt,
        'the dense two-column row is kept horizontal at Core\'s mobile breakpoint',
    );
    assert_contains(
        '`isStackedOnMobile:false` is MANDATORY for BOTH flush and framed rows',
        $prompt,
    );
    assert_contains(
        '`"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}`',
        $prompt,
        'the text column owns a tight intra-row rhythm',
    );
    assert_contains('`"className":"list-thumb-flush"`', $prompt);
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
        $request['cached_prefixes'][1] ?? '',
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
        'media_mode' => 'SECTION-HERO-BLUEPRINT-LEAK-SENTINEL',
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

    $archetype = $input['section']['layout_archetype'];
    $composition = $renderer->render('section-composition.md', [
        'layout_archetype' => $archetype,
        'background'       => $input['section']['background'],
        'vertical_density' => $input['section']['vertical_density'],
        'handoff'          => $input['section']['handoff'],
        'neighbors'        => $input['neighbors'],
        'root_marker'      => SectionComposition::marker($archetype),
        'composition_recipe' => $renderer->render(
            SectionComposition::recipeTemplate($archetype),
            []
        ),
    ]);
    $rendered = $renderer->render('section.md', [
        'site_context'      => rtrim($renderer->render('site-context.md', [
            'site_spec'        => $input['site_spec'],
            'theme_json'       => $input['theme_json'],
            'design_direction' => $input['design_direction'],
        ]), "\r\n"),
        'language'          => $input['language'],
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
        'form_instructions'  => $renderer->render('no-forms.md', []),
        'block_markup_output_contract' => rtrim(
            $renderer->render('block-markup-output-contract.md', []),
            "\r\n",
        ),
    ]);
    $withoutMarkers = rtrim(ltrim(str_replace([
        "<!-- cache-layer:site -->\n",
        "<!-- cache-layer:build -->\n",
        "<!-- cache-layer:page -->\n",
        "<!-- cache-layer:brief -->\n",
    ], '', $rendered), "\r\n"), "\r\n");

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
