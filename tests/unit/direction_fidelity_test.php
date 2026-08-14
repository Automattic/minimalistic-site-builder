<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\DirectionFidelityStep;

function fidelity_project(array $direction, string $home, string $functions = ''): array
{
    $tmp = sys_get_temp_dir() . '/builder_fid_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('designDirection.json', $direction);
    $project->writeJson('plugin/pages.json', ['pages' => [[
        'slug' => 'home',
        'title' => 'Home',
        'front' => true,
    ]]]);
    $project->writeText('plugin/pages/home.html', $home);
    $project->writeJson('theme/theme.json', [
        'settings' => [
            'color' => ['palette' => [
                ['slug' => 'base', 'color' => '#F5F1EA', 'name' => 'Base'],
                ['slug' => 'contrast', 'color' => '#1A1714', 'name' => 'Contrast'],
            ]],
            'typography' => ['fontFamilies' => [
                ['slug' => 'heading', 'fontFamily' => '"Fraunces", serif', 'name' => 'Heading'],
                ['slug' => 'body', 'fontFamily' => '"Source Sans 3", sans-serif', 'name' => 'Body'],
            ]],
        ],
    ]);
    $project->writeText('theme/functions.php', $functions !== '' ? $functions : "<?php\n");
    return [$project, $tmp];
}

