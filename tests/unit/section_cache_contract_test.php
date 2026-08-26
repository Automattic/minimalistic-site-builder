<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TextBatchResult;
use Automattic\SiteBuild\UsageReporting;
use Automattic\SiteBuild\Units\FooterUnit;
use Automattic\SiteBuild\Units\HeaderUnit;
use Automattic\SiteBuild\Units\HeroUnit;
use Automattic\SiteBuild\Units\SectionUnit;

const SECTION_CACHE_PROBE_PROMPT = 'Warm the cached markup context.';

/** @return array<string,mixed> */
function section_cache_input(string $slug = 'hero', string $title = 'Hero'): array
{
    return [
        'site_spec'        => '{"name":"CACHE-SPEC-SENTINEL"}',
        'language'         => 'cache-language-sentinel',
        'theme_json'       => '{"cache-theme-sentinel":true}',
        'design_direction' => 'CACHE-DIRECTION-SENTINEL',
        'card_style'       => 'flush',
        'outline'          => "1. Hero (hero) [#hero]\n2. About (about) [#about]",
        'site_pages'       => '- "Home" — / (front page)',
        'page'             => ['slug' => 'home', 'title' => 'Home', 'path' => '/'],
        'section'          => [
            'slug'             => $slug,
            'title'            => $title,
            'role'             => 'hero',
            'type'             => 'hero',
            'purpose'          => "Purpose for {$title}",
            'content_notes'    => "Notes for {$title}",
            'layout_archetype' => 'full-bleed-cover',
            'background'       => 'image',
            'vertical_density' => 'standard',
            'text_placement'   => 'left-column',
            'handoff'          => 'Between the header and the next section.',
            'primary_action'   => null,
        ],
        'neighbors' => 'Above: the site header. Below: the next section.',
        'header_contract' => 'HEADER CONTRACT (this is a page-opening section): test contract.',
    ];
}

