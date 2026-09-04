<?php
declare(strict_types=1);

use Automattic\SiteBuild\LayoutFixer;
use Automattic\SiteBuild\SectionComposition;
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

function stagger_section(string $inner, string $marker = ''): string
{
    $class = $marker === '' ? '' : '{"className":"' . $marker . '"} ';
    $attr = $marker === '' ? '' : ' ' . $marker;
    return '<!-- wp:group ' . $class . '-->'
        . '<div class="wp-block-group' . $attr . '">'
        . $inner
        . '</div><!-- /wp:group -->';
}

test('normalize-layout flattens staggered columns in a section the plan did not assign offset-grid', function () {
    with_project('stagger_unassigned_', function ($project): void {
        // A photography brief under an offset rhythm: neither the brief nor
        // the direction saves an unassigned stagger. Only the marker does.
        $project->writeJson('siteSpec.json', ['name' => 'Stillrange', 'area' => 'photography']);
        $project->writeJson('designDirection.json', ['rhythm' => 'offset']);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $markup = stagger_section(stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        ), SectionComposition::marker('equal-card-grid'));
        $project->writeText('theme/parts/page-home--cards.html', $markup);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);

        $delivered = $project->readText('theme/parts/page-home--cards.html');
        assert_true(!str_contains($delivered, '"top":"3rem"'), 'unassigned rows are leveled');
        assert_contains('flattened staggered top offsets', $project->readText('logs/normalize-layout.log'));
    });
});

test('normalize-layout keeps staggered columns in a section assigned offset-grid', function () {
    with_project('stagger_assigned_', function ($project): void {
        // A bakery: the brief no longer decides. The assignment does.
        $project->writeJson('siteSpec.json', ['name' => 'Hearth', 'area' => 'bakery']);
        $project->writeJson('designDirection.json', ['rhythm' => 'offset']);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $markup = stagger_section(stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        ), SectionComposition::marker('offset-grid'));
        $project->writeText('theme/parts/page-home--cards.html', $markup);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);

        $delivered = $project->readText('theme/parts/page-home--cards.html');
        assert_contains('"top":"3rem"', $delivered, 'assigned offset-grid rows stay staggered');
    });
});

test('normalize-layout keeps an assigned offset-grid row when the root carries no marker', function () {
    with_project('stagger_planned_', function ($project): void {
        // SectionUnit stamps no marker on a section whose root is not one
        // wp:group. The plan still names the assignment, and that is enough.
        $project->writeJson('siteSpec.json', ['name' => 'Hearth', 'area' => 'bakery']);
        $project->writeJson('designDirection.json', ['rhythm' => 'offset']);
        $project->writeJson('pages.json', ['pages' => [[
            'slug' => 'home', 'front' => true,
            'sections' => [
                ['slug' => 'hero', 'layout_archetype' => 'full-bleed-cover'],
                ['slug' => 'cards', 'layout_archetype' => 'offset-grid'],
                ['slug' => 'visit', 'layout_archetype' => 'equal-card-grid'],
            ],
        ]]]);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $row = stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        );
        $project->writeText('theme/parts/page-home--cards.html', $row);
        $project->writeText('theme/parts/page-home--visit.html', $row);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep())->run($project);

        assert_contains('"top":"3rem"', $project->readText('theme/parts/page-home--cards.html'), 'the planned offset-grid row stays staggered');
        assert_true(!str_contains($project->readText('theme/parts/page-home--visit.html'), '"top":"3rem"'), 'the planned level row is leveled');
    });
});

test('normalize-layout on the HTML-first path levels a marked fallback section under a broken-grid rhythm', function () {
    with_project('stagger_htmlfirst_marked_', function ($project): void {
        // A blocks-fallback section on the HTML-first graph carries a level
        // marker; the page-level rhythm grant does not reach it.
        $project->writeJson('siteSpec.json', ['name' => 'Hearth', 'area' => 'bakery']);
        $project->writeJson('designDirection.json', ['rhythm' => 'gallery']);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $markup = stagger_section(stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        ), SectionComposition::marker('equal-card-grid'));
        $project->writeText('theme/parts/page-contact--cards.html', $markup);

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep(htmlFirst: true))->run($project);

        assert_true(!str_contains($project->readText('theme/parts/page-contact--cards.html'), '"top":"3rem"'), 'a marked level section is leveled on HTML-first too');
    });
});

