<?php
declare(strict_types=1);

use Automattic\SiteBuild\CssContrastCheck;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * PageStylesStep: the layout-only CSS-appendix validator (namespaced selectors
 * only, preset variables for color, @media only), the used-class scan, and the
 * run behavior (skip when unused, append when valid, reject-and-skip when
 * invalid). Motion timing and hover behavior belong to the static motion kit.
 */

const PS_VALID_CSS = <<<CSS
    .overlap-up {
        margin-top: -4rem;
        position: relative;
        z-index: 2;
    }
    .masonry-3 {
        columns: 3;
        column-gap: 1.5rem;
    }
    .masonry-3 > * {
        break-inside: avoid;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 1024px) {
        .masonry-3 {
            columns: 2;
        }
    }
    @media (max-width: 600px) {
        .masonry-3 {
            columns: 1;
        }
    }
    CSS;

/**
 * Chunk one of every HTML-first merge: the deterministic wrap policy, which
 * ships whether or not the design contributed any CSS.
 */
function ps_wrap(): string
{
    return PageStylesStep::WORD_WRAP_CSS . "\n";
}

/** Foundation chunk shipped right after the wrap policy, before any design CSS. */
function ps_table_reset(): string
{
    return PageStylesStep::TABLE_BORDER_RESET_CSS . "\n";
}

function ps_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('design/page-artifact-map.json', [
        'home' => 'home',
        'about' => 'about',
        'zeta' => 'zeta',
    ]);
    return [$project, $tmp];
}

function ps_warning_rows(Project $project, string $stepId): array
{
    if (!$project->exists('warnings.json')) {
        return [];
    }
    return $project->readJson('warnings.json')[$stepId] ?? [];
}

/** @return list<string> */
function ps_css_bodies_for_selector(string $css, string $wanted): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);
    $bodies = [];
    foreach ($matches as $match) {
        $selectorList = preg_replace('/\/\*.*?\*\//s', '', $match[1]);
        if (!is_string($selectorList)) {
            continue;
        }
        foreach (explode(',', $selectorList) as $selector) {
            if (trim($selector) === $wanted) {
                $bodies[] = $match[2];
            }
        }
    }
    return $bodies;
}

function ps_assert_no_root_inline_padding(string $css, string $selector): void
{
    foreach (ps_css_bodies_for_selector($css, $selector) as $body) {
        assert_true(
            preg_match('/(?:^|;)\s*(?:padding|padding-inline|padding-left|padding-right)\s*:/i', $body) !== 1,
            "{$selector} retains root-owned inline padding in {$body}",
        );
    }
}

function ps_html_first_step(FakeLlm $llm): PageStylesStep
{
    return new PageStylesStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        htmlFirst: true,
    );
}

test('validate accepts namespaced classes, preset vars, and media queries', function () {
    assert_eq([], PageStylesStep::validate(PS_VALID_CSS));
});

test('validate accepts color-mix over preset variables', function () {
    $css = ".overlap-up {\n    background: color-mix(in srgb, var(--wp--preset--color--contrast) 20%, transparent);\n}";
    assert_eq([], PageStylesStep::validate($css));
});

test('validate rejects global and element selectors', function () {
    foreach ([
        "body {\n    margin: 0;\n}",
        "img {\n    display: block;\n}",
        ":root {\n    --x: 1;\n}",
        "* {\n    box-sizing: border-box;\n}",
        ".my-own-class {\n    color: var(--wp--preset--color--primary);\n}",
        ".overlap-up, p {\n    margin-top: -3rem;\n}", // one bad selector in a list
        ".overlap-upper {\n    margin-top: -3rem;\n}", // prefix must not false-match
    ] as $css) {
        $problems = PageStylesStep::validate($css);
        assert_true($problems !== [], "should reject: {$css}");
    }
});

test('validate rejects raw color literals', function () {
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    color: #fff;\n}"), 'hex');
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);\n}"), 'rgba');
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    color: hsl(20 10% 20%);\n}"), 'hsl');
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    color: red;\n}"), 'named color');
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    box-shadow: 0 10px 30px black;\n}"), 'named shadow color');
});

test('validate rejects disallowed at-rules and url()', function () {
    assert_true([] !== PageStylesStep::validate("@import 'x.css';\n.overlap-up {\n    color: var(--wp--preset--color--base);\n}"), '@import');
    assert_true([] !== PageStylesStep::validate("@keyframes spin {\n    to { transform: rotate(1turn); }\n}"), '@keyframes');
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    background: url(x.png);\n}"), 'url()');
    assert_true(
        [] !== PageStylesStep::validate(".overlap-up {\n    background-image: image-set(\"https://example.invalid/t.png\" 1x);\n}"),
        'image-set()'
    );
});

test('validate rejects motion overrides even under an allowed layout selector', function () {
    $problems = PageStylesStep::validate(
        ".overlap-up {\n    --motion-enter-duration: 150ms;\n    margin-top: -4rem;\n}"
    );
    assert_contains('profile-owned', implode('; ', $problems));
});

test('validate rejects corner setters and all resets only on owned image/button selectors', function () {
    foreach ([
        'border-radius',
        'border-top-left-radius',
        'border-start-start-radius',
        '-webkit-border-radius',
    ] as $property) {
        $problems = PageStylesStep::validate(
            ".masonry-3 .wp-block-image img {\n    {$property}: 9999px !important;\n}",
        );
        assert_contains('shape-owned', implode('; ', $problems), "rejects {$property}");
    }

    $problems = PageStylesStep::validate(
        ".masonry-3 .wp-block-button__link {\n    all: initial !important;\n}",
    );
    assert_contains('shape-owned', implode('; ', $problems), 'CSS-wide reset cannot bypass button radius');

    foreach ([
        '.masonry-3 > *',
        '.masonry-3 > a',
        '.masonry-3 [class]',
        '.masonry-3 :not(.card)',
    ] as $selector) {
        $problems = PageStylesStep::validate(
            "{$selector} {\n    border-radius: 9999px !important;\n}",
        );
        assert_contains('shape-owned', implode('; ', $problems), "broad subject {$selector}");
        [$salvaged, $dropped] = PageStylesStep::dropOffendingDeclarations(
            "{$selector} { border-radius: 9999px !important; color: inherit; }",
        );
        assert_eq(1, count($dropped), "salvages broad subject {$selector}");
        assert_true(!str_contains($salvaged, 'border-radius'));
        assert_contains('color: inherit', $salvaged);
    }

    assert_eq(
        [],
        PageStylesStep::validate(
            ".overlap-up, .overlap-up .card {\n    border-radius: 1rem;\n    --card-border-radius: 1rem;\n    all: revert-layer;\n}",
        ),
        'generic utility/card geometry remains outside image/button ownership',
    );
});

test('shape scanning ignores declaration-looking text inside quoted and custom-property values', function () {
    $css = '.masonry-3::before { content: "foo; border-radius: 2rem"; '
        . '--card-template: { border-radius: 3rem; }; margin:0; }';

    assert_eq([], PageStylesStep::validate($css));
    [$salvaged, $dropped] = PageStylesStep::dropOffendingDeclarations($css);
    assert_eq($css, $salvaged);
    assert_eq([], $dropped);
});

test('validate rejects CSS that hides generated content', function () {
    foreach ([
        ".masonry-3 > * {\n    opacity: 0;\n}",
        ".masonry-3 > * {\n    opacity: 0%;\n}",
        ".masonry-3 > * {\n    opacity: calc(0);\n}",
        ".masonry-3 .caption {\n    visibility: hidden;\n}",
        ".masonry-3 .wp-block-image {\n    display: none;\n}",
    ] as $css) {
        $problems = PageStylesStep::validate($css);
        assert_true($problems !== [], "should reject hidden content: {$css}");
    }
});

test('validate rejects empty, oversized, and unbalanced CSS', function () {
    assert_eq(['empty CSS'], PageStylesStep::validate("  \n "));
    $long = str_repeat(".overlap-up {\n    opacity: 1;\n}\n", 40); // 120 lines
    assert_true([] !== PageStylesStep::validate($long), 'over the line ceiling');
    assert_true([] !== PageStylesStep::validate(".overlap-up {\n    opacity: 1;\n"), 'unbalanced braces');
    assert_true(
        in_array('unbalanced braces', PageStylesStep::validate("}\n.overlap-up {\n    opacity: 1;\n}\n@media (min-width: 600px) {"), true),
        'stray closing brace balanced by a trailing open brace'
    );
});

