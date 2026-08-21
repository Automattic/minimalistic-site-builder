<?php
declare(strict_types=1);

use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\StaggeredChildren;

function stagger_card(string $title, string $top = '', bool $htmlStyle = false): string
{
    $margin = $top === '' ? '' : '"style":{"spacing":{"margin":{"top":"' . $top . '"}}},';
    $styleAttr = ($htmlStyle && $top !== '') ? ' style="margin-top:' . $top . '"' : '';
    return '<!-- wp:group {' . $margin . '"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"' . $styleAttr . '>'
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

test('StaggeredChildren clears matching inline margin-top so LayoutFixer cannot mirror it back', function () {
    $markup = stagger_row(
        stagger_column(stagger_card('One', '', true))
        . stagger_column(stagger_card('Two', '3rem', true))
        . stagger_column(stagger_card('Three', '', true))
    );

    $first = StaggeredChildren::flatten($markup);

    assert_true(!str_contains($first['markup'], '"top":"3rem"'), 'comment offset is removed');
    assert_true(!str_contains($first['markup'], 'margin-top:3rem'), 'inline offset is removed');
    assert_contains('Two', $first['markup']);
    assert_true($first['notes'] !== []);

    $fixed = LayoutFixer::fix($first['markup'], LayoutFixer::ROLE_SECTION, 860.0);
    $mirrored = array_values(array_filter(
        $fixed['notes'],
        static fn (string $note): bool => str_contains($note, 'mirrored'),
    ));
    assert_eq([], $mirrored, 'LayoutFixer has no HTML-only margin-top to restore');

    $second = StaggeredChildren::flatten($fixed['markup']);
    assert_eq([], $second['notes'], 'flattening reaches a fixed point with LayoutFixer');
    assert_true(!str_contains($second['markup'], 'margin-top:3rem'));
    assert_true(!str_contains($second['markup'], '"top":"3rem"'));
});

test('StaggeredChildren keeps unrelated inline styles when stripping margin-top', function () {
    $left = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"><!-- wp:heading --><h2>One</h2><!-- /wp:heading --></div><!-- /wp:group -->';
    $right = '<!-- wp:group {"style":{"spacing":{"margin":{"top":"3rem","bottom":"1rem"}}}} -->'
        . '<div class="wp-block-group" style="margin-top:3rem;margin-bottom:1rem">'
        . '<!-- wp:heading --><h2>Two</h2><!-- /wp:heading --></div><!-- /wp:group -->';
    $markup = stagger_row(stagger_column($left) . stagger_column($right));

    $result = StaggeredChildren::flatten($markup);

    assert_true(!str_contains($result['markup'], '"top":"3rem"'));
    assert_true(!str_contains($result['markup'], 'margin-top:3rem'));
    assert_contains('"bottom":"1rem"', $result['markup']);
    assert_contains('margin-bottom:1rem', $result['markup']);
});

test('StaggeredChildren flattens a grid group of cards', function () {
    $markup = '<!-- wp:group {"layout":{"type":"grid","columnCount":3}} -->'
        . '<div class="wp-block-group">'
        . stagger_card('One')
        . stagger_card('Two', '3rem')
        . stagger_card('Three')
        . '</div><!-- /wp:group -->';

    $result = StaggeredChildren::flatten($markup);

    assert_true(!str_contains($result['markup'], '"top":"3rem"'));
    assert_contains('One', $result['markup']);
    assert_contains('Two', $result['markup']);
    assert_true($result['notes'] !== []);
});

test('StaggeredChildren flattens an offset on a later child of the column', function () {
    $kicker = '<!-- wp:paragraph --><p>Kicker</p><!-- /wp:paragraph -->';
    $markup = stagger_row(
        stagger_column($kicker . stagger_card('One'))
        . stagger_column($kicker . stagger_card('Two', '3rem'))
    );

    $result = StaggeredChildren::flatten($markup);

    assert_true(!str_contains($result['markup'], '"top":"3rem"'));
    assert_contains('Kicker', $result['markup']);
    assert_contains('One', $result['markup']);
    assert_contains('Two', $result['markup']);
    assert_true($result['notes'] !== []);
});

test('StaggeredChildren does not treat a nested heading margin as a row stagger', function () {
    $card = static function (string $title, string $headingTop): string {
        $attr = $headingTop === '' ? '' : '{"style":{"spacing":{"margin":{"top":"' . $headingTop . '"}}}} ';
        $style = $headingTop === '' ? '' : ' style="margin-top:' . $headingTop . '"';
        return '<!-- wp:group {"layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group">'
            . '<!-- wp:heading ' . $attr . '--><h2' . $style . '>' . $title . '</h2><!-- /wp:heading -->'
            . '</div><!-- /wp:group -->';
    };
    $markup = stagger_row(
        stagger_column($card('One', ''))
        . stagger_column($card('Two', '3rem'))
    );

    $result = StaggeredChildren::flatten($markup);

    assert_eq($markup, $result['markup'], 'heading offsets inside a card are not a staggered row');
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