function seed_section_cache_project(Project $project): void
{
    $project->writeJson('siteSpec.json', ['name' => 'Cache Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
    seed_test_design_direction($project);
    $project->writeJson('pages.json', ['pages' => [[
        'slug'       => 'home',
        'title'      => 'Home',
        'path'       => '/',
        'front'      => true,
        'parent'     => null,
        'menu_order' => 0,
        'purpose'    => 'Cache demo',
        'sections'   => [
            [
                'slug' => 'hero', 'title' => 'Hero', 'role' => 'hero', 'type' => 'hero',
                'purpose' => 'Open', 'content_notes' => 'Lead strongly.',
                'layout_archetype' => 'full-bleed-cover', 'background' => 'image',
                'vertical_density' => 'standard', 'text_placement' => 'left-column',
                'handoff' => 'Between header and about.',
                'primary_action' => null,
            ],
            [
                'slug' => 'about', 'title' => 'About', 'role' => 'closing', 'type' => 'about',
                'purpose' => 'Explain', 'content_notes' => 'Tell the story.',
                'layout_archetype' => 'asymmetric-split', 'background' => 'base',
                'vertical_density' => 'standard', 'text_placement' => 'split',
                'handoff' => 'Between hero and footer.',
                'primary_action' => null,
            ],
        ],
    ]]]);
}

function queue_section_cache_parts(FakeLlm $llm, bool $includeProbe = true): void
{
    if ($includeProbe) {
        $llm->queueText('OK');
    }
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:heading --><h1>Hero</h1><!-- /wp:heading --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:heading --><h2>About</h2><!-- /wp:heading --><!-- /wp:group -->');
}

function section_cache_throwing_usage_reporter(FakeLlm $llm, int $throwOnCall): Llm
{
    return new class ($llm, $throwOnCall) implements Llm, UsageReporting {
        private int $usageCalls = 0;

        public function __construct(
            private FakeLlm $llm,
            private int $throwOnCall,
        ) {
        }

        public function usageTotals(): array
        {
            $this->usageCalls++;
            if ($this->usageCalls === $this->throwOnCall) {
                throw new RuntimeException('usage reporting unavailable');
            }
            return $this->llm->usageTotals();
        }

        public function complete(string $prompt, array $opts = []): string
        {
            return $this->llm->complete($prompt, $opts);
        }

        public function completeJson(string $prompt, array $opts = []): array
        {
            return $this->llm->completeJson($prompt, $opts);
        }

        public function completeJsonBatch(array $requests): array
        {
            return $this->llm->completeJsonBatch($requests);
        }

        public function completeBatch(array $requests): TextBatchResult
        {
            return $this->llm->completeBatch($requests);
        }
    };
}

test('every markup prompt freezes the same leading site layer', function () {
    $siteMarker = '<!-- cache-layer:site -->';
    $unitMarker = '<!-- cache-layer:unit -->';

    foreach (['header.md', 'footer.md', 'hero.md'] as $name) {
        $template = (string) file_get_contents(repo_path('prompts/' . $name));
        assert_eq(1, substr_count($template, $siteMarker), "{$name} opens exactly one site layer");
        assert_eq(1, substr_count($template, $unitMarker), "{$name} opens exactly one unit layer");
        assert_true(str_starts_with($template, $siteMarker), "{$name} has nothing before its site layer");
        assert_true(strpos($template, $siteMarker) < strpos($template, $unitMarker));

        [, $afterSite] = explode($siteMarker, $template, 2);
        [$site, $unit] = explode($unitMarker, $afterSite, 2);
        assert_eq('{{site_context}}', trim($site), "{$name} site layer is the shared partial alone");
        assert_true(
            !str_contains($unit, '{{site_context}}'),
            "{$name} does not repeat the shared context in its varying layer",
        );
    }

    $shared = (string) file_get_contents(repo_path('prompts/site-context.md'));
    foreach (['{{site_spec}}', '{{theme_json}}', '{{design_direction}}'] as $placeholder) {
        assert_contains($placeholder, $shared);
    }
});

test('section prompt freezes site, build, page, and brief layer boundaries', function () {
    $template = (string) file_get_contents(repo_path('prompts/section.md'));
    $siteMarker = '<!-- cache-layer:site -->';
    $buildMarker = '<!-- cache-layer:build -->';
    $pageMarker = '<!-- cache-layer:page -->';
    $briefMarker = '<!-- cache-layer:brief -->';

    foreach ([$siteMarker, $buildMarker, $pageMarker, $briefMarker] as $marker) {
        assert_eq(1, substr_count($template, $marker));
    }
    assert_true(str_starts_with($template, $siteMarker), 'the shared layer leads, so chrome can reuse it');
    assert_true(strpos($template, $siteMarker) < strpos($template, $buildMarker));
    assert_true(strpos($template, $buildMarker) < strpos($template, $pageMarker));
    assert_true(strpos($template, $pageMarker) < strpos($template, $briefMarker));

    [, $afterSite] = explode($siteMarker, $template, 2);
    [$site, $afterBuild] = explode($buildMarker, $afterSite, 2);
    [$build, $afterPage] = explode($pageMarker, $afterBuild, 2);
    [$page, $brief] = explode($briefMarker, $afterPage, 2);

    assert_eq('{{site_context}}', trim($site));
    foreach ([
        '{{language}}',
        '{{card_style}}',
        '{{image_instructions}}',
        '{{block_markup_output_contract}}',
    ] as $placeholder) {
        assert_contains($placeholder, $build);
    }
    foreach (['{{site_spec}}', '{{theme_json}}', '{{design_direction}}'] as $placeholder) {
        assert_true(
            !str_contains($build . $page . $brief, $placeholder),
            "{$placeholder} lives only in the shared site layer",
        );
    }
    foreach (['{{page_title}}', '{{outline}}', '{{site_pages}}'] as $placeholder) {
        assert_contains($placeholder, $page);
        assert_true(!str_contains($build, $placeholder), "{$placeholder} excluded from build layer");
    }
    foreach (['{{section_title}}', '{{section_slug}}', '{{section_type}}', '{{section_purpose}}', '{{content_notes}}', '{{composition}}', '{{page_path}}'] as $placeholder) {
        assert_contains($placeholder, $brief);
        assert_true(!str_contains($build, $placeholder), "{$placeholder} excluded from build layer");
        assert_true(!str_contains($page, $placeholder), "{$placeholder} excluded from page layer");
    }
});

test('section request contract exposes three stable cached prefixes and a varying brief', function () {
    $unit = new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        'cache-model',
        0.4,
    );
    $hero = $unit->request(section_cache_input());
    $about = $unit->request(section_cache_input('about', 'About'));

    assert_eq(3, count($hero['cached_prefixes'] ?? []));
    assert_eq($hero['cached_prefixes'], $about['cached_prefixes'], 'same site/build/page produces byte-identical layers');
    assert_true($hero['prompt'] !== $about['prompt'], 'per-section brief remains variable');
    assert_eq('cache-model', $hero['model'] ?? null);
    assert_eq(0.4, $hero['temperature'] ?? null);
    assert_contains('CACHE-SPEC-SENTINEL', $hero['cached_prefixes'][0]);
    assert_contains('CACHE-DIRECTION-SENTINEL', $hero['cached_prefixes'][0]);
    assert_contains('ASSIGNED CARD STYLE (authoritative machine contract): flush', $hero['cached_prefixes'][1]);
    assert_contains('FULL PAGE OUTLINE', $hero['cached_prefixes'][2]);
    assert_contains('SECTION TO BUILD', $hero['prompt']);
    foreach ($hero['cached_prefixes'] as $prefix) {
        assert_true(str_ends_with($prefix, "\n\n"), 'every cached prefix carries its explicit separator');
        assert_true(!str_ends_with($prefix, "\n\n\n"), 'cached prefix separator is exactly two newlines');
    }
    assert_true(!str_contains(implode('', $hero['cached_prefixes']), '<!-- cache-layer:'));
    assert_true(!str_contains($hero['prompt'], '<!-- cache-layer:'));
});

test('section cached prefixes assemble byte-equally across Anthropic and OpenAI', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_cache_input());

    $anthropic = AnthropicClient::bodyFor($request, 'claude-sonnet-4-6', 16000);
    $anthropicText = implode('', array_map(
        static fn (array $block): string => (string) $block['text'],
        $anthropic['messages'][0]['content'],
    ));
    $openAi = OpenAiCompatibleClient::bodyFor($request, 'gpt-4o', 16000);
    $openAiText = (string) $openAi['messages'][1]['content'];

    assert_eq(implode('', $request['cached_prefixes']) . $request['prompt'], $anthropicText);
    assert_eq($anthropicText, $openAiText, 'providers receive byte-identical assembled user prompts');
});

