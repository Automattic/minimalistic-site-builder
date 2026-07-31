<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;
use Automattic\SiteBuild\Units\SectionUnit;

/** @return array<string,mixed> */
function block_markup_contract_input(): array
{
    return [
        'site_spec'        => '{"name":"Contract Test"}',
        'language'         => 'English',
        'theme_json'       => '{"version":3}',
        'design_direction' => 'Editorial',
        'outline'          => '1. Hero (hero) [#hero]',
        'site_pages'       => '- "Home" — / (front page)',
    ];
}

/** Reconstruct the logical text sent for either a flat or layered request. */
function block_markup_contract_request_text(array $request): string
{
    return implode('', $request['cached_prefixes'] ?? []) . $request['prompt'];
}

test('markup generation units share one output-only contract', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $llm = new FakeLlm();
    $input = block_markup_contract_input();
    $contract = rtrim($renderer->render('block-markup-output-contract.md', []), "\r\n");

    $requests = [
        'header' => (new HeaderUnit($llm, $renderer))->request($input + [
            'hero_brief' => 'A text-led hero.',
            'nav_rule' => '- Use wp:page-list.',
            'archetype_assignment' => 'ASSIGNED HEADER ARCHETYPE: standard-row',
        ]),
        'footer' => (new FooterUnit($llm, $renderer))->request($input + [
            'final_section_brief' => 'A quiet closing section.',
            'composition_archetype' => 'typographic-billboard',
            'page_count' => 1,
        ]),
        'section' => (new SectionUnit($llm, $renderer))->request($input + [
            'page' => [
                'slug' => 'home',
                'title' => 'Home',
                'path' => '/',
            ],
            'section' => [
                'slug' => 'hero',
                'title' => 'Hero',
                'role' => 'hero',
                'type' => 'hero',
                'purpose' => 'Introduce the site.',
                'content_notes' => 'Lead with the value proposition.',
                'layout_archetype' => 'centered-stack',
                'background' => 'base',
                'vertical_density' => 'standard',
                'handoff' => 'Between the header and the next section.',
            ],
            'neighbors' => 'Above: header. Below: content.',
            'header_contract' => '',
        ]),
    ];

    foreach ($requests as $name => $request) {
        assert_eq(
            1,
            substr_count(block_markup_contract_request_text($request), $contract),
            "{$name} receives the exact shared contract once",
        );
    }

    $section = $requests['section'];
    assert_contains($contract, $section['cached_prefixes'][0], 'contract stays in the stable build layer');
    assert_true(!str_contains($section['cached_prefixes'][1], $contract), 'contract is absent from the page layer');
    assert_true(!str_contains($section['prompt'], $contract), 'contract is absent from the varying brief');
});

test('block-markup output contract pins clean document boundaries and examples', function () {
    $contract = rtrim(
        (new PromptRenderer(repo_path('prompts')))->render('block-markup-output-contract.md', []),
        "\r\n",
    );

    assert_contains(
        'first non-whitespace bytes of the response MUST be `<!-- wp:`',
        $contract,
    );
    assert_contains(
        'MUST end immediately after the final top-level block delimiter',
        $contract,
    );
    foreach ([
        'Preambles',
        'reasoning',
        'acknowledgements',
        'Markdown code fences',
        'trailing notes',
        'alternative drafts',
        'illustrative block examples outside the intended document',
    ] as $forbidden) {
        assert_contains($forbidden, $contract);
    }

    $exampleStart = "Valid response example (the entire response):\n";
    $invalidStart = "\n\nInvalid response examples";
    assert_eq(1, substr_count($contract, $exampleStart));
    assert_eq(1, substr_count($contract, $invalidStart));
    [, $afterExampleStart] = explode($exampleStart, $contract, 2);
    [$example] = explode($invalidStart, $afterExampleStart, 2);
    assert_eq(
        "<!-- wp:paragraph -->\n"
        . "<p>Example.</p>\n"
        . "<!-- /wp:paragraph -->",
        $example,
        'positive example is one neutral Gutenberg paragraph document',
    );
    assert_true(str_starts_with($example, '<!-- wp:'));
    assert_true(str_ends_with($example, '<!-- /wp:paragraph -->'));
    assert_true(!str_contains($example, '```'), 'positive example is unfenced');

    foreach (['Here is the markup:', '```html', 'Hope this helps'] as $invalidWrapper) {
        assert_contains($invalidWrapper, $contract);
    }
});
