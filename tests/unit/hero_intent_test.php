<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('hero intent preserves an authored source order without assigning a universal order', function (): void {
    $raw = HeroBlueprint::defaultFor('authored');
    assert_eq([], HeroBlueprint::promptValues($raw)['source_order']);
    $raw['source_order'] = ['design-opening-image', 'design-opening-copy'];
    $repairs = $warnings = [];
    assert_eq($raw, HeroBlueprint::normalize($raw, 'authored', $repairs, $warnings));
    assert_eq([], $repairs);
    assert_eq([], $warnings);
    assert_eq($raw['source_order'], HeroBlueprint::promptValues($raw)['source_order']);
    assert_contains('primary impression', HeroComposition::choicePrompt());
    assert_contains('essential content', HeroComposition::choicePrompt());
});

test('hero intent drops only invalid source-order entries and normalizes to a fixed point', function (): void {
    $raw = HeroBlueprint::defaultFor('authored');
    $raw['source_order'] = [' design-copy ', 'body > img', 42, 'design-copy', 'design-image'];
    $repairs = $warnings = [];
    $normalized = HeroBlueprint::normalize($raw, 'authored', $repairs, $warnings);
    assert_eq(['design-copy', 'design-image'], $normalized['source_order']);
    assert_eq($raw['composition'], $normalized['composition']);
    assert_eq(2, count($warnings));
    foreach ($warnings as $warning) {
        foreach (['designDirection.json', 'source_order', 'authored', 'removed', 'disposition'] as $context) {
            assert_contains($context, $warning);
        }
    }
    $repairs = $warnings = [];
    assert_eq($normalized, HeroBlueprint::normalize($normalized, 'authored', $repairs, $warnings));
    assert_eq([], $repairs);
    assert_eq([], $warnings);
    foreach ([null, 'image first', ['first' => 'design-image']] as $invalid) {
        $raw['source_order'] = $invalid;
        $repairs = $warnings = [];
        assert_eq([], HeroBlueprint::normalize($raw, 'authored', $repairs, $warnings)['source_order']);
        assert_eq(1, count($warnings));
    }
});

test('hero source-order checks inspect saved HTML and warn without rearranging content', function (): void {
    $markup = '<section id="hero"><!-- class="design-image" -->'
        . '<p class="design-copy">Keep this copy</p>'
        . '<figure class="design-image"><img src="subject.jpg" alt="Subject"></figure></section>';
    $before = $markup;
    $order = ['design-image', 'design-copy'];
    $warnings = HeroComposition::sourceOrderWarnings($markup, $order, 'plugin/pages/home.html', 'hero');
    assert_eq(1, count($warnings));
    foreach (['plugin/pages/home.html', 'source_order', 'design-image', 'design-copy', 'authored', 'delivered', 'disposition'] as $context) {
        assert_contains($context, $warnings[0]);
    }
    assert_eq($before, $markup);
    assert_eq($warnings, HeroComposition::sourceOrderWarnings($markup, $order, 'plugin/pages/home.html', 'hero'));
    assert_eq([], HeroComposition::sourceOrderWarnings($markup, array_reverse($order), 'home.html', 'hero'));
    assert_eq([], HeroComposition::sourceOrderWarnings($markup, [], 'home.html', 'hero'));
    // Another section's matching classes must not affect this opening's check.
    assert_eq([], HeroComposition::sourceOrderWarnings($markup . '<footer class="design-copy"></footer>', array_reverse($order), 'home.html', 'hero'));
    foreach ([
        '<section id="hero"><p class="design-copy">Copy</p></section>',
        '<section id="hero"><img class="design-image"><img class="design-image"><p class="design-copy">Copy</p></section>',
        '<section id="hero" class="design-image"><p class="design-copy">Copy</p></section>',
        '<section id="other"><img class="design-image"><p class="design-copy">Copy</p></section>',
    ] as $ambiguous) {
        assert_true(HeroComposition::sourceOrderWarnings($ambiguous, $order, 'home.html', 'hero') !== []);
    }
});