test('every markup unit opens with the same primeable site layer', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $llm = new FakeLlm();
    $input = section_cache_input();

    $section = (new SectionUnit($llm, $renderer))->request($input);
    $header = (new HeaderUnit($llm, $renderer))->request($input + [
        'hero_brief' => 'A text-led hero.',
        'nav_rule' => '- Use wp:page-list.',
        'above_fold_contract' => test_above_fold_contract(),
        'header_behavior' => 'DETERMINISTIC HEADER BEHAVIOR: static.',
    ]);
    $footer = (new FooterUnit($llm, $renderer))->request($input + [
        'final_section_brief' => 'A quiet closing section.',
        'composition_archetype' => 'typographic-billboard',
        'page_count' => 1,
    ]);
    // The hero's own consistent blueprint/contract fixture, carrying this
    // test's site context so the shared layer can be compared byte-for-byte.
    $hero = (new HeroUnit($llm, $renderer))->request(array_merge(hero_unit_contract_input(), [
        'site_spec' => $input['site_spec'],
        'theme_json' => $input['theme_json'],
        'design_direction' => $input['design_direction'],
    ]));

    $site = $section['cached_prefixes'][0];
    foreach (['header' => $header, 'footer' => $footer, 'hero' => $hero] as $name => $request) {
        assert_eq($site, $request['cached_prefixes'][0], "{$name} reuses the section's site layer byte-for-byte");
        assert_eq(1, count($request['cached_prefixes']), "{$name} adds no further reusable layer");
    }
    assert_contains('CACHE-SPEC-SENTINEL', $site);
    assert_contains('CACHE-DIRECTION-SENTINEL', $site);
    assert_contains('cache-theme-sentinel', $site);
    // Warming a section primes the chrome layer too; the reverse would leave
    // the section build and page layers cold.
    assert_true(count($section['cached_prefixes']) > count($header['cached_prefixes']));
});

