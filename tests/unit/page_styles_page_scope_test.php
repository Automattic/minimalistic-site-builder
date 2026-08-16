<?php
declare(strict_types=1);

use Automattic\SiteBuild\PageScope;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\FinalizeThemeStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * A design page is `site.css` plus that page's own `<style data-page-css>`
 * chunk, over the browser default. The HTML-first merge has to deliver that
 * same cascade: one page's element rules may not restyle another page, and a
 * heading its own design page never styles must fall back to the browser
 * default rather than to theme.json's invented heading scale.
 */

/** Seed a two-page HTML-first project whose inner page styles bare headings. */
function ps_scope_project(string $prefix, string $aboutCss, string $siteCss = ''): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('design/page-artifact-map.json', ['home' => 'home', 'about' => 'about']);
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true],
        ['slug' => 'about', 'front' => false],
    ]]);
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeText('design/home.html', '<main><h2>Home heading</h2></main>');
    $project->writeText(
        'design/about.html',
        '<style data-page-css>' . $aboutCss . '</style><main><h2>About heading</h2></main>'
    );
    return [$project, $tmp];
}

function ps_scope_step(): PageStylesStep
{
    return new PageStylesStep(
        new FakeLlm(),
        new \Automattic\SiteBuild\PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    );
}

/**
 * Every selector branch in $css that is not inside @keyframes, keyed by the
 * rule body, so a test can assert what a given declaration is scoped to.
 *
 * @return list<array{selector:string,body:string}>
 */
function ps_scope_rules(string $css): array
{
    $css = preg_replace('/@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $css) ?? $css;
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);
    $rules = [];
    foreach ($matches as $match) {
        $selectors = trim(preg_replace('/\/\*.*?\*\//s', '', $match[1]) ?? $match[1]);
        if ($selectors === '' || str_starts_with($selectors, '@')) {
            continue;
        }
        foreach (explode(',', $selectors) as $selector) {
            $rules[] = ['selector' => trim($selector), 'body' => $match[2]];
        }
    }
    return $rules;
}

