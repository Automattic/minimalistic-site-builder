<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockFixers;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionRole;
use Automattic\SiteBuild\StepComposition;
use Automattic\SiteBuild\Steps\InnerPagesDesignStep;
use Automattic\SiteBuild\Steps\SpliceHomeDesignStep;
use Automattic\SiteBuild\Steps\TransformSiteStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/** @return array<string,mixed> */
function dp3_contract_golden(): array
{
    $json = json_decode(
        (string) file_get_contents(repo_path('tests/fixtures/dp-slice3/page-mode-golden.json')),
        true,
    );
    if (!is_array($json)) {
        throw new RuntimeException('DP-Slice 3 page-mode golden fixture is invalid JSON');
    }
    return $json;
}

/**
 * @param list<array<string,mixed>>|null $pages
 * @return array{0:Project,1:FakeLlm,2:string,3:array<string,mixed>}
 */
function dp3_contract_fixture(?array $pages = null): array
{
    $golden = dp3_contract_golden();
    $input = $golden['input'];
    if ($pages !== null) {
        $input['site_spec']['pages'] = $pages;
    }
    $tmp = sys_get_temp_dir() . '/builder_dp_slice3_contract_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', $input['meta']);
    $project->writeJson('siteSpec.json', $input['site_spec']);
    $designDirection = $input['design_direction'];
    $designDirection['hero_blueprint'] ??= \Automattic\SiteBuild\HeroBlueprint::defaultFor('cinematic-safe-zone');
    $project->writeJson('designDirection.json', $designDirection);
    $project->writeText('design/site.css', $input['site_css']);
    $project->writeText('design/preview.html', $input['preview_html']);
    return [$project, new FakeLlm(), $tmp, $golden];
}

function dp3_contract_cleanup(string $tmp): void
{
    exec('rm -rf ' . escapeshellarg($tmp));
}

function dp3_contract_step(FakeLlm $llm): InnerPagesDesignStep
{
    return new InnerPagesDesignStep($llm, new PromptRenderer(repo_path('prompts')));
}

/** @param array<string,mixed> $golden */
function dp3_contract_queue_page(FakeLlm $llm, array $golden): void
{
    foreach ($golden['input']['responses'] as $response) {
        $llm->queueText($response);
    }
}

/** @return array<string,string> */
function dp3_contract_design_manifest(Project $project): array
{
    $root = $project->path('design');
    $manifest = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $relative = 'design/' . substr($file->getPathname(), strlen($root) + 1);
        $manifest[$relative] = (string) file_get_contents($file->getPathname());
    }
    ksort($manifest);
    return $manifest;
}

/** @return array<string,mixed> */
function dp3_contract_page_observation(Project $project, FakeLlm $llm): array
{
    $calls = array_map(static function (array $call): array {
        $prefixes = $call['opts']['cached_prefixes'] ?? [];
        return [
            'prompt_sha256' => hash('sha256', $call['prompt']),
            'prefix_sha256' => array_map(
                static fn (string $value): string => hash('sha256', $value),
                $prefixes,
            ),
            'model' => $call['opts']['model'] ?? null,
            'temperature' => $call['opts']['temperature'] ?? null,
        ];
    }, $llm->calls);
    return [
        'request_count' => count($llm->calls),
        'complete_batch_calls' => $llm->completeBatchCalls,
        'complete_json_batch_calls' => $llm->completeJsonBatchCalls,
        'calls' => $calls,
        'design_manifest' => dp3_contract_design_manifest($project),
        'warnings_exists' => $project->exists('warnings.json'),
    ];
}

/** @return array<string,mixed> */
function dp3_contract_section(
    string $slug,
    string $title,
    string $archetype,
    string $background = 'base',
): array {
    return [
        'slug' => $slug,
        'title' => $title,
        'type' => 'content',
        'purpose' => "Deliver {$title}",
        'content_notes' => "Specific copy for {$title}.",
        'layout_archetype' => $archetype,
        'background' => $background,
        'vertical_density' => 'standard',
        'handoff' => "Connect {$title} to adjacent sections.",
    ];
}

