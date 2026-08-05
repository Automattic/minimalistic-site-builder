<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\CardStyleContract;
use Automattic\SiteBuild\Units\SectionUnit;

function card_contract_image(mixed $radius = null): string
{
    $attrs = ['className' => 'card-media'];
    if ($radius !== null) {
        $attrs['style'] = ['border' => ['radius' => $radius]];
    }
    return '<!-- wp:image ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<figure class="wp-block-image card-media"><img src="card.jpg" alt="Card" /></figure>'
        . '<!-- /wp:image -->';
}

/** @param array<mixed> $attrs */
function card_contract_body(array $attrs = [], string $classes = ''): string
{
    if ($classes !== '') {
        $attrs['className'] = $classes;
    }
    $json = $attrs === [] ? '' : ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES);
    $htmlClasses = 'wp-block-group' . ($classes === '' ? '' : ' ' . $classes);
    return '<!-- wp:group' . $json . ' -->'
        . '<div class="' . $htmlClasses . '">'
        . '<!-- wp:heading --><h3 class="wp-block-heading">Surviving title</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Surviving copy.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}

/** @param array<mixed> $attrs */
function card_contract_group(array $attrs, string $children, string $htmlClasses = 'wp-block-group'): string
{
    return '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div class="' . $htmlClasses . '">' . $children . '</div><!-- /wp:group -->';
}

function card_contract_grid(string $cards): string
{
    return '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:columns {"className":"equal-cards"} -->'
        . '<div class="wp-block-columns equal-cards">' . $cards . '</div><!-- /wp:columns -->'
        . '</div><!-- /wp:group -->';
}

function card_contract_column(string $card): string
{
    return '<!-- wp:column --><div class="wp-block-column">' . $card . '</div><!-- /wp:column -->';
}

function card_contract_ordinary_columns(string $columns): string
{
    return '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:columns --><div class="wp-block-columns">'
        . $columns
        . '</div><!-- /wp:columns -->'
        . '</div><!-- /wp:group -->';
}

function card_contract_flush_card(string $classes = ''): string
{
    $attrs = [
        'backgroundColor' => 'base',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ];
    if ($classes !== '') {
        $attrs['className'] = $classes;
    }
    $htmlClasses = 'wp-block-group has-base-background-color has-background'
        . ($classes === '' ? '' : ' ' . $classes);
    return card_contract_group(
        $attrs,
        card_contract_image() . card_contract_body([
            'style' => ['spacing' => ['padding' => [
                'top' => '1rem',
                'right' => '1rem',
                'bottom' => '1rem',
                'left' => '1rem',
            ]]],
        ]),
        $htmlClasses,
    );
}

function card_contract_old_inset_card(): string
{
    return card_contract_group([
        'backgroundColor' => 'base',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['padding' => [
                'top' => '1rem',
                'right' => '1rem',
                'bottom' => '1rem',
                'left' => '1rem',
            ]],
        ],
    ], card_contract_image('16px')
        . '<!-- wp:heading --><h3 class="wp-block-heading">Inset title</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Inset copy survives.</p><!-- /wp:paragraph -->',
        'wp-block-group has-base-background-color has-background');
}

function card_contract_framed_px_card(string $imageRadius): string
{
    return card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '24px'],
            'spacing' => ['padding' => [
                'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
            ]],
        ],
    ], card_contract_image($imageRadius)
        . '<!-- wp:paragraph --><p>Framed pixels.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--flush card-flush');
}

test('card contract repairs only class drift on conforming flush anatomy and reaches a fixed point', function () {
    $markup = card_contract_grid(card_contract_column(card_contract_flush_card()));

    $first = CardStyleContract::enforce($markup, 'flush', 'page-home--cards');

    assert_eq([], $first['warnings']);
    assert_eq(1, count($first['repairs']));
    assert_contains('"className":"card-style\\u002d\\u002dflush card-flush"', $first['markup']);
    assert_contains('card-style--flush card-flush', $first['markup']);
    assert_contains('"className":"card-body"', $first['markup']);
    assert_contains('wp-block-group card-body', $first['markup']);
    assert_contains('Surviving title', $first['markup']);
    assert_contains('Surviving copy.', $first['markup']);

    $second = CardStyleContract::enforce($first['markup'], 'flush', 'page-home--cards');
    assert_eq($first['markup'], $second['markup'], 'the class repair is byte-stable');
    assert_eq([], $second['repairs']);
    assert_eq([], $second['warnings']);
});

test('card contract assigns misplaced body hooks to their parent card and normalizes every style once', function () {
    $padding = [
        'top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem',
    ];
    $polluted = 'body-extra card-style--flush card-flush overlap-up card-body';
    $cases = [
        'flush' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--flush card-flush',
                'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
            ],
            'outer_html' => 'wp-block-group has-base-background-color has-background card-style--flush card-flush',
            'image_radius' => null,
            'body' => ['style' => ['spacing' => ['padding' => $padding]]],
            'body_html' => 'wp-block-group body-extra card-body',
        ],
        'overlap' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--overlap card-flush',
                'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
            ],
            'outer_html' => 'wp-block-group has-base-background-color has-background card-style--overlap card-flush',
            'image_radius' => null,
            'body' => [
                'backgroundColor' => 'base',
                'style' => ['spacing' => [
                    'padding' => $padding,
                    'margin' => ['left' => '1rem', 'right' => '1rem'],
                ]],
            ],
            'body_html' => 'wp-block-group body-extra card-body overlap-up',
        ],
        'framed' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--framed',
                'style' => [
                    'border' => ['radius' => '24px'],
                    'spacing' => ['padding' => [
                        'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
                    ]],
                ],
            ],
            'outer_html' => 'wp-block-group has-base-background-color has-background card-style--framed',
            'image_radius' => '16px',
            'body' => ['style' => ['spacing' => ['padding' => $padding]]],
            'body_html' => 'wp-block-group body-extra card-body',
        ],
        'borderless' => [
            'outer' => ['className' => 'card-style--borderless'],
            'outer_html' => 'wp-block-group card-style--borderless',
            'image_radius' => null,
            'body' => ['style' => ['spacing' => ['padding' => $padding]]],
            'body_html' => 'wp-block-group body-extra card-body',
        ],
    ];

    foreach ($cases as $style => $case) {
        $markup = card_contract_group(
            $case['outer'],
            card_contract_image($case['image_radius'])
                . card_contract_body($case['body'], $polluted),
            $case['outer_html'],
        );

        $first = CardStyleContract::enforce($markup, $style, "page-home--{$style}-hooks");

        assert_eq([], $first['warnings'], "{$style} body hook drift is repaired without a child-card warning");
        assert_eq(1, count($first['repairs']), "{$style} body hook repair is reported once");
        assert_contains($case['body_html'], $first['markup']);
        assert_true(!str_contains($first['markup'], 'wp-block-group body-extra card-flush'));
        assert_true(!str_contains($first['markup'], 'wp-block-group body-extra card-style--'));

        $second = CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-hooks",
        );
        assert_eq($first['markup'], $second['markup'], "{$style} body hooks reach a byte fixed point");
        assert_eq([], $second['repairs']);
        assert_eq([], $second['warnings']);
    }
});

