<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\PromptRenderer;

test('default CSS discovers authored design hooks across multiple pages', function (): void {
    with_project('design-hooks-', function (Project $project): void {
        $project->writeText('theme/parts/header.html', '<div class="design-brand__masthead"></div>');
        $project->writeText('plugin/pages/home.html', '<div class="design-home-grid"></div>');
        $project->writeText('plugin/pages/about.html', '<div class="design-about-index"></div>');
        $classes = PageStylesStep::usedClasses($project);
        foreach (['design-brand__masthead', 'design-home-grid', 'design-about-index'] as $class) {
            assert_true(in_array($class, $classes, true), $class);
        }
    });
});

test('default CSS accepts long scoped responsive composition without a utility menu', function (): void {
    $css = str_repeat('.design-home-grid { display:grid; grid-template-columns:2fr 1fr; }' . "\n", 110)
        . '@media(max-width:600px){.design-home-grid {grid-template-columns:1fr;}}';
    assert_eq([], PageStylesStep::validate($css));
});

test('default authored CSS rejects scope escape while retaining resource and hiding checks', function (): void {
    foreach ([
        '.design-hero + main {color:var(--wp--preset--color--accent);}',
        '.design-hero, body {display:grid;}',
        '.design-hero {body {display:grid;}}',
        '.design-hero {background:url(https://example.test/tracker);}',
        '.design-hero {opacity:0;}',
    ] as $css) {
        assert_true(PageStylesStep::validate($css) !== [], $css);
    }
});

test('default CSS author receives inner-page and shared markup and preserves safe sibling rules on resume', function (): void {
    with_project('design-css-run-', function (Project $project): void {
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText('theme/style.css', '/* foundation */');
        $project->writeText('theme/parts/header.html', '<div class="design-masthead">Shared masthead</div>');
        $project->writeText('plugin/pages/home.html', '<div class="design-home">Home content</div>');
        $project->writeText('plugin/pages/about.html', '<div class="design-about">Inner-page content</div>');
        $llm = new FakeLlm();
        $css = '.design-home {display:grid;} .design-about {padding:2rem;} '
            . '.design-about + main {display:none;}';
        $llm->queueText($css);
        $llm->queueText($css);
        $step = new PageStylesStep($llm, new PromptRenderer(repo_path('prompts')));
        quietly(fn () => $step->run($project));
        foreach (['Shared masthead', 'Home content', 'Inner-page content'] as $content) {
            assert_contains($content, $llm->calls[0]['prompt']);
        }
        foreach (['Eyebrows are banned', 'Decorative numbering is banned', 'Lines and borders need a structural purpose'] as $rule) {
            assert_contains($rule, $llm->calls[0]['prompt']);
        }
        $first = $project->readText('theme/style.css');
        assert_contains('.design-home {display:grid;}', $first);
        assert_contains('.design-about {padding:2rem;}', $first);
        assert_true(!str_contains($first, 'display:none'));
        assert_true(!isset($project->readJson('warnings.json')['css_contrast']), 'layout-only rules are not contrast defects');
        foreach (['theme/style.css', 'authored', 'removed', 'disposition'] as $context) {
            assert_contains($context, $project->readText('warnings.json'));
        }
        quietly(fn () => $step->run($project));
        assert_eq($first, $project->readText('theme/style.css'));
    });
});

test('requested style is not subordinated to an industry material palette or forced creative tension', function (): void {
    $seed = file_get_contents(repo_path('prompts/design-direction-seeds.md'));
    $direction = file_get_contents(repo_path('prompts/design-direction.md'));
    assert_contains('recognizable visual language', $seed);
    assert_contains('Choose the budget AFTER', $seed);
    assert_contains('Recognizable style characteristics are not clichés to eliminate', $direction);
    foreach (['Name the one thing in the subject\'s OWN physical world', 'Let that sentence decide light or dark', 'A direction with no tension resolves into the category\'s default'] as $restriction) {
        assert_true(!str_contains($direction, $restriction));
    }
});

test('default CSS contrast checks resolve palette variables without shipping analysis definitions', function (): void {
    with_project('design-css-colors-', function (Project $project): void {
        $project->writeJson('theme/theme.json', ['version'=>3,'settings'=>['color'=>['palette'=>[
            ['slug'=>'base','color'=>'#ffffff'],['slug'=>'contrast','color'=>'#111111'],
        ]]]]);
        $project->writeText('theme/style.css', '/* foundation */');
        $project->writeText('plugin/pages/home.html', '<p class="design-copy">Visible copy</p>');
        $llm=new FakeLlm();
        $llm->queueText('.design-copy{color:var(--wp--preset--color--contrast);background:var(--wp--preset--color--base);}');
        quietly(fn()=>(new PageStylesStep($llm,new PromptRenderer(repo_path('prompts'))))->run($project));
        assert_true(!$project->exists('warnings.json'), 'readable resolved pair needs no unverified warning');
        assert_true(!str_contains($project->readText('theme/style.css'),'site-build-contrast-context'));
        assert_contains('var(--wp--preset--color--contrast)', $project->readText('theme/style.css'));
    });
});