/** @param list<array<string,mixed>> $sections @return list<array<string,mixed>> */
function dp3_contract_planned_sections(array $sections): array
{
    $planned = [];
    foreach ($sections as $index => $section) {
        $planned[] = [
            'slug' => $section['slug'],
            'title' => $section['title'],
            'role' => SectionRole::forPosition($index, count($sections)),
            'type' => $section['type'],
            'purpose' => $section['purpose'],
            'content_notes' => $section['content_notes'],
            'layout_archetype' => $section['layout_archetype'],
            'background' => $section['background'],
            'vertical_density' => $section['vertical_density'],
            'item_pattern' => null,
            'handoff' => $section['handoff'],
            'primary_action' => null,
        ];
    }
    return $planned;
}

function dp3_contract_outline_json(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
}

/** @return list<string> */
function dp3_contract_direct_section_ids(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
        return [];
    }
    $main = $dom->getElementsByTagName('main')->item(0);
    if (!$main instanceof DOMElement) {
        return [];
    }
    $ids = [];
    foreach ($main->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        assert_eq('section', strtolower($child->tagName), 'stitched main has section children only');
        $ids[] = $child->getAttribute('id');
    }
    return $ids;
}

/** @param callable():void $callback */
function dp3_contract_with_mode(?string $mode, callable $callback): void
{
    $previous = getenv('SITE_BUILD_GEN_UNIT');
    $mode === null
        ? putenv('SITE_BUILD_GEN_UNIT')
        : putenv('SITE_BUILD_GEN_UNIT=' . $mode);
    try {
        $callback();
    } finally {
        $previous === false
            ? putenv('SITE_BUILD_GEN_UNIT')
            : putenv('SITE_BUILD_GEN_UNIT=' . $previous);
    }
}