test('card contract records exact reserved-hook drift when anatomy blocks class repair', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $outerClasses = 'card-style--framed card-body overlap-up';
    $bodyClasses = 'card-body card-style--overlap card-flush overlap-up';
    $card = card_contract_group([
        'backgroundColor' => 'base',
        'className' => $outerClasses,
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ], card_contract_image()
        . card_contract_body(['style' => ['spacing' => ['padding' => $padding]]], $bodyClasses)
        . '<!-- wp:paragraph --><p>Unexpected extra sibling.</p><!-- /wp:paragraph -->',
        'wp-block-group has-base-background-color has-background ' . $outerClasses);

    $first = CardStyleContract::enforce($card, 'flush', 'page-home--blocked-hooks');

    assert_eq($card, $first['markup']);
    assert_eq([], $first['repairs']);
    assert_eq(1, count($first['warnings']));
    $warning = $first['warnings'][0];
    foreach ([
        'direct_children=["wp:image","wp:group","wp:paragraph"]',
        'outer_attribute_reserved_hooks=["card-style--framed","card-body","overlap-up"]',
        'outer_html_reserved_hooks=["card-style--framed","card-body","overlap-up"]',
        'body_attribute_reserved_hooks=["card-body","card-style--overlap","card-flush","overlap-up"]',
        'body_html_reserved_hooks=["card-body","card-style--overlap","card-flush","overlap-up"]',
        'outer_attribute_reserved_hooks=["card-style--flush","card-flush"]',
        'body_attribute_reserved_hooks=["card-body"]',
        'remain incorrect because anatomy blocked class repair',
    ] as $evidence) {
        assert_contains($evidence, $warning);
    }

    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--blocked-hooks',
    );
    assert_eq($first, $second, 'blocked hook evidence is a fixed point');
});

test('card contract blocks ambiguous text wrappers for every style with exact hook evidence', function () {
    $firstClasses = 'wrapper-first overlap-up';
    $secondClasses = 'wrapper-second card-body card-style--framed card-flush';
    $firstBody = card_contract_group(
        ['className' => $firstClasses],
        '<!-- wp:heading --><h3>First wrapper.</h3><!-- /wp:heading -->',
        'wp-block-group ' . $firstClasses,
    );
    $secondBody = card_contract_group(
        ['className' => $secondClasses],
        '<!-- wp:paragraph --><p>Second wrapper.</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $secondClasses,
    );
    $cases = [
        'flush' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--flush card-flush',
                'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
            ],
            'html' => 'wp-block-group has-base-background-color has-background card-style--flush card-flush',
            'image_radius' => null,
        ],
        'overlap' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--overlap card-flush',
                'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
            ],
            'html' => 'wp-block-group has-base-background-color has-background card-style--overlap card-flush',
            'image_radius' => null,
        ],
        'framed' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--framed',
                'style' => [
                    'border' => ['radius' => '24px'],
                    'spacing' => ['padding' => [
                        'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
                    ]],
                ],
            ],
            'html' => 'wp-block-group has-base-background-color has-background card-style--framed',
            'image_radius' => '16px',
        ],
        'borderless' => [
            'outer' => ['className' => 'card-style--borderless'],
            'html' => 'wp-block-group card-style--borderless',
            'image_radius' => null,
        ],
    ];

    foreach ($cases as $style => $case) {
        $card = card_contract_group(
            $case['outer'],
            card_contract_image($case['image_radius']) . $firstBody . $secondBody,
            $case['html'],
        );
        $first = CardStyleContract::enforce($card, $style, "page-home--{$style}-ambiguous-bodies");

        assert_eq($card, $first['markup'], "{$style} ambiguous body stays byte-exact");
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        foreach ([
            'text_body_topology=',
            'multiple ambiguous direct text wrappers',
            'attribute_reserved_hooks":["overlap-up"]',
            'attribute_reserved_hooks":["card-body","card-style--framed","card-flush"]',
            'text_body_group=',
        ] as $evidence) {
            assert_contains($evidence, $first['warnings'][0], "{$style}: {$evidence}");
        }
        assert_true(
            substr_count($first['warnings'][0], 'text_body_group') >= 2,
            "{$style} warning records every direct text group",
        );
        assert_eq($first, CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-ambiguous-bodies",
        ));
    }
});

