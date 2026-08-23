<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\SectionPattern;

function sp_page(array $anchors): string
{
    $out = '';
    foreach ($anchors as $anchor) {
        $attrs = $anchor === null ? '{}' : '{"anchor":"' . $anchor . '"}';
        $out .= "<!-- wp:group {$attrs} -->\n<div class=\"wp-block-group\">"
            . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'
            . "</div>\n<!-- /wp:group -->\n";
    }
    return $out;
}

function sp_columns(int $count): string
{
    $columns = '';
    for ($i = 0; $i < $count; $i++) {
        $columns .= '<!-- wp:column --><div class="wp-block-column">'
            . '<!-- wp:paragraph --><p>card</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:column -->';
    }
    return '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:columns --><div class="wp-block-columns">'
        . $columns
        . '</div><!-- /wp:columns -->'
        . '</div><!-- /wp:group -->';
}

function sp_shape(string $markup): string
{
    $doc = BlockMarkup::parse($markup);
    $root = $doc->topLevel();
    assert_true($root !== null, 'shape fixture must have a root block');
    return SectionPattern::shape($doc, $root);
}

function sp_text_section(int $length): string
{
    return '<!-- wp:paragraph --><p>' . str_repeat('x', $length) . '</p><!-- /wp:paragraph -->';
}

test('split zips top-level blocks with planned sections by position', function (): void {
    $planned = [['slug' => 'hero'], ['slug' => 'about'], ['slug' => 'cta']];
    $result = SectionPattern::split(sp_page([null, null, null]), $planned);

    assert_eq(3, count($result['sections']), 'a page with zero anchors still yields every section');
    assert_eq('hero', $result['sections'][0]['slug']);
    assert_eq('cta', $result['sections'][2]['slug']);
    assert_eq([], $result['warnings']);
});

test('split returns the complete top-level block byte-for-byte', function (): void {
    $section = "<!-- wp:group {\"anchor\":\"hero\"} -->\n"
        . '<div class="wp-block-group"><!-- wp:buttons --><div class="wp-block-buttons">'
        . '<!-- wp:button --><div class="wp-block-button"><a href="/contact">Talk</a></div><!-- /wp:button -->'
        . '</div><!-- /wp:buttons --></div>'
        . "\n<!-- /wp:group -->";

    $result = SectionPattern::split($section, [['slug' => 'hero']]);

    assert_eq(1, count($result['sections']));
    assert_eq($section, $result['sections'][0]['markup']);
});

test('split warns when an anchor disagrees with its positional slug', function (): void {
    $planned = [['slug' => 'hero'], ['slug' => 'about']];
    $result = SectionPattern::split(sp_page(['hero', 'contact']), $planned);

    assert_eq(2, count($result['sections']), 'the section is kept, not dropped');
    assert_eq(1, count($result['warnings']));
    assert_contains('contact', $result['warnings'][0]);
    assert_contains('about', $result['warnings'][0]);
});

test('split falls back to anchor matching when counts differ', function (): void {
    $planned = [['slug' => 'hero'], ['slug' => 'about']];
    $result = SectionPattern::split(sp_page(['hero', 'about', 'extra']), $planned);

    assert_eq(2, count($result['sections']), 'anchor fallback recovers the planned two');
    assert_eq(['hero', 'about'], array_column($result['sections'], 'slug'));
    assert_true($result['warnings'] !== [], 'count mismatch is the drift signal and must warn');
});

test('split gives up with a warning when neither rule yields a section', function (): void {
    $planned = [['slug' => 'hero'], ['slug' => 'about']];
    $result = SectionPattern::split(sp_page([null, null, null]), $planned);

    assert_eq([], $result['sections']);
    assert_true($result['warnings'] !== []);
});

test('split skips an unsafe top-level section instead of emitting truncated markup', function (): void {
    $unsafe = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>unfinished</p><!-- /wp:paragraph -->';

    $result = SectionPattern::split($unsafe, [['slug' => 'hero']]);

    assert_eq([], $result['sections']);
    assert_true($result['warnings'] !== []);
    assert_contains('hero', $result['warnings'][0]);
});