test('dropOffendingDeclarations removes bad declarations and keeps the rest', function () {
    // The real-world failure shape (tbilisi20): a shadow var() with a raw
    // rgba() fallback poisoning an otherwise-valid appendix.
    $css = "/* utilities */\n"
        . ".overlap-up {\n"
        . "    margin-top: -4rem;\n"
        . "    position: relative;\n"
        . "    box-shadow: var(--wp--preset--shadow--glow, 0 12px 30px rgba(0,0,0,0.6));\n"
        . "    --motion-enter-duration: 150ms;\n"
        . "    z-index: 2;\n"
        . "}\n"
        . ".masonry-3 .wp-block-image img {\n"
        . "    border-radius: 9999px !important;\n"
        . "}\n"
        . "@media (max-width: 600px) {\n"
        . "    .masonry-3 {\n"
        . "        columns: 1;\n"
        . "        opacity: 0;\n"
        . "    }\n"
        . "}";
    [$salvaged, $dropped] = PageStylesStep::dropOffendingDeclarations($css);

    assert_eq(4, count($dropped), 'four offending declarations dropped');
    assert_contains('raw color literal', implode('; ', $dropped));
    assert_contains('profile-owned', implode('; ', $dropped));
    assert_contains('hides content', implode('; ', $dropped));
    assert_contains('shape-owned', implode('; ', $dropped));
    assert_contains('margin-top: -4rem', $salvaged, 'clean declarations kept');
    assert_contains('z-index: 2', $salvaged, 'declarations after a dropped one kept');
    assert_contains('columns: 1', $salvaged, 'media-nested rule bodies salvaged too');
    assert_contains('@media (max-width: 600px)', $salvaged, 'media prelude untouched');
    assert_true(!str_contains($salvaged, 'rgba'), 'raw color gone');
    assert_true(!str_contains($salvaged, '--motion-'), 'motion override gone');
    assert_true(!str_contains($salvaged, 'opacity'), 'hidden-content declaration gone');
    assert_true(!str_contains($salvaged, 'border-radius'), 'shape override gone');
    assert_eq([], PageStylesStep::validate($salvaged), 'salvaged CSS validates clean');
});

test('dropOffendingDeclarations leaves structural problems for the re-validation', function () {
    [$salvaged, $dropped] = PageStylesStep::dropOffendingDeclarations(
        ":root {\n    --motion-enter-duration: 150ms;\n}\n.overlap-up {\n    margin-top: -4rem;\n}"
    );
    assert_eq(1, count($dropped), 'the declaration is dropped');
    assert_contains(':root', $salvaged, 'the unscoped selector is NOT repaired away');
    assert_true(PageStylesStep::validate($salvaged) !== [], 'still rejected on the selector');
});