test('card contract blocks mixed flat and wrapped text for framed and borderless cards', function () {
    $wrapperClasses = 'mixed-wrapper overlap-up';
    $wrapper = card_contract_group(
        ['className' => $wrapperClasses],
        '<!-- wp:paragraph --><p>Wrapped copy.</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $wrapperClasses,
    );
    $cases = [
        'framed' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--framed',
                'style' => [
                    'border' => ['radius' => '24px'],
                    'spacing' => ['padding' => [
                        'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
                    ]],
                ],
            ],
            'html' => 'wp-block-group has-base-background-color has-background card-style--framed',
            'image_radius' => '16px',
        ],
        'borderless' => [
            'outer' => ['className' => 'card-style--borderless'],
            'html' => 'wp-block-group card-style--borderless',
            'image_radius' => null,
        ],
    ];

    foreach ($cases as $style => $case) {
        $card = card_contract_group(
            $case['outer'],
            card_contract_image($case['image_radius'])
                . '<!-- wp:paragraph --><p>Flat copy.</p><!-- /wp:paragraph -->'
                . $wrapper,
            $case['html'],
        );
        $first = CardStyleContract::enforce($card, $style, "page-home--{$style}-mixed-body");

        assert_eq($card, $first['markup']);
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        foreach ([
            'text_body_topology=',
            'wp:image","wp:paragraph","wp:group',
            'mixes a text wrapper with related content outside that wrapper',
            'attribute_reserved_hooks":["overlap-up"]',
            '"text_group_paths":',
        ] as $evidence) {
            assert_contains($evidence, $first['warnings'][0], "{$style}: {$evidence}");
        }
        assert_eq($first, CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-mixed-body",
        ));
    }
});

test('card contract quarantines a nested image-card subtree while repairing a sibling', function () {
    $nested = card_contract_group(
        ['className' => 'card-style--borderless'],
        card_contract_image()
            . '<!-- wp:paragraph --><p>Nested card copy stays exact.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless',
    );
    $outer = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ], card_contract_image() . $nested,
        'wp-block-group has-base-background-color has-background card-style--flush card-flush');
    $markup = card_contract_grid(
        card_contract_column($outer) . card_contract_column(card_contract_flush_card()),
    );

    $first = CardStyleContract::enforce($markup, 'flush', 'page-home--nested-card');

    assert_eq(1, count($first['warnings']));
    assert_eq(1, count($first['repairs']));
    assert_contains($outer, $first['markup'], 'the entire conflicting outer subtree stays byte-exact');
    assert_contains('nested_image_card=', $first['warnings'][0]);
    assert_contains('card-style--borderless', $first['warnings'][0]);
    assert_contains('nested inside the outer card subtree', $first['warnings'][0]);
    assert_contains('Nested card copy stays exact.', $first['markup']);

    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--nested-card',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq($first['warnings'], $second['warnings'], 'nested-card quarantine warning is a fixed point');
});

test('card contract quarantines nested image cards across multi-group and deeper body shapes', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $nested = card_contract_flush_card('card-style--framed nested-first');
    $extraBody = card_contract_body(
        ['style' => ['spacing' => ['padding' => $padding]]],
        'extra-body',
    );
    $deepBody = card_contract_group(
        [
            'className' => 'card-body',
            'style' => ['spacing' => ['padding' => $padding]],
        ],
        '<!-- wp:paragraph --><p>Outer body copy.</p><!-- /wp:paragraph -->' . $nested,
        'wp-block-group card-body',
    );
    $outerAttrs = [
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ];
    $outerHtml = 'wp-block-group has-base-background-color has-background card-style--flush card-flush';
    $cases = [
        'multi-group' => card_contract_group(
            $outerAttrs,
            card_contract_image() . $nested . $extraBody,
            $outerHtml,
        ),
        'deeper-body' => card_contract_group(
            $outerAttrs,
            card_contract_image() . $deepBody,
            $outerHtml,
        ),
    ];

    foreach ($cases as $case => $outer) {
        $first = CardStyleContract::enforce($outer, 'flush', "page-home--nested-{$case}");

        assert_eq($outer, $first['markup'], "{$case} outer subtree stays byte-exact");
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        assert_contains('nested_image_card=', $first['warnings'][0]);
        assert_contains('card-style--framed', $first['warnings'][0]);
        assert_eq($first, CardStyleContract::enforce(
            $first['markup'],
            'flush',
            "page-home--nested-{$case}",
        ));
    }

    $nestedSecond = card_contract_group(
        ['className' => 'card-style--borderless nested-second'],
        card_contract_image()
            . '<!-- wp:paragraph --><p>Second nested card.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless nested-second',
    );
    $multiple = card_contract_group(
        $outerAttrs,
        card_contract_image() . $nested . $nestedSecond,
        $outerHtml,
    );
    $multipleResult = CardStyleContract::enforce(
        $multiple,
        'flush',
        'page-home--multiple-nested-cards',
    );

    assert_eq($multiple, $multipleResult['markup']);
    assert_eq([], $multipleResult['repairs']);
    assert_eq(1, count($multipleResult['warnings']));
    assert_contains('nested-first', $multipleResult['warnings'][0]);
    assert_contains('nested-second', $multipleResult['warnings'][0]);
    assert_true(substr_count($multipleResult['warnings'][0], 'nested_image_card') >= 2);
    assert_true(!str_contains($multipleResult['warnings'][0], 'the sole group after'));
    assert_eq($multipleResult, CardStyleContract::enforce(
        $multipleResult['markup'],
        'flush',
        'page-home--multiple-nested-cards',
    ));
});

test('card contract quarantines every marked descendant of a nested image card', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $nestedBodyClasses = 'nested-marked-body card-style--overlap card-flush overlap-up';
    $nestedBody = card_contract_group([
        'className' => $nestedBodyClasses,
        'style' => ['spacing' => ['padding' => $padding]],
    ], '<!-- wp:paragraph --><p>Nested marked body.</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $nestedBodyClasses);
    $nestedCard = card_contract_group(
        ['className' => 'card-style--framed nested-card-root'],
        card_contract_image() . $nestedBody,
        'wp-block-group card-style--framed nested-card-root',
    );
    $outer = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ], card_contract_image() . $nestedCard,
        'wp-block-group has-base-background-color has-background card-style--flush card-flush');

    $first = CardStyleContract::enforce($outer, 'flush', 'page-home--nested-marked-body');

    assert_eq($outer, $first['markup'], 'the quarantined subtree remains byte-for-byte exact');
    assert_eq([], $first['repairs']);
    assert_eq(1, count($first['warnings']));
    assert_contains('nested-card-root', $first['warnings'][0]);
    assert_contains('nested-marked-body', $first['warnings'][0]);
    assert_contains('nested_reserved_hooks=', $first['warnings'][0]);
    assert_contains('card-style--overlap', $first['warnings'][0]);
    assert_eq($first, CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--nested-marked-body',
    ));
});