test('normalize-layout on the HTML-first path levels a hero part and a blocks-fallback page under a broken-grid rhythm', function () {
    with_project('stagger_htmlfirst_excl_', function ($project): void {
        $project->writeJson('siteSpec.json', ['name' => 'Hearth', 'area' => 'bakery']);
        $project->writeJson('designDirection.json', ['rhythm' => 'offset']);
        $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
        $project->writeJson('design/page-artifact-map.json', ['home' => 'home', 'about' => 'about', 'visit' => 'visit']);
        $project->writeText('design/about.failed', "design failed\n");
        $row = stagger_row(
            stagger_column(stagger_card('One'))
            . stagger_column(stagger_card('Two', '3rem'))
        );
        // A transformed hero part carries the hero marker, no section marker.
        $project->writeText('theme/parts/page-home--hero.html', stagger_section($row, 'hero-composition--foreground-split'));
        // A section on a page the graph routed through the blocks path, whose
        // root SectionUnit could not stamp.
        $project->writeText('theme/parts/page-about--cards.html', $row);
        // A transformed section on a page whose design succeeded.
        $project->writeText('theme/parts/page-visit--cards.html', stagger_section($row));

        (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep(htmlFirst: true))->run($project);

        assert_true(!str_contains($project->readText('theme/parts/page-home--hero.html'), '"top":"3rem"'), 'the hero part is leveled');
        assert_true(!str_contains($project->readText('theme/parts/page-about--cards.html'), '"top":"3rem"'), 'the blocks-fallback page is leveled');
        assert_contains('"top":"3rem"', $project->readText('theme/parts/page-visit--cards.html'), 'the transformed section keeps the stagger');
    });
});

test('normalize-layout on the HTML-first path keeps stagger only under a broken-grid rhythm', function () {
    foreach ([
        ['rhythm' => 'offset', 'keeps' => true],
        ['rhythm' => 'gallery', 'keeps' => true],
        ['rhythm' => 'alternating', 'keeps' => false],
        ['rhythm' => null, 'keeps' => false],
    ] as $case) {
        with_project('stagger_htmlfirst_', function ($project) use ($case): void {
            $project->writeJson('siteSpec.json', ['name' => 'Hearth', 'area' => 'bakery']);
            if ($case['rhythm'] !== null) {
                $project->writeJson('designDirection.json', ['rhythm' => $case['rhythm']]);
            }
            $project->writeJson('theme/theme.json', ['version' => 3, 'settings' => ['layout' => ['contentSize' => '860px']]]);
            // HTML-first sections carry no archetype marker.
            $markup = stagger_section(stagger_row(
                stagger_column(stagger_card('One'))
                . stagger_column(stagger_card('Two', '3rem'))
            ));
            $project->writeText('theme/parts/page-home--cards.html', $markup);

            (new \Automattic\SiteBuild\Steps\NormalizeLayoutStep(htmlFirst: true))->run($project);

            $delivered = $project->readText('theme/parts/page-home--cards.html');
            $label = 'rhythm ' . var_export($case['rhythm'], true);
            if ($case['keeps']) {
                assert_contains('"top":"3rem"', $delivered, $label . ' keeps the stagger');
            } else {
                assert_true(!str_contains($delivered, '"top":"3rem"'), $label . ' levels the row');
            }
        });
    }
});

test('home-body and inner-page design prompts state the rhythm rule the build enforces', function () {
    foreach (['home-body-design.md', 'inner-page-design.md'] as $file) {
        $prompt = (string) file_get_contents(repo_path('prompts/' . $file));
        assert_contains('Do not stagger a row of siblings', $prompt, $file);
        assert_contains('`offset` or `gallery`', $prompt, $file);
        assert_contains('{{band_rhythm}}', $prompt, $file . ' names the committed rhythm');
        assert_true(!str_contains($prompt, 'photography or gallery site'), $file . ' no longer gates on a kind of site');
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

test('DesignDirectionStep::rhythmFor returns the committed rhythm or the write-side default', function () {
    with_project('rhythm_for_', function ($project): void {
        assert_eq('alternating', \Automattic\SiteBuild\Steps\DesignDirectionStep::rhythmFor($project), 'no direction yet');
        $project->writeJson('designDirection.json', ['rhythm' => ' Gallery ']);
        assert_eq('gallery', \Automattic\SiteBuild\Steps\DesignDirectionStep::rhythmFor($project));
        $project->writeJson('designDirection.json', ['rhythm' => 'zigzag']);
        assert_eq('alternating', \Automattic\SiteBuild\Steps\DesignDirectionStep::rhythmFor($project), 'an uncommitted value falls back');
    });
});