test('run drops offending declarations and ships the rest of the appendix', function () {
    [$project, $tmp] = ps_project('builder_ps_salvage_');
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up"></div><!-- /wp:group -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(
        ".overlap-up {\n"
        . "    margin-top: -4rem;\n"
        . "    position: relative;\n"
        . "    z-index: 2;\n"
        . "    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.55);\n"
        . "}\n"
        . ".overlap-up .wp-block-image img {\n"
        . "    border-radius: 9999px !important;\n"
        . "}"
    );

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains('margin-top: -4rem', $style, 'appendix shipped');
    assert_contains('page-styles step', $style, 'marker comment present');
    assert_true(!str_contains($style, 'rgba'), 'offending declaration not shipped');
    assert_true(!str_contains($style, 'border-radius'), 'shape-owned declaration not shipped');
    $log = $project->readText('logs/page-styles.log');
    assert_contains('SALVAGED', $log);
    assert_contains('raw color literal', $log);
    assert_contains('shape-owned', $log);
    // Each dropped declaration is recorded durably for the repair pass.
    $joined = implode(' ', $project->readJson('warnings.json')['page-styles'] ?? []);
    assert_contains('dropped offending CSS declaration', $joined);
    assert_contains('border-radius: 9999px !important', $joined);
    assert_contains('delivered removed', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('classesIn finds layout utilities only and ignores static hover classes', function () {
    $markup = '<!-- wp:group {"className":"masonry-3 hover-lift hover-reveal"} -->'
        . '<div class="wp-block-group masonry-3 hover-lift hover-reveal"></div><!-- /wp:group -->'
        . '<div class="hover-lifted masonry-30"></div>'; // near-misses must not match
    assert_eq(['masonry-3'], PageStylesStep::classesIn($markup));
    assert_eq([], PageStylesStep::classesIn('<div class="plain"></div>'));
    assert_true(!array_key_exists('hover-lift', PageStylesStep::CLASSES));
    assert_true(!array_key_exists('hover-reveal', PageStylesStep::CLASSES));
});

test('run skips without an LLM call when no utility class is used', function () {
    [$project, $tmp] = ps_project('builder_ps_skip_');
    $project->writeText('theme/parts/section-hero.html', '<!-- wp:heading --><h2>Hi</h2><!-- /wp:heading -->');
    $before = $project->readText('theme/style.css');
    $llm = new FakeLlm(); // nothing queued: any call would throw

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq([], $llm->calls, 'no LLM call made');
    assert_eq($before, $project->readText('theme/style.css'), 'style.css untouched');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('legacy mode ignores stale site CSS and keeps the recorded call trace and style bytes', function () {
    [$project, $tmp] = ps_project('builder_ps_legacy_control_');
    $project->writeText('theme/style.css', "/*\nTheme Name: Legacy Control\n*/\n");
    $project->writeText(TransformArtifacts::SITE_CSS, '/* STALE-HTML-FIRST-CSS */');
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up"></div><!-- /wp:group -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(
        ".overlap-up {\n"
        . "    margin-top: -4rem;\n"
        . "    position: relative;\n"
        . "    z-index: 2;\n"
        . "}"
    );

    (new PageStylesStep(
        $llm,
        new PromptRenderer(repo_path('prompts')),
        'legacy-model',
        0.25,
    ))->run($project);

    assert_eq(1, $llm->completeCalls, 'legacy path makes one serial text call');
    assert_eq(0, $llm->completeBatchCalls, 'legacy path makes no batch call');
    assert_eq(1, count($llm->calls), 'legacy call trace count');
    assert_eq(
        'cf47f535f6644e9fa5e9f17d5c32cf68e7ff1bf0664f01c30a6806791544757a',
        hash('sha256', $llm->calls[0]['prompt']),
        'legacy prompt bytes'
    );
    assert_eq(
        [
            'log_label'   => 'page-styles',
            'model'       => 'legacy-model',
            'temperature' => 0.25,
        ],
        $llm->calls[0]['opts'],
        'legacy call options'
    );
    assert_eq(
        "/*\nTheme Name: Legacy Control\n*/\n\n"
        . "/* Layout utilities — generated per-design by the page-styles step. */\n"
        . ".overlap-up {\n"
        . "    margin-top: -4rem;\n"
        . "    position: relative;\n"
        . "    z-index: 2;\n"
        . "}\n",
        $project->readText('theme/style.css'),
        'legacy style.css bytes'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('page-styles declares HTML-first design and delivered markup reads only when enabled', function () {
    $llm = new FakeLlm();
    $renderer = new PromptRenderer(repo_path('prompts'));
    $legacyReads = [
        'pages.json',
        'theme/theme.json',
        'theme/style.css',
        'designDirection.json',
        'theme/parts/*',
        'theme/templates/*',
    ];

    assert_eq(
        $legacyReads,
        (new PageStylesStep($llm, $renderer))->declaration()->reads,
        'legacy declaration stays unchanged',
    );
    assert_eq(
        [...$legacyReads, 'design/page-artifact-map.json', 'design/*', 'plugin/pages/*'],
        (new PageStylesStep($llm, $renderer, htmlFirst: true))->declaration()->reads,
        'HTML-first declaration covers every deterministic CSS and delivered-markup input',
    );
});

test('site CSS path adjusts only the merged tail against delivered markup and reaches a fixed point', function () {
    [$project, $tmp] = ps_project('builder_ps_contrast_tail_');
    $base = ".scaffold { color: #777777; background: #ffffff; }\n";
    $tail = ".copy { color: #777777; background: #ffffff; }\n"
        . ".panel .inherited { color: #777777; }\n";
    $markup = '<div class="scaffold">Scaffold</div>'
        . '<p class="copy">Tail</p>'
        . '<section class="panel"><p class="inherited">Inherited</p></section>';
    $project->writeText('theme/style.css', $base);
    $project->writeText(TransformArtifacts::SITE_CSS, $tail);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Source</main>');
    $project->writeText('plugin/pages/home.html', $markup);
    $llm = new FakeLlm();
    $step = ps_html_first_step($llm);
    $findings = CssContrastCheck::check($tail, $markup);
    $failed = array_values(array_filter(
        $findings,
        static fn (array $finding): bool => $finding['status'] === 'fail',
    ));
    assert_eq(1, count($failed), 'test control has one repairable tail failure');

    $step->run($project);

    $once = $project->readText('theme/style.css');
    assert_contains($base, $once, 'pre-existing scaffold bytes stay untouched');
    assert_eq(1, substr_count($once, '#777777; background: #ffffff'), 'only scaffold retains failing pair');
    assert_contains('color: ' . $failed[0]['suggested'], $once, 'tail text color adjusted');
    $warnings = $project->readJson('warnings.json')['css_contrast'] ?? [];
    assert_eq(2, count($warnings), 'adjusted and unverified findings both remain durable');
    assert_contains('disposition=adjusted', implode("\n", $warnings));
    assert_contains('disposition=unverified', implode("\n", $warnings));

    $step->run($project);

    assert_eq($once, $project->readText('theme/style.css'), 'second run preserves final CSS bytes');
    assert_eq($warnings, $project->readJson('warnings.json')['css_contrast'] ?? [], 'warnings deduplicate');
    assert_eq([], $llm->calls, 'deterministic path makes zero LLM calls');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path skips a source HTML file when its page has a failed marker', function () {
    [$project, $tmp] = ps_project('builder_ps_failed_source_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $project->writeText(TransformArtifacts::SITE_CSS, '.site{display:grid;}');
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'home', 'front' => true],
        ['slug' => 'about', 'front' => false],
    ]]);
    $project->writeText('design/home.html', '<style data-page-css>.home{padding:1rem;}</style>');
    $project->writeText('design/about.failed', 'malformed inner page');
    $project->writeText(
        'design/about.html',
        '<style data-page-css>.stale-failed-source{position:fixed;}</style>',
    );
    $project->writeText('plugin/pages/home.html', '<p>Home</p>');
    $project->writeText('plugin/pages/about.html', '<p>Legacy about</p>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . ".site{display:grid;}\n.home{padding:1rem;}",
        $project->readText('theme/style.css'),
        'failed page contributes no source HTML CSS',
    );
    assert_true(!str_contains($project->readText('theme/style.css'), 'stale-failed-source'));
    assert_eq([], $llm->calls);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path merges exact safe bytes in deterministic source and document order', function () {
    [$project, $tmp] = ps_project('builder_ps_deterministic_');
    $base = "/* existing theme CSS */\n.base{display:block;}\n";
    $siteCss = "/* site */\n.site{padding:1rem;}\n";
    $home = '<main>Alpha</main>'
        . "<style data-page-css>\n.alpha-one { margin: 1rem; }\n</style>"
        . '<style data-page-css="page">.alpha-two{padding:2rem;}</style>';
    $zeta = '<style class="ignored">.ignored{display:none;}</style>'
        . '<style data-page-css>.zeta{gap:3rem;}</style>';
    $carriedBefore = ".be-inline-geometry-1{width:42%;}\n";
    $carriedAfter = ".blocks-engine-after-author{display:block!important;}\n";
    $project->writeText('theme/style.css', $base);
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [
        ['slug' => 'zeta', 'front' => false],
        ['slug' => 'home', 'front' => true],
    ]]);
    $project->writeText('design/zeta.html', $zeta);
    $project->writeText('design/home.html', $home);
    $project->writeText(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR, $carriedBefore);
    $project->writeText(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR, $carriedAfter);
    $sourceBytes = [
        TransformArtifacts::SITE_CSS    => $project->readText(TransformArtifacts::SITE_CSS),
        'design/home.html'              => $project->readText('design/home.html'),
        'design/zeta.html'              => $project->readText('design/zeta.html'),
        TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR => $project->readText(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR),
        TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR => $project->readText(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR),
    ];
    $llm = new FakeLlm();
    $step = ps_html_first_step($llm);

    $step->run($project);

    $once = $project->readText('theme/style.css');
    assert_eq(
        $base
        . ps_wrap()
        . ps_table_reset()
        . $carriedBefore
        . "\n"
        . $siteCss
        . "\n\n.alpha-one { margin: 1rem; }\n"
        . "\n.alpha-two{padding:2rem;}"
        . "\n.zeta{gap:3rem;}"
        . "\n"
        . $carriedAfter,
        $once,
        'before-author carry, site, sorted design files, document order, then after-author carry'
    );
    assert_eq([], $llm->calls, 'deterministic path makes zero LLM calls');
    assert_eq(0, $llm->completeCalls, 'deterministic path skips legacy complete');
    assert_true(
        !str_contains($once, 'Layout utilities — generated per-design'),
        'deterministic path introduces no legacy marker'
    );
    foreach ($sourceBytes as $source => $bytes) {
        assert_eq($bytes, $project->readText($source), "{$source} remains byte-identical");
    }

    $step->run($project);

    assert_eq($once, $project->readText('theme/style.css'), 'second run is byte-identical');
    assert_eq([], $llm->calls, 'second deterministic run still makes zero LLM calls');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('G3 stylesheet placement brackets repo author CSS in final theme stylesheet', function () {
    [$project, $tmp] = ps_project('builder_ps_support_placement_');
    $before = '.engine-before-author{margin:0;}';
    $author = '.repo-author-marker{color:#123456;}';
    $after = '.engine-after-author{display:block!important;}';
    $project->writeText(TransformArtifacts::CARRIED_CSS_BEFORE_AUTHOR, $before);
    $project->writeText(TransformArtifacts::SITE_CSS, $author);
    $project->writeText(TransformArtifacts::CARRIED_CSS_AFTER_AUTHOR, $after);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><p>Home</p></main>');

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    $beforeAt = strpos($style, $before);
    $authorAt = strpos($style, $author);
    $afterAt = strpos($style, $after);
    assert_true(is_int($beforeAt), 'before-author rule reaches final theme CSS');
    assert_true(is_int($authorAt), 'repo author rule reaches final theme CSS');
    assert_true(is_int($afterAt), 'after-author rule reaches final theme CSS');
    assert_true($beforeAt < $authorAt && $authorAt < $afterAt, 'before-author < repo author < after-author');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path neutralizes core/table cell borders before authored table rules', function () {
    [$project, $tmp] = ps_project('ps-table-reset-');
    // Authored bottom-only row rule, the common table idiom.
    $siteCss = ".ledger td{border-bottom:1px solid #ccc;}\n";
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main>x</main>');
    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    // The core/table default-border reset ships…
    assert_true(str_contains($style, PageStylesStep::TABLE_BORDER_RESET_CSS), 'table-border reset present');
    // …and lands BEFORE the authored border rule, so the authored bottom border
    // wins on the bottom edge while the reset zeroes the other three sides.
    assert_true(
        strpos($style, '.wp-block-table td') < strpos($style, '.ledger td'),
        'reset precedes the authored table rule'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path removes section-root inline padding and preserves nested selector branches', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_insets_');
    $siteCss = <<<'CSS'
#hero {
    padding: 4rem clamp(1.25rem, 5vw, 4.5rem) 6rem;
    padding-left: 2rem;
    color: var(--wp--preset--color--contrast);
}
#story {
    padding-inline: 3rem;
    padding-left: 2rem;
    padding-right: 4rem;
    padding-top: 5rem;
    padding-bottom: 6rem;
}
#hero .child {
    padding: 1rem 2rem 3rem;
    padding-left: 0.75rem;
}
#hero, #hero .badge, .shared {
    padding: 7rem 8rem 9rem 10rem;
    margin: 0;
}
#card {
    padding-inline: 11rem;
}
#source-only {
    padding: 12rem 13rem;
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText(
        'design/home.html',
        '<main><section id="source-only"><p>Not delivered</p></section></main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero"><div class="child badge">Hero</div></section>'
            . '<section id="story"><p>Story</p><div id="card">Card</div></section>',
    );
    $step = ps_html_first_step(new FakeLlm());

    $step->run($project);

    $style = $project->readText('theme/style.css');
    ps_assert_no_root_inline_padding($style, '#hero');
    ps_assert_no_root_inline_padding($style, '#story');
    assert_contains('padding-top: 4rem', implode("\n", ps_css_bodies_for_selector($style, '#hero')));
    assert_contains('padding-bottom: 6rem', implode("\n", ps_css_bodies_for_selector($style, '#hero')));
    assert_contains('padding-top: 5rem', implode("\n", ps_css_bodies_for_selector($style, '#story')));
    assert_contains('padding-bottom: 6rem', implode("\n", ps_css_bodies_for_selector($style, '#story')));
    assert_contains(
        'padding: 1rem 2rem 3rem;',
        implode("\n", ps_css_bodies_for_selector($style, '#hero .child')),
        'nested shorthand survives',
    );
    assert_contains(
        'padding-left: 0.75rem;',
        implode("\n", ps_css_bodies_for_selector($style, '#hero .child')),
        'nested physical padding survives',
    );
    assert_contains(
        'padding: 7rem 8rem 9rem 10rem;',
        implode("\n", ps_css_bodies_for_selector($style, '#hero .badge')),
        'nested mixed-selector branch keeps its padding',
    );
    assert_contains(
        'padding: 7rem 8rem 9rem 10rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.shared')),
        'unrelated mixed-selector branch keeps its padding',
    );
    assert_contains('padding-inline: 11rem;', implode("\n", ps_css_bodies_for_selector($style, '#card')));
    assert_contains(
        'padding: 12rem 13rem;',
        implode("\n", ps_css_bodies_for_selector($style, '#source-only')),
        'source-only section id does not expand final delivered root scope',
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'source CSS stays byte-identical');

    $step->run($project);

    assert_eq($style, $project->readText('theme/style.css'), 'neutralized merge reaches a fixed point');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path matches tag and class selectors only against delivered section roots', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_root_elements_');
    $siteCss = <<<'CSS'
section {
    padding: 2rem 3rem 4rem;
}
.foundation-section {
    padding-left: 5rem;
    padding-right: 6rem;
    padding-top: 7rem;
}
.column-plate {
    padding: 8rem 9rem;
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section class="source-only"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="story" class="foundation-section">'
            . '<div class="column-plate">Inner content</div></section>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    ps_assert_no_root_inline_padding($style, 'section');
    ps_assert_no_root_inline_padding($style, '.foundation-section');
    assert_contains(
        'padding-top: 2rem',
        implode("\n", ps_css_bodies_for_selector($style, 'section')),
        'tag-targeted root keeps vertical shorthand start',
    );
    assert_contains(
        'padding-bottom: 4rem',
        implode("\n", ps_css_bodies_for_selector($style, 'section')),
        'tag-targeted root keeps vertical shorthand end',
    );
    assert_contains(
        'padding-top: 7rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.foundation-section')),
        'class-targeted root keeps vertical padding',
    );
    assert_contains(
        'padding: 8rem 9rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.column-plate')),
        'inner class padding stays byte-for-byte authored',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path strips unsupported trailing pseudos before matching delivered section roots', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_trailing_pseudo_');
    $siteCss = <<<'CSS'