test('card contract warns a marker-only grandchild on the first pass and then stays fixed', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $deepClasses = 'deep-marker card-style--overlap card-flush overlap-up';
    $deepMarker = card_contract_group(
        ['className' => $deepClasses],
        '<!-- wp:paragraph --><p>Deep marker content.</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $deepClasses,
    );
    $bodyClasses = 'body-marker card-style--framed card-flush';
    $body = card_contract_group([
        'className' => $bodyClasses,
        'style' => ['spacing' => ['padding' => $padding]],
    ], $deepMarker, 'wp-block-group ' . $bodyClasses);
    $outer = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ], card_contract_image() . $body,
        'wp-block-group has-base-background-color has-background card-style--flush card-flush');

    $first = CardStyleContract::enforce($outer, 'flush', 'page-home--marker-chain');

    assert_eq(1, count($first['repairs']), 'the retained parent repairs its immediate body');
    assert_eq(1, count($first['warnings']), 'the unowned deep marker warns on the first pass');
    assert_contains('wp:group[0] > wp:group[0] > wp:group[0]', $first['warnings'][0]);
    assert_contains('deep-marker card-style--overlap card-flush overlap-up', $first['markup']);
    assert_contains('wp-block-group body-marker card-body', $first['markup']);

    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--marker-chain',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq($first['warnings'], $second['warnings']);
});

test('card contract recovers unmarked staggered and editorial cards in ordinary columns', function () {
    $markup = card_contract_ordinary_columns(
        card_contract_column(card_contract_flush_card())
        . card_contract_column(card_contract_flush_card()),
    );

    $first = CardStyleContract::enforce($markup, 'flush', 'page-home--ordinary-cards');

    assert_eq([], $first['warnings']);
    assert_eq(2, count($first['repairs']));
    assert_eq(2, substr_count($first['markup'], 'card-style--flush card-flush'));

    $second = CardStyleContract::enforce($first['markup'], 'flush', 'page-home--ordinary-cards');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq([], $second['warnings']);
});

test('card contract leaves old inset flush drift byte-identical and warns with actionable context', function () {
    $card = card_contract_old_inset_card();
    $sibling = '<!-- wp:paragraph --><p>SIBLING-BYTES-STAY-EXACT</p><!-- /wp:paragraph -->';
    $markup = card_contract_grid(card_contract_column($card)) . $sibling;

    $first = CardStyleContract::enforce($markup, 'flush', 'page-home--cards');

    assert_eq($markup, $first['markup'], 'unsafe anatomy is not half-normalized');
    assert_eq([], $first['repairs']);
    assert_eq(1, count($first['warnings']));
    $warning = $first['warnings'][0];
    foreach ([
        "file='theme/parts/page-home--cards.html'",
        "block='wp:group[0] > wp:columns[0] > wp:column[0] > wp:group[0]'",
        'authored=',
        'delivered=unchanged card markup',
        "assigned card_style 'flush'",
        'outer card padding remains inset',
        'direct image radius remains',
        'outer_padding={"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}',
        'image_radius="16px"',
        'sibling content were retained',
    ] as $context) {
        assert_contains($context, $warning);
    }
    assert_contains($card, $first['markup'], 'the isolated card survives byte-for-byte');
    assert_contains($sibling, $first['markup'], 'its sibling survives byte-for-byte');

    $second = CardStyleContract::enforce($first['markup'], 'flush', 'page-home--cards');
    assert_eq($first, $second, 'unchanged degradation is a stable warning fixed point');
});

test('card contract repairs a valid sibling while isolating one drifting card', function () {
    $good = card_contract_flush_card();
    $bad = card_contract_old_inset_card();
    $markup = card_contract_grid(card_contract_column($good) . card_contract_column($bad));

    $result = CardStyleContract::enforce($markup, 'flush', 'page-home--cards');

    assert_true($result['markup'] !== $markup, 'the valid sibling is normalized');
    assert_contains('card-style--flush card-flush', $result['markup']);
    assert_contains($bad, $result['markup'], 'the failing sibling remains byte-for-byte intact');
    assert_eq(1, count($result['repairs']));
    assert_eq(1, count($result['warnings']));
    assert_contains('wp:column[1] > wp:group[0]', $result['warnings'][0]);
});

test('card contract does not infer cards from generic or masonry card-media crops', function () {
    $cropped = card_contract_group([
        'backgroundColor' => 'base',
        'style' => ['border' => ['radius' => '20px'], 'spacing' => ['blockGap' => '0']],
    ], card_contract_image() . card_contract_body([
        'style' => ['spacing' => ['padding' => [
            'top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem',
        ]]],
    ]));
    $generic = '<!-- wp:group --><div class="wp-block-group">' . $cropped . '</div><!-- /wp:group -->';
    $masonry = '<!-- wp:group {"className":"masonry-3"} -->'
        . '<div class="wp-block-group masonry-3">' . $cropped . '</div><!-- /wp:group -->';
    $nonCardImage = str_replace('card-media', 'feature-media', card_contract_image());
    $ordinaryNonCard = card_contract_ordinary_columns(
        card_contract_column(card_contract_group(
            ['backgroundColor' => 'base'],
            $nonCardImage . '<!-- wp:paragraph --><p>Feature copy.</p><!-- /wp:paragraph -->',
        ))
        . card_contract_column(card_contract_group(
            ['backgroundColor' => 'base'],
            $nonCardImage . '<!-- wp:paragraph --><p>More copy.</p><!-- /wp:paragraph -->',
        )),
    );
    $ordinarySplit = card_contract_ordinary_columns(
        card_contract_column($cropped)
        . card_contract_column('<!-- wp:paragraph --><p>Independent split-layout copy.</p><!-- /wp:paragraph -->'),
    );
    $listThumb = card_contract_ordinary_columns(
        card_contract_column(str_replace('card-media', 'card-media-thumb', card_contract_image()))
        . card_contract_column(
            '<!-- wp:heading --><h3 class="wp-block-heading">Index item</h3><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Compact list copy.</p><!-- /wp:paragraph -->',
        ),
    );

    foreach ([
        'generic' => $generic,
        'masonry' => $masonry,
        'ordinary-non-card' => $ordinaryNonCard,
        'ordinary-split' => $ordinarySplit,
        'list-thumb' => $listThumb,
    ] as $case => $markup) {
        $result = CardStyleContract::enforce($markup, 'flush', "page-home--{$case}");
        assert_eq($markup, $result['markup'], "{$case} crop stays byte-identical");
        assert_eq([], $result['repairs']);
        assert_eq([], $result['warnings']);
    }

    $explicitMasonry = '<!-- wp:group {"className":"masonry-3"} -->'
        . '<div class="wp-block-group masonry-3">'
        . card_contract_flush_card('card-style--framed')
        . '</div><!-- /wp:group -->';
    $explicitResult = CardStyleContract::enforce(
        $explicitMasonry,
        'flush',
        'page-home--explicit-masonry-card',
    );
    assert_eq([], $explicitResult['warnings']);
    assert_eq(1, count($explicitResult['repairs']));
    assert_contains('card-style--flush card-flush', $explicitResult['markup']);
});

