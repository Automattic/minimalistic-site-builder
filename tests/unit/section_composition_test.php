<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
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
        'the catalog holds exactly the six published archetypes, in order',
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
            [],
            array_diff($meta['requires_context'], SectionComposition::CONTEXT_KEYS),
            "{$archetype} gates on published context keys only",
        );
        assert_true(
            $meta['requires_context'] === [] || trim($meta['ineligible_reason']) !== '',
            "{$archetype} explains why it can be ineligible — PagePlanStep interpolates "
            . 'the reason into its replacement warning row',
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
        // A fragment may carry only the placeholders `recipeVars()` fills.
        // PromptRenderer throws on an unresolved one, and that throw would land
        // mid-build, so the fragment and its filler are checked against each
        // other rather than the fragment being forbidden any variable at all.
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $fragment, $found);
        assert_eq(
            [],
            array_values(array_diff($found[1], SectionComposition::RECIPE_VARS)),
            "{$archetype} fragment uses only published recipe variables",
        );
        foreach ([null, 'card'] as $itemPattern) {
            $vars = SectionComposition::recipeVars($archetype, $itemPattern);
            assert_eq(
                [],
                array_values(array_diff(SectionComposition::RECIPE_VARS, array_keys($vars))),
                "{$archetype} recipe vars cover every published variable",
            );
            // The real render is the proof: it throws on any placeholder the
            // catalog does not fill.
            $rendered = PromptRenderer::fill($fragment, $vars);
            assert_true(
                !str_contains($rendered, '{{'),
                "{$archetype} fragment renders with no placeholder left",
            );
        }
    }
});

test('only an unequal-region archetype pins a lead column', function () {
    // The pin is code-owned on two facts that are both already on the plan: an
    // archetype whose regions differ in weight, and a section the planner
    // marked as a repeated set.
    assert_true(SectionComposition::hasUnequalRegions('asymmetric-split'));
    assert_true(SectionComposition::pinsLeadColumn('asymmetric-split', 'rule-row'));
    assert_true(SectionComposition::pinsLeadColumn('asymmetric-split', 'card'));

    // No item pattern means no repeated set to scroll past the lead.
    assert_true(!SectionComposition::pinsLeadColumn('asymmetric-split', null));
    assert_true(!SectionComposition::pinsLeadColumn('asymmetric-split', '  '));

    // Every other archetype composes level or equal regions, so none of them
    // can strand a region and none of them may pin.
    foreach (SectionComposition::ARCHETYPES as $archetype) {
        if ($archetype === 'asymmetric-split') {
            continue;
        }
        assert_true(
            !SectionComposition::hasUnequalRegions($archetype),
            "{$archetype} does not compose unequal regions",
        );
        assert_true(
            !SectionComposition::pinsLeadColumn($archetype, 'card'),
            "{$archetype} never pins a column",
        );
        assert_eq(
            '',
            SectionComposition::recipeVars($archetype, 'card')['pin_directive'],
            "{$archetype} reads no word about the pin",
        );
    }
});

test('the pin directive names the class, the column, and the viewport guard', function () {
    $directive = SectionComposition::pinDirective('asymmetric-split', 'rule-row');
    assert_contains(SectionComposition::PIN_CLASS, $directive, 'names the class to apply');
    assert_contains('never on the column that holds the repeated items', $directive);
    assert_contains('fits one screen', $directive, 'caps the pinned column against the viewport');
    assert_contains('exactly two regions', $directive, 'three regions never pin');
    assert_eq('', SectionComposition::pinDirective('asymmetric-split', null));
});

test('mixed-width-editorial is retired into asymmetric-split', function () {
    // BIGR-945. The two were one topology; the merged entry keeps the wider
    // media ceiling so a three-region feature-and-notes row still fits.
    assert_true(!SectionComposition::isKnown('mixed-width-editorial'));
    assert_true(!in_array('mixed-width-editorial', SectionComposition::ARCHETYPES, true));
    assert_true(!is_file(repo_path('prompts/section-compositions/mixed-width-editorial.md')));
    assert_eq(12, SectionComposition::metadata('asymmetric-split')['max_images']);

    // The retired name must not survive anywhere a build reads it.
    foreach (['prompts/page-plan.md', 'prompts/section-compositions/asymmetric-split.md'] as $file) {
        assert_true(
            !str_contains((string) file_get_contents(repo_path($file)), 'mixed-width-editorial'),
            "{$file} no longer names the retired archetype",
        );
    }

    // The surviving fragment absorbed the three-region case it was retired for.
    $fragment = (string) file_get_contents(
        repo_path('prompts/section-compositions/asymmetric-split.md')
    );
    assert_contains('Three regions', $fragment, 'the feature-and-notes row survives the merge');
    assert_contains('50/25/25', $fragment, 'the three-region widths survive the merge');
});

