<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\CustomMotionStep;
use Automattic\SiteBuild\Steps\SiteSpecStep;
use Automattic\SiteBuild\Tests\FakeLlm;

const CM_VALID_CSS = <<<CSS
    .custom-motion {
        display: inline-block;
    }
    .custom-motion:hover {
        animation: custom-motion-spin 0.8s ease-in-out;
    }
    @keyframes custom-motion-spin {
        from { transform: rotate(0deg); opacity: 0; }
        to { transform: rotate(360deg); opacity: 1; }
    }
    CSS;

function cm_project(string $prefix, string $request = 'the logo should spin on hover'): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    if ($request !== '') {
        $project->writeJson('siteSpec.json', ['name' => 'Demo', 'animation_request' => $request]);
    } else {
        $project->writeJson('siteSpec.json', ['name' => 'Demo', 'animation_request' => '']);
    }
    return [$project, $tmp];
}

test('custom-motion validate accepts scoped rules with custom-motion keyframes', function () {
    assert_eq([], CustomMotionStep::validate(CM_VALID_CSS));
});

test('custom-motion validate keeps the hidden-content and scoping rules', function () {
    $cases = [
        'unscoped selector'         => ".logo { animation: custom-motion-x 1s; }",
        'foreign keyframe name'     => "@keyframes spin { from { transform: none; } to { transform: rotate(1turn); } }",
        'display none'              => ".custom-motion { display: none; }",
        'visibility hidden'         => ".custom-motion { visibility: hidden; }",
        'opacity 0 at rest'         => ".custom-motion { opacity: 0; }",
        'opacity 0% at rest'        => ".custom-motion { opacity: 0%; }",
        'opacity .0 at rest'        => ".custom-motion { opacity: .0; }",
        'opacity calc(0) at rest'   => ".custom-motion { opacity: calc(0); }",
        'exit animation ends hidden' => ".custom-motion { animation: custom-motion-out 1s forwards; }\n"
            . "@keyframes custom-motion-out { from { opacity: 1; } to { opacity: 0; } }",
        'mid-keyframe zero opacity' => ".custom-motion { animation: custom-motion-blink 1s both; }\n"
            . "@keyframes custom-motion-blink { 0% { opacity: 1; } 50%, 100% { opacity: 0%; } }",
        'url()'                     => ".custom-motion { background: url(x.png); }",
        'image-set()'               => '.custom-motion { background-image: image-set("https://example.invalid/t.png" 1x); }',
        'prefixed image-set()'      => '.custom-motion { background-image: -webkit-image-set("https://example.invalid/t.png" 1x); }',
        'cross-fade()'              => '.custom-motion { background-image: cross-fade(image("a.png"), image("b.png"), 50%); }',
        '@import'                   => "@import 'x.css';\n.custom-motion { color: inherit; }",
        'empty'                     => '',
        'unbalanced'                => ".custom-motion { transform: none;",
        'wrapper escape'            => "}\n.custom-motion { animation: custom-motion-x 2s linear infinite; }\n@media (prefers-reduced-motion: no-preference) {",
    ];
    foreach ($cases as $label => $css) {
        assert_true(CustomMotionStep::validate($css) !== [], $label);
    }
    // opacity:0 in a keyframe START is the allowed exception (entrances).
    assert_eq([], CustomMotionStep::validate(
        ".custom-motion { animation: custom-motion-in 1s both; }\n"
        . "@keyframes custom-motion-in { from { opacity: 0; } to { opacity: 1; } }"
    ));
    assert_eq([], CustomMotionStep::validate(
        ".custom-motion { animation: custom-motion-in 1s both; }\n"
        . "@keyframes custom-motion-in { 0% { opacity: 0; } 100% { opacity: 1; } }"
    ));
});

test('custom-motion stripKeyframes removes nested-brace blocks and collects names and bodies', function () {
    [$names, $rest, $bodies] = CustomMotionStep::stripKeyframes(
        ".custom-motion { color: inherit; }\n@keyframes custom-motion-a { 0% { opacity: 0; } 100% { opacity: 1; } }\n.custom-motion img { transform: none; }"
    );
    assert_eq(['custom-motion-a'], $names);
    assert_true(!str_contains($rest, 'opacity'), 'keyframe bodies removed');
    assert_contains('.custom-motion img', $rest, 'rules around the block survive');
    assert_eq(1, count($bodies));
    assert_contains('0% { opacity: 0; }', $bodies[0], 'body collected for the step-level checks');
});

