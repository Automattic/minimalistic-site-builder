<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\DesignDirectionStep;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;

test('style signatures describe image choice, palette, type and composition without decorative hooks', function () {
    $signature = 'Directional portraits, an ink and brass palette, narrow capitals and balanced composition.';
    $direction = DesignDirectionStep::normalize([
        'description' => 'A considered visual direction.',
        'style_signature' => "  {$signature}\n",
        'style_hooks' => ['design-frame', 'design-motif'],
    ]);
    assert_eq($signature, $direction['style_signature']);
    assert_true(!array_key_exists('style_hooks', $direction));
    $formatted = DesignDirectionStep::format($direction);
    assert_contains($signature, $formatted);
    assert_true(!str_contains($formatted, 'design-motif'));
    assert_true(!str_contains($formatted, 'design-frame'));
});

test('style prompts direct the image choice rather than asking for decorative shapes', function () {
    foreach (['design-direction.md', 'design-seed-judge.md', 'hero.md', 'section.md', 'page-styles.md'] as $file) {
        $prompt = file_get_contents(repo_path('prompts/' . $file));
        foreach (['design-frame', 'design-motif', 'style_hooks'] as $retired) {
            assert_true(!str_contains($prompt, $retired), "{$file} still advertises {$retired}");
        }
    }
    $images = file_get_contents(repo_path('prompts/image-generation.md'));
    assert_contains('Style signature', $images);
    assert_contains('subject and composition', $images);
    assert_contains('site-wide grade', $images);
    $direction = file_get_contents(repo_path('prompts/design-direction.md'));
    assert_true(!str_contains($direction, 'Do not promise assets this channel cannot create'));
    assert_contains('illustration', $direction);
    assert_true(!str_contains($images, 'Never emit a decorative image'));
    assert_true(!str_contains($images, 'Feature icons: use none'));
});

test('illustration subject and shared art direction survive the image prompt composer', function () {
    $prompt = \Automattic\SiteBuild\ImagePromptComposer::compose(
        'A botanical illustration of climbing vines with hand-drawn filigree around the foliage.',
        'wide feature image',
        'illustration',
        '',
        'Ink and brass illustration, precise line work and a dark green ground.',
    );
    assert_contains('hand-drawn filigree', $prompt);
    assert_contains('Style: illustration', $prompt);
    assert_contains('Art direction for all site imagery: Ink and brass illustration', $prompt);
});

test('retired decorative classes cannot trigger the CSS generator', function () {
    assert_eq([], PageStylesStep::classesIn('<div class="wp-block-group design-frame design-motif"><p>Original copy</p></div>'));
    $tmp = sys_get_temp_dir() . '/builder_style_signature_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText('theme/style.css', '/* Theme Name: Demo */');
        $project->writeJson('designDirection.json', [
            'style_signature' => 'Directional portraits and balanced composition.',
            'style_hooks' => ['design-motif'],
        ]);
        $markup = '<div class="wp-block-group design-motif"><p>Original copy</p><a href="/about/">About</a></div>';
        $project->writeText('theme/parts/demo.html', $markup);
        $llm = new FakeLlm();
        (new PageStylesStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
        assert_eq(0, $llm->completeCalls);
        assert_eq($markup, $project->readText('theme/parts/demo.html'));
        assert_true(!str_contains($project->readText('theme/style.css'), '::before'));
        assert_true(!$project->exists('warnings.json'), 'no warnings demanding retired ornament');
    } finally {
        remove_tree($tmp);
    }
});

test('page styles discards retired decorative rules while preserving layout CSS and content', function () {
    $tmp = sys_get_temp_dir() . '/builder_style_layout_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    try {
        $project->writeJson('theme/theme.json', ['version' => 3]);
        $project->writeText('theme/style.css', '/* Theme Name: Demo */');
        $markup = '<div class="wp-block-group sticky-side"><p>Original copy</p><a href="/about/">About</a></div>';
        $project->writeText('theme/parts/demo.html', $markup);
        $llm = new FakeLlm();
        $css = '.sticky-side {position:sticky;top:2rem}';
        $llm->queueText($css . '.design-motif::before {background:linear-gradient(currentColor, transparent)}');
        $step = new PageStylesStep($llm, new PromptRenderer(repo_path('prompts')));
        $step->run($project);
        $first = $project->readText('theme/style.css');
        assert_contains($css, $first);
        assert_true(!str_contains($first, 'design-motif'));
        assert_eq($markup, $project->readText('theme/parts/demo.html'));
        assert_contains('delivered removed', json_encode($project->readJson('warnings.json')));
        $llm->queueText($css);
        $step->run($project);
        assert_eq($first, $project->readText('theme/style.css'), 'resuming does not duplicate the utility appendix');
        $sibling = '/* static sibling */ .some-kit {color:inherit}';
        $project->writeText('theme/style.css', $first . "\n" . $sibling);
        $llm->queueText($css);
        $step->run($project);
        assert_contains($sibling, $project->readText('theme/style.css'));
    } finally {
        remove_tree($tmp);
    }
});