.foundation-section:nth-of-type(1) {
    padding-left: 3rem;
    padding-top: 4rem;
}
.column-plate {
    padding: 5rem 6rem;
}
#story:before {
    padding-left: 7rem;
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section class="source-only"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="story" class="foundation-section">'
            . '<div class="column-plate">Inner content</div></section>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    ps_assert_no_root_inline_padding($style, '.foundation-section:nth-of-type(1)');
    assert_contains(
        'padding-top: 4rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.foundation-section:nth-of-type(1)')),
        'unsupported trailing pseudo keeps vertical padding on delivered root',
    );
    assert_contains(
        'padding: 5rem 6rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.column-plate')),
        'inner class rule remains byte-for-byte authored',
    );
    assert_contains(
        'padding-left: 7rem;',
        implode("\n", ps_css_bodies_for_selector($style, '#story:before')),
        'legacy pseudo-element rule does not target the section-root box',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path recognizes functional attribute and escaped final-subject section selectors', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_selector_forms_');
    $siteCss = <<<'CSS'
:is(#hero) {
    padding: 1rem 2rem 3rem;
}
section:where(#hero) {
    padding-left: 4rem;
    padding-top: 5rem;
}
section[id="hero"] {
    padding-right: 6rem;
    padding-bottom: 7rem;
}
#\68 ero {
    padding-inline: 8rem;
}
:is(#hero), .shared {
    padding: 9rem 10rem 11rem 12rem;
    margin: 0;
}
#hero .child {
    padding: 13rem 14rem;
}
main #hero .descendant {
    padding-left: 15rem;
}
:not(#hero) {
    padding-right: 16rem;
}
:has(#hero) {
    padding-inline: 17rem;
}
#hero::before {
    padding: 18rem 19rem;
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero"><div class="child descendant">Hero</div></section>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    foreach ([':is(#hero)', 'section:where(#hero)', 'section[id="hero"]', '#\68 ero'] as $selector) {
        ps_assert_no_root_inline_padding($style, $selector);
    }
    assert_contains('padding-top: 1rem', implode("\n", ps_css_bodies_for_selector($style, ':is(#hero)')));
    assert_contains('padding-bottom: 3rem', implode("\n", ps_css_bodies_for_selector($style, ':is(#hero)')));
    assert_contains('padding-top: 5rem;', implode("\n", ps_css_bodies_for_selector($style, 'section:where(#hero)')));
    assert_contains(
        'padding-bottom: 7rem;',
        implode("\n", ps_css_bodies_for_selector($style, 'section[id="hero"]')),
    );
    assert_contains(
        'padding: 9rem 10rem 11rem 12rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.shared')),
        'unrelated mixed functional-selector branch keeps exact padding',
    );
    foreach (
        [
            '#hero .child' => 'padding: 13rem 14rem;',
            'main #hero .descendant' => 'padding-left: 15rem;',
            ':not(#hero)' => 'padding-right: 16rem;',
            ':has(#hero)' => 'padding-inline: 17rem;',
            '#hero::before' => 'padding: 18rem 19rem;',
        ] as $selector => $declaration
    ) {
        assert_contains(
            $declaration,
            implode("\n", ps_css_bodies_for_selector($style, $selector)),
            "{$selector} does not target the section-root box",
        );
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path neutralizes escaped section-root padding property names', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_escaped_properties_');
    $siteCss = <<<'CSS'
#hero {
    padd\69ng: 2rem 3rem 4rem 5rem;
    padding-\6c eft: 6rem;
    color: var(--wp--preset--color--contrast);
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText('plugin/pages/home.html', '<section id="hero"><p>Hero</p></section>');

    ps_html_first_step(new FakeLlm())->run($project);

    $body = implode("\n", ps_css_bodies_for_selector($project->readText('theme/style.css'), '#hero'));
    assert_true(!str_contains($body, 'padd\69ng'), 'escaped padding shorthand removed');
    assert_true(!str_contains($body, 'padding-\6c eft'), 'escaped padding-left removed');
    assert_contains('padding-top: 2rem', $body);
    assert_contains('padding-bottom: 4rem', $body);
    assert_contains('color: var(--wp--preset--color--contrast);', $body, 'sibling declaration survives');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path neutralizes outer padding through valid CSS nesting without touching children', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_css_nesting_');
    $siteCss = <<<'CSS'
#hero {
    padding: 1rem 2rem 3rem;
    color: var(--wp--preset--color--contrast);
    & .child {
        padding: 4rem 5rem;
    }
    @media (min-width: 40rem) {
        & .child {
            padding-left: 6rem;
        }
    }
}
@media (min-width: 60rem) {
    #hero {
        padding: 7rem 8rem 9rem 10rem;
    }
    #hero .child {
        padding-right: 11rem;
    }
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText('plugin/pages/home.html', '<section id="hero"><div class="child">Child</div></section>');

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, 'padding: 1rem 2rem 3rem;'), 'outer nested-rule padding removed');
    assert_contains('padding-top: 1rem;', $style);
    assert_contains('padding-bottom: 3rem;', $style);
    assert_contains('padding: 4rem 5rem;', $style, 'nested child shorthand survives');
    assert_contains('padding-left: 6rem;', $style, 'child padding inside nested at-rule survives');
    assert_true(!str_contains($style, 'padding: 7rem 8rem 9rem 10rem;'), 'root padding inside media removed');
    assert_contains('padding-top: 7rem;', $style);
    assert_contains('padding-bottom: 9rem;', $style);
    assert_contains('padding-right: 11rem;', $style, 'descendant padding inside media survives');
    assert_true(
        !str_contains(
            implode("\n", ps_warning_rows($project, 'page-styles')),
            'retained malformed CSS',
        ),
        'valid CSS nesting does not degrade as malformed',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path removes opaque variable padding shorthand with an actionable warning', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_opaque_padding_');
    $authored = 'padding: var(--section-padding, 2rem 3rem 4rem 5rem);';
    $siteCss = '#hero{' . $authored . 'color:var(--wp--preset--color--contrast);}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText('plugin/pages/home.html', '<section id="hero"><p>Hero</p></section>');

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, $authored), 'opaque shorthand removed at declaration scope');
    assert_true(!str_contains($style, 'padding-top: var('), 'opaque shorthand not copied into invalid top longhand');
    assert_true(!str_contains($style, 'padding-bottom: var('), 'opaque shorthand not copied into invalid bottom longhand');
    assert_contains('color:var(--wp--preset--color--contrast);', $style, 'safe sibling survives');
    $warnings = implode("\n", ps_warning_rows($project, 'page-styles'));
    assert_contains('source=design/site.css', $warnings);
    assert_contains('authored_value=', $warnings);
    assert_contains('padding: var(--section-padding, 2rem 3rem 4rem 5rem)', $warnings);
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path resolves nested selector parent context before neutralizing section roots', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_nested_parent_context_');
    $siteCss = <<<'CSS'
#hero {
    & {
        padding: 1rem 2rem 3rem;
    }
    &.variant {
        padding-left: 4rem;
        padding-top: 5rem;
    }
    & .child {
        padding: 6rem 7rem;
    }
}
body {
    & #hero {
        padding: 8rem 9rem 10rem;
    }
    & #hero .child {
        padding-right: 11rem;
    }
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero" class="variant"><div class="child">Child</div></section>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    foreach (
        [
            'padding: 1rem 2rem 3rem;' => '#hero { & targets the section root',
            'padding-left: 4rem;' => '#hero { &.variant targets the section root',
            'padding: 8rem 9rem 10rem;' => 'body { & #hero targets the section root',
        ] as $declaration => $message
    ) {
        assert_true(!str_contains($style, $declaration), $message);
    }
    assert_contains('padding-top: 1rem;', $style);
    assert_contains('padding-bottom: 3rem;', $style);
    assert_contains('padding-top: 5rem;', $style, 'non-inline sibling declaration survives');
    assert_contains('padding-top: 8rem;', $style);
    assert_contains('padding-bottom: 10rem;', $style);
    assert_contains('padding: 6rem 7rem;', $style, '#hero { & .child keeps descendant padding');
    assert_contains('padding-right: 11rem;', $style, 'body { & #hero .child keeps descendant padding');
    assert_true(
        !str_contains(implode("\n", ps_warning_rows($project, 'page-styles')), 'retained malformed CSS'),
        'valid nested selectors do not degrade as malformed',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path splits mixed functional selector alternatives without changing specificity', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_mixed_is_selector_');
    $siteCss = <<<'CSS'
:is(#hero, .shared) {
    padding: 12rem 13rem 14rem 15rem;
    color: var(--wp--preset--color--contrast);
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero">Hero</section><div class="shared">Shared</div>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(
        preg_match(
            '/:is\(#hero,\s*\.shared\):where\(#hero\)\s*\{([^{}]*)\}/s',
            $style,
            $rootMatch,
        ) === 1,
        'root alternative is isolated with zero-specificity filtering',
    );
    assert_true(
        !str_contains($rootMatch[1] ?? '', 'padding: 12rem 13rem 14rem 15rem;'),
        'root alternative loses inline padding',
    );
    assert_contains('padding-top: 12rem;', $rootMatch[1] ?? '');
    assert_contains('padding-bottom: 14rem;', $rootMatch[1] ?? '');
    assert_true(
        preg_match(
            '/:is\(#hero,\s*\.shared\):not\(:where\(#hero\)\)\s*\{([^{}]*)\}/s',
            $style,
            $sharedMatch,
        ) === 1,
        'non-root alternative keeps the original ID specificity through :not(:where()) filtering',
    );
    assert_contains(
        'padding: 12rem 13rem 14rem 15rem;',
        $sharedMatch[1] ?? '',
        '.shared keeps exact authored padding',
    );
    assert_contains(
        'color: var(--wp--preset--color--contrast);',
        $sharedMatch[1] ?? '',
        'unrelated declaration survives the selector split',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path preserves parent-list nesting specificity while splitting direct root padding', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_parent_list_nesting_');
    $siteCss = <<<'CSS'
