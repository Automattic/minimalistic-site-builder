<?php
declare(strict_types=1);

use Automattic\SiteBuild\CtaBudget;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Steps\CtaBudgetStep;

function cta_button(string $label, string $href, string $extra = ''): string
{
    return '<!-- wp:button -->' . "\n"
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $href . '"'
        . $extra . '>' . $label . '</a></div>' . "\n"
        . '<!-- /wp:button -->';
}

test('CtaBudget demotes every button beyond the budget to a text action that keeps its link', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n<div>\n"
        . '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->' . "\n"
        . '<div class="wp-block-buttons">' . "\n"
        . cta_button('See full <em>specifications</em>', '/products/', ' target="_blank" rel="noopener"') . "\n"
        . '</div>' . "\n" . '<!-- /wp:buttons -->' . "\n"
        . "</div>\n<!-- /wp:group -->";

    $result = CtaBudget::apply($markup, 0);

    assert_eq(1, $result['demoted']);
    assert_eq(0, $result['kept']);
    $out = $result['markup'];
    assert_true(!str_contains($out, 'wp:button'), 'no button block survives a zero budget');
    assert_contains('<!-- wp:paragraph {"align":"center","className":"text-action"} -->', $out);
    assert_contains(
        '<p class="text-action"><a href="/products/" target="_blank" rel="noopener">See full <em>specifications</em></a></p>',
        $out,
    );
    assert_contains('<!-- /wp:paragraph -->', $out);
    assert_contains('<!-- wp:group', $out, 'the surrounding structure is untouched');
});

test('CtaBudget keeps the first buttons within the budget and demotes the rest, in document order', function () {
    $markup = '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons">' . "\n"
        . cta_button('Send an inquiry', '/contact/') . "\n"
        . cta_button('See the menu', '/menu/') . "\n"
        . '</div>' . "\n" . '<!-- /wp:buttons -->';

    $result = CtaBudget::apply($markup, 1);

    assert_eq(1, $result['kept']);
    assert_eq(1, $result['demoted']);
    $out = $result['markup'];
    assert_eq(1, substr_count($out, '<!-- wp:button -->'), 'exactly one button block remains');
    assert_contains('wp-block-button__link wp-element-button" href="/contact/"', $out, 'the kept one is the first');
    assert_contains('<p class="text-action"><a href="/menu/">See the menu</a></p>', $out, 'the second becomes a link');
    $rowEnd = strpos($out, '<!-- /wp:buttons -->');
    $link = strpos($out, '<!-- wp:paragraph');
    assert_true($rowEnd !== false && $link !== false && $link > $rowEnd, 'the demoted link follows the buttons row');
});

test('CtaBudget carries a card row bottom alignment onto the demoted link', function () {
    $markup = '<!-- wp:buttons {"className":"cta-bottom"} -->' . "\n"
        . '<div class="wp-block-buttons cta-bottom">' . "\n"
        . cta_button('Full seed data', '/products/#seeds') . "\n"
        . '</div>' . "\n" . '<!-- /wp:buttons -->';

    $out = CtaBudget::apply($markup, 0)['markup'];

    assert_contains('{"className":"text-action cta-bottom"}', $out);
    assert_contains('<p class="text-action cta-bottom"><a href="/products/#seeds">Full seed data</a></p>', $out);
});

test('CtaBudget renders a button with no link as plain text, and refuses an unsafe part', function () {
    $markup = '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons">' . "\n"
        . '<!-- wp:button -->' . "\n"
        . '<div class="wp-block-button"><a class="wp-block-button__link">Nowhere</a></div>' . "\n"
        . '<!-- /wp:button -->' . "\n"
        . '</div>' . "\n" . '<!-- /wp:buttons -->';
    $out = CtaBudget::apply($markup, 0)['markup'];
    assert_contains('<p class="text-action">Nowhere</p>', $out);

    $unsafe = '<!-- wp:buttons --><div class="wp-block-buttons">' . cta_button('x', '/x/');
    assert_throws(fn () => CtaBudget::apply($unsafe, 0), 'an unclosed part is left for structural recovery');
});

test('CtaBudget is a fixed point: a second pass changes nothing', function () {
    $markup = '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons">' . "\n"
        . cta_button('A', '/a/') . "\n" . cta_button('B', '/b/') . "\n"
        . '</div>' . "\n" . '<!-- /wp:buttons -->';
    $once = CtaBudget::apply($markup, 1)['markup'];
    $twice = CtaBudget::apply($once, 1);
    assert_eq($once, $twice['markup']);
    assert_eq(0, $twice['demoted']);
});

