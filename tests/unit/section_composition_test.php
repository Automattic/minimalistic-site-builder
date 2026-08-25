<?php
declare(strict_types=1);

use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\Steps\PagePlanStep;

/** One well-formed section root carrying the assigned marker. */
function section_composition_markup(string $archetype, string $inner = ''): string
{
    $marker = SectionComposition::marker($archetype);
    return '<!-- wp:group {"className":"' . $marker . '","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group ' . $marker . '">'
        . '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
        . $inner
        . '</div><!-- /wp:group -->';
}

function section_composition_row(int $images = 1): string
{
    $image = '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="a.jpg" alt="a"/></figure><!-- /wp:image -->';
    return '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column {"width":"40%"} --><div class="wp-block-column">'
        . str_repeat($image, max(0, $images))
        . '</div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"60%"} --><div class="wp-block-column">'
        . '<!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
}

test('the section catalog rejects an unknown archetype id', function () {
    assert_true(!SectionComposition::isKnown('sticky-pair'), 'an uncataloged id is not known');
    assert_true(!SectionComposition::isKnown(''), 'an empty id is not known');
    assert_true(SectionComposition::isKnown('centered-stack'), 'a cataloged id is known');

    $error = assert_throws(fn () => SectionComposition::assertKnown('sticky-pair'));
    assert_contains("unknown section archetype 'sticky-pair'", $error->getMessage());
    assert_contains('centered-stack', $error->getMessage(), 'the message lists the valid ids');

    assert_throws(fn () => SectionComposition::metadata('sticky-pair'));
    assert_throws(fn () => SectionComposition::marker('sticky-pair'));
    assert_throws(fn () => SectionComposition::recipeTemplate('sticky-pair'));
    assert_throws(fn () => SectionComposition::markupWarnings('<!-- wp:group --><!-- /wp:group -->', 'sticky-pair', 'p'));
});

test('the section catalog is the one source of the plan step archetype menu', function () {
    assert_eq(SectionComposition::ARCHETYPES, PagePlanStep::ARCHETYPES);
    assert_eq(SectionComposition::BACKGROUNDS, PagePlanStep::BACKGROUNDS);
    assert_eq(
        SectionComposition::ARCHETYPES,
        array_keys(SectionComposition::catalog()),
        'the catalog holds exactly the seven published archetypes, in order',
    );
});

test('every section catalog entry carries complete executable metadata', function () {
    foreach (SectionComposition::ARCHETYPES as $archetype) {
        $meta = SectionComposition::metadata($archetype);
        foreach ([
            'backgrounds', 'default_background', 'min_images', 'max_images', 'copy_capacity',
            'requires_row', 'requires_context', 'ineligible_reason', 'root_hook', 'prompt',
        ] as $field) {
            assert_true(array_key_exists($field, $meta), "{$archetype} declares {$field}");
        }

        assert_true($meta['backgrounds'] !== [], "{$archetype} allows at least one background");
        assert_eq(
            [],
            array_diff($meta['backgrounds'], SectionComposition::BACKGROUNDS),
            "{$archetype} allows only published backgrounds",
        );
        assert_true(
            in_array($meta['default_background'], $meta['backgrounds'], true),
            "{$archetype} defaults to a background it allows",
        );
        assert_eq($meta['default_background'], SectionComposition::defaultBackground($archetype));
        assert_eq($meta['backgrounds'], SectionComposition::backgrounds($archetype));

        assert_true($meta['min_images'] >= 0, "{$archetype} has a non-negative image floor");
        assert_true(
            $meta['max_images'] >= $meta['min_images'],
            "{$archetype} has an image ceiling at or above its floor",
        );
        assert_true(
            in_array($meta['copy_capacity'], SectionComposition::COPY_CAPACITIES, true),
            "{$archetype} uses a published copy capacity",
        );

        assert_eq(
            'section-composition--' . $archetype,
            SectionComposition::marker($archetype),
            "{$archetype} owns its root class marker",
        );
        assert_eq(
            '.' . SectionComposition::marker($archetype),
            SectionComposition::rootHook($archetype),
            "{$archetype} exposes its marker as a CSS hook",
        );

        assert_eq(
            "section-compositions/{$archetype}.md",
            SectionComposition::recipeTemplate($archetype),
            "{$archetype} names its own prompt fragment",
        );
        $fragment = (string) file_get_contents(
            repo_path('prompts/' . SectionComposition::recipeTemplate($archetype))
        );
        assert_contains("### {$archetype}", $fragment, "{$archetype} fragment opens with its id");
        foreach ([
            '- Structure:', '- Copy budget:', '- Identity:', '- Media:',
            '- Surface/width:', '- Objective failure:',
        ] as $bullet) {
            assert_contains($bullet, $fragment, "{$archetype} fragment carries the {$bullet} bullet");
        }
        assert_true(
            !str_contains($fragment, '{{'),
            "{$archetype} fragment holds no unresolved placeholder",
        );
    }
});