test('hero author self-check and intent use the existing home and inner-page requests', function (): void {
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $input = hero_unit_contract_input('authored', null);
    $input['hero_blueprint']['source_order'] = ['design-image', 'design-copy'];
    $request = (new Automattic\SiteBuild\Units\HeroUnit($llm, $renderer))->request($input);
    $inner = section_unit_input();
    $inner['authored_opening'] = true;
    $innerRequest = (new Automattic\SiteBuild\Units\SectionUnit($llm, $renderer))->request($inner);
    foreach ([$request, $innerRequest] as $candidate) {
        $prompt = sections_request_text($candidate);
        assert_contains('Before returning markup', $prompt);
        assert_contains('source_order', $prompt);
        assert_contains('not a universal image-first rule', $prompt);
    }
    assert_contains('design-image', sections_request_text($request));
    assert_true(!str_contains(sections_request_text($innerRequest), 'design-image'));
    $result = (new Automattic\SiteBuild\Units\HeroUnit($llm, $renderer))->finish(
        '<!-- wp:group {"anchor":"hero"} --><div id="hero" class="wp-block-group">'
        . '<!-- wp:paragraph {"className":"design-copy"} --><p class="design-copy">Copy</p><!-- /wp:paragraph -->'
        . '<!-- wp:image {"className":"design-image"} --><figure class="wp-block-image design-image"><img src="subject.jpg" alt="Subject"/></figure><!-- /wp:image -->'
        . '</div><!-- /wp:group -->',
        $input,
    );
    assert_contains('source_order', implode("\n", $result->warnings));
    assert_contains('Copy', $result->markup);
    assert_eq([], $llm->calls, 'request construction and advisory checks add no AI calls');
});

test('page styles receives scoped opening intent and reports final order drift in its single call', function (): void {
    with_project('hero-intent-css-', function (Project $project): void {
        $blueprint = HeroBlueprint::defaultFor('authored');
        $blueprint['composition'] = 'HOME-FOCAL-INTENT';
        $blueprint['source_order'] = ['design-image', 'design-copy'];
        $project->writeJson('designDirection.json', ['hero_blueprint' => $blueprint]);
        $project->writeJson('pages.json', ['pages' => [
            ['slug' => 'home', 'path' => '/', 'front' => true, 'sections' => [
                ['slug' => 'hero', 'role' => 'hero', 'purpose' => 'Home purpose', 'content_notes' => 'Home notes'],
            ]],
            ['slug' => 'about', 'path' => '/about/', 'front' => false, 'sections' => [
                ['slug' => 'hero', 'role' => 'hero', 'purpose' => 'INNER-PURPOSE', 'content_notes' => 'INNER-FOCAL-INTENT'],
            ]],
        ]]);
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText('theme/style.css', '/* foundation */');
        $home = '<section id="hero" class="design-hero"><p class="design-copy">Keep home copy</p><img class="design-image" src="subject.jpg"></section>';
        $inner = '<section id="hero" class="design-about"><p>Independent inner opening</p></section>';
        $project->writeText('plugin/pages/home.html', $home);
        $project->writeText('plugin/pages/about.html', $inner);
        $llm = new FakeLlm();
        $llm->queueText('.design-hero {display:grid;}');
        quietly(fn () => (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));
        assert_eq(1, count($llm->calls));
        foreach (['HOME-FOCAL-INTENT', 'INNER-FOCAL-INTENT', 'INNER-PURPOSE', 'source_order', 'WordPress constrained-layout', 'Do not use CSS reordering'] as $text) {
            assert_contains($text, $llm->calls[0]['prompt']);
        }
        assert_eq(1, substr_count($llm->calls[0]['prompt'], 'HOME-FOCAL-INTENT'));
        assert_contains('source_order', $project->readText('warnings.json'));
        assert_contains('plugin/pages/home.html', $project->readText('warnings.json'));
        assert_eq($home, $project->readText('plugin/pages/home.html'));
        assert_eq($inner, $project->readText('plugin/pages/about.html'));
        assert_contains('.design-hero {display:grid;}', $project->readText('theme/style.css'));
        $firstCss = $project->readText('theme/style.css');
        $firstWarnings = $project->readText('warnings.json');
        $llm->queueText('.design-hero {display:grid;}');
        quietly(fn () => (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));
        assert_eq(2, count($llm->calls), 'one CSS call per run, including resume');
        assert_eq($firstCss, $project->readText('theme/style.css'));
        assert_eq($firstWarnings, $project->readText('warnings.json'));
        assert_eq($home, $project->readText('plugin/pages/home.html'));
        assert_eq($inner, $project->readText('plugin/pages/about.html'));
    });
});

test('missing hero intent hooks warn even when page styles has no reason to call AI', function (): void {
    with_project('hero-intent-no-css-', function (Project $project): void {
        $blueprint = HeroBlueprint::defaultFor('authored');
        $blueprint['source_order'] = ['design-image', 'design-copy'];
        $project->writeJson('designDirection.json', ['hero_blueprint' => $blueprint]);
        $project->writeJson('pages.json', ['pages' => [
            ['slug' => 'home', 'front' => true, 'sections' => [['slug' => 'hero']]],
        ]]);
        $project->writeText('theme/style.css', '/* foundation */');
        $markup = '<section id="hero"><h1>Keep this opening</h1><img src="subject.jpg"></section>';
        $project->writeText('plugin/pages/home.html', $markup);
        $llm = new FakeLlm();
        quietly(fn () => (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));
        assert_eq([], $llm->calls);
        assert_contains('source_order', $project->readText('warnings.json'));
        assert_eq($markup, $project->readText('plugin/pages/home.html'));
    });
});
