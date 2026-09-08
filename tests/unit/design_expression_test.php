<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignExpression;
use Automattic\SiteBuild\DirectionExecutability;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('design expression finds delivered group hooks, not promises in text or comments', function () {
    assert_eq(['design-frame', 'design-motif'], PageStylesStep::classesIn(
        '<!-- wp:group {"className":"design-motif"} --><div class="wp-block-group design-frame design-motif"><p>Real copy</p></div><!-- /wp:group -->'
    ));
    assert_eq([], PageStylesStep::classesIn(
        '<!-- design-frame --><p>design-motif</p><img class="design-frame"><div data-class="design-motif"></div>'
    ));
});

test('design expression supports contrasting geometric and organic paint without recipes', function () {
    foreach ([
        '.design-frame {border: 3px double var(--wp--preset--color--primary)} .design-motif::before {background: repeating-conic-gradient(from 45deg, var(--wp--preset--color--primary) 0deg 10deg, transparent 10deg 30deg); height: 4rem; width: 50%}',
        '.design-frame {border: 2px solid var(--wp--preset--color--primary); border-radius: 35% 8%} .design-motif::after {background: radial-gradient(ellipse, var(--wp--preset--color--primary), transparent); border-radius: 70% 30%; height: 6rem}',
    ] as $css) {
        assert_eq([], PageStylesStep::validate($css));
        assert_eq([$css, []], PageStylesStep::dropOffendingDeclarations($css));
    }
});

test('design expression cannot reach siblings, descendants, content or media', function () {
    foreach (['.design-frame + body', '.design-frame p', '.design-motif::before, body', '.design-frame:has(p)', '.design-motif', '.design-frame::before'] as $selector) {
        assert_true(PageStylesStep::validate($selector . '{border: 1px solid currentColor}') !== [], $selector);
    }
    foreach (['position:fixed', 'content:"Buy now"', 'width:200vw', 'height:100vh', 'margin-top:-10rem', 'transform:scale(500)', 'z-index:9999', 'background:url(https://example.com/a)', '--paint:red', 'background:var(--arbitrary)', 'background:var(--wp--preset--color--primary, url(x))'] as $decl) {
        assert_true(PageStylesStep::validate('.design-motif::before {' . $decl . '}') !== [], $decl);
    }
    foreach (['background:var(--wp--preset--color--primary)', 'color:transparent', 'clip-path:circle(0)', 'font-size:0', 'padding:0', 'display:none', 'border-width:100vw'] as $decl) {
        assert_true(PageStylesStep::validate('.design-frame {' . $decl . '}') !== [], $decl);
    }
});

test('design expression salvage removes only unsafe declarations and reaches a fixed point', function () {
    $safe = 'border: 2px double var(--wp--preset--color--primary);';
    $sibling = '.sticky-side {position: sticky; top: 2rem}';
    $css = '.design-frame {' . $safe . 'color:transparent;}' . $sibling;
    [$out, $dropped] = PageStylesStep::dropOffendingDeclarations($css);
    assert_eq('.design-frame {' . $safe . '}' . $sibling, $out);
    assert_eq(1, count($dropped));
    assert_eq([], PageStylesStep::validate($out));
    assert_eq([$out, []], PageStylesStep::dropOffendingDeclarations($out));
});

test('style signature survives normalization and reaches downstream authors', function () {
    $warnings = [];
    $direction = DesignDirectionStep::normalize(['description' => 'A graphic direction.', 'style_signature' => " Stepped gold geometry via design-motif above introductory copy.\nDouble design-frame borders around the invitation. "]);
    assert_eq('Stepped gold geometry via design-motif above introductory copy. Double design-frame borders around the invitation.', $direction['style_signature']);
    assert_contains($direction['style_signature'], DesignDirectionStep::format($direction));
});

test('style delivery audit checks actual markup and paint, not the style name', function () {
    $direction = ['requested_style' => 'art-deco', 'style_signature' => 'Gold geometry via design-motif, paired design-frame borders.', 'style_hooks' => ['design-motif', 'design-frame']];
    $rows = DesignExpression::deliveryWarnings($direction, [], '');
    assert_eq(2, count($rows));
    assert_contains('design-motif', implode(' ', $rows));
    assert_contains('delivered=', implode(' ', $rows));
    assert_eq(2, count(DesignExpression::deliveryWarnings($direction, ['design-frame', 'design-motif'], '/* design-motif */ .design-frame {}')));
    assert_eq(2, count(DesignExpression::deliveryWarnings($direction, ['design-frame', 'design-motif'], '.design-frame {border-color: currentColor} .design-motif::before {background:transparent}')));
    assert_eq([], DesignExpression::deliveryWarnings($direction, ['design-frame', 'design-motif'], '.design-frame {border: 2px double currentColor} .design-motif::before {background:linear-gradient(currentColor, transparent)}'));
    assert_eq([], DesignExpression::deliveryWarnings(['style_signature' => 'Monochrome typography and unadorned whitespace.'], [], ''));
    assert_eq([], DesignExpression::deliveryWarnings(['style_signature' => 'No design-frame or design-motif; typography carries this.', 'style_hooks' => []], [], ''));
    assert_eq(1, count(DesignExpression::deliveryWarnings(['requested_style' => 'art-deco'], [], '')));
});

test('CSS-executable motifs are not confused with unsupported drawn illustration', function () {
    assert_eq([], DirectionExecutability::problems(['description' => 'A geometric motif rendered as a gradient in design-motif.', 'device' => 'none']));
    assert_eq(1, count(DirectionExecutability::problems(['description' => 'Hand-drawn filigree runs along the design-motif.', 'device' => 'none'])));
});

