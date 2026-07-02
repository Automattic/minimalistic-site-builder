<?php
declare(strict_types=1);

/**
 * PageStylesStep: the CSS-appendix validator (namespaced selectors only, preset
 * variables for color, @media only), the used-class scan, and the run behavior
 * (skip when unused, append when valid, reject-and-skip when invalid).
 */

const PS_VALID_CSS = <<<CSS
    .hover-lift {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .hover-lift:hover {
        transform: translateY(-6px);
        box-shadow: var(--wp--preset--shadow--natural);
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
    $css = ".hover-reveal img {\n    filter: brightness(0.9);\n}\n"
        . ".hover-reveal:hover {\n    background: color-mix(in srgb, var(--wp--preset--color--contrast) 20%, transparent);\n}";
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
    assert_true([] !== PageStylesStep::validate(".hover-lift {\n    color: #fff;\n}"), 'hex');
    assert_true([] !== PageStylesStep::validate(".hover-lift {\n    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);\n}"), 'rgba');
    assert_true([] !== PageStylesStep::validate(".hover-lift {\n    color: hsl(20 10% 20%);\n}"), 'hsl');
});

test('validate rejects disallowed at-rules and url()', function () {
    assert_true([] !== PageStylesStep::validate("@import 'x.css';\n.hover-lift {\n    color: var(--wp--preset--color--base);\n}"), '@import');
    assert_true([] !== PageStylesStep::validate("@keyframes spin {\n    to { transform: rotate(1turn); }\n}"), '@keyframes');
    assert_true([] !== PageStylesStep::validate(".hover-lift {\n    background: url(x.png);\n}"), 'url()');
});

test('validate rejects empty, oversized, and unbalanced CSS', function () {
    assert_eq(['empty CSS'], PageStylesStep::validate("  \n "));
    $long = str_repeat(".hover-lift {\n    opacity: 1;\n}\n", 40); // 120 lines
    assert_true([] !== PageStylesStep::validate($long), 'over the line ceiling');
    assert_true([] !== PageStylesStep::validate(".hover-lift {\n    opacity: 1;\n"), 'unbalanced braces');
});

test('classesIn finds documented classes on word boundaries only', function () {
    $markup = '<!-- wp:group {"className":"equal-cards hover-lift"} -->'
        . '<div class="wp-block-group equal-cards hover-lift"></div><!-- /wp:group -->'
        . '<div class="hover-lifted masonry-30"></div>'; // near-misses must not match
    assert_eq(['hover-lift'], PageStylesStep::classesIn($markup));
    assert_eq([], PageStylesStep::classesIn('<div class="plain"></div>'));
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
        '<!-- wp:group {"className":"masonry-3"} --><div class="wp-block-group masonry-3">'
        . '<!-- wp:image {"className":"hover-lift"} --><figure class="wp-block-image hover-lift"></figure><!-- /wp:image -->'
        . '</div><!-- /wp:group -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(PS_VALID_CSS);

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts')), 'claude-haiku-4-5'))->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains('Theme Name: Demo', $style, 'header kept');
    assert_contains('.hover-lift:hover', $style, 'appendix appended');
    assert_contains('page-styles step', $style, 'marker comment present');
    // The prompt asked only for the classes actually used, in vocabulary order.
    assert_contains('- .masonry-3', $llm->calls[0]['prompt']);
    assert_contains('- .hover-lift', $llm->calls[0]['prompt']);
    assert_true(!str_contains($llm->calls[0]['prompt'], '- .sticky-side'), 'unused class not requested');
    assert_eq('claude-haiku-4-5', $llm->calls[0]['opts']['model'] ?? null);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('run rejects invalid CSS, leaves style.css untouched, and logs the problems', function () {
    [$project, $tmp] = ps_project('builder_ps_bad_');
    $project->writeText(
        'theme/parts/section-work.html',
        '<!-- wp:group {"className":"hover-lift"} --><div class="wp-block-group hover-lift"></div><!-- /wp:group -->'
    );
    $before = $project->readText('theme/style.css');
    $llm = new FakeLlm();
    $llm->queueText("body {\n    margin: 0;\n}");

    (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    assert_eq($before, $project->readText('theme/style.css'), 'style.css untouched');
    assert_contains('not scoped', $project->readText('logs/page-styles.log'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
