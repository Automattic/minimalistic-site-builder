<?php
declare(strict_types=1);

/** A DESIGN.md-standard document body for use as a canned LLM response. */
function canned_design_md(): string
{
    return <<<MD
        ---
        name: Hearth & Crumb
        description: Warm, rustic neighborhood bakery
        colors:
          base: "#fdf6ec"
          contrast: "#2b2118"
          primary: "#8a5a2b"
          secondary: "#c98a5a"
          accent: "#e08a3c"
        typography:
          heading:
            fontFamily: Fraunces
          body:
            fontFamily: Source Sans 3
        rounded:
          sm: 4px
        spacing:
          md: 16px
        ---

        ## Overview
        Warm and inviting bakery brand for neighborhood locals, evoking a cozy hearth and fresh-baked bread every morning.

        ## Colors
        The base is a soft cream and contrast a deep cocoa. Primary is crust brown.

        ## Typography
        Fraunces headings paired with Source Sans 3 body for a rustic-but-readable feel.

        ## Layout
        Generous spacing, comfortable reading width, hero-first landing page.

        ## Components
        Accent-driven buttons, soft cards, simple nav.
        MD;
}

test('design-doc writes a standard design.md from prompt + spec', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'A cozy neighborhood bakery']);
    $project->writeJson('siteSpec.json', ['name' => 'Hearth & Crumb', 'slug' => 'hearth-crumb', 'visual_vibe' => 'warm and rustic']);

    $llm = new FakeLlm();
    $llm->queueText("```markdown\n" . canned_design_md() . "\n```"); // fenced — must be stripped
    $renderer = new PromptRenderer(repo_path('prompts'));

    (new DesignDocStep($llm, $renderer))->run($project);

    $md = $project->readText('design.md');
    assert_true(!str_starts_with($md, '```'), 'fence stripped');
    assert_contains('## Overview', $md);
    assert_contains('base: "#fdf6ec"', $md);             // front matter tokens preserved
    // Both inputs injected into the prompt.
    assert_contains('cozy neighborhood bakery', $llm->calls[0]['prompt']); // user prompt
    assert_contains('warm and rustic', $llm->calls[0]['prompt']);          // site spec

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-doc throws on too-short output', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'x']);
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $llm = new FakeLlm();
    $llm->queueText('too short');
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDocStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-doc throws when the YAML front matter is missing entirely', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'x']);
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $llm = new FakeLlm();
    // Long enough, but no YAML front matter at all.
    $llm->queueText("## Overview\n" . str_repeat('Words about the design. ', 20)
        . "\n## Colors\nsome\n## Typography\nfonts");
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDocStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-doc throws when one front-matter color token is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'x']);
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $llm = new FakeLlm();
    // Valid front matter and all sections, but 'accent' is dropped — this
    // exercises the per-token color loop (not the missing-front-matter branch).
    $body = "---\nname: X\ncolors:\n  base: \"#fff\"\n  contrast: \"#111\"\n  primary: \"#222\"\n"
        . "  secondary: \"#333\"\ntypography:\n  heading:\n    fontFamily: A\n"
        . "  body:\n    fontFamily: B\n---\n\n## Overview\n" . str_repeat('Design words. ', 20)
        . "\n## Colors\nstuff\n## Typography\nfonts";
    $llm->queueText($body);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDocStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('design-doc throws when a required section is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_doc_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('meta.json', ['prompt' => 'x']);
    $project->writeJson('siteSpec.json', ['name' => 'X']);
    $llm = new FakeLlm();
    // Valid front matter, but the body omits ## Typography.
    $body = "---\nname: X\ncolors:\n  base: \"#fff\"\n  contrast: \"#111\"\n  primary: \"#222\"\n"
        . "  secondary: \"#333\"\n  accent: \"#444\"\ntypography:\n  heading:\n    fontFamily: A\n"
        . "  body:\n    fontFamily: B\n---\n\n## Overview\n" . str_repeat('Design words. ', 20)
        . "\n## Colors\nstuff";
    $llm->queueText($body);
    $renderer = new PromptRenderer(repo_path('prompts'));
    assert_throws(function () use ($llm, $renderer, $project) {
        (new DesignDocStep($llm, $renderer))->run($project);
    });
    exec('rm -rf ' . escapeshellarg($tmp));
});
