<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

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

function ps_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    return [$project, $tmp];
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