test('TG3 page mode default matches the immutable Slice-2 request and artifact golden', function () {
    dp3_contract_with_mode(null, function (): void {
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture();
        try {
            foreach (['snapshot_commit', 'snapshot_tree'] as $provenanceKey) {
                assert_true(
                    preg_match('/\A[0-9a-f]{40}\z/D', (string) ($golden[$provenanceKey] ?? '')) === 1,
                    "golden records immutable Slice-2 {$provenanceKey} provenance",
                );
            }
            dp3_contract_queue_page($llm, $golden);
            dp3_contract_step($llm)->run($project);
            assert_eq($golden['page_mode'], dp3_contract_page_observation($project, $llm));
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });
});

test('TG4 section mode fans one request per inner section from fold CSS preview and full page outline', function () {
    dp3_contract_with_mode('section', function (): void {
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture();
        try {
            $aboutSections = [
                dp3_contract_section('about-intro', 'About intro', 'centered-stack'),
                dp3_contract_section('about-values', 'About values', 'asymmetric-split', 'tinted'),
            ];
            $contactSections = [
                dp3_contract_section('contact-visit', 'Contact visit', 'centered-stack'),
            ];
            $llm->queueJson(['sections' => $aboutSections]);
            $llm->queueJson(['sections' => $contactSections]);
            $llm->queueText($golden['input']['responses']['design/home-body.html']);
            $llm->queueText('<section id="about-intro"><h1>ABOUT-INTRO-MARKER</h1></section>');
            $llm->queueText('<section id="about-values"><h2>ABOUT-VALUES-MARKER</h2></section>');
            $llm->queueText('<section id="contact-visit"><h1>CONTACT-VISIT-MARKER</h1></section>');

            dp3_contract_step($llm)->run($project);

            assert_eq(1, $llm->completeJsonBatchCalls, 'one serial planning batch precedes generation');
            $plannerCalls = array_values(array_filter(
                $llm->calls,
                static fn (array $call): bool => isset($call['opts']['json_schema']),
            ));
            assert_eq(2, count($plannerCalls), 'exactly one planner request per inner page');
            assert_contains("THIS PAGE:\n  Title:   About\n  Slug:    about", $plannerCalls[0]['prompt']);
            assert_contains("THIS PAGE:\n  Title:   Contact\n  Slug:    contact", $plannerCalls[1]['prompt']);
            assert_true(!str_contains($plannerCalls[0]['prompt'], "Slug:    home"));
            assert_true(!str_contains($plannerCalls[1]['prompt'], "Slug:    home"));
            assert_eq(1, $llm->completeBatchCalls, 'one text batch owns home plus every inner section');
            $generationCalls = array_values(array_filter(
                $llm->calls,
                static fn (array $call): bool => !isset($call['opts']['json_schema']),
            ));
            assert_eq(4, count($generationCalls), 'home plus three inner-section requests');
            $sectionCalls = array_slice($generationCalls, 1);
            foreach ($sectionCalls as $call) {
                $prefixes = $call['opts']['cached_prefixes'] ?? [];
                assert_eq(2, count($prefixes));
                assert_contains($golden['input']['site_css'], $prefixes[0]);
                assert_contains($golden['input']['preview_html'], $prefixes[1]);
                assert_true(!str_contains($call['prompt'], 'theme/theme.json'));
                assert_true(!str_contains($call['prompt'], 'theme.json'));
                assert_true(!str_contains(implode("\n", $prefixes), 'theme/theme.json'));
            }
            assert_contains('about-intro', $sectionCalls[0]['prompt']);
            assert_contains('about-values', $sectionCalls[0]['prompt']);
            assert_true(!str_contains($sectionCalls[0]['prompt'], 'contact-visit'));
            assert_contains('about-intro', $sectionCalls[1]['prompt']);
            assert_contains('about-values', $sectionCalls[1]['prompt']);
            assert_true(!str_contains($sectionCalls[1]['prompt'], 'contact-visit'));
            assert_contains('contact-visit', $sectionCalls[2]['prompt']);
            assert_true(!str_contains($sectionCalls[2]['prompt'], 'about-intro'));
            assert_true(!str_contains($sectionCalls[2]['prompt'], 'about-values'));
            $aboutOutline = dp3_contract_outline_json([
                'sections' => dp3_contract_planned_sections($aboutSections),
            ]);
            assert_contains($aboutOutline, $sectionCalls[0]['prompt']);
            assert_contains($aboutOutline, $sectionCalls[1]['prompt']);
            $contactOutline = dp3_contract_outline_json([
                'sections' => dp3_contract_planned_sections($contactSections),
            ]);
            assert_contains($contactOutline, $sectionCalls[2]['prompt']);
            assert_eq(
                ['about-intro', 'about-values'],
                dp3_contract_direct_section_ids($project->readText('design/about.html')),
            );
            assert_eq(
                ['contact-visit'],
                dp3_contract_direct_section_ids($project->readText('design/contact.html')),
            );
            assert_true(!$project->exists('warnings.json'), 'explicit section mode is silent on valid output');
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });
});

test('TG6 unset empty and explicit page stay silent while invalid non-empty falls back with warning', function () {
    foreach ([null, '', 'page'] as $mode) {
        dp3_contract_with_mode($mode, function () use ($mode): void {
            [$project, $llm, $tmp, $golden] = dp3_contract_fixture();
            try {
                dp3_contract_queue_page($llm, $golden);
                dp3_contract_step($llm)->run($project);
                assert_eq(
                    $golden['page_mode'],
                    dp3_contract_page_observation($project, $llm),
                    'silent page-mode fallback for ' . var_export($mode, true),
                );
            } finally {
                dp3_contract_cleanup($tmp);
            }
        });
    }

    dp3_contract_with_mode('section', function (): void {
        $pages = [
            ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome'],
            ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain studio'],
        ];
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture($pages);
        try {
            $llm->queueJson(['sections' => [
                dp3_contract_section('about-intro', 'About intro', 'centered-stack'),
            ]]);
            $llm->queueText($golden['input']['responses']['design/home-body.html']);
            $llm->queueText('<section id="about-intro"><h1>ABOUT</h1></section>');
            dp3_contract_step($llm)->run($project);
            assert_true(!$project->exists('warnings.json'), 'explicit section selection adds no warning');
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });

    dp3_contract_with_mode('garbage', function (): void {
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture();
        try {
            dp3_contract_queue_page($llm, $golden);
            dp3_contract_step($llm)->run($project);
            $expected = $golden['page_mode'];
            $expected['warnings_exists'] = true;
            assert_eq($expected, dp3_contract_page_observation($project, $llm));
            $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
            foreach (['SITE_BUILD_GEN_UNIT', 'garbage', 'page', 'fallback', 'disposition'] as $needle) {
                assert_contains($needle, $warnings);
            }
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });
});

test('TG7 section failures drop only failed sections and collapse total loss to one page marker', function () {
    $pages = [
        ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome'],
        ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain studio'],
    ];
    $plan = ['sections' => [
        dp3_contract_section('about-intro', 'About intro', 'centered-stack'),
        dp3_contract_section('about-values', 'About values', 'asymmetric-split', 'tinted'),
    ]];

    dp3_contract_with_mode('section', function () use ($pages, $plan): void {
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture($pages);
        try {
            $llm->queueJson($plan);
            $llm->queueText($golden['input']['responses']['design/home-body.html']);
            $llm->queueText('<section id="about-intro"><h1>SECTION-SURVIVOR</h1></section>');
            $llm->queueText('<main><p>FAILED-SECTION-AUTHORED</p></main>');
            $llm->queueText('<div>FAILED-SECTION-REPAIR</div>');
            dp3_contract_step($llm)->run($project);

            assert_eq(['about-intro'], dp3_contract_direct_section_ids($project->readText('design/about.html')));
            assert_contains('SECTION-SURVIVOR', $project->readText('design/about.html'));
            assert_true(!$project->exists('design/about.failed'));
            assert_eq([], glob($project->path('design/*.failed')) ?: [], 'no per-section marker written');
            $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
            foreach (['design/about.html', 'about-values', 'FAILED-SECTION-AUTHORED', 'removed', 'disposition'] as $needle) {
                assert_contains($needle, $warnings);
            }
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });

    dp3_contract_with_mode('section', function () use ($pages, $plan): void {
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture($pages);
        try {
            $llm->queueJson($plan);
            $llm->queueText($golden['input']['responses']['design/home-body.html']);
            $llm->queueText('<main><p>FAILED-FIRST-AUTHORED</p></main>');
            $llm->queueText('<main><p>FAILED-SECOND-AUTHORED</p></main>');
            $llm->queueText('<div>FAILED-FIRST-REPAIR</div>');
            $llm->queueText('<div>FAILED-SECOND-REPAIR</div>');
            dp3_contract_step($llm)->run($project);

            assert_true(!$project->exists('design/about.html'));
            assert_true($project->exists('design/about.failed'));
            assert_true($project->exists('design/home-body.html'), 'front page body survives inner-page total loss');
            assert_true(!$project->exists('design/home-body.failed'), 'front page never receives a failure marker');
            $markers = array_map('basename', glob($project->path('design/*.failed')) ?: []);
            assert_eq(['about.failed'], $markers, 'all failures collapse to one page-level marker');
            $warnings = implode("\n", $project->readJson('warnings.json')['inner-pages-design'] ?? []);
            foreach (['design/about.failed', 'about-intro', 'about-values', 'removed', 'disposition'] as $needle) {
                assert_contains($needle, $warnings);
            }

            $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => []]);
            $project->writeText(
                'design/home.html',
                '<!doctype html><html><body><header><p>FRONT-HEADER</p></header><main>'
                    . '<section id="front-content"><h1>FRONT-SURVIVOR</h1></section></main>'
                    . '<footer><p>FRONT-FOOTER</p></footer></body></html>',
            );
            $llm->queueJson(['sections' => [
                dp3_contract_section('legacy-about', 'Legacy about', 'centered-stack'),
            ]]);
            $llm->queueText('OK');
            $llm->queueText(
                '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
                    . '<!-- wp:paragraph --><p>LEGACY-ABOUT-REROUTE</p><!-- /wp:paragraph -->'
                    . '</div><!-- /wp:group -->',
            );
            (new TransformSiteStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
            assert_contains(
                'LEGACY-ABOUT-REROUTE',
                $project->readText('theme/parts/page-about--legacy-about.html'),
            );
            assert_contains('FRONT-SURVIVOR', $project->readText('theme/parts/page-home--front-content.html'));
            $report = $project->readJson(TransformArtifacts::REPORT);
            assert_true(in_array('inner_page_blocks_reroute', $report['fallback_codes'], true));
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });
});

test('TG8 composed graph validates both modes with explicit planner inputs declared', function () {
    $previousHtmlFirst = getenv('SITE_BUILD_HTML_FIRST');
    putenv('SITE_BUILD_HTML_FIRST=1');
    try {
        foreach (['page', 'section'] as $mode) {
            dp3_contract_with_mode($mode, function (): void {
                $llm = new FakeLlm();
                $composition = StepComposition::default(
                    $llm,
                    new PromptRenderer(repo_path('prompts')),
                    blockFixer: BlockFixers::default(),
                );
                $steps = array_values(array_filter(
                    $composition->steps(),
                    static fn ($step): bool => $step->id() === 'inner-pages-design',
                ));
                assert_eq(1, count($steps));
                assert_eq(
                    ['meta.json', 'siteSpec.json', 'designDirection.json', 'design/site.css', 'design/preview.html'],
                    $steps[0]->declaration()->reads,
                );
            });
        }
    } finally {
        $previousHtmlFirst === false
            ? putenv('SITE_BUILD_HTML_FIRST')
            : putenv('SITE_BUILD_HTML_FIRST=' . $previousHtmlFirst);
    }
});

test('TG9 section mode keeps home body one unit and splice preserves fold hero plus footer envelope', function () {
    $pages = [
        ['slug' => 'home', 'title' => 'Home', 'purpose' => 'Welcome'],
        ['slug' => 'about', 'title' => 'About', 'purpose' => 'Explain studio'],
    ];
    dp3_contract_with_mode('section', function () use ($pages): void {
        [$project, $llm, $tmp, $golden] = dp3_contract_fixture($pages);
        try {
            $llm->queueJson(['sections' => [
                dp3_contract_section('about-intro', 'About intro', 'centered-stack'),
            ]]);
            $llm->queueText($golden['input']['responses']['design/home-body.html']);
            $llm->queueText('<section id="about-intro"><h1>ABOUT</h1></section>');
            dp3_contract_step($llm)->run($project);

            assert_eq(1, $llm->completeJsonBatchCalls, 'planner receives inner page only');
            $plannerCalls = array_values(array_filter(
                $llm->calls,
                static fn (array $call): bool => isset($call['opts']['json_schema']),
            ));
            assert_eq(1, count($plannerCalls), 'home has no planning request');
            assert_contains("THIS PAGE:\n  Title:   About\n  Slug:    about", $plannerCalls[0]['prompt']);
            assert_true(!str_contains($plannerCalls[0]['prompt'], "Slug:    home"));
            $generationCalls = array_values(array_filter(
                $llm->calls,
                static fn (array $call): bool => !isset($call['opts']['json_schema']),
            ));
            assert_eq(2, count($generationCalls), 'one home body plus one inner section');
            $homeBody = $project->readText('design/home-body.html');
            assert_true(str_starts_with($homeBody, '<main>'));
            assert_eq(1, substr_count($homeBody, '<main>'));
            assert_eq(1, substr_count($homeBody, '<footer>'));
            foreach (['<header', 'id="hero"', '<h1', '<style'] as $forbidden) {
                assert_true(!str_contains(strtolower($homeBody), strtolower($forbidden)), $forbidden);
            }

            (new SpliceHomeDesignStep())->run($project);
            $home = $project->readText('design/home.html');
            assert_eq(1, substr_count(strtolower($home), '<header'));
            assert_eq(1, substr_count(strtolower($home), '<footer'));
            assert_contains('EXACT-FOLD-HERO-6D1F', $home);
            assert_contains('GOLD-HOME', $home);
        } finally {
            dp3_contract_cleanup($tmp);
        }
    });
});