test('custom-motion no-ops without an LLM call when no animation was requested', function () {
    [$project, $tmp] = cm_project('builder_cm_skip_', '');
    $llm = new FakeLlm(); // any call would throw

    ob_start();
    (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    ob_end_clean();

    assert_eq([], $llm->calls);
    assert_true(!str_contains($project->readText('theme/style.css'), 'Custom motion'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion skips without an LLM call when the markup carries no tag', function () {
    [$project, $tmp] = cm_project('builder_cm_notag_');
    $project->writeText('theme/parts/section-hero.html', '<!-- wp:heading --><h1>Hi</h1><!-- /wp:heading -->');
    $llm = new FakeLlm();

    ob_start();
    (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    ob_end_clean();

    assert_eq([], $llm->calls);
    assert_contains('no', $project->readText('logs/custom-motion.log'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion acquires dynamic-block targets tagged only in comment attributes', function () {
    [$project, $tmp] = cm_project('builder_cm_dyn_');
    // wp:site-logo is dynamic: void comment, no saved HTML tag to scan.
    $project->writeText(
        'theme/parts/header.html',
        '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n"
        . '<div class="wp-block-group"><!-- wp:site-logo {"width":120,"className":"custom-motion"} /--></div>' . "\n"
        . '<!-- /wp:group -->'
    );
    $tagged = CustomMotionStep::taggedElements($project);
    assert_eq(1, count($tagged));
    assert_contains('wp:site-logo', $tagged[0]);
    assert_contains('custom-motion', $tagged[0]);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('the header and footer prompts teach the custom-motion tag (sections must skip chrome)', function () {
    foreach (['prompts/header.md', 'prompts/footer.md'] as $prompt) {
        $text = file_get_contents(repo_path($prompt));
        assert_contains('animation_request', $text, "{$prompt} reads the request");
        assert_contains('custom-motion', $text, "{$prompt} places the tag");
    }
});

test('custom-motion appends validated CSS wrapped in the reduced-motion media query', function () {
    [$project, $tmp] = cm_project('builder_cm_ok_');
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:image {"className":"custom-motion"} --><figure class="wp-block-image custom-motion"><img src="logo.png" alt="Logo"/></figure><!-- /wp:image -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(CM_VALID_CSS);

    ob_start();
    (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    ob_end_clean();

    $style = $project->readText('theme/style.css');
    assert_contains('@media (prefers-reduced-motion: no-preference) {', $style, 'deterministic wrapper added');
    assert_contains('custom-motion-spin', $style);
    // The prompt carried the verbatim request and the tagged element context.
    assert_contains('the logo should spin on hover', $llm->calls[0]['prompt']);
    assert_contains('wp-block-image custom-motion', $llm->calls[0]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion rejects bad CSS, logs, and leaves style.css untouched', function () {
    [$project, $tmp] = cm_project('builder_cm_bad_');
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:image {"className":"custom-motion"} --><figure class="wp-block-image custom-motion"></figure><!-- /wp:image -->'
    );
    $before = $project->readText('theme/style.css');
    $llm = new FakeLlm();
    $llm->queueText('.logo { display: none; }');

    ob_start();
    (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);
    ob_end_clean();

    assert_eq($before, $project->readText('theme/style.css'));
    assert_contains('PROBLEMS', $project->readText('logs/custom-motion.log'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('animationRequestOf reads the verbatim flag and defaults empty', function () {
    $tmp = sys_get_temp_dir() . '/builder_cm_spec_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    assert_eq('', SiteSpecStep::animationRequestOf($project), 'no spec yet');

    $project->writeJson('siteSpec.json', ['name' => 'Demo']);
    assert_eq('', SiteSpecStep::animationRequestOf($project), 'spec predates the field');

    $project->writeJson('siteSpec.json', ['name' => 'Demo', 'animation_request' => ' spin the logo ']);
    assert_eq('spin the logo', SiteSpecStep::animationRequestOf($project));
    exec('rm -rf ' . escapeshellarg($tmp));
});