test('normalizeLabel collapses qualifier variants and synonyms', function (): void {
    assert_eq('service', SectionPattern::normalizeLabel('services-grid'));
    assert_eq('service', SectionPattern::normalizeLabel('services-overview'));
    assert_eq('service', SectionPattern::normalizeLabel('service-list'));
    assert_eq('cta', SectionPattern::normalizeLabel('call-to-action'));
    assert_eq('testimonial', SectionPattern::normalizeLabel('social-proof'));
    assert_eq('testimonial', SectionPattern::normalizeLabel('trust-proof'));
    assert_eq('hero', SectionPattern::normalizeLabel('page-header'));
    assert_eq('contact', SectionPattern::normalizeLabel('contact-form'));
    assert_eq('contact', SectionPattern::normalizeLabel('form'));
    assert_eq('grid', SectionPattern::normalizeLabel('grid'), 'normalization never returns empty');
});

test('normalizeLabel picks the head noun from the END of a compound', function (): void {
    // English compounds put the head noun last. First-token-wins gets every one
    // of these wrong, and the -cta pair would mint duplicate CTA patterns.
    assert_eq('hero', SectionPattern::normalizeLabel('page-hero'));      // 16x in corpus
    assert_eq('hero', SectionPattern::normalizeLabel('cover-hero'));     //  2x
    assert_eq('hero', SectionPattern::normalizeLabel('contact-hero'));   //  3x
    assert_eq('cta',  SectionPattern::normalizeLabel('closing-cta'));    //  5x
    assert_eq('cta',  SectionPattern::normalizeLabel('contact-cta'));    //  2x
    // ...but only for known head nouns; an unknown last token keeps the first.
    assert_eq('case', SectionPattern::normalizeLabel('case-study'));
});

test('normalizeLabel does not strip a false plural', function (): void {
    assert_eq('process', SectionPattern::normalizeLabel('process'));     // 13x; NOT "proces"
    assert_eq('status',  SectionPattern::normalizeLabel('status'));
    assert_eq('analysis', SectionPattern::normalizeLabel('analysis'));
    assert_eq('news', SectionPattern::normalizeLabel('news'));
    assert_eq('series', SectionPattern::normalizeLabel('series'));
    assert_eq('species', SectionPattern::normalizeLabel('species'));
    // real plurals still collapse
    assert_eq('service', SectionPattern::normalizeLabel('services'));
    assert_eq('testimonial', SectionPattern::normalizeLabel('testimonials'));
    assert_eq('credential', SectionPattern::normalizeLabel('credentials'));
});

test('normalizeLabel singularizes ies and preserves other plural rules', function (): void {
    foreach ([
        'studies' => 'study',
        'galleries' => 'gallery',
        'stories' => 'story',
        'categories' => 'category',
        'classes' => 'class',
        'courses' => 'course',
        'processes' => 'process',
        'boxes' => 'box',
        'services' => 'service',
        'process' => 'process',
        'class' => 'class',
        'status' => 'status',
        'analysis' => 'analysis',
        'news' => 'news',
        'series' => 'series',
        'species' => 'species',
    ] as $authored => $expected) {
        assert_eq($expected, SectionPattern::normalizeLabel($authored), $authored);
    }
});

test('isGenericSlug rejects positional ids', function (): void {
    foreach (['section-1', 'section-12', 'div-3', 'nav-2', 'col-1', 'row'] as $slug) {
        assert_true(SectionPattern::isGenericSlug($slug), "{$slug} should be generic");
    }
    foreach (['philosophy', 'kiln-share', 'hero'] as $slug) {
        assert_true(!SectionPattern::isGenericSlug($slug), "{$slug} should not be generic");
    }
});

test('label ladder leaves a generic middle content section unresolved', function (): void {
    assert_eq(null, SectionPattern::label(['type' => 'content', 'slug' => 'section-3'], 2, 6));
});

test('label ladder resolves positional edges and meaningful plan values', function (): void {
    assert_eq('hero', SectionPattern::label(['type' => 'content', 'slug' => 'section-1'], 0, 6));
    assert_eq('closing', SectionPattern::label(['type' => 'content', 'slug' => 'section-6'], 5, 6));
    assert_eq('philosophy', SectionPattern::label(['type' => 'content', 'slug' => 'philosophy'], 2, 6));
    assert_eq('testimonial', SectionPattern::label(['type' => 'testimonials-grid', 'slug' => 'x1'], 2, 6));
});