test('page styles delivers expression CSS, warns on lost paint, and resumes without duplicate appendices', function () {
    $tmp = sys_get_temp_dir() . '/builder_expression_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/style.css', "/* Theme Name: Demo */\n");
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeJson('designDirection.json', ['style_signature' => 'Double design-frame borders and radial design-motif.', 'style_hooks' => ['design-frame', 'design-motif']]);
        $markup = '<!-- wp:group {"className":"design-frame design-motif"} --><div class="wp-block-group design-frame design-motif"><p>Keep this copy</p><a href="/about/">About</a></div><!-- /wp:group -->';
        $project->writeText('theme/parts/demo.html', $markup);
        $llm = new FakeLlm();
        $css = '.design-frame {border: 2px double var(--wp--preset--color--primary)} .design-motif::before {background:var(--wp--preset--color--primary); position:fixed}';
        $step = new PageStylesStep($llm, new PromptRenderer(repo_path('prompts')));
        $llm->queueText($css);
        $step->run($project);
        $first = $project->readText('theme/style.css');
        assert_contains('pointer-events: none', $first);
        assert_contains('.design-motif::before', $first);
        assert_contains('div.wp-block-group.design-frame', $first, 'a misplaced hook cannot paint an image or control');
        assert_true(!str_contains($first, 'position:fixed'));
        assert_eq($markup, $project->readText('theme/parts/demo.html'));
        assert_contains('position:fixed', json_encode($project->readJson('warnings.json')));
        $llm->queueText($css);
        $step->run($project);
        assert_eq($first, $project->readText('theme/style.css'));
        $llm->queueText('.design-frame {border: 2px double var(--wp--preset--color--primary)} .design-motif::before {background:url(x)}');
        $step->run($project);
        assert_contains('no decorative paint', json_encode($project->readJson('warnings.json')));
    } finally {
        remove_tree($tmp);
    }
});

test('explicit style hooks normalize without inventing artwork or duplicating warnings', function () {
    $warnings = [];
    assert_eq(['design-frame'], DesignExpression::normalizeHooks(['design-frame', 'filigree', 'design-frame', ['bad']], $warnings));
    assert_eq(2, count($warnings));
    assert_contains("path='style_hooks'", $warnings[0]);
    assert_contains('delivered=removed', $warnings[0]);
    $again = [];
    assert_eq(['design-frame'], DesignExpression::normalizeHooks(['design-frame'], $again));
    assert_eq([], $again);
    $direction = DesignDirectionStep::normalize(['description' => 'Geometric.', 'style_hooks' => ['design-frame']]);
    assert_eq(['design-frame'], $direction['style_hooks']);
    assert_contains('**Style hooks**: design-frame', DesignDirectionStep::format($direction));
});

test('style expression hooks survive the real Gutenberg serializer', function () {
    $tmp = sys_get_temp_dir() . '/builder_expression_save_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/parts/demo.html', '<!-- wp:group {"className":"design-frame design-motif","layout":{"type":"constrained"}} --><div class="design-frame design-motif"><!-- wp:paragraph --><p>Original copy <a href="/about/">About</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->');
        $fixer = new \Automattic\SiteBuild\PhpBlockFixer();
        $report = $fixer->fix($project->path('theme'));
        assert_true(!str_contains($report, 'failed'), $report);
        $saved = $project->readText('theme/parts/demo.html');
        assert_eq(['design-frame', 'design-motif'], PageStylesStep::classesIn($saved));
        assert_contains('Original copy <a href="/about/">About</a>', $saved);
        $fixer->fix($project->path('theme'));
        assert_eq($saved, $project->readText('theme/parts/demo.html'));
    } finally {
        remove_tree($tmp);
    }
});

test('expression scoping preserves media rules, comments and later style siblings on resume', function () {
    $tmp = sys_get_temp_dir() . '/builder_expression_media_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeText('theme/style.css', '/* Theme Name: Demo */');
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText('theme/parts/demo.html', '<div class="wp-block-group design-frame design-motif"></div>');
        $css = '@media (min-width: 600px) { .design-frame {border: /* authored */ 2px solid currentColor} .design-motif::before, .design-motif::after {background:linear-gradient(currentColor, transparent)} }';
        assert_eq([], PageStylesStep::validate($css));
        assert_eq([$css, []], PageStylesStep::dropOffendingDeclarations($css));
        $llm = new FakeLlm();
        $step = new PageStylesStep($llm, new PromptRenderer(repo_path('prompts')));
        $llm->queueText($css);
        $step->run($project);
        assert_contains('@media (min-width: 600px)', $project->readText('theme/style.css'));
        assert_contains('div.wp-block-group.design-motif::before, div.wp-block-group.design-motif::after', $project->readText('theme/style.css'));
        $sibling = "\n/* static sibling */ .some-kit { color: inherit; }\n";
        $project->writeText('theme/style.css', $project->readText('theme/style.css') . $sibling);
        $llm->queueText($css);
        $step->run($project);
        assert_contains(trim($sibling), $project->readText('theme/style.css'));
    } finally {
        remove_tree($tmp);
    }
});

test('a desktop-only motif does not create an empty decorative box on mobile', function () {
    $foundation = DesignExpression::foundation('@media (min-width: 782px) { .design-motif::before {background:linear-gradient(currentColor, transparent)} }');
    assert_true(str_starts_with($foundation, '@media (min-width: 782px)'), 'generated content and its dimensions share the paint media condition');
    assert_contains('div.wp-block-group.design-motif::before', $foundation);
    assert_eq('', DesignExpression::foundation('.design-motif::before {background:transparent}'));
});
