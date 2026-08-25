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

test('custom-motion validate rejects shape-owned radius declarations in rules and keyframes', function () {
    $cases = [
        'direct shorthand' => '.custom-motion img { border-radius: 50% !important; }',
        'physical longhand' => '.custom-motion { border-top-left-radius: 1rem; }',
        'logical longhand' => '.custom-motion { border-start-end-radius: 1rem; }',
        'vendor shorthand' => '.custom-motion { -webkit-border-radius: 1rem; }',
        'keyframe shorthand' => ".custom-motion { animation: custom-motion-round 1s both; }\n"
            . '@keyframes custom-motion-round { from { border-radius: 0; } to { border-radius: 50%; } }',
    ];
    foreach ($cases as $label => $css) {
        $problems = CustomMotionStep::validate($css, true);
        assert_contains('shape-owned image/button corner', implode('; ', $problems), $label);
    }

    assert_eq(
        [],
        CustomMotionStep::validate(
            '.custom-motion { border-radius: 1rem; all: revert-layer; transform: none; }',
            false,
        ),
        'a tagged generic wrapper may keep unrelated corner geometry',
    );

    foreach ([
        '.custom-motion > *',
        '.custom-motion > a',
        '.custom-motion [class]',
        '.custom-motion :not(.card)',
    ] as $selector) {
        $css = "{$selector} { border-radius: 9999px !important; transform: none; }";
        $problems = CustomMotionStep::validate($css, false);
        assert_contains('shape-owned image/button corner', implode('; ', $problems), $selector);
        [$repaired, $dropped] = CustomMotionStep::dropShapeOwnedDeclarations($css, false);
        assert_eq(['border-radius: 9999px !important'], $dropped, $selector);
        assert_contains('transform: none', $repaired);
        assert_true(!str_contains($repaired, 'border-radius'));
    }

    assert_eq(
        [],
        CustomMotionStep::validate(
            '.custom-motion .card { border-radius: 1rem; transform: none; }',
            true,
        ),
        'an explicit generic-card subject remains outside image/button ownership',
    );
});

test('custom-motion drops only radius declarations and preserves direct and keyframed motion', function () {
    $css = ".custom-motion img {\n"
        . "    display: block;\n"
        . "    --card-border-radius: 2rem;\n"
        . "    border-radius: 9999px !important;\n"
        . "    -webkit-border-radius: 50%;\n"
        . "    all: var(--shape-reset, initial) !important;\n"
        . "    animation: custom-motion-spin 1s both;\n"
        . "}\n"
        . "@keyframes custom-motion-spin {\n"
        . "    from { transform: rotate(0deg); border-top-left-radius: 0; }\n"
        . "    50% { transform: rotate(180deg); border-start-end-radius: 2rem; }\n"
        . "    to { transform: rotate(360deg); border-radius: 50%; }\n"
        . "}";

    [$repaired, $dropped] = CustomMotionStep::dropShapeOwnedDeclarations($css);

    assert_eq(6, count($dropped), 'radius forms and a CSS-wide reset are dropped');
    assert_true(
        preg_match('/(?<![-\w])border-radius\s*:/', $repaired) !== 1,
        'radius shorthand removed',
    );
    assert_true(!str_contains($repaired, 'border-top-left-radius'), 'physical longhand removed');
    assert_true(!str_contains($repaired, 'border-start-end-radius'), 'logical longhand removed');
    assert_true(!str_contains($repaired, '-webkit-border-radius'), 'vendor form removed');
    assert_true(!str_contains($repaired, 'all: var('), 'CSS-wide reset removed');
    assert_contains('--card-border-radius: 2rem', $repaired, 'similarly named custom property is not a radius declaration');
    assert_contains('animation: custom-motion-spin 1s both', $repaired, 'animation wiring survives');
    assert_contains('transform: rotate(0deg)', $repaired, 'keyframe start survives');
    assert_contains('transform: rotate(360deg)', $repaired, 'keyframe end survives');
    assert_true(
        substr_count($repaired, "\n") <= substr_count($css, "\n"),
        'the repair cannot create a line-ceiling rejection',
    );
    assert_eq([], CustomMotionStep::validate($repaired), 'remaining motion revalidates cleanly');
});

test('custom-motion drops only profile-owned motion tokens and preserves the animation', function () {
    $css = ".custom-motion {\n"
        . "    --motion-hover-duration: 180ms;\n"
        . "    --Motion-distance: 4px;\n"
        . "    --card-radius: 2rem;\n"
        . "    transition: transform var(--motion-hover-duration, 360ms);\n"
        . "}\n"
        . "@keyframes custom-motion-in {\n"
        . "    from { --motion-enter-duration: 150ms; transform: translateY(8px); }\n"
        . "    to { transform: none; }\n"
        . "}";

    [$repaired, $dropped] = CustomMotionStep::dropProfileOwnedMotionDeclarations($css);

    assert_eq(3, count($dropped), 'root and keyframe motion tokens are dropped');
    assert_true(!str_contains($repaired, '--motion-hover-duration:'), 'hover duration override removed');
    assert_true(!str_contains($repaired, '--Motion-distance:'), 'case-insensitive prefix removed');
    assert_true(!str_contains($repaired, '--motion-enter-duration:'), 'keyframe override removed');
    assert_contains('--card-radius: 2rem', $repaired, 'unrelated custom property survives');
    assert_contains('transition: transform var(--motion-hover-duration, 360ms)', $repaired, 'reading a token is not declaring it');
    assert_contains('transform: translateY(8px)', $repaired, 'keyframe motion survives');
    assert_eq([], CustomMotionStep::validate($repaired), 'remaining motion revalidates cleanly');
});