test('card contract activates CSS-backed flush resets once and then reaches a fixed point', function () {
    $card = card_contract_group([
        'backgroundColor' => 'base',
        'style' => [
            'border' => ['radius' => '18px'],
            'spacing' => [
                'blockGap' => '0',
                'padding' => [
                    'top' => '13px', 'right' => '13px', 'bottom' => '13px', 'left' => '13px',
                ],
            ],
        ],
    ], card_contract_image('7px') . card_contract_body([
        'style' => ['spacing' => ['padding' => [
            'top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem',
        ]]],
    ]), 'wp-block-group has-base-background-color has-background');
    $markup = card_contract_grid(card_contract_column($card));

    $first = CardStyleContract::enforce($markup, 'flush', 'page-home--cards');

    assert_eq([], $first['warnings']);
    assert_contains('card-style--flush card-flush', $first['markup']);
    assert_contains('"13px"', $first['markup'], 'authored outer padding remains for the CSS reset');
    assert_contains('"7px"', $first['markup'], 'authored image radius remains for the CSS reset');
    assert_eq(
        ['card-style-classes-normalized', 'card-style-css-reset-bound'],
        array_column($first['repairs'], 'code'),
    );
    $cssRepair = $first['repairs'][1];
    assert_eq('13px', $cssRepair['authored']['outer_padding']['top'] ?? null);
    assert_eq('7px', $cssRepair['authored']['image_radius'] ?? null);

    $second = CardStyleContract::enforce($first['markup'], 'flush', 'page-home--cards');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs'], 'an already-active CSS reset is not reported twice');
    assert_eq([], $second['warnings']);
});

test('card contract uses one marker family across overlap framed and borderless constructions', function () {
    $overlap = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ], card_contract_image() . card_contract_body([
        'backgroundColor' => 'base',
        'style' => ['spacing' => [
            'padding' => ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'],
            'margin' => ['left' => '1rem', 'right' => '1rem'],
        ]],
    ]), 'wp-block-group card-style--framed has-base-background-color has-background');
    $overlapResult = CardStyleContract::enforce($overlap, 'overlap', 'page-home--overlap');
    assert_eq([], $overlapResult['warnings']);
    assert_contains('card-style--overlap', $overlapResult['markup']);
    assert_contains('card-flush', $overlapResult['markup']);
    assert_contains('card-body overlap-up', $overlapResult['markup']);

    $framed = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '24px'],
            'spacing' => ['padding' => [
                'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
            ]],
        ],
    ], card_contract_image('16px')
        . '<!-- wp:paragraph --><p>Framed copy.</p><!-- /wp:paragraph -->');
    $framedResult = CardStyleContract::enforce($framed, 'framed', 'page-home--framed');
    assert_eq([], $framedResult['warnings']);
    assert_contains('card-style--framed', $framedResult['markup']);
    assert_true(!str_contains($framedResult['markup'], 'card-flush'));

    $borderless = card_contract_group(
        ['className' => 'card-style--flush card-flush'],
        card_contract_image() . '<!-- wp:paragraph --><p>Borderless copy.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--flush card-flush',
    );
    $borderlessResult = CardStyleContract::enforce($borderless, 'borderless', 'page-home--borderless');
    assert_eq([], $borderlessResult['warnings']);
    assert_contains('card-style--borderless', $borderlessResult['markup']);
    assert_true(!str_contains($borderlessResult['markup'], 'card-flush'));
});

test('card contract enforces the exact comparable framed radius formula', function () {
    $valid = card_contract_framed_px_card('16px');
    $validResult = CardStyleContract::enforce($valid, 'framed', 'page-home--framed-valid');

    assert_eq([], $validResult['warnings']);
    assert_eq(1, count($validResult['repairs']));
    assert_contains('card-style--framed', $validResult['markup']);
    assert_true(!str_contains($validResult['markup'], 'card-flush'));

    $mismatch = card_contract_framed_px_card('2px');
    $mismatchResult = CardStyleContract::enforce($mismatch, 'framed', 'page-home--framed-mismatch');

    assert_eq($mismatch, $mismatchResult['markup'], 'a radius mismatch is retained for later repair');
    assert_eq([], $mismatchResult['repairs']);
    assert_eq(1, count($mismatchResult['warnings']));
    $warning = $mismatchResult['warnings'][0];
    foreach ([
        'framed_radii={"card":"24px"',
        '"padding":{"top":"8px","right":"8px","bottom":"8px","left":"8px"}',
        '"image":"2px"',
        'framed_radii={"image":"16px"',
        'max(card radius - uniform padding, 2px)',
        'concentric pixel formula',
    ] as $evidence) {
        assert_contains($evidence, $warning);
    }
});