#hero, .shared {
    padding: 1rem 2rem;
    & .child {
        padding: 3rem 4rem;
    }
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero"><div class="child">Root child</div></section>'
            . '<div class="shared"><div class="child">Shared child</div></div>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(
        preg_match(
            '/#hero\s*,\s*\.shared\s*\{\s*&\s+\.child\s*\{\s*padding:\s*3rem 4rem;\s*\}\s*\}/s',
            $style,
        ) === 1,
        'nested child stays under the original parent list and inherits its maximum ID specificity',
    );
    assert_true(
        preg_match('/(?:^|\})\s*\.shared\s*\{[^{}]*&\s+\.child\s*\{/s', $style) !== 1,
        'nested child is not moved under a split class-only parent',
    );
    assert_eq(1, substr_count($style, 'padding: 3rem 4rem;'), 'nested child rule stays single');
    $rootBody = implode("\n", ps_css_bodies_for_selector($style, '#hero'));
    assert_contains('padding-top: 1rem;', $rootBody);
    assert_contains('padding-bottom: 1rem;', $rootBody);
    assert_true(!str_contains($rootBody, 'padding: 1rem 2rem;'), 'root direct horizontal padding removed');
    assert_contains(
        'padding: 1rem 2rem;',
        implode("\n", ps_css_bodies_for_selector($style, '.shared')),
        'non-root direct padding stays exact',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path neutralizes mixed-parent declarations inside nested grouping at-rules', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_parent_list_grouping_at_rule_');
    $siteCss = <<<'CSS'
#hero, .shared {
    @media (min-width: 1px) {
        padding: 1rem 2rem;
        & .child {
            padding: 3rem 4rem;
        }
    }
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero"><div class="child">Root child</div></section>'
            . '<div class="shared"><div class="child">Shared child</div></div>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    $compact = preg_replace('/\s+/', '', $style);
    assert_true(is_string($compact));
    $transposedDirectBranches = str_contains(
        $compact,
        '@media(min-width:1px){#hero{padding-top:1rem;padding-bottom:1rem;}'
            . '.shared{padding:1rem2rem;}}',
    );
    $nestedDirectBranches = str_contains(
        $compact,
        '#hero{@media(min-width:1px){padding-top:1rem;padding-bottom:1rem;}}',
    ) && str_contains(
        $compact,
        '.shared{@media(min-width:1px){padding:1rem2rem;}}',
    );
    assert_true(
        $transposedDirectBranches || $nestedDirectBranches,
        'media-scoped root direct padding is vertical-only while .shared keeps exact padding and specificity',
    );
    assert_eq(
        1,
        substr_count($compact, 'padding:1rem2rem;'),
        'only the non-root media branch keeps authored horizontal padding',
    );
    assert_contains(
        '#hero,.shared{@media(min-width:1px){&.child{padding:3rem4rem;}}}',
        $compact,
        'nested child stays once under the original parent list and media query',
    );
    assert_true(
        preg_match(
            '/(?:^|})\.shared\{@media\(min-width:1px\)\{&\.child\{/',
            $compact,
        ) !== 1,
        'nested child is not moved under a class-only parent',
    );
    assert_true(
        !str_contains(implode("\n", ps_warning_rows($project, 'page-styles')), 'retained malformed CSS'),
        'valid nested grouping at-rule does not degrade as malformed',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path recognizes advanced final-subject root selector forms only', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_advanced_root_selectors_');
    $siteCss = <<<'CSS'
section[id="HERO" i] {
    padding-left: 1rem;
    padding-top: 2rem;
}
section[id="hero" s] {
    padding-right: 3rem;
    padding-bottom: 4rem;
}
:nth-child(1 of #hero) {
    padding-inline: 5rem;
}
:not(:not(#hero)) {
    padding: 6rem 7rem 8rem;
}
section[data-id="hero"] {
    padding-left: 9rem;
}
:nth-child(1 of #hero .child) {
    padding-right: 10rem;
}
:not(#hero) {
    padding-inline: 11rem;
}
:has(#hero) {
    padding: 12rem 13rem;
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero"><div class="child">Child</div></section>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    foreach (
        [
            'padding-left: 1rem;' => 'case-insensitive exact id attribute targets root',
            'padding-right: 3rem;' => 'case-sensitive exact id attribute targets root',
            'padding-inline: 5rem;' => ':nth-child of-list targets its final subject',
            'padding: 6rem 7rem 8rem;' => 'double negation targets root',
        ] as $declaration => $message
    ) {
        assert_true(!str_contains($style, $declaration), $message);
    }
    assert_contains('padding-top: 2rem;', $style);
    assert_contains('padding-bottom: 4rem;', $style);
    assert_contains('padding-top: 6rem;', $style);
    assert_contains('padding-bottom: 8rem;', $style);
    foreach (
        [
            'padding-left: 9rem;' => 'non-id attribute selector survives',
            'padding-right: 10rem;' => 'nth-child descendant final subject survives',
            'padding-inline: 11rem;' => 'single negation survives',
            'padding: 12rem 13rem;' => ':has related-element selector survives',
        ] as $declaration => $message
    ) {
        assert_contains($declaration, $style, $message);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path resolves root-bearing attribute and nested-negation selectors without widening controls', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_root_selector_logic_');
    $siteCss = <<<'CSS'
section[id|=hero] {
    padding: 3rem 4rem;
}
:not(:is(:not(#hero))) {
    padding: 5rem 6rem;
}
#hero:not(#hero.featured) {
    padding: 1rem 2rem;
}
section[id|=other] {
    padding: 7rem 8rem;
}
:not(:is(#hero)) {
    padding: 9rem 10rem;
}
#other:not(#hero.featured) {
    padding: 11rem 12rem;
}
CSS;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="hero"><section id="hero-panel">Nested</section></section>'
            . '<div id="other">Other</div>',
    );

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(
        preg_match(
            '/section\[id\|=hero\]:where\(#hero\)\s*\{([^{}]*)\}/s',
            $style,
            $attributeRoot,
        ) === 1,
        'dash-match id selector isolates the delivered root with a zero-specificity filter',
    );
    assert_true(!str_contains($attributeRoot[1] ?? '', 'padding: 3rem 4rem;'));
    assert_contains('padding-top: 3rem;', $attributeRoot[1] ?? '');
    assert_contains('padding-bottom: 3rem;', $attributeRoot[1] ?? '');
    assert_true(
        preg_match(
            '/section\[id\|=hero\]:not\(:where\(#hero\)\)\s*\{([^{}]*)\}/s',
            $style,
            $attributeOther,
        ) === 1,
        'dash-match non-root alternative keeps its original specificity',
    );
    assert_contains(
        'padding: 3rem 4rem;',
        $attributeOther[1] ?? '',
        'nested #hero-panel match keeps exact padding',
    );
    foreach (
        [
            ':not(:is(:not(#hero)))' => ['padding-top: 5rem;', 'padding-bottom: 5rem;'],
            '#hero:not(#hero.featured)' => ['padding-top: 1rem;', 'padding-bottom: 1rem;'],
        ] as $selector => $vertical
    ) {
        $body = implode("\n", ps_css_bodies_for_selector($style, $selector));
        assert_contains($vertical[0], $body, "{$selector} keeps top padding");
        assert_contains($vertical[1], $body, "{$selector} keeps bottom padding");
        assert_true(
            preg_match('/(?:^|;)\s*padding\s*:/i', $body) !== 1,
            "{$selector} loses root-owned horizontal padding",
        );
    }
    foreach (
        [
            'section[id|=other]' => 'padding: 7rem 8rem;',
            ':not(:is(#hero))' => 'padding: 9rem 10rem;',
            '#other:not(#hero.featured)' => 'padding: 11rem 12rem;',
        ] as $selector => $declaration
    ) {
        assert_contains(
            $declaration,
            implode("\n", ps_css_bodies_for_selector($style, $selector)),
            "{$selector} remains outside delivered-root scope",
        );
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path removes every opaque substitution padding shorthand with actionable warnings', function () {
    [$project, $tmp] = ps_project('builder_ps_foundation_opaque_substitution_padding_');
    $envPadding = 'padding: env(safe-area-inset-top, 1rem 2rem 3rem 4rem);';
    $escapedVarPadding = 'padding: v\\61 r(--section-padding, 5rem 6rem 7rem 8rem);';
    $siteCss = '#hero{' . $envPadding . $escapedVarPadding
        . 'color:var(--wp--preset--color--contrast);}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main><section id="hero"></section></main>');
    $project->writeText('plugin/pages/home.html', '<section id="hero"><p>Hero</p></section>');

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, $envPadding), 'env shorthand removed at declaration scope');
    assert_true(!str_contains($style, $escapedVarPadding), 'escaped var shorthand removed at declaration scope');
    foreach (['padding-top: env(', 'padding-bottom: env(', 'padding-top: v\\61 r(', 'padding-bottom: v\\61 r('] as $invalid) {
        assert_true(!str_contains($style, $invalid), "opaque shorthand not copied into {$invalid}");
    }
    assert_contains('color:var(--wp--preset--color--contrast);', $style, 'safe sibling survives');
    $warnings = implode("\n", ps_warning_rows($project, 'page-styles'));
    assert_contains('source=design/site.css', $warnings);
    assert_contains('authored_value=', $warnings);
    assert_contains('env(safe-area-inset-top, 1rem 2rem 3rem 4rem)', $warnings);
    assert_contains('v\\61 r(--section-padding, 5rem 6rem 7rem 8rem)', $warnings);
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path ignores inert page-style text inside an HTML comment', function () {
    [$project, $tmp] = ps_project('builder_ps_inert_comment_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $project->writeText(TransformArtifacts::SITE_CSS, '.site{display:grid;}');
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText(
        'design/home.html',
        '<!-- <style data-page-css>.comment-only{position:fixed;}</style> --><main>Home</main>'
    );
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . '.site{display:grid;}',
        $project->readText('theme/style.css'),
        'comment text never becomes live CSS'
    );
    assert_eq([], $llm->calls);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path ignores a style-like string with whitespace after the tag opener', function () {
    [$project, $tmp] = ps_project('builder_ps_inert_spaced_tag_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $project->writeText(TransformArtifacts::SITE_CSS, '.site{display:grid;}');
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText(
        'design/home.html',
        '< style data-page-css>body{display:none}</style><main>Home</main>'
    );
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . '.site{display:grid;}',
        $project->readText('theme/style.css'),
        'HTML text that is not a DOM style element never becomes live CSS'
    );
    assert_eq([], $llm->calls);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path excludes losing tournament candidates from delivered page CSS', function () {
    [$project, $tmp] = ps_project('builder_ps_candidate_exclusion_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $project->writeText(TransformArtifacts::SITE_CSS, '.site{display:grid;}');
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText(
        'design/home.html',
        '<style data-page-css>.winner{padding:1rem;}</style><main>Winner</main>'
    );
    $project->writeText(
        'design/candidate-1.html',
        '<style data-page-css>.loser{position:fixed;}</style><main>Loser</main>'
    );
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . ".site{display:grid;}\n.winner{padding:1rem;}",
        $project->readText('theme/style.css'),
        'only delivered design source contributes page CSS'
    );
    assert_eq([], $llm->calls);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path works without transformer-carried CSS', function () {
    [$project, $tmp] = ps_project('builder_ps_no_carried_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $project->writeText(TransformArtifacts::SITE_CSS, ".site{display:grid;}\n");
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText(
        'design/home.html',
        '<style data-page-css>.home{grid-template-columns:1fr 1fr;}</style>'
    );
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . ".site{display:grid;}\n\n.home{grid-template-columns:1fr 1fr;}",
        $project->readText('theme/style.css')
    );
    assert_eq([], $llm->calls, 'absent carried CSS does not fall back to the LLM');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path always merges a wrap policy that never splits words', function () {
    [$project, $tmp] = ps_project('builder_ps_word_wrap_');
    $scaffold = $project->readText('theme/style.css');
    $project->writeText(TransformArtifacts::SITE_CSS, '');
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main>Home</main>');
    $llm = new FakeLlm();
    $step = ps_html_first_step($llm);

    $step->run($project);

    // Ships even when the design contributed no CSS at all.
    $style = $project->readText('theme/style.css');
    assert_eq($scaffold . ps_wrap() . ps_table_reset(), $style, 'wrap + table-reset foundation is the whole tail when there is no design CSS');
    assert_contains('hyphens: none;', $style);
    assert_contains('-webkit-hyphens: none;', $style);
    assert_contains('word-break: normal;', $style);
    assert_contains('overflow-wrap: normal;', $style);
    assert_true(!str_contains($style, 'break-all'), 'break-all is never introduced');
    assert_true(!str_contains($style, 'hyphens: auto'), 'no automatic hyphenation');

    $step->run($project);

    assert_eq($style, $project->readText('theme/style.css'), 'second run is byte-identical');
    assert_eq([], $llm->calls, 'the wrap policy is deterministic');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path lets design CSS override the wrap policy by ordering', function () {
    [$project, $tmp] = ps_project('builder_ps_word_wrap_order_');
    $project->writeText(TransformArtifacts::SITE_CSS, 'h1{hyphens:auto;}');
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    $project->writeText('design/home.html', '<main>Home</main>');

    ps_html_first_step(new FakeLlm())->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(
        strpos($style, 'hyphens: none;') < strpos($style, 'hyphens:auto;'),
        'the policy is a default the design can still override',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path routes live narration through Narrator', function () {
    [$project, $tmp] = ps_project('builder_ps_narrator_');
    $project->writeText(TransformArtifacts::SITE_CSS, '.site{display:grid;}');
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Home</main>');
    $llm = new FakeLlm();
    $step = ps_html_first_step($llm);
    $stream = fopen('php://memory', 'w+');
    assert_true(is_resource($stream), 'memory narration stream opened');
    Narrator::setStream($stream);

    try {
        $step->run($project);
        $step->run($project);
        rewind($stream);
        assert_eq(
            "  merged deterministic page CSS\n  deterministic page CSS already merged\n",
            stream_get_contents($stream),
            'new deterministic narration uses Narrator'
        );
    } finally {
        Narrator::reset();
        fclose($stream);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});