test('custom-motion motion scanning leaves declaration-like quoted content byte-identical', function () {
    $css = '.custom-motion::before { content: "foo; --motion-hover-duration: 180ms"; transform: none; }';
    [$repaired, $dropped] = CustomMotionStep::dropProfileOwnedMotionDeclarations($css);
    assert_eq($css, $repaired);
    assert_eq([], $dropped);
    assert_eq([], CustomMotionStep::validate($repaired));
});

test('custom-motion shape scanning leaves declaration-like quoted content byte-identical', function () {
    $css = '.custom-motion::before { content: "foo; border-radius: 2rem"; transform: none; }';
    [$repaired, $dropped] = CustomMotionStep::dropShapeOwnedDeclarations($css, true);
    assert_eq($css, $repaired);
    assert_eq([], $dropped);
    assert_eq([], CustomMotionStep::validate($repaired, true));
});

test('custom-motion shape scanning recognizes balanced root pseudos without owning descendants', function () {
    $css = '.custom-motion:not(:has(.excluded)) { border-radius: 1rem; transform: none; } '
        . '.custom-motion:is(:hover,:focus) { all: initial; opacity: 1; } '
        . '.custom-motion .card { border-radius: 2rem; color: inherit; }';

    [$repaired, $dropped] = CustomMotionStep::dropShapeOwnedDeclarations($css, true);

    assert_eq(['border-radius: 1rem', 'all: initial'], $dropped);
    assert_contains('transform: none', $repaired);
    assert_contains('opacity: 1', $repaired);
    assert_contains('.custom-motion .card { border-radius: 2rem', $repaired);
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
        // Same shape spelled with clip-path. hiddenContentProblems() exempts
        // every keyframe so an entrance may start clipped (BIGR-887), which
        // leaves the parked LAST step to this walk alone.
        'clip-path exit ends hidden' => ".custom-motion { animation: custom-motion-out 1s forwards; }\n"
            . "@keyframes custom-motion-out { from { clip-path: inset(0); } to { clip-path: inset(0 0 100% 0); } }",
        'clip-path parked by both'   => ".custom-motion { animation: custom-motion-iris 1s both; }\n"
            . "@keyframes custom-motion-iris { from { clip-path: circle(100%); } to { clip-path: circle(0); } }",
        'mid-keyframe full clip'     => ".custom-motion { animation: custom-motion-wink 1s both; }\n"
            . "@keyframes custom-motion-wink { 0% { clip-path: inset(0); } 50%, 100% { clip-path: inset(100%); } }",
        'motion token override'     => ".custom-motion { --motion-hover-duration: 180ms; transform: none; }",
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
    // And so is a clip-path START — the wipe-in and the iris-in (BIGR-887).
    assert_eq([], CustomMotionStep::validate(
        ".custom-motion { animation: custom-motion-wipe 1s both; }\n"
        . "@keyframes custom-motion-wipe { from { clip-path: inset(0 0 100% 0); } to { clip-path: inset(0); } }"
    ));
    assert_eq([], CustomMotionStep::validate(
        ".custom-motion { animation: custom-motion-iris 1s both; }\n"
        . "@keyframes custom-motion-iris { 0% { clip-path: circle(0); } 100% { clip-path: circle(75%); } }"
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

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    assert_eq([], $llm->calls);
    assert_true(!str_contains($project->readText('theme/style.css'), 'Custom motion'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion skips without an LLM call when the markup carries no tag', function () {
    [$project, $tmp] = cm_project('builder_cm_notag_');
    $project->writeText('theme/parts/section-hero.html', '<!-- wp:heading --><h1>Hi</h1><!-- /wp:heading -->');
    $llm = new FakeLlm();

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    assert_eq([], $llm->calls);
    assert_contains('no', $project->readText('logs/custom-motion.log'));
    // The user's explicit request went unimplemented — a durable defect.
    $joined = implode(' ', $project->readJson('warnings.json')['custom-motion'] ?? []);
    assert_contains('not implemented', $joined);
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

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    $style = $project->readText('theme/style.css');
    assert_contains('@media screen and (prefers-reduced-motion: no-preference) {', $style, 'deterministic wrapper added (screen: print must not render a paused entrance)');
    assert_contains('custom-motion-spin', $style);
    // The prompt carried the verbatim request and the tagged element context.
    assert_contains('the logo should spin on hover', $llm->calls[0]['prompt']);
    assert_contains('wp-block-image custom-motion', $llm->calls[0]['prompt']);
    assert_contains('Contained-media and button corner shape is build-owned', $llm->calls[0]['prompt']);
    assert_contains('Never declare a `--motion-*` custom property', $llm->calls[0]['prompt']);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion removes shape overrides and ships the remaining animation with actionable warnings', function () {
    [$project, $tmp] = cm_project('builder_cm_shape_salvage_');
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:image {"className":"custom-motion"} --><figure class="wp-block-image custom-motion">'
        . '<img src="logo.png" alt="Logo"/></figure><!-- /wp:image -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(
        ".custom-motion img {\n"
        . "    border-radius: 50% !important;\n"
        . "    animation: custom-motion-spin 0.8s ease-in-out;\n"
        . "}\n"
        . "@keyframes custom-motion-spin {\n"
        . "    from { transform: rotate(0deg); border-start-start-radius: 0; }\n"
        . "    to { transform: rotate(360deg); border-start-start-radius: 50%; }\n"
        . "}"
    );

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    $style = $project->readText('theme/style.css');
    assert_contains('animation: custom-motion-spin 0.8s ease-in-out', $style, 'remaining animation ships');
    assert_contains('transform: rotate(360deg)', $style, 'remaining keyframe motion ships');
    assert_true(!str_contains($style, 'border-radius:'), 'direct radius does not ship');
    assert_true(!str_contains($style, 'border-start-start-radius'), 'keyframe radius does not ship');
    $log = $project->readText('logs/custom-motion.log');
    assert_contains('SALVAGED CSS', $log);
    assert_contains('border-radius: 50% !important', $log);
    $warnings = implode(' ', $project->readJson('warnings.json')['custom-motion'] ?? []);
    assert_contains("file='theme/style.css'", $warnings);
    assert_contains("block='generated custom-motion CSS'", $warnings);
    assert_contains('authored=', $warnings);
    assert_contains('delivered=removed', $warnings);
    assert_contains('disposition=dropped a shape-owned corner declaration', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion removes motion-token overrides and ships the remaining animation with actionable warnings', function () {
    [$project, $tmp] = cm_project('builder_cm_motion_salvage_');
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:group {"className":"custom-motion"} --><div class="wp-block-group custom-motion">'
        . '<p>Card</p></div><!-- /wp:group -->'
    );
    $llm = new FakeLlm();
    $llm->queueText(
        ".custom-motion {\n"
        . "    --motion-hover-duration: 180ms;\n"
        . "    transition: transform var(--motion-hover-duration, 360ms);\n"
        . "}\n"
        . ".custom-motion:hover {\n"
        . "    transform: translateY(-4px);\n"
        . "}"
    );

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    $style = $project->readText('theme/style.css');
    assert_contains('transition: transform var(--motion-hover-duration, 360ms)', $style, 'remaining animation ships');
    assert_contains('transform: translateY(-4px)', $style, 'hover motion ships');
    assert_true(!str_contains($style, '--motion-hover-duration: 180ms'), 'token override does not ship');
    $log = $project->readText('logs/custom-motion.log');
    assert_contains('SALVAGED CSS', $log);
    assert_contains('--motion-hover-duration: 180ms', $log);
    $warnings = implode(' ', $project->readJson('warnings.json')['custom-motion'] ?? []);
    assert_contains("file='theme/style.css'", $warnings);
    assert_contains("block='generated custom-motion CSS'", $warnings);
    assert_contains('authored=', $warnings);
    assert_contains('delivered=removed', $warnings);
    assert_contains('disposition=dropped a profile-owned motion custom property declaration', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('custom-motion preserves unrelated radius on a tagged generic group', function () {
    [$project, $tmp] = cm_project('builder_cm_generic_radius_');
    $project->writeText(
        'theme/parts/section-hero.html',
        '<!-- wp:group {"className":"custom-motion"} --><div class="wp-block-group custom-motion">'
        . '<p>Card</p></div><!-- /wp:group -->',
    );
    $llm = new FakeLlm();
    $llm->queueText('.custom-motion { border-radius: 1rem; transform: translateY(1px); }');

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    assert_contains('border-radius: 1rem', $project->readText('theme/style.css'));
    assert_true(!$project->exists('warnings.json'));
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

    quietly(fn () => (new CustomMotionStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project));

    assert_eq($before, $project->readText('theme/style.css'));
    assert_contains('PROBLEMS', $project->readText('logs/custom-motion.log'));

    // The user explicitly asked for this animation; shipping without it is a
    // delivered defect, recorded durably for the later repair pass.
    $joined = implode(' ', $project->readJson('warnings.json')['custom-motion'] ?? []);
    assert_contains('not implemented', $joined);
    assert_contains('model CSS rejected', $joined);
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