test('an inner page CSS chunk cannot restyle another page bare element', function () {
    [$project, $tmp] = ps_scope_project(
        'ps_scope_bleed_',
        'h2{font-size:9rem;text-transform:uppercase;max-width:20ch;}'
    );

    ps_scope_step()->run($project);

    $style = $project->readText('theme/style.css');
    $scope = PageScope::bodyClass('about');
    assert_contains('font-size:9rem', $style, 'the authored rule still ships — nothing is dropped');
    foreach (ps_scope_rules($style) as $rule) {
        if (!str_contains($rule['body'], 'font-size:9rem')) {
            continue;
        }
        assert_true(
            str_contains($rule['selector'], $scope),
            "about's bare h2 rule reaches every page unscoped: {$rule['selector']}"
        );
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page scoping keeps the authored specificity so the design cascade is unchanged', function () {
    [$project, $tmp] = ps_scope_project(
        'ps_scope_specificity_',
        'h2{color:#111111;}',
        ".lede h2{color:#222222;}\n"
    );

    ps_scope_step()->run($project);

    $style = $project->readText('theme/style.css');
    foreach (ps_scope_rules($style) as $rule) {
        if (!str_contains($rule['body'], '#111111')) {
            continue;
        }
        // `:where()` contributes nothing, so the scoped branch still weighs
        // exactly as much as the `h2` the design wrote.
        assert_contains(
            ':where(.' . PageScope::bodyClass('about') . ') h2',
            $rule['selector'],
            'the scope is applied through :where() so specificity is untouched'
        );
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page scoping recurses into grouping at-rules and leaves keyframes alone', function () {
    [$project, $tmp] = ps_scope_project(
        'ps_scope_atrules_',
        '@media (min-width:40rem){h3{font-size:5rem;}}'
        . '@keyframes ps-scope-in{from{opacity:0;}to{opacity:1;}}'
    );

    ps_scope_step()->run($project);

    $style = $project->readText('theme/style.css');
    $scope = PageScope::bodyClass('about');
    assert_contains('@media (min-width:40rem)', $style, 'the media query still ships');
    foreach (ps_scope_rules($style) as $rule) {
        if (str_contains($rule['body'], 'font-size:5rem')) {
            assert_true(
                str_contains($rule['selector'], $scope),
                "a rule inside @media escaped the page scope: {$rule['selector']}"
            );
        }
    }
    // Keyframe selectors are not element selectors; scoping them breaks the
    // animation outright.
    assert_contains('from{opacity:0;}', $style, 'keyframe stops are carried verbatim');
    assert_true(
        !str_contains($style, $scope . ') from'),
        'keyframe selectors must not be scoped'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page scoping leaves document-level custom properties global and scopes body rules', function () {
    [$project, $tmp] = ps_scope_project(
        'ps_scope_root_',
        ':root{--ps-scope-ink:#0b0b0b;}body{background:#fefefe;}'
    );

    ps_scope_step()->run($project);

    $style = $project->readText('theme/style.css');
    $scope = PageScope::bodyClass('about');
    foreach (ps_scope_rules($style) as $rule) {
        if (str_contains($rule['body'], '--ps-scope-ink')) {
            assert_eq(':root', $rule['selector'], 'custom properties stay document-level');
        }
        if (str_contains($rule['body'], '#fefefe')) {
            assert_eq(
                'body:where(.' . $scope . ')',
                $rule['selector'],
                'a body rule scopes onto the body itself, not to a descendant'
            );
        }
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('carried design pages get the browser heading baseline before any author CSS', function () {
    [$project, $tmp] = ps_scope_project('ps_scope_baseline_', 'h2{font-size:9rem;}', "h1{color:#333333;}\n");

    ps_scope_step()->run($project);

    $style = $project->readText('theme/style.css');
    $baselineAt = strpos($style, 'text-transform: revert');
    assert_true(is_int($baselineAt), 'the heading baseline ships');
    // Every carried page is covered, so theme.json's invented heading scale
    // never reaches a heading the design left at the browser default.
    foreach (['home', 'about'] as $slug) {
        assert_contains(
            '.' . PageScope::bodyClass($slug),
            substr($style, $baselineAt - 400, 400),
            "the baseline covers the {$slug} page"
        );
    }
    assert_contains('font: revert', $style, 'family, size, weight and line-height roll back together');
    assert_true(
        $baselineAt < strpos($style, '#333333'),
        'the baseline lands before the design CSS so every authored rule outranks it'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('a resumed merge replaces the tail an earlier revision of the merge left behind', function () {
    [$project, $tmp] = ps_scope_project('ps_scope_resume_', 'h2{font-size:9rem;}', ".site{display:grid;}\n");
    $scaffold = $project->readText('theme/style.css');
    // What a build on older code left in the theme: the same foundation, with
    // that revision's unscoped design CSS behind it.
    $project->writeText(
        'theme/style.css',
        $scaffold . PageStylesStep::WORD_WRAP_CSS . "\n" . PageStylesStep::TABLE_BORDER_RESET_CSS
            . "\n.site{display:grid;}\nh2{font-size:9rem;}"
    );

    ps_scope_step()->run($project);

    $style = $project->readText('theme/style.css');
    assert_eq(
        1,
        substr_count($style, PageStylesStep::WORD_WRAP_CSS),
        'the stale merge is replaced, not stacked under a second one'
    );
    foreach (ps_scope_rules($style) as $rule) {
        if (!str_contains($rule['body'], 'font-size:9rem')) {
            continue;
        }
        assert_contains(
            PageScope::bodyClass('about'),
            $rule['selector'],
            'no unscoped copy of the inner page rule survives the resume'
        );
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('finalize-theme publishes the page scope class WordPress needs on the body', function () {
    $tmp = sys_get_temp_dir() . '/ps_scope_functions_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_contains("add_filter('body_class'", $php, 'the scope class is published to body_class');
    assert_contains(PageScope::CLASS_PREFIX, $php, 'the published class uses the shared scope prefix');
    assert_contains("get_post_field('post_name'", $php, 'the scope is keyed on the page slug');
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($project->themePath('functions.php')) . ' 2>&1', $out, $rc);
    assert_eq(0, $rc, implode("\n", $out));

    exec('rm -rf ' . escapeshellarg($tmp));
});
