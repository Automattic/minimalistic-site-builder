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

test('direction-fidelity stamps assigned card-style on an image card and skips the hero', function () {
    $hero = '<!-- wp:group {"className":"hero-composition--focal-subject-stage"} -->'
        . '<div class="wp-block-group hero-composition--focal-subject-stage">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="h.jpg" alt="" /></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    $card = '<!-- wp:group {"className":"card"} -->'
        . '<div class="wp-block-group card">'
        . '<!-- wp:image --><figure class="wp-block-image"><img src="c.jpg" alt="" /></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h2>Dish</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    [$out] = DirectionFidelityStep::repairCardStyle(
        $hero . $card,
        ['card_style' => 'flush'],
    );
    assert_contains('card-style--flush', $out);
    assert_true(!str_contains($out, 'hero-composition--focal-subject-stage card-style--flush'));
});

test('direction-fidelity never aborts when the home page is missing', function () {
    $tmp = sys_get_temp_dir() . '/builder_fid_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('designDirection.json', ['description' => 'x', 'motion' => 'calm']);

    quietly(fn () => (new DirectionFidelityStep())->run($project));

    assert_true($project->exists('logs/direction-fidelity.txt'));
    exec('rm -rf ' . escapeshellarg($tmp));
});