test('the site layer is the same bytes whether a step read JSON as text or as an array', function () {
    $renderer = new PromptRenderer(repo_path('prompts'));
    $llm = new FakeLlm();
    $spec = ['name' => 'CACHE-SPEC-SENTINEL', 'slug' => 'cache-spec'];
    $theme = ['cache-theme-sentinel' => true, 'settings' => ['layout' => ['contentSize' => '40rem']]];

    // Exactly what writeJson() puts on disk, so this is readText() vs readJson()
    // of one file: SectionsStep takes the text, TransformSiteStep the array.
    $encode = static fn (array $data): string => (string) json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";

    $asText = section_cache_input() + [];
    $asText['site_spec'] = $encode($spec);
    $asText['theme_json'] = $encode($theme);

    $asArray = $asText;
    $asArray['site_spec'] = $spec;
    $asArray['theme_json'] = $theme;

    $fromText = (new SectionUnit($llm, $renderer))->request($asText);
    $fromArray = (new SectionUnit($llm, $renderer))->request($asArray);

    assert_eq(
        $fromText['cached_prefixes'][0],
        $fromArray['cached_prefixes'][0],
        'a one-byte terminator difference would be a second cache entry for the same context',
    );
    assert_contains('CACHE-SPEC-SENTINEL', $fromText['cached_prefixes'][0]);
    assert_contains('cache-theme-sentinel', $fromText['cached_prefixes'][0]);
});

test('a fully layered section still fits the Anthropic breakpoint budget', function () {
    $request = (new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
    ))->request(section_cache_input());

    $body = AnthropicClient::bodyFor($request, 'claude-sonnet-4-6', 16000);
    $content = $body['messages'][0]['content'];

    assert_eq(4, count($content), 'three cached layers plus the varying brief');
    $marked = array_values(array_filter(
        $content,
        static fn (array $block): bool => isset($block['cache_control']),
    ));
    // Anthropic allows four breakpoints per request; the section is the
    // deepest markup prompt and must leave one in reserve.
    assert_eq(3, count($marked), 'one breakpoint stays unspent');
    assert_true(!isset($content[3]['cache_control']), 'the varying brief is never a breakpoint');
});

test('sections skips the uncached hero and warms the first ordinary section prefixes', function () {
    with_project('builder_section_cache_', function ($project) {
        seed_section_cache_project($project);
        $llm = new FakeLlm();
        queue_section_cache_parts($llm);
        $step = new SectionsStep($llm, new PromptRenderer(repo_path('prompts')), 'cache-model');
        $requests = $step->requests($project);

        $step->run($project);

        assert_eq(0, $llm->completeCalls);
        assert_eq(2, $llm->completeBatchCalls, 'the probe and generation both use the batch seam');
        assert_eq(SECTION_CACHE_PROBE_PROMPT, $llm->calls[0]['prompt']);
        assert_eq(1, $llm->calls[0]['opts']['max_tokens'] ?? null);
        assert_eq(true, $llm->calls[0]['opts']['tolerate_empty'] ?? null);
        assert_eq('markup-cache-warm', $llm->calls[0]['opts']['log_label'] ?? null);
        assert_eq('cache-model', $llm->calls[0]['opts']['model'] ?? null);
        assert_eq(
            $requests['page-home--about']['cached_prefixes'],
            $llm->calls[0]['opts']['cached_prefixes'] ?? null,
            'probe reuses the first ordinary section request prefixes byte-for-byte',
        );
        assert_eq(SECTION_CACHE_PROBE_PROMPT, $llm->calls[0]['prompt'], 'probe is first in call history');
    });
});

