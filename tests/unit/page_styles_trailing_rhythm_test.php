<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\PageStylesStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\TransformArtifacts;

/**
 * The authored-rhythm tail has two inner branches: painted landmarks and
 * trailing flow controls. Only the first is about paint, so a flat design
 * that declares no background+radius surface anywhere must still get its
 * trailing control's authored block-end margin reinforced.
 */

/**
 * Trailing reinforcement is selector-scoped, so it carries a zero-specificity
 * guard that keeps a grid item from matching. Spelled out here rather than read
 * off the class: a test that took the constant would pass if it went empty.
 */
const PSTR_GRID_GUARD = ':not(:where(.blocks-engine-css-owned-grid > *))';

/** @return array{0:\Automattic\SiteBuild\Project,1:string} */
function pstr_project(string $prefix): array
{
    $tmp = sys_get_temp_dir() . '/' . $prefix . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeText('theme/style.css', "/*\nTheme Name: Demo\n*/\n");
    $project->writeJson('theme/theme.json', ['version' => 3]);
    $project->writeJson('design/page-artifact-map.json', ['home' => 'home']);
    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'front' => true]]]);
    return [$project, $tmp];
}

function pstr_step(): PageStylesStep
{
    return new PageStylesStep(new FakeLlm(), new PromptRenderer(repo_path('prompts')), htmlFirst: true);
}

/** Declaration bodies of every rule whose selector list carries $wanted verbatim. */
function pstr_bodies(string $css, string $wanted): string
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
    return implode("\n", $bodies);
}

/**
 * A flat editorial design: a coloured button, no border-radius anywhere, so
 * paintedRhythmSelectorKeys() is empty. The trailing control still has to be
 * defended against `:root :where(.is-layout-flow) > *:last-child{margin-block-end:0}`.
 */