test('direction-fidelity rewrites a wrong card-style marker to the assigned construction', function () {
    [$project, $tmp] = fidelity_project(
        ['description' => 'x', 'card_style' => 'overlap', 'motion' => 'none'],
        '<!-- wp:group {"className":"card-style--flush"} --><div class="card-style--flush"></div><!-- /wp:group -->',
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $home = $project->readText('plugin/pages/home.html');
    assert_contains('card-style--overlap', $home);
    assert_true(!str_contains($home, 'card-style--flush'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('direction-fidelity strips motion classes the profile does not allow', function () {
    [$project, $tmp] = fidelity_project(
        ['description' => 'x', 'motion' => 'none'],
        '<!-- wp:group {"className":"reveal-up"} --><div class="reveal-up"></div><!-- /wp:group -->',
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $home = $project->readText('plugin/pages/home.html');
    assert_true(!str_contains($home, 'reveal-up'));
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('direction-fidelity warns when a calm profile ships no motion classes', function () {
    [$project, $tmp] = fidelity_project(
        ['description' => 'x', 'motion' => 'calm'],
        '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $joined = implode(' ', $project->readJson('warnings.json')['direction-fidelity'] ?? []);
    assert_contains('zero kit classes', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('direction-fidelity warns when the shipped heading family drifts from the direction', function () {
    [$project, $tmp] = fidelity_project(
        [
            'description' => 'x',
            'type' => [
                'heading' => ['family' => 'Caveat', 'weights' => [400], 'italic' => false, 'axes' => [], 'character' => ''],
                'body' => ['family' => '', 'weights' => [], 'italic' => false, 'axes' => [], 'character' => ''],
            ],
        ],
        '<!-- wp:group --><div></div><!-- /wp:group -->',
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $joined = implode(' ', $project->readJson('warnings.json')['direction-fidelity'] ?? []);
    assert_contains('type.heading.family', $joined);
    assert_contains('Caveat', $joined);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('stampDevice marks the first non-hero full-bleed band', function () {
    $markup = '<!-- wp:group {"align":"full","className":"hero-composition--focal-subject-stage"} -->'
        . '<div class="wp-block-group alignfull hero-composition--focal-subject-stage"></div><!-- /wp:group -->'
        . '<!-- wp:group {"align":"full","className":"alignfull"} -->'
        . '<div class="wp-block-group alignfull"></div><!-- /wp:group -->';
    [$out, $repairs] = DirectionFidelityStep::stampDevice(
        $markup,
        ['device' => 'hairline-rule'],
        'plugin/pages/visit.html',
    );
    assert_contains('device--hairline-rule', $out);
    assert_eq(2, substr_count($out, 'device--hairline-rule'), 'comment attribute and saved HTML');
    assert_true($repairs !== []);
    assert_true(!str_contains($out, 'hero-composition--focal-subject-stage device--hairline-rule'));
});

test('stampDevice marks an HTML-first section group that has no alignfull', function () {
    $markup = '<!-- wp:group {"tagName":"section","anchor":"hours"} -->'
        . '<section id="hours" class="wp-block-group"></section><!-- /wp:group -->';
    [$out, $repairs] = DirectionFidelityStep::stampDevice(
        $markup,
        ['device' => 'stamp'],
        'plugin/pages/home.html',
    );
    assert_contains('device--stamp', $out);
    assert_true($repairs !== []);
});

test('direction-fidelity stamps the assigned card-style on image cards that have no marker', function () {
    $card = '<!-- wp:group {"className":"collection-card"} -->'
        . '<div class="wp-block-group collection-card">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="x.jpg" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h3>Acervo</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Obras raras.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    [$project, $tmp] = fidelity_project(
        ['description' => 'x', 'card_style' => 'flush', 'motion' => 'none'],
        $card,
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $home = $project->readText('plugin/pages/home.html');
    assert_contains('card-style--flush', $home);
    assert_contains('card-flush', $home);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('direction-fidelity stamps hover-lift from motion_note onto image cards', function () {
    $card = '<!-- wp:group {"className":"collection-card"} -->'
        . '<div class="wp-block-group collection-card">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="x.jpg" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h3>Acervo</h3><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    [$project, $tmp] = fidelity_project(
        [
            'description' => 'x',
            'card_style' => 'flush',
            'motion' => 'energetic',
            'motion_note' => 'Use kit classes: stagger-children, hover-lift.',
        ],
        $card,
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $home = $project->readText('plugin/pages/home.html');
    assert_contains('hover-lift', $home);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('direction-fidelity does not stamp card-style onto the hero inner', function () {
    $home = '<!-- wp:group {"className":"hero-composition--framed-portrait"} -->'
        . '<div class="wp-block-group hero-composition--framed-portrait">'
        . '<!-- wp:group {"className":"hero-inner"} -->'
        . '<div class="wp-block-group hero-inner">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="x.jpg" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h1>Hero</h1><!-- /wp:heading -->'
        . '</div><!-- /wp:group --></div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"card-grid"} -->'
        . '<div class="wp-block-group card-grid">'
        . '<!-- wp:group {"tagName":"article","className":"card"} -->'
        . '<article class="wp-block-group card">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="y.jpg" alt=""/></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h3>Card</h3><!-- /wp:heading -->'
        . '</article><!-- /wp:group --></div><!-- /wp:group -->';
    [$project, $tmp] = fidelity_project(
        ['description' => 'x', 'card_style' => 'borderless', 'motion' => 'none'],
        $home,
    );

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    $out = $project->readText('plugin/pages/home.html');
    assert_true(!str_contains($out, 'hero-inner card-style--borderless'));
    assert_contains('card-style--borderless', $out);
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('stampDevice does not mark a group inside the hero', function () {
    $markup = '<!-- wp:group {"align":"full","className":"hero-composition--layered-poster"} -->'
        . '<div class="wp-block-group alignfull hero-composition--layered-poster">'
        . '<!-- wp:group {"className":"hero-media"} -->'
        . '<div class="wp-block-group hero-media"></div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"tagName":"section","anchor":"hours"} -->'
        . '<section id="hours" class="wp-block-group"></section><!-- /wp:group -->';
    [$out, $repairs] = DirectionFidelityStep::stampDevice(
        $markup,
        ['device' => 'hairline-rule'],
        'plugin/pages/home.html',
    );
    assert_contains('device--hairline-rule', $out);
    assert_true(!str_contains($out, 'hero-media device--hairline-rule'));
    assert_contains('id="hours" class="wp-block-group device--hairline-rule"', $out);
    assert_true($repairs !== []);
});

test('direction-fidelity never aborts when the home page is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_fid_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'calm']);

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    assert_true($project->exists('logs/direction-fidelity.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