test('shape recognizes form sections', function (): void {
    $markup = '<!-- wp:group --><div><!-- wp:jetpack/contact-form --><form></form>'
        . '<!-- /wp:jetpack/contact-form --></div><!-- /wp:group -->';
    assert_eq('form', sp_shape($markup));
});

test('shape recognizes gallery sections', function (): void {
    $markup = '<!-- wp:group --><div><!-- wp:gallery --><figure></figure>'
        . '<!-- /wp:gallery --></div><!-- /wp:group -->';
    assert_eq('gallery', sp_shape($markup));
});

test('shape recognizes quote collections', function (): void {
    $markup = '<!-- wp:group --><div>'
        . '<!-- wp:quote --><blockquote>a</blockquote><!-- /wp:quote -->'
        . '<!-- wp:pullquote --><figure>b</figure><!-- /wp:pullquote -->'
        . '</div><!-- /wp:group -->';
    assert_eq('quotes', sp_shape($markup));
});

test('shape recognizes core cover roots', function (): void {
    $markup = '<!-- wp:cover --><div class="wp-block-cover">'
        . '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:cover -->';
    assert_eq('cover', sp_shape($markup));
});

test('shape recognizes an image band cover without a core cover block', function (): void {
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:image {"align":"full"} --><figure><img src="hero.jpg"/></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    assert_true(!str_contains($markup, 'wp:cover'));
    assert_eq('cover', sp_shape($markup));
});

test('shape recognizes grids', function (): void {
    assert_eq('grid', sp_shape(sp_columns(3)));
});

test('shape recognizes two-column splits', function (): void {
    assert_eq('split', sp_shape(sp_columns(2)));
});

test('shape recognizes media stacks', function (): void {
    $markup = '<!-- wp:group --><div>'
        . '<!-- wp:image --><figure><img src="photo.jpg"/></figure><!-- /wp:image -->'
        . '<!-- wp:heading --><h2>Story</h2><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->';
    assert_eq('media-stack', sp_shape($markup));
});

test('shape falls back to stack', function (): void {
    $markup = '<!-- wp:group --><div>'
        . '<!-- wp:paragraph --><p>Plain section.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    assert_eq('stack', sp_shape($markup));
});

test('completeness scores heading body action and media independently', function (): void {
    $markup = '<!-- wp:group --><div>'
        . '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
        . '<!-- wp:button --><div><a href="/contact">Act</a></div><!-- /wp:button -->'
        . '<!-- wp:image --><figure><img src="photo.jpg"/></figure><!-- /wp:image -->'
        . '</div><!-- /wp:group -->';
    assert_eq(40, SectionPattern::score($markup, ['/contact'])['completeness']);
});

test('repetition rewards a filled grid over a one-off', function (): void {
    assert_eq(15, SectionPattern::score(sp_columns(3), [])['repetition']);
    assert_eq(5, SectionPattern::score(sp_columns(1), [])['repetition']);
});

test('repetition recognizes consecutive sibling groups with the same child shape', function (): void {
    $item = '<!-- wp:group --><div><!-- wp:heading --><h3>Card</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $markup = '<!-- wp:group --><div>' . $item . $item . $item . '</div><!-- /wp:group -->';
    assert_eq(15, SectionPattern::score($markup, [])['repetition']);
});

test('copy fit is full in range and falls off on both sides', function (): void {
    assert_eq(15, SectionPattern::score(sp_text_section(150), [])['copy_fit']);
    assert_eq(15, SectionPattern::score(sp_text_section(1500), [])['copy_fit']);
    assert_true(SectionPattern::score(sp_text_section(75), [])['copy_fit'] < 15);
    assert_true(SectionPattern::score(sp_text_section(2250), [])['copy_fit'] < 15);
});

test('fidelity ignores normal transformer bookkeeping', function (): void {
    $normal = '<!-- wp:group {"className":"blocks-engine-css-owned-layout blocks-engine-source-p-1 blocks-engine-richtext-marker blocks-engine-control-a"} -->'
        . '<div class="wp-block-group blocks-engine-css-owned-layout blocks-engine-source-p-1 blocks-engine-richtext-marker blocks-engine-control-a"></div>'
        . '<!-- /wp:group -->';
    assert_eq(15, SectionPattern::score($normal, [])['fidelity']);
});