test('a design with no painted surface still reinforces its trailing control margin', function () {
    [$project, $tmp] = pstr_project('builder_ps_trailing_flat_');
    $siteCss = <<<'CSS'
.copy p {
    margin: 0 0 0.75rem;
}
.actions {
    display: flex;
    gap: 1rem 1.5rem;
    margin: 0 0 clamp(1.5rem,4vw,2.25rem);
}
.btn {
    background: var(--accent);
    color: #FFFFFF;
    margin: 0.5rem 0 0.5rem;
}
CSS;
    assert_true(
        !str_contains($siteCss, 'border-radius'),
        'the fixture declares no painted surface at all',
    );
    $project->writeText(TransformArtifacts::SITE_CSS, $siteCss);
    $project->writeText(
        'design/home.html',
        '<main><section id="services"><div class="copy"><p>Copy</p><hr class="rule"></div>'
            . '<div class="actions"><a class="btn" href="#">Ask</a><span>which one fits</span></div>'
            . '</section></main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="services">'
            . '<div class="wp-block-group copy is-layout-flow"><p>Copy</p><hr class="rule"></div>'
            . '<div class="wp-block-group actions blocks-engine-css-owned-layout '
            . 'blocks-engine-css-owned-flow"><a class="btn" href="#">Ask</a>'
            . '<span>which one fits</span></div></section>',
    );

    pstr_step()->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains(
        'Preserve authored block-axis spacing against WordPress layout resets',
        $style,
        'the deterministic author-rhythm tail is present',
    );
    assert_contains(
        'margin-bottom: clamp(1.5rem,4vw,2.25rem)',
        pstr_bodies($style, ':root:root .actions:last-child' . PSTR_GRID_GUARD),
        'the trailing control keeps the authored block-end margin that held sections apart',
    );

    pstr_step()->run($project);
    assert_eq($style, $project->readText('theme/style.css'), 'the trailing reinforcement is a fixed point');
    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * The landmark branch is the one that genuinely reads paint. Un-gating the
 * trailing branch may not leak landmark rules into a flat design: a coloured
 * button with no radius is not a rhythm landmark.
 */
test('a design with no painted surface acquires no landmark rules', function () {
    [$project, $tmp] = pstr_project('builder_ps_trailing_no_landmark_');
    $project->writeText(TransformArtifacts::SITE_CSS, <<<'CSS'
.copy p {
    margin: 0 0 0.75rem;
}
.actions {
    margin: 0 0 clamp(1.5rem,4vw,2.25rem);
}
.btn {
    background: var(--accent);
    margin: 0.5rem 0 0.5rem;
}
CSS);
    $project->writeText(
        'design/home.html',
        '<main><section id="services"><div class="copy"><p>Copy</p><hr class="rule"></div>'
            . '<div class="actions"><a class="btn" href="#">Ask</a><span>which one fits</span></div>'
            . '</section></main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="services">'
            . '<div class="wp-block-group copy is-layout-flow"><p>Copy</p><hr class="rule"></div>'
            . '<div class="wp-block-group actions blocks-engine-css-owned-layout '
            . 'blocks-engine-css-owned-flow"><a class="btn" href="#">Ask</a>'
            . '<span>which one fits</span></div></section>',
    );

    pstr_step()->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains(
        'margin-bottom: clamp(1.5rem,4vw,2.25rem)',
        pstr_bodies($style, ':root:root .actions:last-child' . PSTR_GRID_GUARD),
        'the trailing control is reinforced, so this fixture proves an emitting run',
    );
    assert_eq(
        '',
        pstr_bodies($style, ':root:root .btn'),
        'a background without a radius is not a rhythm landmark',
    );
    assert_eq(
        '',
        pstr_bodies($style, ':root:root .copy p'),
        'a mid-flow element gets no landmark rule when nothing is painted',
    );
    assert_true(
        !str_contains($style, '.copy p:last-child'),
        'a mid-flow element is not a trailing control either, guarded or not',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * The painted path is unchanged by the un-gating: a landmark still routes
 * through the landmark branch (unscoped, both edges), and a trailing control
 * in the same design still routes through the trailing branch.
 */
test('a painted design still routes its landmarks through the landmark branch', function () {
    [$project, $tmp] = pstr_project('builder_ps_trailing_painted_');
    $project->writeText(TransformArtifacts::SITE_CSS, <<<'CSS'
.flow-card {
    background: var(--accent);
    border-radius: 1rem;
    margin: 1rem 2rem 3rem;
}
.actions {
    margin: 0 0 clamp(1.5rem,4vw,2.25rem);
}
CSS);
    $project->writeText(
        'design/home.html',
        '<main><section id="story"><div class="flow-card">Card</div>'
            . '<div class="actions">Ask</div></section></main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="story">'
            . '<div class="wp-block-group is-layout-flow"><div class="flow-card">Card</div></div>'
            . '<div class="wp-block-group actions blocks-engine-css-owned-layout '
            . 'blocks-engine-css-owned-flow">Ask</div></section>',
    );

    pstr_step()->run($project);

    $style = $project->readText('theme/style.css');
    $card = pstr_bodies($style, ':root:root .flow-card');
    assert_contains('margin-top: 1rem', $card, 'a painted landmark keeps its authored block-start margin');
    assert_contains('margin-bottom: 3rem', $card, 'a painted landmark keeps its authored block-end margin');
    assert_eq(
        '',
        pstr_bodies($style, ':root:root .flow-card:last-child'),
        'a painted landmark is not demoted to the trailing-control branch',
    );
    assert_contains(
        'margin-bottom: clamp(1.5rem,4vw,2.25rem)',
        pstr_bodies($style, ':root:root .actions:last-child' . PSTR_GRID_GUARD),
        'a trailing control in a painted design keeps the reinforcement it already had',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * One class can match both a grid item and an ordinary trailing control, and
 * the reinforcement is written per selector, not per element. The guard is the
 * only thing standing between the grid item and a margin that would add to its
 * track gap instead of to the section edge.
 */
test('a trailing control that is also a grid item is excluded by the emitted selector', function () {
    [$project, $tmp] = pstr_project('builder_ps_trailing_grid_item_');
    $project->writeText(TransformArtifacts::SITE_CSS, ".actions {\n    margin: 0 0 2.1rem;\n}\n");
    $project->writeText(
        'design/home.html',
        '<main><section id="story"><div class="actions">Ask</div></section>'
            . '<section id="closer"><div class="grid"><div class="actions">Ask</div></div>'
            . '</section></main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="story"><div class="wp-block-group actions '
            . 'blocks-engine-css-owned-flow">Ask</div></section>'
            . '<section id="closer"><div class="wp-block-group grid '
            . 'blocks-engine-css-owned-layout blocks-engine-css-owned-grid">'
            . '<div class="wp-block-group actions blocks-engine-css-owned-flow">Ask</div>'
            . '</div></section>',
    );

    pstr_step()->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains(
        'margin-bottom: 2.1rem',
        pstr_bodies($style, ':root:root .actions:last-child' . PSTR_GRID_GUARD),
        'the ordinary trailing control is still reinforced',
    );
    assert_true(
        !str_contains($style, ':root:root .actions:last-child {'),
        'no unguarded trailing rule is emitted for a class a grid item also matches',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

/**
 * Nothing may follow a pseudo-element, so appending `:last-child` to one builds
 * a selector the browser drops whole. Emitting it is dead bytes that read like
 * working CSS.
 */
test('a trailing control selector ending in a pseudo-element emits no rule', function () {
    [$project, $tmp] = pstr_project('builder_ps_trailing_pseudo_element_');
    $project->writeText(TransformArtifacts::SITE_CSS, <<<'CSS'
.marker::after {
    margin-bottom: 0.55rem;
}
.actions {
    margin: 0 0 2.1rem;
}
CSS);
    $project->writeText(
        'design/home.html',
        '<main><section id="story"><p class="marker">Sec</p>'
            . '<div class="actions">Ask</div></section></main>',
    );
    $project->writeText(
        'plugin/pages/home.html',
        '<section id="story">'
            . '<div class="wp-block-group is-layout-flow"><p class="marker">Sec</p></div>'
            . '<div class="wp-block-group actions blocks-engine-css-owned-flow">Ask</div></section>',
    );

    pstr_step()->run($project);

    $style = $project->readText('theme/style.css');
    assert_contains(
        'margin-bottom: 2.1rem',
        pstr_bodies($style, ':root:root .actions:last-child' . PSTR_GRID_GUARD),
        'the ordinary trailing control still emits, so this fixture is not vacuous',
    );
    assert_true(
        !str_contains($style, '::after:last-child'),
        'no rule is built by appending a pseudo-class to a pseudo-element',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});