/** @return array{0:\Automattic\SiteBuild\Project,1:string} */
function cta_budget_project(): array
{
    $tmp = sys_get_temp_dir() . '/builder_cta_' . uniqid();
    $project = (new ProjectStore($tmp))->create('demo');
    $project->writeJson('pages.json', ['pages' => [[
        'slug' => 'home', 'title' => 'Home', 'path' => '/', 'front' => true,
        'sections' => [
            ['slug' => 'hero', 'role' => 'hero', 'primary_action' => ['label' => 'Explore', 'intent' => 'x', 'destination' => '/products/']],
            ['slug' => 'lines', 'role' => 'content', 'primary_action' => null],
            ['slug' => 'specs', 'role' => 'content', 'primary_action' => null],
            ['slug' => 'closing', 'role' => 'closing', 'primary_action' => null],
        ],
    ]]]);
    $section = static fn (string $anchor, string ...$buttons): string =>
        '<!-- wp:group {"anchor":"' . $anchor . '","layout":{"type":"constrained"}} -->' . "\n<div id=\"{$anchor}\">\n"
        . '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons">' . "\n" . implode("\n", $buttons) . "\n"
        . '</div>' . "\n" . '<!-- /wp:buttons -->' . "\n"
        . "</div>\n<!-- /wp:group -->";
    $project->writeText('theme/parts/page-home--hero.html', $section('hero', cta_button('Explore', '/products/')));
    $project->writeText('theme/parts/page-home--lines.html', $section('lines', cta_button('See full specifications', '/products/')));
    $project->writeText(
        'theme/parts/page-home--specs.html',
        $section('specs', cta_button('Full seed data', '/products/#seeds'), cta_button('Fruit specifications', '/products/#fruit')),
    );
    $project->writeText(
        'theme/parts/page-home--closing.html',
        $section('closing', cta_button('Send an inquiry', '/contact/'), cta_button('Call us', '/contact/#phone')),
    );
    return [$project, $tmp];
}

test('cta-budget keeps the hero and one closing button and demotes every unplanned button to a link', function () {
    [$project, $tmp] = cta_budget_project();

    (new CtaBudgetStep())->run($project);

    $hero = $project->readText('theme/parts/page-home--hero.html');
    assert_eq(1, substr_count($hero, '<!-- wp:button -->'), 'the hero is not this step\'s business');
    $lines = $project->readText('theme/parts/page-home--lines.html');
    assert_true(!str_contains($lines, 'wp:button'), 'an unplanned mid-page button becomes a link');
    assert_contains('<a href="/products/">See full specifications</a>', $lines);
    $specs = $project->readText('theme/parts/page-home--specs.html');
    assert_true(!str_contains($specs, 'wp:button'));
    assert_eq(2, substr_count($specs, 'class="text-action"'));
    $closing = $project->readText('theme/parts/page-home--closing.html');
    assert_eq(1, substr_count($closing, '<!-- wp:button -->'), 'the closing next step keeps one button');
    assert_contains('href="/contact/"', $closing);
    assert_contains('<a href="/contact/#phone">Call us</a>', $closing);

    $log = $project->readText('logs/cta-budget.log');
    assert_contains('lines', $log);
    assert_contains('demoted', $log);
    $warnings = $project->exists('warnings.json') ? ($project->readJson('warnings.json')['cta-budget'] ?? []) : [];
    assert_eq([], $warnings, 'demotions are policy, not defects');

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('cta-budget keeps a planned action button on a non-hero section', function () {
    [$project, $tmp] = cta_budget_project();
    $pages = $project->readJson('pages.json');
    $pages['pages'][0]['sections'][1]['primary_action'] = [
        'label' => 'See full specifications', 'intent' => 'x', 'destination' => '/products/',
    ];
    $project->writeJson('pages.json', $pages);

    (new CtaBudgetStep())->run($project);

    $lines = $project->readText('theme/parts/page-home--lines.html');
    assert_eq(1, substr_count($lines, '<!-- wp:button -->'), 'a planned action keeps its button');
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('cta-budget leaves a malformed part byte-identical with a warning and still budgets its siblings', function () {
    [$project, $tmp] = cta_budget_project();
    $broken = '<!-- wp:group --><div><!-- wp:buttons --><div class="wp-block-buttons">' . cta_button('x', '/x/');
    $project->writeText('theme/parts/page-home--specs.html', $broken);

    (new CtaBudgetStep())->run($project);

    assert_eq($broken, $project->readText('theme/parts/page-home--specs.html'));
    $warnings = implode(' ', $project->readJson('warnings.json')['cta-budget'] ?? []);
    assert_contains('page-home--specs', $warnings);
    assert_contains('delivered unchanged', $warnings);
    assert_true(
        !str_contains($project->readText('theme/parts/page-home--lines.html'), 'wp:button'),
        'siblings are still budgeted',
    );
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('cta-budget declares the plan and the parts it edits', function () {
    $step = new CtaBudgetStep();
    assert_eq('cta-budget', $step->id());
    $declaration = $step->declaration();
    assert_true(in_array('pages.json', $declaration->reads, true));
    assert_true(in_array('theme/parts/*', $declaration->writes, true));
    assert_true(in_array('warnings.json', $declaration->writes, true));
});