test('the section eligibility gate reserves offset-grid for photography and gallery sites', function () {
    $photography = [SectionComposition::CONTEXT_PHOTOGRAPHY_SITE => true];
    $other = [SectionComposition::CONTEXT_PHOTOGRAPHY_SITE => false];

    assert_true(SectionComposition::eligible('offset-grid', $photography));
    assert_true(!SectionComposition::eligible('offset-grid', $other));
    assert_true(!SectionComposition::eligible('offset-grid', []), 'an unstated fact is false');

    foreach (SectionComposition::ARCHETYPES as $archetype) {
        if ($archetype === 'offset-grid') {
            continue;
        }
        assert_true(
            SectionComposition::eligible($archetype, $other),
            "{$archetype} needs no site fact",
        );
    }

    assert_eq(SectionComposition::ARCHETYPES, SectionComposition::eligibleArchetypes($photography));
    assert_eq(
        array_values(array_diff(SectionComposition::ARCHETYPES, ['offset-grid'])),
        SectionComposition::eligibleArchetypes($other),
    );

    assert_contains('photography and gallery sites', SectionComposition::ineligibleReason('offset-grid'));
    assert_eq('', SectionComposition::ineligibleReason('centered-stack'));
});

test('the section eligibility context refuses a fact the catalog never reads', function () {
    $error = assert_throws(
        fn () => SectionComposition::eligible('offset-grid', ['photograhpy_site' => true])
    );
    assert_contains('unknown section eligibility context field', $error->getMessage());
    assert_throws(fn () => SectionComposition::eligibleArchetypes(['register' => true]));
    assert_throws(
        fn () => SectionComposition::eligible('offset-grid', [
            SectionComposition::CONTEXT_PHOTOGRAPHY_SITE => 'yes',
        ])
    );
});

test('site context turns the spec into the one fact the gate reads', function () {
    assert_eq(
        [SectionComposition::CONTEXT_PHOTOGRAPHY_SITE => true],
        SectionComposition::siteContext(['area' => 'photography'], ''),
    );
    assert_eq(
        [SectionComposition::CONTEXT_PHOTOGRAPHY_SITE => true],
        SectionComposition::siteContext([], 'a gallery in Lisbon'),
    );
    assert_eq(
        [SectionComposition::CONTEXT_PHOTOGRAPHY_SITE => false],
        SectionComposition::siteContext(['area' => 'bakery'], 'a neighborhood bakery in Lisbon'),
    );
});

test('the section catalog stays quiet on markup that executes its assignment', function () {
    foreach ([
        'full-bleed-cover' => '<!-- wp:cover --><div class="wp-block-cover">'
            . '<!-- wp:paragraph --><p>Over the image.</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:cover -->',
        'asymmetric-split' => section_composition_row(1),
        'centered-stack' => '<!-- wp:paragraph --><p>One column.</p><!-- /wp:paragraph -->',
        'offset-grid' => section_composition_row(2),
        'mixed-width-editorial' => section_composition_row(2),
        'equal-card-grid' => section_composition_row(3),
        'list-with-thumbnails' => section_composition_row(2),
    ] as $archetype => $inner) {
        assert_eq(
            [],
            SectionComposition::markupWarnings(
                section_composition_markup($archetype, $inner),
                $archetype,
                'page-home--band',
            ),
            "{$archetype} executed as assigned draws no warning",
        );
    }
});

test('the section catalog reports an ignored assignment as an advisory warning', function () {
    $missingMarker = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>No marker.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $rows = SectionComposition::markupWarnings($missingMarker, 'centered-stack', 'page-home--story');
    assert_eq(1, count($rows), 'one missing hook is one row');
    assert_contains("file='theme/parts/page-home--story.html'", $rows[0]);
    assert_contains('archetype root marker', $rows[0]);
    assert_contains('section-composition--centered-stack', $rows[0]);
    assert_contains('disposition=safe parseable section was retained', $rows[0]);

    // A split delivered as one stacked column, with no thumbnail row beneath.
    $stacked = section_composition_markup(
        'list-with-thumbnails',
        '<!-- wp:paragraph --><p>Just a list.</p><!-- /wp:paragraph -->'
    );
    $joined = implode("\n", SectionComposition::markupWarnings($stacked, 'list-with-thumbnails', 'page-menu--index'));
    assert_contains('archetype media count', $joined, 'a thumbnail row with no thumbnail is reported');
    assert_contains('"min_images":1', $joined);
    assert_contains('archetype row topology', $joined, 'a stacked column is not a thumbnail row');
    assert_contains('"row_block_count":0', $joined);

    $overRation = section_composition_markup('centered-stack', str_repeat(
        '<!-- wp:image --><figure class="wp-block-image"><img src="a.jpg" alt="a"/></figure><!-- /wp:image -->',
        5
    ));
    $joined = implode("\n", SectionComposition::markupWarnings($overRation, 'centered-stack', 'page-home--story'));
    assert_contains('archetype media count', $joined);
    assert_contains('"image_count":5', $joined);
});

test('the section catalog advisory check never throws on hostile markup', function () {
    foreach ([
        '',
        'plain text, no blocks at all',
        '<!-- wp:group -->',
        '<!-- wp:group --><div><!-- wp:columns --></div>',
        '<!-- wp:group {"className":} --><div></div><!-- /wp:group -->',
    ] as $markup) {
        $rows = SectionComposition::markupWarnings($markup, 'asymmetric-split', 'page-home--band');
        assert_true(is_array($rows), 'the advisory check always returns rows, never an exception');
    }
});
