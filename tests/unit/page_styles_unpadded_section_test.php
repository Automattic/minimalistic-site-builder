<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * SectionRhythm gives every section root an inline vertical padding preset from
 * the page plan's density. That is only ever a floor: whatever the design
 * authored for the same edge is reinforced after it and wins.
 *
 * A design that puts its rhythm INSIDE the section — padding on an inner
 * container, margins between blocks — authors no section padding at all, so
 * there is no declaration to reinforce and the preset is the only thing left
 * standing. Zero is as much an authored decision as 108px, and it has to be
 * preserved the same way, or the preset stacks on top of spacing the design
 * already had and every seam on the page opens by the preset's width.
 *
 * Measured on calm-lantern, whose six home sections carry no padding rule:
 * text-to-text seams read 187/143/189/162/189 in the design and
 * 317/228/264/245/275 in the theme. Zeroing the section padding in the browser
 * returned them to 180/146/182/163/193 — within font-ink tolerance of the
 * design, from +76..+130 out.
 */

/** @return array{0:\Automattic\SiteBuild\Project,1:string} */
function psus_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('design/page-artifact-map.json', ['home' => 'home']);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    return [$project, $tmp];
}

function psus_step(): PageStylesStep
{
    return new PageStylesStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')), htmlFirst: true);
}

/**
 * Every declaration body from a rule whose selector list mentions $idToken.
 *
 * Deliberately not an exact-selector lookup: the zero may be grouped into a
 * `:where(#a,#b)` subject list with its siblings, and a test pinned to one
 * spelling would fail on a regrouping that changed nothing about the behaviour.
 */
function psus_bodies_mentioning(string $css, string $idToken): string
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);
    $bodies = [];
    foreach ($matches as $match) {
        $selectorList = preg_replace('/\/\*.*?\*\//s', '', $match[1]);
        if (!is_string($selectorList) || !str_contains($selectorList, $idToken)) {
            continue;
        }
        $bodies[] = $match[2];
    }
    return implode("\n", $bodies);
}

/** True when $bodies zeroes $property with !important, whatever the spacing. */
function psus_zeroes(string $bodies, string $property): bool
{
    return preg_match('/' . preg_quote($property, '/') . '\s*:\s*0\s*!important/i', $bodies) === 1;
}

test('a section the design never padded keeps zero vertical padding', function () {
    [$project, $tmp] = psus_project('builder_ps_unpadded_section_');

    // The rhythm lives on an inner container. No rule anywhere gives a section
    // vertical padding, so each section's authored value is zero by omission.
    $siteCss = <<<'CSS'
.inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 64px 24px;
}
.inner h2 {
    margin: 0 0 1rem;
}
CSS;
    assert_true(
        preg_match('/(^|\})\s*section\s*\{/', $siteCss) !== 1,
        'the fixture authors no section rule at all — the whole point of the case',
    );
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);

    $project->writeText(
        'design/home.html',
        '<main>'
            . '<section id="services"><div class="inner"><h2>Services</h2><p>What we do</p></div></section>'
            . '<section id="about"><div class="inner"><h2>About</h2><p>Who we are</p></div></section>'
            . '</main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="services" class="wp-block-group is-layout-constrained">'
            . '<div class="wp-block-group inner is-layout-flow"><h2>Services</h2><p>What we do</p></div>'
            . '</section>'
            . '<section id="about" class="wp-block-group is-layout-constrained">'
            . '<div class="wp-block-group inner is-layout-flow"><h2>About</h2><p>Who we are</p></div>'
            . '</section>',
    );

    psus_step()->run($project);

    $style = $project->readText('theme/style.css');

    foreach (['services', 'about'] as $id) {
        $bodies = psus_bodies_mentioning($style, '#' . $id);
        assert_true(
            psus_zeroes($bodies, 'padding-top'),
            "#{$id} keeps its authored zero top padding, so the density preset cannot open a seam",
        );
        assert_true(
            psus_zeroes($bodies, 'padding-bottom'),
            "#{$id} keeps its authored zero bottom padding",
        );
    }

    // The inner container is where the design put its rhythm; it must survive.
    assert_contains(
        'padding',
        psus_bodies_mentioning($style, '.inner'),
        'the authored inner padding is still reinforced — the zero did not eat it',
    );

    psus_step()->run($project);
    assert_eq($style, $project->readText('theme/style.css'), 'the zero preservation is a fixed point');
    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * The floor still has to yield to a real authored value, or this fix would
 * flatten designs like azure-garden, whose #results authors
 * `padding-top: clamp(60px,9vw,108px)` and measured identical in design and
 * theme before any of this.
 */
test('an authored non-zero section padding still outranks the preserved zero', function () {
    [$project, $tmp] = psus_project('builder_ps_padded_section_');

    $project->writeText(TransformArtifacts::SITE_CSS, <<<'CSS'
.shell {
    max-width: 1200px;
    margin: 0 auto;
}
#results {
    padding-top: clamp(60px,9vw,108px);
}
CSS);
    $project->writeText(
        'design/home.html',
        '<main>'
            . '<section id="results" class="shell"><h2>Results</h2><p>Proof</p></section>'
            . '<section id="contact" class="shell"><h2>Contact</h2><p>Reach us</p></section>'
            . '</main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="results" class="wp-block-group shell is-layout-constrained">'
            . '<h2>Results</h2><p>Proof</p></section>'
            . '<section id="contact" class="wp-block-group shell is-layout-constrained">'
            . '<h2>Contact</h2><p>Reach us</p></section>',
    );

    psus_step()->run($project);

    $style = $project->readText('theme/style.css');

    // The zero floor and the authored value coexist by design: the floor is a
    // grouped `:where(...)` subject at (0,2,0) and the authored value is
    // id-qualified at (1,2,0), so the cascade already resolves this. Asserting
    // the floor is absent would encode a contract the pass never had.
    $winning = '';
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $style, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (str_contains($rule[1], '#results:') && str_contains($rule[2], 'padding-top')) {
            $winning = $rule[2];
        }
    }
    assert_contains(
        'clamp(60px,9vw,108px)',
        $winning,
        'the id-qualified rule that outranks the floor is the authored top padding',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});