test('fidelity penalizes only distinct real degradation markers', function (): void {
    $degraded = '<!-- wp:group {"className":"blocks-engine-synthetic-paragraph blocks-engine-empty-flex-item"} -->'
        . '<div class="blocks-engine-synthetic-paragraph blocks-engine-empty-flex-item"></div>'
        . '<!-- /wp:group -->';
    assert_eq(5, SectionPattern::score($degraded, [])['fidelity']);
});

test('self containment deducts unresolved HTML and block-json targets', function (): void {
    $markup = '<!-- wp:group --><div>'
        . '<a href="/about">About</a><a href="/missing">Missing</a>'
        . '<!-- wp:navigation-link {"label":"Contact","url":"/contact"} /-->'
        . '</div><!-- /wp:group -->';
    assert_eq(8, SectionPattern::score($markup, ['/about', '/contact'])['self_containment']);
});

test('score total is the deterministic sum of its components', function (): void {
    $score = SectionPattern::score(sp_columns(3), []);
    assert_eq(
        $score['completeness'] + $score['repetition'] + $score['copy_fit']
            + $score['fidelity'] + $score['self_containment'],
        $score['total']
    );
});

test('pickWinner applies score then every tie-break tier', function (): void {
    $lowScore = ['score' => ['total' => 49], 'menu_order' => 0, 'index' => 0, 'slug' => 'a'];
    $highScore = ['score' => ['total' => 50], 'menu_order' => 9, 'index' => 9, 'slug' => 'z'];
    assert_eq($highScore, SectionPattern::pickWinner([$lowScore, $highScore]));

    $latePage = ['score' => ['total' => 50], 'menu_order' => 10, 'index' => 0, 'slug' => 'a'];
    $earlyPage = ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 5, 'slug' => 'z'];
    assert_eq($earlyPage, SectionPattern::pickWinner([$latePage, $earlyPage]));

    $lateSection = ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 5, 'slug' => 'a'];
    $earlySection = ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 1, 'slug' => 'z'];
    assert_eq($earlySection, SectionPattern::pickWinner([$lateSection, $earlySection]));

    $slugZ = ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 1, 'slug' => 'z'];
    $slugA = ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 1, 'slug' => 'a'];
    assert_eq($slugA, SectionPattern::pickWinner([$slugZ, $slugA]));
});

test('full classification and scoring path is deterministic and input-order independent', function (): void {
    $page = sp_page([null, null, null]);
    $planned = [
        ['type' => 'content', 'slug' => 'section-1'],
        ['type' => 'content', 'slug' => 'section-2'],
        ['type' => 'content', 'slug' => 'section-3'],
    ];
    $classify = static function () use ($page, $planned): array {
        $split = SectionPattern::split($page, $planned);
        $classified = [];
        foreach ($split['sections'] as $section) {
            $doc = BlockMarkup::parse($section['markup']);
            $root = $doc->topLevel();
            assert_true($root !== null);
            $classified[] = [
                'label' => SectionPattern::label(
                    $planned[$section['index']],
                    $section['index'],
                    count($planned)
                ),
                'shape' => SectionPattern::shape($doc, $root),
                'score' => SectionPattern::score($section['markup'], ['/about', '/contact']),
            ];
        }
        return ['split' => $split, 'classified' => $classified];
    };

    assert_eq($classify(), $classify());

    $candidates = [
        ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 1, 'slug' => 'z'],
        ['score' => ['total' => 50], 'menu_order' => 0, 'index' => 1, 'slug' => 'a'],
    ];
    assert_eq(
        SectionPattern::pickWinner($candidates),
        SectionPattern::pickWinner(array_reverse($candidates))
    );
    assert_eq(
        SectionPattern::score(sp_columns(3), ['/about', '/contact']),
        SectionPattern::score(sp_columns(3), ['/contact', '/about'])
    );
});

test('an over-long model-authored label degrades instead of reaching a filename', function (): void {
    $long = str_repeat('servicesoverview', 20);
    assert_eq('', SectionPattern::normalizeLabel($long));
    assert_eq('service', SectionPattern::normalizeLabel('services'));
    assert_eq('cta', SectionPattern::normalizeLabel('call-to-action'));
    assert_eq(str_repeat('a', 64), SectionPattern::normalizeLabel(str_repeat('a', 64)));
    assert_eq('', SectionPattern::normalizeLabel(str_repeat('a', 65)));
});