test('card contract validates missing and zero framed radii instead of bypassing the formula', function () {
    $padding = ['top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px'];
    $makeCard = static function (mixed $cardRadius, string $imageRadius) use ($padding): string {
        $style = ['spacing' => ['padding' => $padding]];
        if ($cardRadius !== null) {
            $style['border'] = ['radius' => $cardRadius];
        }
        return card_contract_group([
            'backgroundColor' => 'base',
            'className' => 'card-style--framed',
            'style' => $style,
        ], card_contract_image($imageRadius)
            . '<!-- wp:paragraph --><p>Radius boundary.</p><!-- /wp:paragraph -->',
            'wp-block-group has-base-background-color has-background card-style--framed');
    };

    foreach ([
        'missing' => $makeCard(null, '999px'),
        'unitless-zero' => $makeCard('0', '2px'),
    ] as $case => $card) {
        $first = CardStyleContract::enforce($card, 'framed', "page-home--framed-{$case}");

        assert_eq($card, $first['markup']);
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        foreach ([
            'framed_radii={"card":',
            'framed_radii={"card":"scalar px"',
            '"padding":"uniform non-zero scalar px on all four sides"',
            '"image":"scalar px"',
            'max(card radius - uniform padding, 2px)',
        ] as $evidence) {
            assert_contains($evidence, $first['warnings'][0]);
        }

        $second = CardStyleContract::enforce(
            $first['markup'],
            'framed',
            "page-home--framed-{$case}",
        );
        assert_eq($first, $second, "{$case} radius warning is a fixed point");
    }

    $zeroMismatch = $makeCard('0px', '999px');
    $mismatchResult = CardStyleContract::enforce(
        $zeroMismatch,
        'framed',
        'page-home--framed-zero-mismatch',
    );
    assert_eq($zeroMismatch, $mismatchResult['markup']);
    assert_eq([], $mismatchResult['repairs']);
    assert_eq(1, count($mismatchResult['warnings']));
    assert_contains('framed_radii={"card":"0px"', $mismatchResult['warnings'][0]);
    assert_contains('framed_radii={"image":"2px"', $mismatchResult['warnings'][0]);

    $zeroValid = $makeCard('0px', '2px');
    $validResult = CardStyleContract::enforce(
        $zeroValid,
        'framed',
        'page-home--framed-zero-valid',
    );
    assert_eq($zeroValid, $validResult['markup']);
    assert_eq([], $validResult['repairs']);
    assert_eq([], $validResult['warnings']);
});

test('card contract accepts leading-decimal pixel values in the framed radius formula', function () {
    $card = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['padding' => [
                'top' => '.5px', 'right' => '.5px', 'bottom' => '.5px', 'left' => '.5px',
            ]],
        ],
    ], card_contract_image('15.5px')
        . '<!-- wp:paragraph --><p>Leading decimal.</p><!-- /wp:paragraph -->',
        'wp-block-group has-base-background-color has-background card-style--framed');

    $first = CardStyleContract::enforce($card, 'framed', 'page-home--framed-leading-decimal');

    assert_eq($card, $first['markup']);
    assert_eq([], $first['repairs']);
    assert_eq([], $first['warnings']);
    assert_eq($first, CardStyleContract::enforce(
        $first['markup'],
        'framed',
        'page-home--framed-leading-decimal',
    ));
});

test('card contract treats every leading-decimal zero spelling consistently', function () {
    $zeroPadding = ['top' => '.0px', 'right' => '.00px', 'bottom' => '00px', 'left' => '00.0px'];
    $framed = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['padding' => $zeroPadding],
        ],
    ], card_contract_image('16px')
        . '<!-- wp:paragraph --><p>Zero framed padding.</p><!-- /wp:paragraph -->',
        'wp-block-group has-base-background-color has-background card-style--framed');
    $framedResult = CardStyleContract::enforce(
        $framed,
        'framed',
        'page-home--framed-leading-zero',
    );
    assert_eq($framed, $framedResult['markup']);
    assert_eq([], $framedResult['repairs']);
    assert_eq(1, count($framedResult['warnings']));
    assert_contains('outer_padding=', $framedResult['warnings'][0]);
    assert_contains('non-zero top/right/bottom/left padding', $framedResult['warnings'][0]);
    assert_eq($framedResult, CardStyleContract::enforce(
        $framedResult['markup'],
        'framed',
        'page-home--framed-leading-zero',
    ));

    $flushAttrs = [
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '.0px'],
        ],
    ];
    $validPadding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $zeroBodyPadding = ['top' => '.0rem', 'right' => '.00rem', 'bottom' => '00rem', 'left' => '00.0rem'];
    $validZeroGap = card_contract_group(
        $flushAttrs,
        card_contract_image() . card_contract_body(
            ['style' => ['spacing' => ['padding' => $validPadding]]],
            'card-body',
        ),
        'wp-block-group has-base-background-color has-background card-style--flush card-flush',
    );
    $gapResult = CardStyleContract::enforce(
        $validZeroGap,
        'flush',
        'page-home--flush-leading-zero-gap',
    );
    assert_eq($validZeroGap, $gapResult['markup']);
    assert_eq([], $gapResult['repairs']);
    assert_eq([], $gapResult['warnings']);

    $zeroBody = card_contract_group(
        $flushAttrs,
        card_contract_image() . card_contract_body(
            ['style' => ['spacing' => ['padding' => $zeroBodyPadding]]],
            'card-body',
        ),
        'wp-block-group has-base-background-color has-background card-style--flush card-flush',
    );
    $bodyResult = CardStyleContract::enforce(
        $zeroBody,
        'flush',
        'page-home--flush-leading-zero-padding',
    );
    assert_eq($zeroBody, $bodyResult['markup']);
    assert_eq([], $bodyResult['repairs']);
    assert_eq(1, count($bodyResult['warnings']));
    assert_contains('body_padding=', $bodyResult['warnings'][0]);
    assert_contains('padding on all four sides', $bodyResult['warnings'][0]);
});