test('site CSS path warns for every scrub removal and continues after an empty source', function () {
    [$project, $tmp] = ps_project('builder_ps_scrub_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $siteCss = '@import url("https://fonts.example.invalid/font.css");';
    $page = '<main>Kept</main><style data-page-css>'
        . '.page{color:var(--wp--preset--color--contrast);'
        . 'background-image:url(https://cdn.example.invalid/nope.png);padding:1rem;}'
        . '</style>';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'about',
        'front' => false,
    ]]]);
    $project->writeText('design/about.html', $page);
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . ".page{color:var(--wp--preset--color--contrast);padding:1rem;}",
        $project->readText('theme/style.css'),
        'empty scrubbed site CSS does not block surviving page CSS'
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq($page, $project->readText('design/about.html'), 'page source unchanged');
    assert_eq([], $llm->calls, 'scrub degradation makes zero LLM calls');
    $warnings = implode("\n", $project->readJson('warnings.json')['page-styles'] ?? []);
    assert_contains('source=design/site.css', $warnings);
    assert_contains('@import url', $warnings);
    assert_contains('source=design/about.html style[data-page-css]#1', $warnings);
    assert_contains('background-image:url', $warnings);
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=removed_import', $warnings);
    assert_contains('disposition=removed_external_url', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path scrubs image-set bare remote strings before deterministic merge', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_scrub_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $remote = 'https://evil.example/tracker.png';
    $siteCss = '.hero{color:var(--wp--preset--color--contrast);'
        . 'background-image:image-set("' . $remote . '" 1x);padding:1rem;}'
        . '.site-safe{background-image:image-set("./local.png" 1x);}';
    $pageCss = '.page-safe{display:grid;}';
    $page = '<main>Kept</main><style data-page-css>' . $pageCss . '</style>';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', $page);
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, $remote), 'remote image-set bytes never reach theme/style.css');
    assert_eq(
        $base
        . '.hero{color:var(--wp--preset--color--contrast);padding:1rem;}'
        . '.site-safe{background-image:image-set("./local.png" 1x);}'
        . "\n"
        . $pageCss,
        $style,
        'deterministic merge keeps safe declarations and page sibling bytes'
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq($page, $project->readText('design/home.html'), 'page source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $warnings = implode("\n", $project->readJson('warnings.json')['page-styles'] ?? []);
    assert_contains('source=design/site.css', $warnings);
    assert_contains(
        'authored_value="background-image:image-set(\\"https://evil.example/tracker.png\\" 1x);"',
        $warnings
    );
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=removed_external_url', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

foreach (['hash' => '#', 'at-keyword' => '@'] as $label => $prefix) {
    test("site CSS path preserves {$label} image-set lookalikes without warnings", function () use ($label, $prefix) {
        [$project, $tmp] = ps_project('builder_ps_image_set_' . $label . '_lookalike_');
        $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
        $siteCss = '.tokens{--' . $label . ':' . $prefix
            . 'image-set("https://evil.example/not-a-function.png");color:red}';
        $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'front' => true,
        ]]]);
        $project->writeText('design/home.html', '<main>Kept</main>');
        $llm = new FakeLlm();

        ps_html_first_step($llm)->run($project);

        $expected = $base . $siteCss;
        $style = $project->readText('theme/style.css');
        assert_eq(strlen($expected), strlen($style), 'merged byte length retained');
        assert_eq(hash('sha256', $expected), hash('sha256', $style), 'merged byte hash retained');
        assert_eq($expected, $style, "{$label} token bytes retained exactly");
        assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
        assert_eq([], $llm->calls, 'deterministic merge makes zero LLM calls');
        assert_eq([], ps_warning_rows($project, 'page-styles'), "{$label} lookalike emits zero scrub warnings");
        exec('rm -rf ' . escapeshellarg($tmp));
    });
}