test('a request that does not share the primed layer is reported', function () {
    $shared = str_repeat('site layer ', 40);
    $requests = [
        'page-home--about' => ['prompt' => 'p', 'cached_prefixes' => [$shared, 'build', 'page']],
        'header'           => ['prompt' => 'p', 'cached_prefixes' => [$shared]],
        'footer'           => ['prompt' => 'p', 'cached_prefixes' => [$shared . ' ']],
        'plain'            => ['prompt' => 'p'],
    ];

    $warnings = SectionsStep::requestsOutsideSharedLayer($requests, $shared);

    // One byte of difference is a total cache miss, and nothing else notices.
    assert_eq(1, count($warnings), 'only the diverging request is reported');
    assert_contains('footer', $warnings[0]);
    assert_contains('pays full price', $warnings[0]);

    assert_eq([], SectionsStep::requestsOutsideSharedLayer($requests, ''), 'no primed layer, nothing to compare');
    unset($requests['footer']);
    assert_eq([], SectionsStep::requestsOutsideSharedLayer($requests, $shared), 'agreeing batch is silent');
});

test('section cache warm-up failure is non-fatal', function () {
    with_project('builder_section_cache_', function ($project) {
        seed_section_cache_project($project);
        $llm = new FakeLlm();
        $llm->failPromptSubstrings[] = SECTION_CACHE_PROBE_PROMPT;
        queue_section_cache_parts($llm, false);

        (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        assert_eq(0, $llm->completeCalls);
        assert_eq(2, $llm->completeBatchCalls);
        assert_true($project->exists('theme/parts/page-home--hero.html'));
        assert_true($project->exists('theme/parts/page-home--about.html'));
    });
});

test('section cache usage reporting failure is non-fatal and inconclusive', function () {
    foreach ([1 => 'before', 2 => 'after'] as $throwOnCall => $phase) {
        with_project('builder_section_cache_', function ($project) use ($throwOnCall, $phase) {
            seed_section_cache_project($project);
            $inner = new FakeLlm();
            queue_section_cache_parts($inner);
            $llm = section_cache_throwing_usage_reporter($inner, $throwOnCall);

            (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

            assert_eq(2, $inner->completeBatchCalls, "{$phase} snapshot failure keeps probe and generation");
            assert_true($project->exists('theme/parts/page-home--hero.html'));
            assert_true($project->exists('theme/parts/page-home--about.html'));
            $warnings = $project->exists('warnings.json') ? $project->readJson('warnings.json') : [];
            $sectionWarnings = implode("\n", $warnings['sections'] ?? []);
            assert_true(
                !str_contains($sectionWarnings, 'discard cached_prefixes'),
                "{$phase} snapshot failure cannot prove context loss",
            );
        });
    }
});

test('a hero-only front page still warms the layer its chrome shares', function () {
    with_project('builder_section_cache_', function ($project) {
        seed_section_cache_project($project);
        $plan = $project->readJson('pages.json');
        $plan['pages'][0]['sections'] = [$plan['pages'][0]['sections'][0]];
        $project->writeJson('pages.json', $plan);

        $llm = new FakeLlm();
        $llm->queueText('OK'); // cache warm-up probe
        $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
        $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --><!-- /wp:group -->');
        $llm->queueText('<!-- wp:group --><!-- wp:heading --><h1>Hero</h1><!-- /wp:heading --><!-- /wp:group -->');

        (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        // Header, footer and hero all open with the same site layer, so the
        // probe pays for it once instead of three concurrent calls missing it.
        assert_eq(0, $llm->completeCalls, 'the probe travels the batch seam, not complete()');
        assert_eq(2, $llm->completeBatchCalls, 'one warm-up batch, then the parts batch');
        assert_true($project->exists('theme/parts/page-home--hero.html'));
    });
});

test('sections warn when the batch path drops cached prefixes', function () {
    with_project('builder_section_cache_', function ($project) {
        seed_section_cache_project($project);
        $llm = new FakeLlm();
        $llm->billCachedPrefixes = false;
        queue_section_cache_parts($llm);

        (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

        $warnings = $project->readJson('warnings.json');
        $sectionWarnings = implode("\n", $warnings['sections'] ?? []);
        assert_contains('discard cached_prefixes', $sectionWarnings);
        assert_contains('completeBatch() forwards cached_prefixes', $sectionWarnings);
        assert_true(!str_contains($sectionWarnings, 'bin/llm-conformance.php'));
    });
});