test('card contract refuses to certify framed radii it cannot compare in pixels', function () {
    $cases = [
        'non-pixel' => [
            'card' => '1.5rem',
            'padding' => ['top' => '0.5rem', 'right' => '0.5rem', 'bottom' => '0.5rem', 'left' => '0.5rem'],
            'image' => '1rem',
        ],
        'preset-with-obvious-mismatch' => [
            'card' => '24px',
            'padding' => [
                'top' => 'var:preset|spacing|sm',
                'right' => 'var:preset|spacing|sm',
                'bottom' => 'var:preset|spacing|sm',
                'left' => 'var:preset|spacing|sm',
            ],
            'image' => '999px',
        ],
        'per-corner' => [
            'card' => ['topLeft' => '24px', 'topRight' => '24px', 'bottomRight' => '24px', 'bottomLeft' => '24px'],
            'padding' => ['top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px'],
            'image' => ['topLeft' => '16px', 'topRight' => '16px', 'bottomRight' => '16px', 'bottomLeft' => '16px'],
        ],
        'non-uniform' => [
            'card' => '24px',
            'padding' => ['top' => '8px', 'right' => '9px', 'bottom' => '8px', 'left' => '9px'],
            'image' => '16px',
        ],
    ];

    foreach ($cases as $case => $values) {
        $card = card_contract_group([
            'backgroundColor' => 'base',
            'className' => 'card-style--framed',
            'style' => [
                'border' => ['radius' => $values['card']],
                'spacing' => ['padding' => $values['padding']],
            ],
        ], card_contract_image($values['image'])
            . '<!-- wp:paragraph --><p>Unverifiable radius.</p><!-- /wp:paragraph -->',
            'wp-block-group has-base-background-color has-background card-style--framed');

        $first = CardStyleContract::enforce($card, 'framed', "page-home--framed-{$case}");

        assert_eq($card, $first['markup']);
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        assert_contains('framed_radii=', $first['warnings'][0]);
        assert_contains('uniform non-zero scalar px', $first['warnings'][0]);
        assert_contains('cannot be verified safely', $first['warnings'][0]);

        $second = CardStyleContract::enforce(
            $first['markup'],
            'framed',
            "page-home--framed-{$case}",
        );
        assert_eq($first, $second, "{$case} warning degradation is a fixed point");
    }
});

test('card contract rejects conflicting overlap placement and stale panels for every style', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $cases = [
        'flush' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--flush card-flush',
                'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
            ],
            'outer_html' => 'wp-block-group has-base-background-color has-background card-style--flush card-flush',
            'image_radius' => null,
            'body' => [
                'backgroundColor' => 'base',
                'style' => ['spacing' => [
                    'padding' => $padding,
                    'margin' => ['top' => '-1rem', 'left' => '1rem', 'right' => '1rem'],
                ]],
            ],
            'warning_fragments' => ['body_top_margin=', 'body_background=', 'body_side_margins='],
        ],
        'overlap' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--overlap card-flush',
                'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
            ],
            'outer_html' => 'wp-block-group has-base-background-color has-background card-style--overlap card-flush',
            'image_radius' => null,
            'body' => [
                'backgroundColor' => 'base',
                'style' => ['spacing' => [
                    'padding' => $padding,
                    'margin' => ['top' => '0', 'left' => '1rem', 'right' => '1rem'],
                ]],
            ],
            'warning_fragments' => ['body_top_margin='],
        ],
        'framed' => [
            'outer' => [
                'backgroundColor' => 'base',
                'className' => 'card-style--framed',
                'style' => [
                    'border' => ['radius' => '24px'],
                    'spacing' => ['padding' => [
                        'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
                    ]],
                ],
            ],
            'outer_html' => 'wp-block-group has-base-background-color has-background card-style--framed',
            'image_radius' => '16px',
            'body' => [
                'backgroundColor' => 'base',
                'style' => ['spacing' => [
                    'padding' => $padding,
                    'margin' => ['top' => '-1rem', 'left' => '1rem', 'right' => '1rem'],
                ]],
            ],
            'warning_fragments' => ['body_top_margin=', 'body_background=', 'body_side_margins='],
        ],
        'borderless' => [
            'outer' => ['className' => 'card-style--borderless'],
            'outer_html' => 'wp-block-group card-style--borderless',
            'image_radius' => null,
            'body' => [
                'backgroundColor' => 'base',
                'style' => ['spacing' => [
                    'padding' => $padding,
                    'margin' => ['top' => '-1rem', 'left' => '1rem', 'right' => '1rem'],
                ]],
            ],
            'warning_fragments' => ['body_top_margin=', 'body_background=', 'body_side_margins='],
        ],
    ];

    foreach ($cases as $style => $case) {
        $card = card_contract_group(
            $case['outer'],
            card_contract_image($case['image_radius'])
                . card_contract_body($case['body'], $style === 'overlap' ? 'card-body overlap-up' : 'card-body'),
            $case['outer_html'],
        );

        $first = CardStyleContract::enforce($card, $style, "page-home--{$style}-residue");

        assert_eq($card, $first['markup'], "{$style} residue is retained transactionally");
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        foreach ($case['warning_fragments'] as $fragment) {
            assert_contains($fragment, $first['warnings'][0]);
        }

        $second = CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-residue",
        );
        assert_eq($first, $second, "{$style} residue warning is a fixed point");
    }
});

test('card contract rejects a self-closing non-void saved wrapper transactionally', function () {
    $attrs = [
        'backgroundColor' => 'base',
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ];
    $card = '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div />'
        . card_contract_image()
        . card_contract_body(['style' => ['spacing' => ['padding' => [
            'top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem',
        ]]]])
        . '<!-- /wp:group -->';
    $sibling = '<!-- wp:paragraph --><p>SELF-CLOSING-SIBLING-EXACT</p><!-- /wp:paragraph -->';
    $markup = card_contract_grid(card_contract_column($card)) . $sibling;

    $result = CardStyleContract::enforce($markup, 'flush', 'page-home--self-closing');

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_contains('<div />', $result['warnings'][0]);
    assert_contains('normal non-void opening tag', $result['warnings'][0]);
    assert_contains($card, $result['markup']);
    assert_contains($sibling, $result['markup']);
});