foreach (
    [
        'whitespace-gap' => 'image-set ("https://evil.example/x.png")',
        'bracket-nested' => 'image-set(["https://evil.example/x.png"])',
    ] as $label => $value
) {
    test("site CSS path preserves {$label} image-set lookalikes without warnings", function () use ($label, $value) {
        [$project, $tmp] = ps_project('builder_ps_image_set_' . $label . '_lookalike_');
        $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
        $siteCss = '.tokens{--fake:' . $value . ';color:red}';
        $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'front' => true,
        ]]]);
        $project->writeText('design/home.html', '<main>Kept</main>');
        $llm = new FakeLlm();

        ps_html_first_step($llm)->run($project);

        $expected = $base . $siteCss;
        $style = $project->readText('theme/style.css');
        assert_eq(strlen($expected), strlen($style), 'merged byte length retained');
        assert_eq(hash('sha256', $expected), hash('sha256', $style), 'merged byte hash retained');
        assert_eq($expected, $style, "{$label} token bytes retained exactly");
        assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
        assert_eq([], $llm->calls, 'deterministic merge makes zero LLM calls');
        assert_eq([], ps_warning_rows($project, 'page-styles'), "{$label} lookalike emits zero scrub warnings");
        exec('rm -rf ' . escapeshellarg($tmp));
    });
}

foreach (
    [
        'immediate' => 'image-set("https://evil.example/x.png" 1x)',
        'comment-gap' => 'image-set/**/("https://evil.example/x.png" 1x)',
        'bracket-nested-real' => 'image-set([image("https://evil.example/x.png")])',
    ] as $label => $value
) {
    test("site CSS path removes {$label} real image function control", function () use ($label, $value) {
        [$project, $tmp] = ps_project('builder_ps_image_set_' . $label . '_control_');
        $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
        $declaration = 'background-image:' . $value . ';';
        $siteCss = '.actual{' . $declaration . 'color:red}';
        $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'front' => true,
        ]]]);
        $project->writeText('design/home.html', '<main>Kept</main>');
        $llm = new FakeLlm();

        ps_html_first_step($llm)->run($project);

        assert_eq($base . '.actual{color:red}', $project->readText('theme/style.css'), $label);
        assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
        assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
        $authored = json_encode(
            $declaration,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        assert_true(is_string($authored));
        assert_eq(
            [
                'source=design/site.css; authored_value=' . $authored
                    . '; delivered_value=removed; disposition=removed_external_url',
            ],
            $project->readJson('warnings.json')['page-styles'] ?? [],
            $label
        );
        exec('rm -rf ' . escapeshellarg($tmp));
    });
}

