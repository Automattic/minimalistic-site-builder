<?php
declare(strict_types=1);

use Automattic\SiteBuild\StaggeredChildren;

function stagger_card(string $title, string $top = ''): string
{
    $margin = $top === '' ? '' : '"style":{"spacing":{"margin":{"top":"' . $top . '"}}},';
    return '<!-- wp:group {' . $margin . '"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading --><h2>' . $title . '</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
}

function stagger_column(string $inner, string $width = '33.33%'): string
{
    return '<!-- wp:column {"width":"' . $width . '"} -->'
        . '<div class="wp-block-column" style="flex-basis:' . $width . '">'
        . $inner
        . '</div><!-- /wp:column -->';
}

function stagger_row(string $columns): string
{
    return '<!-- wp:columns -->'
        . '<div class="wp-block-columns">'
        . $columns
        . '</div><!-- /wp:columns -->';
}

test('StaggeredChildren flattens every-second-column card top offsets', function () {
    $markup = stagger_row(
        stagger_column(stagger_card('One'))
        . stagger_column(stagger_card('Two', '3rem'))
        . stagger_column(stagger_card('Three'))
    );

    $first = StaggeredChildren::flatten($markup);

    assert_true(!str_contains($first['markup'], '"top":"3rem"'), 'offset top margin is removed');
    assert_contains('One', $first['markup']);
    assert_contains('Two', $first['markup']);
    assert_contains('Three', $first['markup']);
    assert_true($first['notes'] !== [], 'flattening is reported');

    $second = StaggeredChildren::flatten($first['markup']);
    assert_eq($first['markup'], $second['markup'], 'flattening is a fixed point');
    assert_eq([], $second['notes']);
});

test('StaggeredChildren leaves a level card row byte-identical', function () {
    $markup = stagger_row(
        stagger_column(stagger_card('One'))
        . stagger_column(stagger_card('Two'))
        . stagger_column(stagger_card('Three'))
    );

    $result = StaggeredChildren::flatten($markup);

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});

test('StaggeredChildren leaves a two-column split with preset top spacing byte-identical', function () {
    $left = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"var:preset|spacing|md"}}}} -->'
        . '<p>Copy</p><!-- /wp:paragraph -->';
    $right = '<!-- wp:image --><figure class="wp-block-image"><img alt=""/></figure><!-- /wp:image -->';
    $markup = stagger_row(
        stagger_column($left, '40%')
        . stagger_column($right, '60%')
    );

    $result = StaggeredChildren::flatten($markup);

    assert_eq($markup, $result['markup'], 'asymmetric-split preset spacing is not a staggered grid');
    assert_eq([], $result['notes']);
});

test('StaggeredChildren leaves uniformly offset siblings byte-identical', function () {
    $markup = stagger_row(
        stagger_column(stagger_card('One', '2rem'))
        . stagger_column(stagger_card('Two', '2rem'))
    );

    $result = StaggeredChildren::flatten($markup);

    assert_eq($markup, $result['markup'], 'a shared top margin is not a stagger');
    assert_eq([], $result['notes']);
});

test('StaggeredChildren flattens a horizontal flex row of groups', function () {
    $markup = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->'
        . '<div class="wp-block-group">'
        . stagger_card('Left')
        . stagger_card('Right', '4rem')
        . '</div><!-- /wp:group -->';

    $result = StaggeredChildren::flatten($markup);

    assert_true(!str_contains($result['markup'], '"top":"4rem"'));
    assert_contains('Left', $result['markup']);
    assert_contains('Right', $result['markup']);
    assert_true($result['notes'] !== []);
});

test('StaggeredChildren does not flatten a vertical stack of groups', function () {
    $markup = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group">'
        . stagger_card('Above')
        . stagger_card('Below', '3rem')
        . '</div><!-- /wp:group -->';

    $result = StaggeredChildren::flatten($markup);

    assert_eq($markup, $result['markup'], 'stacked rhythm is not a staggered row');
    assert_eq([], $result['notes']);
});

test('normalize-layout flattens staggered columns on a non-photography site', function () {
    with_project('stagger_bakery_', function ($project): void {
        $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
        $project->writeJson('siteSpec.json', [
            'name' => 'Hearth',
            'area' => 'bakery',
            'topic' => 'sourdough',
        ]);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $markup = stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        );
        $project->writeText('theme/parts/page-home--cards.html', $markup);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);

        $delivered = $project->readText('theme/parts/page-home--cards.html');
        assert_true(!str_contains($delivered, '"top":"3rem"'), 'bakery rows are leveled');
        assert_contains('flattened staggered top offsets', $project->readText('logs/normalize-layout.log'));
    });
});

test('normalize-layout keeps staggered columns on a photography site', function () {
    with_project('stagger_photo_', function ($project): void {
        $project->writeJson('meta.json', ['prompt' => 'A landscape photography portfolio']);
        $project->writeJson('siteSpec.json', [
            'name' => 'Stillrange',
            'area' => 'photography',
            'topic' => 'landscape photography',
        ]);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $markup = stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        );
        $project->writeText('theme/parts/page-home--cards.html', $markup);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);

        $delivered = $project->readText('theme/parts/page-home--cards.html');
        assert_contains('"top":"3rem"', $delivered, 'photography rows may stay staggered');
    });
});

test('normalize-layout keeps staggered columns on a gallery site', function () {
    with_project('stagger_gallery_', function ($project): void {
        $project->writeJson('meta.json', ['prompt' => 'A contemporary art gallery in Brooklyn']);
        $project->writeJson('siteSpec.json', [
            'name' => 'Northlight',
            'area' => 'art gallery',
            'topic' => 'contemporary painting',
            'site_type' => 'gallery',
        ]);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $markup = stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        );
        $project->writeText('theme/parts/page-home--cards.html', $markup);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);

        $delivered = $project->readText('theme/parts/page-home--cards.html');
        assert_contains('"top":"3rem"', $delivered, 'gallery rows may stay staggered');
    });
});

test('home-body and inner-page design prompts reserve stagger for photography and gallery sites', function () {
    foreach (['home-body-design.md', 'inner-page-design.md'] as $file) {
        $prompt = (string) file_get_contents(repo_path('prompts/' . $file));
        assert_contains('photography', $prompt, $file);
        assert_contains('gallery', $prompt, $file);
        assert_contains('stagger', $prompt, $file);
    }
});

test('StaggeredChildren skips structurally unsafe markup', function () {
    $markup = '<!-- wp:columns --><div class="wp-block-columns">'
        . stagger_column(stagger_card('One'))
        . stagger_column(stagger_card('Two', '3rem'));

    $result = StaggeredChildren::flatten($markup);

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['notes']);
});
