<?php
declare(strict_types=1);

use Automattic\SiteBuild\Eval\EvalMetrics;
use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\Steps\SectionsStep;

/**
 * One delivered band carrying its archetype marker, its item-pattern marker,
 * and `$images` images in its first region.
 */
function eval_layout_band(string $archetype, int $images, ?string $itemPattern, bool $pin): string
{
    $classes = SectionComposition::marker($archetype)
        . ($itemPattern !== null ? ' ' . ItemPattern::marker($itemPattern) : '');
    $image = '<!-- wp:image --><figure class="wp-block-image">'
        . '<img src="a.jpg" alt="a"/></figure><!-- /wp:image -->';
    $lead = $pin
        ? '<!-- wp:column {"className":"' . SectionComposition::PIN_CLASS . '"} -->'
            . '<div class="wp-block-column ' . SectionComposition::PIN_CLASS . '">'
        : '<!-- wp:column --><div class="wp-block-column">';

    return '<!-- wp:group {"className":"' . $classes . '"} -->'
        . '<div class="wp-block-group ' . $classes . '">'
        . '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column --><div class="wp-block-column">'
        . str_repeat($image, max(0, $images))
        . '</div><!-- /wp:column -->'
        . $lead
        . '<!-- wp:paragraph --><p>Copy.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->'
        . '</div><!-- /wp:group -->';
}

/**
 * One built site. `$sections` entries are
 * [slug, archetype, images-in-the-first-region, item_pattern].
 *
 * By default the bands land where a FINISHED build leaves them — folded into
 * the assembled page — because that is the artifact the metric must read.
 *
 * @param list<array{0:string,1:string,2:int,3:?string}> $sections
 */
function eval_layout_project(array $sections, bool $pin = false, bool $partsOnly = false): Project
{
    $project = new Project(sys_get_temp_dir() . '/eval-layout-' . uniqid());
    $planned = [];
    $assembled = '';

    foreach ($sections as [$slug, $archetype, $images, $itemPattern]) {
        $planned[] = [
            'slug' => $slug,
            'layout_archetype' => $archetype,
            'item_pattern' => $itemPattern,
        ];
        // A band only pins where the catalog would ask for one: an unequal-region
        // archetype carrying a repeated set.
        $band = eval_layout_band(
            $archetype,
            $images,
            $itemPattern,
            $pin && SectionComposition::pinsLeadColumn($archetype, $itemPattern),
        );
        if ($partsOnly) {
            $project->writeText('theme/parts/' . SectionsStep::partSlug('home', $slug) . '.html', $band);
        } else {
            $assembled .= $band;
        }
    }

    if (!$partsOnly) {
        $project->writeText('plugin/pages/home.html', $assembled);
    }
    // Every finished project keeps these two under parts. They must never be
    // counted as bands.
    $project->writeText('theme/parts/header.html', '<!-- wp:site-title /-->');
    $project->writeText('theme/parts/footer.html', '<!-- wp:paragraph --><p>c</p><!-- /wp:paragraph -->');

    $project->writeJson('pages.json', ['pages' => [['slug' => 'home', 'sections' => $planned]]]);
    return $project;
}

test('eval reports the archetype histogram and its concentration', function () {
    $metrics = EvalMetrics::collect(eval_layout_project([
        ['a', 'asymmetric-split', 1, null],
        ['b', 'asymmetric-split', 1, null],
        ['c', 'asymmetric-split', 1, null],
        ['d', 'centered-stack', 0, null],
    ]));

    assert_eq(['asymmetric-split' => 3, 'centered-stack' => 1], $metrics['archetypes']);
    // The number BIGR-885 targets: one archetype carrying most of a site. A
    // histogram alone would let 75% read as four healthy entries.
    assert_eq(0.75, $metrics['archetype_max_share'], 'concentration is its own number');
});

test('eval counts the split bands that strand a region beside its sibling', function () {
    // BIGR-945: `unbalanced_split_bands` is the stranded-quadrant count. Only a
    // band whose regions are unequal by design can produce it, so a card grid
    // carrying the same media never lands here.
    $metrics = EvalMetrics::collect(eval_layout_project([
        ['balanced', 'asymmetric-split', 1, null],
        ['stranded', 'asymmetric-split', 3, null],
        ['grid', 'equal-card-grid', 3, null],
    ]));

    assert_eq(3, array_sum($metrics['archetypes']), 'every planned section is counted');
    assert_eq(2, $metrics['split_bands'], 'only unequal-region bands are split bands');
    assert_eq(1, $metrics['unbalanced_split_bands'], 'one image against none stays balanced');
    assert_eq(0, $metrics['pinned_split_bands']);
});

test('eval counts the split bands that delivered their pin', function () {
    $metrics = EvalMetrics::collect(eval_layout_project([
        ['menu', 'asymmetric-split', 1, 'rule-row'],
        ['team', 'asymmetric-split', 1, 'card'],
    ], pin: true));

    assert_eq(2, $metrics['pinned_split_bands'], 'both list-like splits pinned their lead');
    assert_eq(0, $metrics['unbalanced_split_bands']);
});

test('eval reads the assembled page, not the parts a finished build discards', function () {
    // The regression this guards: `assemble-pages` folds every section into the
    // page and leaves only header/footer under parts, so a parts-only reader
    // silently scores every completed build as zero splits.
    $sections = [
        ['menu', 'asymmetric-split', 1, 'rule-row'],
        ['story', 'asymmetric-split', 3, null],
    ];
    $assembled = EvalMetrics::collect(eval_layout_project($sections, pin: true));
    assert_eq(2, $assembled['split_bands'], 'the assembled page is read at all');
    assert_eq(1, $assembled['pinned_split_bands']);
    assert_eq(1, $assembled['unbalanced_split_bands']);

    // The parts fallback exists for a project inspected before assembly, and
    // must report the same shape.
    $parts = EvalMetrics::collect(eval_layout_project($sections, pin: true, partsOnly: true));
    assert_eq($assembled['split_bands'], $parts['split_bands'], 'both readers agree on splits');
    assert_eq($assembled['pinned_split_bands'], $parts['pinned_split_bands']);
    assert_eq($assembled['unbalanced_split_bands'], $parts['unbalanced_split_bands']);
});

test('eval layout metrics stay zero without a plan to read', function () {
    // A site missing the artifact contributes zeros, never a partial figure
    // that would read as a measurement.
    $metrics = EvalMetrics::collect(new Project(sys_get_temp_dir() . '/eval-layout-empty-' . uniqid()));

    assert_eq([], $metrics['archetypes']);
    assert_eq(0.0, $metrics['archetype_max_share']);
    assert_eq(0, $metrics['split_bands']);
    assert_eq(0, $metrics['unbalanced_split_bands']);
    assert_eq(0, $metrics['pinned_split_bands']);
});