test('site CSS path still scrubs a real image-set function beside token lookalikes', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_token_control_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $declaration = 'background-image:image-set("https://evil.example/not-a-function.png" 1x);';
    $siteCss = '.actual{' . $declaration . 'color:red}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq($base . '.actual{color:red}', $project->readText('theme/style.css'));
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $authored = json_encode(
        $declaration,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    assert_true(is_string($authored));
    assert_eq(
        [
            'source=design/site.css; authored_value=' . $authored
                . '; delivered_value=removed; disposition=removed_external_url',
        ],
        $project->readJson('warnings.json')['page-styles'] ?? []
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path scrubs image-set remote strings after leading whitespace normalization', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_space_scrub_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $remote = 'http://127.0.0.1:9/px.png';
    $siteCss = '.hero{background-image:image-set(" ' . $remote . '" 1x);display:grid}'
        . '.safe{padding:1rem}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, $remote), 'normalized remote bytes never reach theme/style.css');
    assert_eq(
        $base . '.hero{display:grid}.safe{padding:1rem}',
        $style,
        'deterministic merge keeps both safe sibling rules'
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $warnings = implode("\n", $project->readJson('warnings.json')['page-styles'] ?? []);
    assert_contains('source=design/site.css', $warnings);
    assert_contains(
        'authored_value="background-image:image-set(\\" http://127.0.0.1:9/px.png\\" 1x);"',
        $warnings
    );
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=removed_external_url', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path scrubs image-set remote strings after leading C0 control normalization', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_c0_scrub_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $vtabRemote = 'http://127.0.0.1:9/vtab.png';
    $unitRemote = 'https://evil.example/unit-separator.png';
    $siteCss = '.vtab{background-image:image-set("\b ' . $vtabRemote . '" 1x);display:grid}'
        . '.unit{background-image:image-set("\1f ' . $unitRemote . '" 2x);padding:1rem}'
        . '.safe{margin:2rem}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, $vtabRemote), 'vertical-tab remote bytes never reach theme/style.css');
    assert_true(!str_contains($style, $unitRemote), 'unit-separator remote bytes never reach theme/style.css');
    assert_eq(
        $base . '.vtab{display:grid}.unit{padding:1rem}.safe{margin:2rem}',
        $style,
        'deterministic merge keeps every safe sibling'
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $warningRows = $project->readJson('warnings.json')['page-styles'] ?? [];
    assert_eq(2, count($warningRows), 'one warning per removed declaration');
    $warnings = implode("\n", $warningRows);
    assert_contains('source=design/site.css', $warnings);
    assert_contains(
        'authored_value="background-image:image-set(\\"\\\\b http://127.0.0.1:9/vtab.png\\" 1x);"',
        $warnings
    );
    assert_contains(
        'authored_value="background-image:image-set(\\"\\\\1f https://evil.example/unit-separator.png\\" 2x);"',
        $warnings
    );
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=removed_external_url', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path scrubs image-set remote strings with embedded URL whitespace', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_embedded_space_scrub_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $siteCss = '.tab{background-image:image-set("h\9 ttp://127.0.0.1:9/tab.png" 1x);display:grid}'
        . '.lf{background-image:image-set("h\a ttp://evil.example/line-feed.png" 2x);padding:1rem}'
        . '.cr{background-image:image-set("h\d ttp://evil.example/carriage-return.png" 3x);margin:2rem}'
        . '.safe{position:relative}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base . '.tab{display:grid}.lf{padding:1rem}.cr{margin:2rem}.safe{position:relative}',
        $project->readText('theme/style.css'),
        'deterministic merge removes all embedded-whitespace remotes and keeps siblings'
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $warningRows = $project->readJson('warnings.json')['page-styles'] ?? [];
    assert_eq(3, count($warningRows), 'one warning per removed declaration');
    $warnings = implode("\n", $warningRows);
    assert_contains('source=design/site.css', $warnings);
    assert_contains(
        'authored_value="background-image:image-set(\\"h\\\\9 ttp://127.0.0.1:9/tab.png\\" 1x);"',
        $warnings
    );
    assert_contains(
        'authored_value="background-image:image-set(\\"h\\\\a ttp://evil.example/line-feed.png\\" 2x);"',
        $warnings
    );
    assert_contains(
        'authored_value="background-image:image-set(\\"h\\\\d ttp://evil.example/carriage-return.png\\" 3x);"',
        $warnings
    );
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=removed_external_url', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path preserves raw and CSS-escaped NUL image strings without warnings', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_nul_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $nul = chr(0);
    $rawRemote = 'http://127.0.0.1:9/raw-nul.png';
    $siteCss = '.raw{background-image:image-set("' . $nul . $rawRemote . '" 1x);display:grid}'
        . '.escaped{background-image:image-set("\0 http://127.0.0.1:9/escaped-nul.png" 2x);padding:1rem}'
        . '.safe{position:relative}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $expected = $base . $siteCss;
    $style = $project->readText('theme/style.css');
    assert_eq(strlen($expected), strlen($style), 'binary merged length preserved');
    assert_eq(hash('sha256', $expected), hash('sha256', $style), 'binary merged hash preserved');
    assert_eq($expected, $style, 'raw and escaped NUL declarations remain byte-identical');
    assert_eq(1, substr_count($style, $nul), 'merged CSS contains one actual NUL byte');
    assert_contains($rawRemote, $style, 'raw-NUL remote-like suffix remains inert');
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic merge makes zero LLM calls');
    assert_eq([], ps_warning_rows($project, 'page-styles'), 'inert NUL strings emit zero scrub warnings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path scrubs every slash-backslash authority pair and keeps a single backslash path', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_authority_pairs_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $backslash = '\\';
    $values = [
        'slash-slash' => '//evil.example/slash-slash.png',
        'backslash-backslash' => str_repeat($backslash, 4) . 'evil.example/backslash-backslash.png',
        'slash-backslash' => '/' . str_repeat($backslash, 2) . 'evil.example/slash-backslash.png',
        'backslash-slash' => str_repeat($backslash, 2) . '/evil.example/backslash-slash.png',
    ];
    $singleBackslash = str_repeat($backslash, 2) . 'assets/local.png';
    $siteCss = '.slash-slash{background-image:image-set("' . $values['slash-slash'] . '" 1x);display:grid}'
        . '.backslash-backslash{background-image:image-set("' . $values['backslash-backslash'] . '" 2x);padding:1rem}'
        . '.slash-backslash{background-image:image-set("' . $values['slash-backslash'] . '" 3x);margin:2rem}'
        . '.backslash-slash{background-image:image-set("' . $values['backslash-slash'] . '" 4x);position:relative}'
        . '.single-backslash{background-image:image-set("' . $singleBackslash . '" 1x);color:red}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    assert_eq(
        $base
        . '.slash-slash{display:grid}'
        . '.backslash-backslash{padding:1rem}'
        . '.slash-backslash{margin:2rem}'
        . '.backslash-slash{position:relative}'
        . '.single-backslash{background-image:image-set("' . $singleBackslash . '" 1x);color:red}',
        $project->readText('theme/style.css'),
        'all network authority pairs removed; single decoded backslash path preserved'
    );
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $warningRows = $project->readJson('warnings.json')['page-styles'] ?? [];
    assert_eq(4, count($warningRows), 'one warning per removed authority-pair declaration');
    $warnings = implode("\n", $warningRows);
    $authored = [
        'background-image:image-set("' . $values['slash-slash'] . '" 1x);',
        'background-image:image-set("' . $values['backslash-backslash'] . '" 2x);',
        'background-image:image-set("' . $values['slash-backslash'] . '" 3x);',
        'background-image:image-set("' . $values['backslash-slash'] . '" 4x);',
    ];
    foreach ($authored as $declaration) {
        $encoded = json_encode(
            $declaration,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        assert_true(is_string($encoded));
        assert_contains('authored_value=' . $encoded, $warnings);
    }
    assert_contains('delivered_value=removed', $warnings);
    assert_contains('disposition=removed_external_url', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path recovers after a malformed string before a later remote image string', function () {
    [$project, $tmp] = ps_project('builder_ps_bad_string_recovery_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $malformed = '.broken{--token:"unterminated' . "\n" . ';color:red}';
    $declaration = 'background-image:image-set("https://evil.example/after-bad-string.png" 1x);';
    $safe = '.note::before{content:"https://x";display:block}';
    $siteCss = $malformed . '.later{' . $declaration . 'display:grid}' . $safe;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $expected = $base . $malformed . '.later{display:grid}' . $safe;
    $style = $project->readText('theme/style.css');
    assert_true(
        !str_contains($style, 'https://evil.example/after-bad-string.png'),
        'later remote bytes never reach theme/style.css'
    );
    assert_eq(strlen($expected), strlen($style), 'malformed and safe merged byte length retained');
    assert_eq(hash('sha256', $expected), hash('sha256', $style), 'malformed and safe merged byte hash retained');
    assert_eq($expected, $style, 'malformed bytes and later safe siblings retained exactly');
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $authored = json_encode(
        $declaration,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    assert_true(is_string($authored));
    assert_eq(
        [
            'source=design/site.css; authored_value=' . $authored
                . '; delivered_value=removed; disposition=removed_external_url',
        ],
        $project->readJson('warnings.json')['page-styles'] ?? [],
        'one exact actionable warning for the later remote declaration'
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path removes an external image string in an EOF-truncated final declaration', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_eof_remote_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $prefix = '.x{color:red;';
    $declaration = 'background-image:image-set("http://127.0.0.1:9/eof.png" 1x';
    $siteCss = $prefix . $declaration;
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $expected = $base . $prefix;
    $style = $project->readText('theme/style.css');
    assert_true(!str_contains($style, 'http://127.0.0.1:9/eof.png'), 'EOF remote bytes absent');
    assert_eq(strlen($expected), strlen($style), 'prior merged byte length retained');
    assert_eq(hash('sha256', $expected), hash('sha256', $style), 'prior merged byte hash retained');
    assert_eq($expected, $style, 'only harmful final declaration removed through EOF');
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic scrub makes zero LLM calls');
    $authored = json_encode(
        $declaration,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    assert_true(is_string($authored));
    assert_eq(
        [
            'source=design/site.css; authored_value=' . $authored
                . '; delivered_value=removed; disposition=removed_external_url',
        ],
        $project->readJson('warnings.json')['page-styles'] ?? []
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('site CSS path preserves a closed rule with an unmatched image function without warnings', function () {
    [$project, $tmp] = ps_project('builder_ps_image_set_closed_unmatched_');
    $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
    $siteCss = '.x{background-image:image-set("https://evil.example/x.png" 1x}';
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home',
        'front' => true,
    ]]]);
    $project->writeText('design/home.html', '<main>Kept</main>');
    $llm = new FakeLlm();

    ps_html_first_step($llm)->run($project);

    $expected = $base . $siteCss;
    $style = $project->readText('theme/style.css');
    assert_eq(strlen($expected), strlen($style));
    assert_eq(hash('sha256', $expected), hash('sha256', $style));
    assert_eq($expected, $style);
    assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
    assert_eq([], $llm->calls, 'deterministic merge makes zero LLM calls');
    assert_eq([], ps_warning_rows($project, 'page-styles'), 'closed unmatched function emits zero scrub warnings');
    exec('rm -rf ' . escapeshellarg($tmp));
});

foreach (
    [
        'relative' => './local.png',
        'data' => 'data:image/png;base64,AAAA',
        'fragment' => '#paint',
    ] as $label => $value
) {
    test("site CSS path preserves an allowed {$label} image string through EOF", function () use ($label, $value) {
        [$project, $tmp] = ps_project('builder_ps_image_set_eof_' . $label . '_');
        $base = $project->readText('theme/style.css') . ps_wrap() . ps_table_reset();
        $siteCss = '.x{color:red;background-image:image-set("' . $value . '" 1x';
        $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home',
            'front' => true,
        ]]]);
        $project->writeText('design/home.html', '<main>Kept</main>');
        $llm = new FakeLlm();

        ps_html_first_step($llm)->run($project);

        $expected = $base . $siteCss;
        $style = $project->readText('theme/style.css');
        assert_eq(strlen($expected), strlen($style));
        assert_eq(hash('sha256', $expected), hash('sha256', $style));
        assert_eq($expected, $style);
        assert_eq($siteCss, $project->readText(TransformArtifacts::SITE_CSS), 'site CSS source unchanged');
        assert_eq([], $llm->calls, 'deterministic merge makes zero LLM calls');
        assert_eq([], ps_warning_rows($project, 'page-styles'), "allowed {$label} URL emits zero scrub warnings");
        exec('rm -rf ' . escapeshellarg($tmp));
    });
}

test('run appends validated CSS to style.css and passes the configured model', function () {
    [$project, $tmp] = ps_project('builder_ps_ok_');
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up">'
        . '<!-- wp:group {"className":"masonry-3"} --><div class="wp-block-group masonry-3"></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(PS_VALID_CSS);

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts')), 'claude-haiku-4-5'))->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains('Theme Name: Demo', $style, 'header kept');
    assert_contains('.overlap-up', $style, 'appendix appended');
    assert_contains('.masonry-3', $style, 'appendix appended');
    assert_contains('page-styles step', $style, 'marker comment present');
    // The prompt asked only for the classes actually used, in vocabulary order.
    assert_contains('- .overlap-up', $llm->calls[0]['prompt']);
    assert_contains('- .masonry-3', $llm->calls[0]['prompt']);
    assert_true(!str_contains($llm->calls[0]['prompt'], '- .hover-lift'), 'hover-lift is not a requested utility');
    assert_true(!str_contains($llm->calls[0]['prompt'], '- .hover-reveal'), 'hover-reveal is not a requested utility');
    assert_true(!str_contains($llm->calls[0]['prompt'], 'MOTION TUNING'), 'motion timing is not offered');
    assert_true(!str_contains($llm->calls[0]['prompt'], '- .sticky-side'), 'unused class not requested');
    assert_contains('The design direction owns contained-media and button corners', $llm->calls[0]['prompt']);
    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run rejects invalid CSS, leaves style.css untouched, and logs the problems', function () {
    [$project, $tmp] = ps_project('builder_ps_bad_');
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up"></div><!-- /wp:group -->'
    );
    $before = $project->readText('theme/style.css');
    $llm = new FakeLlm();
    $llm->queueText("body {\n    margin: 0;\n}");

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq($before, $project->readText('theme/style.css'), 'style.css untouched');
    assert_contains('not scoped', $project->readText('logs/page-styles.log'));
    // The skipped appendix costs every used utility its CSS — durable record.
    $joined = implode(' ', $project->readJson('warnings.json')['page-styles'] ?? []);
    assert_contains('model CSS appendix rejected', $joined);
    assert_contains('overlap-up', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('usedClasses sees classes that only appear in the content plugin pages', function () {
    [$project, $tmp] = ps_project('builder_ps_plug_');
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText(
        'plugin/pages/home.html',
        '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up">x</div><!-- /wp:group -->'
    );

    assert_eq(['overlap-up'], PageStylesStep::usedClasses($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run skips when markup contains only static hover classes', function () {
    [$project, $tmp] = ps_project('builder_ps_static_hover_');
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"hover-lift hover-reveal"} --><div class="wp-block-group hover-lift hover-reveal"></div><!-- /wp:group -->'
    );
    $before = $project->readText('theme/style.css');
    $llm = new FakeLlm(); // nothing queued: PageStyles must not own hover CSS

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq([], $llm->calls, 'no LLM call for static hover classes');
    assert_eq($before, $project->readText('theme/style.css'), 'style.css untouched');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run rejects :root motion tuning instead of extracting it', function () {
    [$project, $tmp] = ps_project('builder_ps_no_motion_tuning_');
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'calm']);
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"overlap-up"} --><div class="wp-block-group overlap-up"></div><!-- /wp:group -->'
    );
    $before = $project->readText('theme/style.css');
    $llm = new FakeLlm();
    $llm->queueText(
        ":root {\n    --motion-enter-duration: 150ms;\n}\n"
        . ".overlap-up { margin-top: -4rem; position: relative; z-index: 2; }"
    );

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_true(!str_contains($llm->calls[0]['prompt'], 'MOTION TUNING'), 'prompt offers no override channel');
    assert_eq($before, $project->readText('theme/style.css'), 'global override rejects the whole appendix');
    assert_contains('not scoped', $project->readText('logs/page-styles.log'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