test('the section eligibility gate reserves offset-grid for a broken-grid rhythm', function () {
    $brokenGrid = [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => true];
    $other = [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => false];

    assert_true(SectionComposition::eligible('offset-grid', $brokenGrid));
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

    assert_eq(SectionComposition::ARCHETYPES, SectionComposition::eligibleArchetypes($brokenGrid));
    assert_eq(
        array_values(array_diff(SectionComposition::ARCHETYPES, ['offset-grid'])),
        SectionComposition::eligibleArchetypes($other),
    );

    assert_contains('offset or gallery band rhythm', SectionComposition::ineligibleReason('offset-grid'));
    assert_eq('', SectionComposition::ineligibleReason('centered-stack'));
});

test('the section eligibility context refuses a fact the catalog never reads', function () {
    $error = assert_throws(
        fn () => SectionComposition::eligible('offset-grid', ['broken_grid_rhytm' => true])
    );
    assert_contains('unknown section eligibility context field', $error->getMessage());
    assert_throws(fn () => SectionComposition::eligibleArchetypes(['register' => true]));
    assert_throws(
        fn () => SectionComposition::eligible('offset-grid', [
            SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => 'yes',
        ])
    );
});

test('direction context turns the committed rhythm into the one fact the gate reads', function () {
    foreach (SectionComposition::BROKEN_GRID_RHYTHMS as $rhythm) {
        assert_eq(
            [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => true],
            SectionComposition::directionContext(['rhythm' => $rhythm]),
            $rhythm,
        );
    }
    foreach (array_diff(\Automattic\SiteBuild\Steps\DesignDirectionStep::RHYTHMS, SectionComposition::BROKEN_GRID_RHYTHMS) as $rhythm) {
        assert_eq(
            [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => false],
            SectionComposition::directionContext(['rhythm' => $rhythm]),
            $rhythm,
        );
    }
    assert_eq(
        [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => false],
        SectionComposition::directionContext([]),
        'no direction grants nothing',
    );
    assert_eq(
        [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => true],
        SectionComposition::directionContext(['rhythm' => ' Offset ']),
        'case and whitespace do not matter',
    );
    assert_eq(
        [SectionComposition::CONTEXT_BROKEN_GRID_RHYTHM => false],
        SectionComposition::directionContext(['rhythm' => ['offset']]),
        'a non-string rhythm grants nothing and raises no warning',
    );
    assert_eq(
        [],
        array_diff(SectionComposition::BROKEN_GRID_RHYTHMS, \Automattic\SiteBuild\Steps\DesignDirectionStep::RHYTHMS),
        'every broken-grid rhythm is one the direction can commit',
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

    // One plate beside one copy column is this archetype's most common and most
    // successful shape. The balance check must never report it.
    assert_eq(
        [],
        SectionComposition::markupWarnings(
            section_composition_markup('asymmetric-split', section_composition_row(1)),
            'asymmetric-split',
            'page-home--band',
            null,
        ),
        'one image against none is a balanced split',
    );

    // A staggered photo grid puts several frames in one region by design. The
    // balance check is scoped to unequal-region bands so it stays quiet here.
    assert_eq(
        [],
        SectionComposition::markupWarnings(
            section_composition_markup('offset-grid', section_composition_row(4)),
            'offset-grid',
            'page-home--band',
            'card',
        ),
        'a stagger is not an unbalanced split',
    );
});

test('the section catalog reports a split that strands a region beside its sibling', function () {
    // BIGR-945: the `cat-luthier` shape — one region carrying a stack of media
    // against a sibling carrying one. Markup holds no heights, so the check
    // measures the authored fact that produces the blank quadrant.
    $rows = SectionComposition::markupWarnings(
        section_composition_markup('asymmetric-split', section_composition_row(3)),
        'asymmetric-split',
        'page-home--band',
        null,
    );
    assert_eq(1, count($rows), 'one unbalanced row is one warning');
    assert_contains('archetype region balance', $rows[0]);
    assert_contains('"images_per_region":[3,0]', $rows[0], 'the row carries the delivered spread');
    assert_contains('"spread":3', $rows[0]);
    assert_contains('balance the regions or pin the short one', $rows[0], 'the row names both fixes');
    assert_contains("file='theme/parts/page-home--band.html'", $rows[0]);

    // Two against none is the first difference copy cannot absorb.
    assert_eq(
        1,
        count(SectionComposition::markupWarnings(
            section_composition_markup('asymmetric-split', section_composition_row(2)),
            'asymmetric-split',
            'page-home--band',
            null,
        )),
        'two images against none is already unbalanced',
    );
});

test('the section catalog reports a pin the delivered band ignored', function () {
    $unpinned = section_composition_markup('asymmetric-split', section_composition_row(1));

    // No item pattern: no pin was asked for, so nothing is reported.
    assert_eq([], SectionComposition::markupWarnings($unpinned, 'asymmetric-split', 'p', null));

    // With one, the pin was required and the delivered band dropped it.
    $rows = SectionComposition::markupWarnings($unpinned, 'asymmetric-split', 'page-home--menu', 'rule-row');
    assert_eq(1, count($rows), 'a dropped pin is one row');
    assert_contains('archetype pinned lead', $rows[0]);
    assert_contains('"required_class":"' . SectionComposition::PIN_CLASS . '"', $rows[0]);
    assert_contains('"item_pattern":"rule-row"', $rows[0], 'the row records why the pin was asked for');
    assert_contains('stranding a blank quadrant', $rows[0]);

    // The same band with the class present is quiet.
    $pinned = str_replace(
        '<!-- wp:column {"width":"60%"} --><div class="wp-block-column">',
        '<!-- wp:column {"width":"60%","className":"' . SectionComposition::PIN_CLASS . '"} -->'
        . '<div class="wp-block-column ' . SectionComposition::PIN_CLASS . '">',
        $unpinned,
    );
    assert_true($pinned !== $unpinned, 'the fixture actually gained the class');
    assert_eq(
        [],
        SectionComposition::markupWarnings($pinned, 'asymmetric-split', 'page-home--menu', 'rule-row'),
        'a delivered pin draws no warning',
    );
});

test('the section catalog reports a pin that sits on the repeated-items column', function () {
    // The pin exists to hold the SHORT lead in view beside the long repeated
    // set. A pin on the items column pins the long region instead, so a bare
    // presence check would pass the exact defect the pin prevents.
    $item = '<!-- wp:group {"className":"item-pattern__item"} -->'
        . '<div class="wp-block-group item-pattern__item">'
        . '<!-- wp:paragraph --><p>Item.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $row = static function (string $leadClass, string $itemsClass) use ($item): string {
        $lead = $leadClass === ''
            ? '{"width":"40%"}'
            : '{"width":"40%","className":"' . $leadClass . '"}';
        $items = $itemsClass === ''
            ? '{"width":"60%"}'
            : '{"width":"60%","className":"' . $itemsClass . '"}';
        return '<!-- wp:columns --><div class="wp-block-columns">'
            . '<!-- wp:column ' . $lead . ' --><div class="wp-block-column">'
            . '<!-- wp:paragraph --><p>Lead copy.</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:column -->'
            . '<!-- wp:column ' . $items . ' --><div class="wp-block-column">'
            . $item . $item . $item
            . '</div><!-- /wp:column -->'
            . '</div><!-- /wp:columns -->';
    };

    // Pin on the items column: reported, and the row says where the pin goes.
    $rows = SectionComposition::markupWarnings(
        section_composition_markup(
            'asymmetric-split',
            $row('', SectionComposition::PIN_CLASS)
        ),
        'asymmetric-split',
        'page-home--menu',
        'rule-row',
    );
    assert_eq(1, count($rows), 'a misplaced pin is one row');
    assert_contains('archetype pinned lead', $rows[0]);
    assert_contains('"pinned_blocks":1', $rows[0], 'the row records the pin was present');
    assert_contains('"pinned_lead_columns":0', $rows[0], 'the row records it missed the lead');
    assert_contains('move the pin class', $rows[0]);

    // Pin on the lead column: silent.
    assert_eq(
        [],
        SectionComposition::markupWarnings(
            section_composition_markup(
                'asymmetric-split',
                $row(SectionComposition::PIN_CLASS, '')
            ),
            'asymmetric-split',
            'page-home--menu',
            'rule-row',
        ),
        'a pin on the lead column draws no warning',
    );
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

test('the bento-grid archetype checks two unequal card rows and one highlight (frm W3a)', function () {
    assert_true(in_array('bento-grid', SectionComposition::ARCHETYPES, true));
    $meta = SectionComposition::metadata('bento-grid');
    assert_eq('section-compositions/bento-grid.md', $meta['prompt']);
    assert_eq(true, $meta['requires_row']);
    assert_eq('.section-composition--bento-grid', $meta['root_hook']);
    assert_true(is_file(repo_path('prompts/' . $meta['prompt'])), 'the recipe fragment ships');

    $card = static fn (string $extra = ''): string => '<!-- wp:column {"width":"50%","verticalAlignment":"stretch"} --><div class="wp-block-column">'
        . '<!-- wp:group {"className":"card-style--flush card-flush' . $extra . '"} --><div class="wp-block-group card-style--flush card-flush' . $extra . '">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Tile</h3><!-- /wp:heading -->'
        . '</div><!-- /wp:group --></div><!-- /wp:column -->';
    $row = static fn (string $cards): string => '<!-- wp:columns {"className":"equal-cards","align":"wide"} --><div class="wp-block-columns alignwide equal-cards">'
        . $cards . '</div><!-- /wp:columns -->';
    $band = static fn (string $inner): string => '<!-- wp:group {"className":"section-composition--bento-grid","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--bento-grid">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Capabilities</h2><!-- /wp:heading -->'
        . $inner . '</div><!-- /wp:group -->';

    $good = $band($row($card(' card-highlight') . $card()) . $row($card() . $card() . $card()));
    assert_eq([], SectionComposition::markupWarnings($good, 'bento-grid', 'page-home--capabilities'));

    $oneRow = $band($row($card(' card-highlight') . $card() . $card()));
    $joined = implode("\n", SectionComposition::markupWarnings($oneRow, 'bento-grid', 'page-home--capabilities'));
    assert_contains('bento row count', $joined);
    assert_contains('"column_row_count":1', $joined);

    $noHighlight = $band($row($card() . $card()) . $row($card() . $card() . $card()));
    $joined = implode("\n", SectionComposition::markupWarnings($noHighlight, 'bento-grid', 'page-home--capabilities'));
    assert_contains('bento highlight', $joined);
    assert_contains('"highlighted_cards":0', $joined);

    $twoHighlights = $band($row($card(' card-highlight') . $card(' card-highlight')) . $row($card() . $card() . $card()));
    $joined = implode("\n", SectionComposition::markupWarnings($twoHighlights, 'bento-grid', 'page-home--capabilities'));
    assert_contains('"highlighted_cards":2', $joined);

    // Other archetypes never see the bento checks.
    $plain = str_replace('section-composition--bento-grid', 'section-composition--equal-card-grid', $oneRow);
    assert_true(
        !str_contains(implode("\n", SectionComposition::markupWarnings($plain, 'equal-card-grid', 'x')), 'bento'),
    );
});

test('the faq-split archetype requires an accordion of three or more details blocks (frm W3b)', function () {
    assert_true(in_array('faq-split', SectionComposition::ARCHETYPES, true));
    $meta = SectionComposition::metadata('faq-split');
    assert_eq('section-compositions/faq-split.md', $meta['prompt']);
    assert_true(is_file(repo_path('prompts/' . $meta['prompt'])), 'the recipe fragment ships');
    assert_true(!in_array('image', $meta['backgrounds'], true), 'an accordion never sits on an image band');

    $item = static fn (string $q): string => '<!-- wp:details --><details class="wp-block-details"><summary>' . $q . '</summary>'
        . '<!-- wp:paragraph --><p>Answer.</p><!-- /wp:paragraph --></details><!-- /wp:details -->';
    $band = static fn (string $items): string => '<!-- wp:group {"className":"section-composition--faq-split","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--faq-split">'
        . '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
        . '<!-- wp:column {"width":"40%"} --><div class="wp-block-column"><!-- wp:heading --><h2 class="wp-block-heading">Questions</h2><!-- /wp:heading --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"60%"} --><div class="wp-block-column"><!-- wp:group {"className":"faq-list"} --><div class="wp-block-group faq-list">'
        . $items . '</div><!-- /wp:group --></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns --></div><!-- /wp:group -->';

    $good = $band($item('One?') . $item('Two?') . $item('Three?'));
    assert_eq([], SectionComposition::markupWarnings($good, 'faq-split', 'page-home--faq'));

    $thin = $band($item('One?') . $item('Two?'));
    $joined = implode("\n", SectionComposition::markupWarnings($thin, 'faq-split', 'page-home--faq'));
    assert_contains('faq accordion items', $joined);
    assert_contains('"details_block_count":2', $joined);

    $plain = str_replace('section-composition--faq-split', 'section-composition--asymmetric-split', $thin);
    assert_true(!str_contains(implode("\n", SectionComposition::markupWarnings($plain, 'asymmetric-split', 'x')), 'faq'));
});

test('the cta-panel archetype checks one contained panel and exactly one action (frm W3d)', function () {
    assert_true(in_array('cta-panel', SectionComposition::ARCHETYPES, true));
    $meta = SectionComposition::metadata('cta-panel');
    assert_eq('section-compositions/cta-panel.md', $meta['prompt']);
    assert_true(is_file(repo_path('prompts/' . $meta['prompt'])), 'the recipe fragment ships');
    assert_eq(['base', 'tinted'], $meta['backgrounds'], 'the band stays on the page ground; the panel carries contrast');

    $button = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/start/">Start</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
    $panel = static fn (string $inner, string $class = 'cta-panel'): string => '<!-- wp:group {"className":"' . $class . '","align":"wide","backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group alignwide ' . $class . ' has-contrast-background-color has-base-color has-text-color has-background">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Start tonight</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One line.</p><!-- /wp:paragraph -->' . $inner . '</div><!-- /wp:group -->';
    $band = static fn (string $inner): string => '<!-- wp:group {"className":"section-composition--cta-panel","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--cta-panel">' . $inner . '</div><!-- /wp:group -->';

    assert_eq([], SectionComposition::markupWarnings($band($panel($button)), 'cta-panel', 'page-home--closing'));

    $noPanel = $band($panel($button, 'closing-box'));
    $joined = implode("\n", SectionComposition::markupWarnings($noPanel, 'cta-panel', 'page-home--closing'));
    assert_contains('cta panel container', $joined);
    assert_contains('"panel_groups":0', $joined);

    $twoButtons = $band($panel($button . $button));
    $joined = implode("\n", SectionComposition::markupWarnings($twoButtons, 'cta-panel', 'page-home--closing'));
    assert_contains('cta panel action', $joined);
    assert_contains('"buttons":2', $joined);

    $noButton = $band($panel(''));
    assert_contains('"buttons":0', implode("\n", SectionComposition::markupWarnings($noButton, 'cta-panel', 'x')));

    $other = str_replace('section-composition--cta-panel', 'section-composition--centered-stack', $noPanel);
    assert_true(!str_contains(implode("\n", SectionComposition::markupWarnings($other, 'centered-stack', 'x')), 'cta panel'));
});

test('the pricing-tiers archetype checks one row of two or three tiers, one highlight, and one list and action per tier (frm W3c)', function () {
    assert_true(in_array('pricing-tiers', SectionComposition::ARCHETYPES, true));
    $meta = SectionComposition::metadata('pricing-tiers');
    assert_eq('section-compositions/pricing-tiers.md', $meta['prompt']);
    assert_eq(true, $meta['requires_row']);
    assert_eq(0, $meta['max_images'], 'pricing carries no image');
    assert_true(is_file(repo_path('prompts/' . $meta['prompt'])), 'the recipe fragment ships');
    assert_true(str_contains((string) file_get_contents(repo_path('prompts/page-plan.md')), 'cta-panel, pricing-tiers'), 'the page plan enum lists it');

    $tier = static fn (string $extra = '', bool $list = true, bool $button = true): string => '<!-- wp:column {"width":"33.33%","verticalAlignment":"stretch"} --><div class="wp-block-column">'
        . '<!-- wp:group {"className":"card-style--flush card-flush' . $extra . '"} --><div class="wp-block-group card-style--flush card-flush' . $extra . '">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Starter</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"className":"price-figure"} --><p class="price-figure">$29 / month</p><!-- /wp:paragraph -->'
        . ($list ? '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Three seats</li><!-- /wp:list-item --></ul><!-- /wp:list -->' : '')
        . ($button ? '<!-- wp:buttons {"className":"cta-bottom"} --><div class="wp-block-buttons cta-bottom"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/signup/">Choose</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' : '')
        . '</div><!-- /wp:group --></div><!-- /wp:column -->';
    $row = static fn (string $tiers): string => '<!-- wp:columns {"className":"equal-cards","align":"wide"} --><div class="wp-block-columns alignwide equal-cards">'
        . $tiers . '</div><!-- /wp:columns -->';
    $band = static fn (string $inner): string => '<!-- wp:group {"className":"section-composition--pricing-tiers","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--pricing-tiers">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Plans</h2><!-- /wp:heading -->'
        . $inner . '</div><!-- /wp:group -->';

    $three = $band($row($tier() . $tier(' card-highlight') . $tier()));
    assert_eq([], SectionComposition::markupWarnings($three, 'pricing-tiers', 'page-home--plans'));
    $two = $band($row($tier() . $tier(' card-highlight')));
    assert_eq([], SectionComposition::markupWarnings($two, 'pricing-tiers', 'page-home--plans'), 'two plans are a legitimate set');

    $four = $band($row($tier() . $tier(' card-highlight') . $tier() . $tier()));
    $joined = implode("\n", SectionComposition::markupWarnings($four, 'pricing-tiers', 'page-home--plans'));
    assert_contains('pricing tier row', $joined);
    assert_contains('"tiers_per_row":[4]', $joined);

    $twoRows = $band($row($tier() . $tier(' card-highlight')) . $row($tier() . $tier()));
    $joined = implode("\n", SectionComposition::markupWarnings($twoRows, 'pricing-tiers', 'page-home--plans'));
    assert_contains('"column_rows":2', $joined);

    $noHighlight = $band($row($tier() . $tier() . $tier()));
    $joined = implode("\n", SectionComposition::markupWarnings($noHighlight, 'pricing-tiers', 'page-home--plans'));
    assert_contains('pricing highlight', $joined);
    assert_contains('"highlighted_tiers":0', $joined);

    $noButton = $band($row($tier() . $tier(' card-highlight') . $tier('', true, false)));
    $joined = implode("\n", SectionComposition::markupWarnings($noButton, 'pricing-tiers', 'page-home--plans'));
    assert_contains('pricing tier anatomy', $joined);
    assert_contains('"buttons":2', $joined);

    $noList = $band($row($tier() . $tier(' card-highlight') . $tier('', false, true)));
    $joined = implode("\n", SectionComposition::markupWarnings($noList, 'pricing-tiers', 'page-home--plans'));
    assert_contains('"lists":2', $joined);

    // Other archetypes never see the pricing checks.
    $plain = str_replace('section-composition--pricing-tiers', 'section-composition--equal-card-grid', $four);
    assert_true(!str_contains(implode("\n", SectionComposition::markupWarnings($plain, 'equal-card-grid', 'x')), 'pricing'));
});

test('the stat-ledger archetype checks one row of three or four figure-led columns and no media (frm W3e)', function () {
    assert_true(in_array('stat-ledger', SectionComposition::ARCHETYPES, true));
    $meta = SectionComposition::metadata('stat-ledger');
    assert_eq('section-compositions/stat-ledger.md', $meta['prompt']);
    assert_eq(true, $meta['requires_row']);
    assert_eq(0, $meta['max_images']);
    assert_true(is_file(repo_path('prompts/' . $meta['prompt'])));
    assert_true(str_contains((string) file_get_contents(repo_path('prompts/page-plan.md')), 'pricing-tiers, stat-ledger'), 'the page plan enum lists it');

    $column = static fn (string $figure, string $label = 'projects shipped'): string => '<!-- wp:column {"width":"25%"} --><div class="wp-block-column">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $figure . '</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">' . $label . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->';
    $row = static fn (string $columns): string => '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $columns . '</div><!-- /wp:columns -->';
    $band = static fn (string $inner): string => '<!-- wp:group {"className":"section-composition--stat-ledger","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--stat-ledger">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">By the numbers</h2><!-- /wp:heading -->'
        . $inner . '</div><!-- /wp:group -->';

    $four = $band($row($column('120+') . $column('98%') . $column('$4.2M') . $column('1,200')));
    assert_eq([], SectionComposition::markupWarnings($four, 'stat-ledger', 'page-home--metrics'));
    $three = $band($row($column('12 km') . $column('40') . $column('3x')));
    assert_eq([], SectionComposition::markupWarnings($three, 'stat-ledger', 'page-home--metrics'));
    $unitWord = $band($row($column('4 years') . $column('40') . $column('3x')));
    assert_contains('stat ledger figures', implode("\n", SectionComposition::markupWarnings($unitWord, 'stat-ledger', 'page-home--metrics')), 'a unit word belongs in the label');
    assert_eq([], SectionComposition::markupWarnings($three, 'stat-ledger', 'page-home--metrics'));

    $two = $band($row($column('120+') . $column('98%')));
    $joined = implode("\n", SectionComposition::markupWarnings($two, 'stat-ledger', 'page-home--metrics'));
    assert_contains('stat ledger row', $joined);
    assert_contains('"columns_per_row":[2]', $joined);

    $sentence = $band($row($column('120+') . $column('98%') . $column('Over 4 million raised')));
    $joined = implode("\n", SectionComposition::markupWarnings($sentence, 'stat-ledger', 'page-home--metrics'));
    assert_contains('stat ledger figures', $joined);
    assert_contains('"figure_led_columns":2', $joined);

    $withImage = str_replace('<h3 class="wp-block-heading">120+</h3><!-- /wp:heading -->', '<h3 class="wp-block-heading">120+</h3><!-- /wp:heading --><!-- wp:image --><figure class="wp-block-image"><img src="x.jpg" alt=""/></figure><!-- /wp:image -->', $four);
    $joined = implode("\n", SectionComposition::markupWarnings($withImage, 'stat-ledger', 'page-home--metrics'));
    assert_contains('archetype media count', $joined);

    $plain = str_replace('section-composition--stat-ledger', 'section-composition--equal-card-grid', $two);
    assert_true(!str_contains(implode("\n", SectionComposition::markupWarnings($plain, 'equal-card-grid', 'x')), 'stat ledger'));
});

test('the feature-row-hairlines archetype checks one row of three or four heading-led text columns without cards (frm W3e)', function () {
    assert_true(in_array('feature-row-hairlines', SectionComposition::ARCHETYPES, true));
    $meta = SectionComposition::metadata('feature-row-hairlines');
    assert_eq('section-compositions/feature-row-hairlines.md', $meta['prompt']);
    assert_eq(0, $meta['max_images']);
    assert_true(is_file(repo_path('prompts/' . $meta['prompt'])));
    assert_true(str_contains((string) file_get_contents(repo_path('prompts/page-plan.md')), 'stat-ledger, feature-row-hairlines"'));

    $column = static fn (string $inner): string => '<!-- wp:column {"width":"25%"} --><div class="wp-block-column">' . $inner . '</div><!-- /wp:column -->';
    $text = static fn (string $title): string => '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $title . '</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>One line of support.</p><!-- /wp:paragraph -->';
    $row = static fn (string $columns): string => '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $columns . '</div><!-- /wp:columns -->';
    $band = static fn (string $inner): string => '<!-- wp:group {"className":"section-composition--feature-row-hairlines","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--feature-row-hairlines">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Four pillars</h2><!-- /wp:heading -->'
        . $inner . '</div><!-- /wp:group -->';

    $four = $band($row($column($text('Dashboards')) . $column($text('Reports')) . $column($text('Workspace')) . $column($text('Bank sync'))));
    assert_eq([], SectionComposition::markupWarnings($four, 'feature-row-hairlines', 'page-home--features'));

    $two = $band($row($column($text('A')) . $column($text('B'))));
    $joined = implode("\n", SectionComposition::markupWarnings($two, 'feature-row-hairlines', 'page-home--features'));
    assert_contains('feature row shape', $joined);

    $noHeading = $band($row($column('<!-- wp:paragraph --><p>No heading.</p><!-- /wp:paragraph -->') . $column($text('B')) . $column($text('C'))));
    $joined = implode("\n", SectionComposition::markupWarnings($noHeading, 'feature-row-hairlines', 'page-home--features'));
    assert_contains('feature row headings', $joined);
    assert_contains('"heading_led_columns":2', $joined);

    $carded = $band($row($column('<!-- wp:group {"className":"card-style--flush"} --><div class="wp-block-group card-style--flush">' . $text('A') . '</div><!-- /wp:group -->') . $column($text('B')) . $column($text('C'))));
    $joined = implode("\n", SectionComposition::markupWarnings($carded, 'feature-row-hairlines', 'page-home--features'));
    assert_contains('feature row cards', $joined);
    assert_contains('"card_columns":1', $joined);
});

test('the ruled-idiom cleanup keeps a composition marker that names hairlines (frm W3e)', function () {
    $markup = '<!-- wp:group {"className":"section-composition--feature-row-hairlines is-style-rule-row","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group section-composition--feature-row-hairlines is-style-rule-row"><!-- wp:paragraph --><p>Row</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $repairs = [];
    $out = \Automattic\SiteBuild\Units\GeneratedMarkup::stripRuleClassTokens($markup, 'page-home--features', $repairs);
    assert_contains('section-composition--feature-row-hairlines', $out, 'the archetype marker survives');
    assert_true(!str_contains($out, 'is-style-rule-row'), 'the authored rule class still goes');
});

test('row checks look past a committed side-label split (frm PR-3j)', function () {
    $column = static fn (string $figure): string => '<!-- wp:column {"width":"25%"} --><div class="wp-block-column">'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . $figure . '</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph {"fontSize":"caption"} --><p class="has-caption-font-size">label</p><!-- /wp:paragraph --></div><!-- /wp:column -->';
    $ledger = '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">' . $column('120+') . $column('18') . $column('12') . $column('4') . '</div><!-- /wp:columns -->';
    $split = '<!-- wp:group {"className":"section-composition--stat-ledger","layout":{"type":"constrained"}} --><div class="wp-block-group section-composition--stat-ledger">'
        . '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
        . '<!-- wp:column {"width":"25%"} --><div class="wp-block-column"><!-- wp:paragraph {"className":"side-label","fontSize":"caption"} --><p class="side-label has-caption-font-size">Scale</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
        . '<!-- wp:column {"width":"75%"} --><div class="wp-block-column">'
        . '<!-- wp:heading --><h2 class="wp-block-heading">Measured</h2><!-- /wp:heading -->' . $ledger
        . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns --></div><!-- /wp:group -->';
    assert_eq([], SectionComposition::markupWarnings($split, 'stat-ledger', 'page-home--stats'), 'the split is not a second ledger row');
    $plain = str_replace('section-composition--stat-ledger', 'section-composition--feature-row-hairlines', $split);
    $joined = implode("\n", SectionComposition::markupWarnings($plain, 'feature-row-hairlines', 'page-home--stats'));
    assert_true(!str_contains($joined, 'feature row shape'), 'the feature row check skips the split too');
    // A split whose leading column holds more than the label is a real row and still counts.
    $notSplit = str_replace('<p class="side-label has-caption-font-size">Scale</p><!-- /wp:paragraph -->', '<p class="side-label has-caption-font-size">Scale</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>More</p><!-- /wp:paragraph -->', $split);
    $joined = implode("\n", SectionComposition::markupWarnings($notSplit, 'stat-ledger', 'page-home--stats'));
    assert_contains('"column_rows":2', $joined);
});