test('card contract preserves multibyte failing-card bytes while repairing a later card', function () {
    $attrs = [
        'backgroundColor' => 'base',
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ];
    $multibyteBody = str_replace(
        'Surviving copy.',
        'Crème 東京 🌿 remains byte-exact.',
        card_contract_body(['style' => ['spacing' => ['padding' => [
            'top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem',
        ]]]]),
    );
    $failing = '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . '<div />' . card_contract_image() . $multibyteBody . '<!-- /wp:group -->';
    $repairable = card_contract_flush_card();
    $markup = card_contract_grid(
        card_contract_column($failing) . card_contract_column($repairable),
    );

    $result = CardStyleContract::enforce($markup, 'flush', 'page-home--multibyte');

    assert_eq(1, count($result['warnings']));
    assert_eq(1, count($result['repairs']));
    assert_contains($failing, $result['markup'], 'the failed card is byte-for-byte intact');
    assert_contains('Crème 東京 🌿 remains byte-exact.', $result['markup']);
    assert_contains('card-style--flush card-flush', $result['markup']);
    assert_contains('wp:column[0] > wp:group[0]', $result['warnings'][0]);
});

test('card contract rejects multiple direct images in equal-card framed and borderless cards', function () {
    $framed = card_contract_group([
        'backgroundColor' => 'base',
        'style' => [
            'border' => ['radius' => '24px'],
            'spacing' => ['padding' => [
                'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px',
            ]],
        ],
    ], card_contract_image('16px') . card_contract_image('16px')
        . '<!-- wp:paragraph --><p>Two framed images.</p><!-- /wp:paragraph -->');
    $borderless = card_contract_group(
        ['className' => 'ordinary-card'],
        card_contract_image() . card_contract_image()
            . '<!-- wp:paragraph --><p>Two borderless images.</p><!-- /wp:paragraph -->',
        'wp-block-group ordinary-card',
    );

    foreach (['framed' => $framed, 'borderless' => $borderless] as $style => $card) {
        $markup = card_contract_grid(card_contract_column($card));
        $result = CardStyleContract::enforce($markup, $style, "page-home--{$style}-images");

        assert_eq($markup, $result['markup'], "{$style} multi-image card stays unchanged");
        assert_eq([], $result['repairs']);
        assert_eq(1, count($result['warnings']));
        assert_contains('exactly one direct primary image', $result['warnings'][0]);
        assert_contains('direct_images=["wp:image","wp:image","wp:paragraph"]', $result['warnings'][0]);
        assert_contains('direct_images="exactly one direct wp:image child"', $result['warnings'][0]);
    }
});

test('SectionUnit exposes card contract drift without turning it into an exception', function () {
    $raw = card_contract_grid(card_contract_column(card_contract_old_inset_card()));
    $unit = new SectionUnit(new FakeLlm(), new PromptRenderer(repo_path('prompts')));

    $result = $unit->finish($raw, [
        'card_style' => 'flush',
        'page' => ['slug' => 'home'],
        'section' => ['slug' => 'cards'],
    ]);

    assert_eq($raw, $result->markup);
    assert_eq([], $result->repairs);
    assert_eq(1, count($result->warnings));
    assert_contains("file='theme/parts/page-home--cards.html'", $result->warnings[0]);
});

test('card contract does not certify a flush body padded on only one edge', function () {
    $card = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush',
        'style' => [
            'border' => ['radius' => '16px'],
            'spacing' => ['blockGap' => '0'],
        ],
    ], card_contract_image() . card_contract_body([
        'style' => ['spacing' => ['padding' => ['top' => '1rem']]],
    ]));

    $result = CardStyleContract::enforce($card, 'flush', 'page-home--cards');

    assert_eq($card, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_contains('padding on all four sides', $result['warnings'][0]);
});

test('card contract isolates a whole-document parser failure and returns original bytes', function () {
    $markup = card_contract_grid(card_contract_column(card_contract_flush_card()));

    $result = CardStyleContract::enforce(
        $markup,
        'flush',
        'page-home--cards',
        static function (string $source): never {
            assert_true($source !== '');
            throw new RuntimeException('PARSER-FAILURE-SENTINEL');
        },
    );

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    $warning = $result['warnings'][0];
    foreach ([
        "file='theme/parts/page-home--cards.html'",
        "block='generated section document'",
        'authored="<!-- wp:group',
        'delivered=original section markup',
        'disposition=',
        "assigned 'flush'",
        'inspection_error="PARSER-FAILURE-SENTINEL"',
        'warnings.json repair pass',
    ] as $context) {
        assert_contains($context, $warning);
    }
});

test('SectionsStep persists unfixable card drift to warnings.json and keeps the section', function () {
    [$project, $tmp] = sections_fixture();
    $direction = $project->readJson('designDirection.json');
    $direction['card_style'] = 'flush';
    $project->writeJson('designDirection.json', $direction);

    $badCardSection = card_contract_grid(card_contract_column(card_contract_old_inset_card()));
    $llm = new FakeLlm();
    $llm->queueText('OK');
    $llm->queueText('<!-- wp:group --><!-- wp:site-title /--><!-- /wp:group -->');
    $llm->queueText('<!-- wp:group --><!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --><!-- /wp:group -->');
    $llm->queueText('<!-- wp:heading --><h2>Hero</h2><!-- /wp:heading -->');
    $llm->queueText($badCardSection);

    (new SectionsStep($llm, new PromptRenderer(repo_path('prompts'))))->run($project);

    $delivered = $project->readText('theme/parts/page-home--about.html');
    assert_contains('Inset copy survives.', $delivered);
    $warnings = implode("\n", $project->readJson('warnings.json')['sections'] ?? []);
    assert_contains("file='theme/parts/page-home--about.html'", $warnings);
    assert_contains("assigned card_style 'flush'", $warnings);
    assert_contains('delivered=unchanged card markup', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});
