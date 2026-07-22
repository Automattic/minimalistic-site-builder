<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;
use Automattic\SiteBuild\OpenAiCompatibleClient;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\SectionUnit;

const SECTION_CACHE_PROBE_PROMPT = 'Warm the cached section context.';

/** @return array<string,mixed> */
function section_cache_input(string $slug = 'hero', string $title = 'Hero'): array
{
    return [
        'site_spec'        => '{"name":"CACHE-SPEC-SENTINEL"}',
        'language'         => 'cache-language-sentinel',
        'theme_json'       => '{"cache-theme-sentinel":true}',
        'design_direction' => 'CACHE-DIRECTION-SENTINEL',
        'outline'          => "1. Hero (hero) [#hero]\n2. About (about) [#about]",
        'site_pages'       => '- "Home" — / (front page)',
        'page'             => ['slug' => 'home', 'title' => 'Home', 'path' => '/'],
        'section'          => [
            'slug'             => $slug,
            'title'            => $title,
            'type'             => 'hero',
            'purpose'          => "Purpose for {$title}",
            'content_notes'    => "Notes for {$title}",
            'layout_archetype' => 'full-bleed-cover',
            'background'       => 'image',
            'vertical_density' => 'standard',
            'handoff'          => 'Between the header and the next section.',
        ],
        'neighbors' => 'Above: the site header. Below: the next section.',
    ];
}

/** @return array{0:\Automattic\SiteBuild\Project,1:string} */
function section_cache_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_section_cache_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('siteSpec.json', ['name' => 'Cache Demo']);
    $project->writeJson('theme/theme.json', ['version' => 3]);
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
                'slug' => 'hero', 'title' => 'Hero', 'type' => 'hero',
                'purpose' => 'Open', 'content_notes' => 'Lead strongly.',
                'layout_archetype' => 'full-bleed-cover', 'background' => 'image',
                'vertical_density' => 'standard', 'handoff' => 'Between header and about.',
            ],
            [
                'slug' => 'about', 'title' => 'About', 'type' => 'about',
                'purpose' => 'Explain', 'content_notes' => 'Tell the story.',
                'layout_archetype' => 'asymmetric-split', 'background' => 'base',
                'vertical_density' => 'standard', 'handoff' => 'Between hero and footer.',
            ],
        ],
    ]]]);
    return [$project, $tmp];
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

test('section prompt freezes build, page, and brief layer boundaries', function () {
    $template = (string) file_get_contents(repo_path('prompts/section.md'));
    $buildMarker = '<!-- section-cache-layer:build -->';
    $pageMarker = '<!-- section-cache-layer:page -->';
    $briefMarker = '<!-- section-cache-layer:brief -->';

    assert_eq(1, substr_count($template, $buildMarker));
    assert_eq(1, substr_count($template, $pageMarker));
    assert_eq(1, substr_count($template, $briefMarker));
    assert_true(strpos($template, $buildMarker) < strpos($template, $pageMarker));
    assert_true(strpos($template, $pageMarker) < strpos($template, $briefMarker));

    [, $afterBuild] = explode($buildMarker, $template, 2);
    [$build, $afterPage] = explode($pageMarker, $afterBuild, 2);
    [$page, $brief] = explode($briefMarker, $afterPage, 2);

    foreach (['{{site_spec}}', '{{language}}', '{{theme_json}}', '{{design_direction}}', '{{image_instructions}}'] as $placeholder) {
        assert_contains($placeholder, $build);
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

test('section request contract exposes two stable cached prefixes and a varying brief', function () {
    $unit = new SectionUnit(
        new FakeLlm(),
        new PromptRenderer(repo_path('prompts')),
        'cache-model',
        0.4,
    );
    $hero = $unit->request(section_cache_input());
    $about = $unit->request(section_cache_input('about', 'About'));

    assert_eq(2, count($hero['cached_prefixes'] ?? []));
    assert_eq($hero['cached_prefixes'], $about['cached_prefixes'], 'same build/page produces byte-identical layers');
    assert_true($hero['prompt'] !== $about['prompt'], 'per-section brief remains variable');
    assert_eq('cache-model', $hero['model'] ?? null);
    assert_eq(0.4, $hero['temperature'] ?? null);
    assert_contains('CACHE-SPEC-SENTINEL', $hero['cached_prefixes'][0]);
    assert_contains('CACHE-DIRECTION-SENTINEL', $hero['cached_prefixes'][0]);
    assert_contains('FULL PAGE OUTLINE', $hero['cached_prefixes'][1]);
    assert_contains('SECTION TO BUILD', $hero['prompt']);
    foreach ($hero['cached_prefixes'] as $prefix) {
        assert_true(str_ends_with($prefix, "\n\n"), 'every cached prefix carries its explicit separator');
        assert_true(!str_ends_with($prefix, "\n\n\n"), 'cached prefix separator is exactly two newlines');
    }
    assert_true(!str_contains(implode('', $hero['cached_prefixes']), '<!-- section-cache-layer:'));
    assert_true(!str_contains($hero['prompt'], '<!-- section-cache-layer:'));
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

test('sections warms the exact first section prefixes before the concurrent fan-out', function () {
    [$project, $tmp] = section_cache_project();
    $llm = new FakeLlm();
    queue_section_cache_parts($llm);
    $step = new SectionsStep($llm, new PromptRenderer(repo_path('prompts')), 'cache-model');
    $requests = $step->requests($project);

    $step->run($project);

    assert_eq(1, $llm->completeCalls);
    assert_eq(1, $llm->completeBatchCalls);
    assert_eq(SECTION_CACHE_PROBE_PROMPT, $llm->calls[0]['prompt']);
    assert_eq(1, $llm->calls[0]['opts']['max_tokens'] ?? null);
    assert_eq(true, $llm->calls[0]['opts']['tolerate_empty'] ?? null);
    assert_eq('section-cache-warm', $llm->calls[0]['opts']['log_label'] ?? null);
    assert_eq('cache-model', $llm->calls[0]['opts']['model'] ?? null);
    assert_eq(
        $requests['page-home--hero']['cached_prefixes'],
        $llm->calls[0]['opts']['cached_prefixes'] ?? null,
        'probe reuses the first section request prefixes byte-for-byte',
    );
    assert_eq('Warm the cached section context.', $llm->calls[0]['prompt'], 'probe is first in call history');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('section cache warm-up failure is non-fatal', function () {
    [$project, $tmp] = section_cache_project();
    $llm = new FakeLlm();
    $llm->failPromptSubstrings[] = SECTION_CACHE_PROBE_PROMPT;
    queue_section_cache_parts($llm, false);

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq(1, $llm->completeCalls);
    assert_eq(1, $llm->completeBatchCalls);
    assert_true($project->exists('theme/parts/page-home--hero.html'));
    assert_true($project->exists('theme/parts/page-home--about.html'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
