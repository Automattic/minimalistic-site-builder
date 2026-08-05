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

/** @return array<string,mixed> */
function card_contract_paint_theme(): array
{
    return ['settings' => [
        'color' => [
            'palette' => [
                ['slug' => 'base', 'color' => '#fff'],
                ['slug' => 'contrast', 'color' => '#000'],
                ['slug' => 'primary', 'color' => '#c00'],
                ['slug' => 'secondary', 'color' => '#0c0'],
                ['slug' => 'accent', 'color' => '#00c'],
                ['slug' => 'extra-ink', 'color' => '#123456'],
            ],
            'gradients' => [
                [
                    'slug' => 'cool-to-warm-spectrum',
                    'gradient' => 'linear-gradient(red, blue)',
                ],
                [
                    'slug' => 'painted-preset-gradient',
                    'gradient' => 'linear-gradient(red, blue)',
                ],
                [
                    'slug' => 'bad-gradient',
                    'gradient' => 'rgb(banana)',
                ],
            ],
        ],
        'shadow' => ['presets' => [
            ['slug' => 'lift', 'shadow' => '0 2px 4px red'],
        ]],
    ]];
}

function card_contract_private(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(CardStyleContract::class, $method))->invokeArgs(null, $args);
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

test('card contract removes unsafe body hooks even when anatomy blocks canonical class repair', function () {
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

    assert_true($card !== $first['markup']);
    assert_eq(2, count($first['repairs']));
    assert_eq(3, count($first['warnings']));
    assert_contains('Unexpected extra sibling.', $first['markup']);
    assert_contains('Surviving title', $first['markup']);
    assert_contains('card-style--framed card-body', $first['markup'], 'the blocked root keeps its identity hooks');
    assert_true(!str_contains($first['markup'], 'card-style--framed card-body overlap-up'));
    assert_contains('wp-block-group', $first['markup']);
    assert_true(!str_contains($first['markup'], 'wp-block-group card-body card-style--overlap'));
    $warning = implode("\n", $first['warnings']);
    foreach ([
        'direct_children=["wp:image","wp:group","wp:paragraph"]',
        'attribute_reserved_hooks=["card-style--framed","card-body","overlap-up"]',
        'attribute_reserved_hooks=["card-body","card-style--overlap","card-flush","overlap-up"]',
        'html_reserved_hooks=["card-body","card-style--overlap","card-flush","overlap-up"]',
        'delivered={attribute_reserved_hooks=[], html_reserved_hooks=[]',
        'outer_attribute_reserved_hooks=["card-style--framed","card-body"]',
        'remain incorrect because anatomy blocked class repair',
    ] as $evidence) {
        assert_contains($evidence, $warning);
    }

    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--blocked-hooks',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq(1, count($second['warnings']));
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
        'flush',
        'page-home--blocked-hooks',
    ), 'the delivered residual warning is a fixed point');
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

        assert_true($card !== $first['markup'], "{$style} harmful wrapper hooks are removed");
        $outerCleanup = in_array($style, ['flush', 'overlap'], true) ? 1 : 0;
        assert_eq(2 + $outerCleanup, count($first['repairs']));
        assert_eq(3 + $outerCleanup, count($first['warnings']));
        assert_contains('wrapper-first', $first['markup']);
        assert_contains('wrapper-second', $first['markup']);
        assert_contains('First wrapper.', $first['markup']);
        assert_contains('Second wrapper.', $first['markup']);
        assert_true(!str_contains($first['markup'], 'wrapper-first overlap-up'));
        assert_true(!str_contains($first['markup'], 'wrapper-second card-body'));
        $warning = implode("\n", $first['warnings']);
        foreach ([
            'text_body_topology=',
            'multiple ambiguous direct text wrappers',
            'attribute_reserved_hooks=["overlap-up"]',
            'attribute_reserved_hooks=["card-body","card-style--framed","card-flush"]',
            'delivered={attribute_reserved_hooks=[], html_reserved_hooks=[]',
            'text_body_group_path=',
            'text_body_group_attribute_reserved_hooks=[]',
        ] as $evidence) {
            assert_contains($evidence, $warning, "{$style}: {$evidence}");
        }
        $second = CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-ambiguous-bodies",
        );
        assert_eq($first['markup'], $second['markup']);
        assert_eq([], $second['repairs']);
        assert_eq(1, count($second['warnings']));
        assert_eq($second, CardStyleContract::enforce(
            $second['markup'],
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

        assert_true($card !== $first['markup']);
        assert_eq(1, count($first['repairs']));
        assert_eq(2, count($first['warnings']));
        assert_contains('Flat copy.', $first['markup']);
        assert_contains('Wrapped copy.', $first['markup']);
        assert_contains('mixed-wrapper', $first['markup']);
        assert_true(!str_contains($first['markup'], 'mixed-wrapper overlap-up'));
        $warning = implode("\n", $first['warnings']);
        foreach ([
            'text_body_topology=',
            'wp:image","wp:paragraph","wp:group',
            'mixes a text wrapper with related content outside that wrapper',
            'attribute_reserved_hooks=["overlap-up"]',
            'text_body_group_path=',
        ] as $evidence) {
            assert_contains($evidence, $warning, "{$style}: {$evidence}");
        }
        $second = CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-mixed-body",
        );
        assert_eq($first['markup'], $second['markup']);
        assert_eq([], $second['repairs']);
        assert_eq($second, CardStyleContract::enforce(
            $second['markup'],
            $style,
            "page-home--{$style}-mixed-body",
        ));
    }
});

test('card contract isolates safe and malformed ambiguous wrappers independently', function () {
    $safeClasses = 'safe-wrapper card-style--framed card-flush card-body overlap-up';
    $safe = card_contract_group(
        ['className' => $safeClasses],
        '<!-- wp:paragraph --><p>SAFE-WRAPPER-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $safeClasses,
    );
    $unsafeTag = '<div class="wp-block-group unsafe-wrapper card-body" '
        . 'class="wp-block-group unsafe-wrapper card-flush overlap-up">';
    $unsafe = '<!-- wp:group {"className":"unsafe-wrapper card-body card-flush overlap-up"} -->'
        . $unsafeTag
        . '<!-- wp:paragraph --><p>UNSAFE-WRAPPER-CONTENT</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $card = card_contract_group(
        ['className' => 'card-style--borderless'],
        card_contract_image() . $safe . $unsafe,
        'wp-block-group card-style--borderless',
    );
    $sibling = '<!-- wp:paragraph --><p>EXACT-OUTER-SIBLING</p><!-- /wp:paragraph -->';

    $first = CardStyleContract::enforce($card . $sibling, 'borderless', 'page-home--partial-hook-cleanup');

    assert_eq(1, count($first['repairs']), 'only the safely parseable wrapper is repaired');
    assert_eq(3, count($first['warnings']));
    assert_contains('safe-wrapper', $first['markup']);
    assert_contains('SAFE-WRAPPER-CONTENT', $first['markup']);
    assert_true(!str_contains($first['markup'], $safeClasses));
    assert_contains($unsafe, $first['markup'], 'the malformed wrapper rolls back as one unit');
    assert_contains($sibling, $first['markup'], 'the unrelated sibling remains byte-exact');
    $repair = $first['repairs'][0];
    assert_eq(
        ['card-style--framed', 'card-flush', 'card-body', 'overlap-up'],
        $repair['authored']['attribute_reserved_hooks'] ?? null,
    );
    assert_eq([], $repair['delivered']['attribute_reserved_hooks'] ?? null);
    $warnings = implode("\n", $first['warnings']);
    foreach ([
        'block=\'wp:group[0] > wp:group[0]\'',
        'attribute_reserved_hooks=["card-style--framed","card-flush","card-body","overlap-up"]',
        'delivered={attribute_reserved_hooks=[], html_reserved_hooks=[]',
        'block=\'wp:group[0] > wp:group[1]\'',
        'recoverable_html_reserved_hooks=["card-body","card-flush","overlap-up"]',
        'saved_wrapper="<div class=\\"wp-block-group unsafe-wrapper card-body\\" class=\\"wp-block-group unsafe-wrapper card-flush overlap-up\\">"',
        'left this wrapper unchanged',
    ] as $evidence) {
        assert_contains($evidence, $warnings);
    }

    $second = CardStyleContract::enforce(
        $first['markup'],
        'borderless',
        'page-home--partial-hook-cleanup',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
        'borderless',
        'page-home--partial-hook-cleanup',
    ));
});

test('card contract preserves raw non-string className evidence for an unsafe wrapper', function () {
    $malformed = '<!-- wp:group {"className":{"sentinel":["RAW-CLASSNAME-VALUE"]}} -->'
        . '<div class="wp-block-group malformed-classname overlap-up">'
        . '<!-- wp:paragraph --><p>MALFORMED-CLASSNAME-CONTENT</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $plain = card_contract_group(
        ['className' => 'plain-wrapper'],
        '<!-- wp:paragraph --><p>PLAIN-WRAPPER-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group plain-wrapper',
    );
    $card = card_contract_group(
        ['className' => 'card-style--borderless'],
        card_contract_image() . $malformed . $plain,
        'wp-block-group card-style--borderless',
    );
    $sibling = '<!-- wp:paragraph --><p>RAW-CLASSNAME-SIBLING</p><!-- /wp:paragraph -->';

    $first = CardStyleContract::enforce(
        $card . $sibling,
        'borderless',
        'page-home--raw-wrapper-classname',
    );

    assert_eq($card . $sibling, $first['markup']);
    assert_eq([], $first['repairs']);
    assert_eq(2, count($first['warnings']));
    $warnings = implode("\n", $first['warnings']);
    assert_contains('comment_className={"sentinel":["RAW-CLASSNAME-VALUE"]}', $warnings);
    assert_contains('text_body_group_className={"sentinel":["RAW-CLASSNAME-VALUE"]}', $warnings);
    assert_contains('not a safely tokenizable string', $warnings);
    assert_contains('MALFORMED-CLASSNAME-CONTENT', $first['markup']);
    assert_contains($sibling, $first['markup']);
    assert_eq($first, CardStyleContract::enforce(
        $first['markup'],
        'borderless',
        'page-home--raw-wrapper-classname',
    ));
});

test('card contract preserves malformed outer and body wrapper evidence behind anatomy failures', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $body = card_contract_body(['style' => ['spacing' => ['padding' => $padding]]]);
    $outerTag = '<div class="wp-block-group card-style--flush card-flush" '
        . 'class="wp-block-group card-body">';
    $outer = '<!-- wp:group {"backgroundColor":"base","className":"card-style--flush card-flush",'
        . '"style":{"border":{"radius":"16px"},"spacing":{"blockGap":"0"}}} -->'
        . $outerTag . card_contract_image() . $body
        . '<!-- wp:paragraph --><p>Extra anatomy.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $bodyTag = '<div class="wp-block-group card-body" class="wp-block-group card-flush">';
    $malformedBody = str_replace('<div class="wp-block-group card-body">', $bodyTag, card_contract_body(
        ['style' => ['spacing' => ['padding' => $padding]]],
        'card-body',
    ));
    $bodyCase = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ], card_contract_image() . $malformedBody
        . '<!-- wp:paragraph --><p>Extra anatomy.</p><!-- /wp:paragraph -->',
        'wp-block-group has-base-background-color has-background card-style--flush card-flush');

    foreach (['outer' => [$outer, $outerTag], 'body' => [$bodyCase, $bodyTag]] as $role => [$card, $tag]) {
        $first = CardStyleContract::enforce($card, 'flush', "page-home--malformed-{$role}");
        if ($role === 'outer') {
            assert_eq($card, $first['markup'], 'the malformed root rolls back as one unit');
            assert_eq([], $first['repairs']);
        } else {
            assert_true($card !== $first['markup'], 'the safe root cleanup commits independently');
            assert_eq(1, count($first['repairs']));
            assert_contains($tag, $first['markup'], 'the malformed body remains byte-exact');
        }
        $warnings = implode("\n", $first['warnings']);
        assert_contains("{$role}_saved_wrapper=", $warnings);
        assert_contains(str_replace('"', '\\"', $tag), $warnings);
        assert_contains("{$role}_saved_wrapper_recoverable_reserved_hooks=", $warnings);
        assert_contains('card-flush', $warnings);
        $second = CardStyleContract::enforce(
            $first['markup'],
            'flush',
            "page-home--malformed-{$role}",
        );
        assert_eq($first['markup'], $second['markup']);
        assert_eq($second, CardStyleContract::enforce(
            $second['markup'],
            'flush',
            "page-home--malformed-{$role}",
        ));
    }
});

test('card contract discovers card hooks in a malformed saved wrapper', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $attrs = [
        'backgroundColor' => 'base',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ];
    $tag = '<div class="wp-block-group card-style--flush card-flush" class="duplicate">';
    $card = '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . $tag . card_contract_image()
        . card_contract_body(['style' => ['spacing' => ['padding' => $padding]]], 'card-body')
        . '</div><!-- /wp:group -->';

    $result = CardStyleContract::enforce($card, 'flush', 'page-home--malformed-locator');

    assert_eq($card, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_contains('outer_saved_wrapper=', $result['warnings'][0]);
    assert_contains('outer_saved_wrapper_recoverable_reserved_hooks=["card-style--flush","card-flush"]', $result['warnings'][0]);
    assert_contains(str_replace('"', '\\"', $tag), $result['warnings'][0]);
});

test('card contract keeps comment-side hook evidence when a conforming wrapper is malformed', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $attrs = [
        'backgroundColor' => 'base',
        'className' => 'card-style--overlap card-flush',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ];
    $tag = '<div class="wp-block-group card-style--framed" '
        . 'class="wp-block-group duplicate card-style--framed">';
    $card = '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . $tag . card_contract_image()
        . card_contract_body(['style' => ['spacing' => ['padding' => $padding]]], 'card-body')
        . '</div><!-- /wp:group -->';

    $result = CardStyleContract::enforce(
        $card,
        'flush',
        'page-home--malformed-comment-evidence',
    );

    assert_eq($card, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    $warning = $result['warnings'][0];
    assert_contains('outer_comment_className="card-style--overlap card-flush"', $warning);
    assert_contains('outer_attribute_reserved_hooks=["card-style--overlap","card-flush"]', $warning);
    assert_contains('outer_saved_wrapper=', $warning);
    assert_contains('outer_saved_wrapper_recoverable_reserved_hooks=["card-style--framed"]', $warning);
});

test('card contract recovers saved hook evidence beyond a malformed text prefix', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $attrs = [
        'backgroundColor' => 'base',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ];
    $tag = '<div class="wp-block-group SECRET-SAVED-HOOK card-flush">';
    $card = '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->'
        . str_repeat('X', 220) . '<span class="prefix-tag"></span>' . $tag . card_contract_image()
        . card_contract_body(['style' => ['spacing' => ['padding' => $padding]]], 'card-body')
        . '</div><!-- /wp:group -->';

    $result = CardStyleContract::enforce(
        $card,
        'flush',
        'page-home--prefixed-saved-wrapper',
    );

    assert_eq($card, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_contains('SECRET-SAVED-HOOK', $result['warnings'][0]);
    assert_contains('outer_saved_wrapper_recoverable_reserved_hooks=["card-flush"]', $result['warnings'][0]);
    assert_eq($result, CardStyleContract::enforce(
        $result['markup'],
        'flush',
        'page-home--prefixed-saved-wrapper',
    ));
});

test('card contract removes a destructive hook even when it was the only candidate locator', function () {
    $card = card_contract_group(
        ['className' => 'card-flush overlap-up'],
        '<!-- wp:paragraph --><p>MARKERLESS-INVALID-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group card-flush overlap-up',
    );

    $first = CardStyleContract::enforce($card, 'framed', 'page-home--sole-hook-locator');

    assert_true($card !== $first['markup']);
    assert_eq(1, count($first['repairs']));
    assert_eq(2, count($first['warnings']));
    assert_true(!str_contains($first['markup'], 'card-flush'));
    assert_true(!str_contains($first['markup'], 'overlap-up'));
    assert_contains('MARKERLESS-INVALID-CONTENT', $first['markup']);
    assert_contains('targeted_hooks=["card-flush","overlap-up"]', $first['warnings'][0]);

    $second = CardStyleContract::enforce(
        $first['markup'],
        'framed',
        'page-home--sole-hook-locator',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq([], $second['warnings']);
});

test('card contract removes newly exposed nested locators with a sole outer hook in one pass', function () {
    $nestedClasses = 'nested-locator card-style--flush card-flush';
    $nested = card_contract_group(
        ['className' => $nestedClasses],
        card_contract_image()
            . '<!-- wp:paragraph --><p>SOLE-LOCATOR-NESTED-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $nestedClasses,
    );
    $outer = card_contract_group(
        ['className' => 'card-flush overlap-up'],
        $nested,
        'wp-block-group card-flush overlap-up',
    );

    $first = CardStyleContract::enforce(
        $outer,
        'borderless',
        'page-home--nested-sole-locator',
    );

    assert_true($outer !== $first['markup']);
    assert_eq(2, count($first['repairs']), 'the root and nested locator are isolated independently');
    foreach (['card-style--', 'card-flush', 'overlap-up'] as $hook) {
        assert_true(!str_contains($first['markup'], $hook), "{$hook} cannot expose a pass-two candidate");
    }
    assert_contains('SOLE-LOCATOR-NESTED-CONTENT', $first['markup']);
    $second = CardStyleContract::enforce(
        $first['markup'],
        'borderless',
        'page-home--nested-sole-locator',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
        'borderless',
        'page-home--nested-sole-locator',
    ));
});

test('deep card warnings keep wrapper and nested hook evidence separate from long paths', function () {
    $wrap = static function (string $markup): string {
        for ($depth = 0; $depth < 14; $depth++) {
            $markup = card_contract_group(
                ['className' => "deep-shell-{$depth}"],
                $markup,
                "wp-block-group deep-shell-{$depth}",
            );
        }
        return $markup;
    };
    $firstBody = card_contract_group(
        ['className' => 'deep-first overlap-up'],
        '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->',
        'wp-block-group deep-first overlap-up',
    );
    $secondBody = card_contract_group(
        ['className' => 'deep-second card-style--framed card-flush'],
        '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->',
        'wp-block-group deep-second card-style--framed card-flush',
    );
    $ambiguous = $wrap(card_contract_group(
        ['className' => 'card-style--borderless'],
        card_contract_image() . $firstBody . $secondBody,
        'wp-block-group card-style--borderless',
    ));
    $ambiguousResult = CardStyleContract::enforce(
        $ambiguous,
        'borderless',
        'page-home--deep-ambiguous',
    );
    $ambiguousWarnings = implode("\n", $ambiguousResult['warnings']);
    assert_contains('text_body_group_index=', $ambiguousWarnings);
    preg_match_all('/text_body_group_index(?:#\d+)?=(\d+)/', $ambiguousWarnings, $textIndices);
    assert_eq(2, count(array_unique($textIndices[1] ?? [])), 'deep ambiguous wrappers keep distinct indices');
    assert_contains('attribute_reserved_hooks=["overlap-up"]', $ambiguousWarnings);
    assert_contains('html_reserved_hooks=["card-style--framed","card-flush"]', $ambiguousWarnings);
    assert_contains('saved_wrapper=', $ambiguousWarnings);

    $nestedClasses = 'nested-deep card-style--framed card-flush';
    $nested = card_contract_group(
        ['className' => $nestedClasses],
        card_contract_image() . '<!-- wp:paragraph --><p>Nested.</p><!-- /wp:paragraph -->',
        'wp-block-group ' . $nestedClasses,
    );
    $nestedSibling = str_replace('Nested.', 'Nested sibling.', $nested);
    $outer = $wrap(card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ], card_contract_image() . $nested . $nestedSibling,
        'wp-block-group has-base-background-color has-background card-style--flush card-flush'));
    $nestedResult = CardStyleContract::enforce($outer, 'flush', 'page-home--deep-nested');
    $nestedWarning = implode("\n", $nestedResult['warnings']);
    assert_contains('nested_image_card_index=', $nestedWarning);
    preg_match_all('/nested_image_card_index(?:#\d+)?=(\d+)/', $nestedWarning, $nestedIndices);
    assert_eq(2, count(array_unique($nestedIndices[1] ?? [])), 'deep nested cards keep distinct indices');
    assert_contains('nested_image_card_path=', $nestedWarning);
    assert_contains('nested_image_card_attribute_reserved_hooks=["card-style--framed"]', $nestedWarning);
    assert_contains('nested_image_card_html_reserved_hooks=["card-style--framed"]', $nestedWarning);
    $nestedSecond = CardStyleContract::enforce(
        $nestedResult['markup'],
        'flush',
        'page-home--deep-nested',
    );
    assert_eq($nestedResult['markup'], $nestedSecond['markup']);
    assert_eq($nestedSecond, CardStyleContract::enforce(
        $nestedSecond['markup'],
        'flush',
        'page-home--deep-nested',
    ));
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

    assert_eq(2, count($first['warnings']));
    assert_eq(2, count($first['repairs']));
    assert_eq(1, substr_count($first['markup'], 'card-style--flush card-flush'), 'only the valid sibling keeps flush behavior');
    assert_contains($nested, $first['markup'], 'nested content without destructive hooks stays byte-exact');
    $firstWarnings = implode("\n", $first['warnings']);
    assert_contains('nested_image_card_path=', $firstWarnings);
    assert_contains('card-style--borderless', $firstWarnings);
    assert_contains('nested inside the outer card subtree', $firstWarnings);
    assert_contains('Nested card copy stays exact.', $first['markup']);

    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--nested-card',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq(1, count($second['warnings']));
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
        'flush',
        'page-home--nested-card',
    ), 'the delivered quarantine warning is a fixed point');
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

        assert_true($outer !== $first['markup'], "{$case} destructive outer behavior is removed");
        assert_eq(1, count($first['repairs']));
        assert_eq(2, count($first['warnings']));
        $warnings = implode("\n", $first['warnings']);
        assert_contains('nested_image_card_path=', $warnings);
        assert_contains('card-style--framed', $warnings);
        $second = CardStyleContract::enforce(
            $first['markup'],
            'flush',
            "page-home--nested-{$case}",
        );
        assert_eq($first['markup'], $second['markup']);
        assert_eq($second, CardStyleContract::enforce(
            $second['markup'],
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

    assert_true($multiple !== $multipleResult['markup']);
    assert_eq(1, count($multipleResult['repairs']));
    assert_eq(2, count($multipleResult['warnings']));
    $multipleWarnings = implode("\n", $multipleResult['warnings']);
    assert_contains('nested-first', $multipleWarnings);
    assert_contains('nested-second', $multipleWarnings);
    assert_true(substr_count($multipleWarnings, 'nested_image_card_path') >= 2);
    assert_true(!str_contains($multipleWarnings, 'the sole group after'));
    $multipleSecond = CardStyleContract::enforce(
        $multipleResult['markup'],
        'flush',
        'page-home--multiple-nested-cards',
    );
    assert_eq($multipleResult['markup'], $multipleSecond['markup']);
    assert_eq($multipleSecond, CardStyleContract::enforce(
        $multipleSecond['markup'],
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

    assert_true($outer !== $first['markup']);
    assert_eq(2, count($first['repairs']));
    assert_eq(3, count($first['warnings']));
    assert_true(!str_contains($first['markup'], 'nested-marked-body card-style--overlap card-flush'));
    assert_contains('Nested marked body.', $first['markup']);
    $warnings = implode("\n", $first['warnings']);
    assert_contains('nested-card-root', $warnings);
    assert_contains('nested-marked-body', $warnings);
    assert_contains('nested_reserved_attribute_hooks=', $warnings);
    assert_contains('card-style--overlap', $warnings);
    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--nested-marked-body',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
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

    assert_eq(2, count($first['repairs']), 'the parent and unsafe grandchild repair independently');
    assert_eq(2, count($first['warnings']), 'the grandchild removal and residual are both durable');
    assert_contains('wp:group[0] > wp:group[0] > wp:group[0]', implode("\n", $first['warnings']));
    assert_contains('deep-marker card-style--overlap', $first['markup']);
    assert_true(!str_contains($first['markup'], 'deep-marker card-style--overlap card-flush'));
    assert_contains('wp-block-group body-marker card-body', $first['markup']);

    $second = CardStyleContract::enforce(
        $first['markup'],
        'flush',
        'page-home--marker-chain',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq(1, count($second['warnings']));
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
        'flush',
        'page-home--marker-chain',
    ));
});

test('card hook cleanup refreshes descendant-shifted offsets before repairing an ancestor', function () {
    $deepFirst = card_contract_group(
        ['className' => 'deep-first overlap-up'],
        '<!-- wp:paragraph --><p>DEEP-FIRST-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group deep-first overlap-up',
    );
    $deepSecond = card_contract_group(
        ['className' => 'deep-second card-body card-flush'],
        '<!-- wp:paragraph --><p>DEEP-SECOND-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group deep-second card-body card-flush',
    );
    $retainedGrandchild = card_contract_group(
        ['className' => 'deep-candidate card-style--overlap card-flush overlap-up'],
        $deepFirst . $deepSecond,
        'wp-block-group deep-candidate card-style--overlap card-flush overlap-up',
    );
    $firstOuterWrapper = card_contract_group(
        ['className' => 'outer-first card-style--framed card-flush'],
        $retainedGrandchild,
        'wp-block-group outer-first card-style--framed card-flush',
    );
    $laterOuterWrapper = card_contract_group(
        ['className' => 'outer-later overlap-up'],
        '<!-- wp:paragraph --><p>LATER-OUTER-CONTENT</p><!-- /wp:paragraph -->',
        'wp-block-group outer-later overlap-up',
    );
    $outer = card_contract_group(
        ['className' => 'card-style--borderless'],
        card_contract_image() . $firstOuterWrapper . $laterOuterWrapper,
        'wp-block-group card-style--borderless',
    );

    $first = CardStyleContract::enforce($outer, 'borderless', 'page-home--shifted-wrapper-offsets');

    assert_true(!str_contains($first['markup'], 'outer-later overlap-up'));
    assert_true(!str_contains($first['markup'], 'deep-first overlap-up'));
    assert_true(!str_contains($first['markup'], 'deep-second card-body card-flush'));
    assert_contains('DEEP-FIRST-CONTENT', $first['markup']);
    assert_contains('DEEP-SECOND-CONTENT', $first['markup']);
    assert_contains('LATER-OUTER-CONTENT', $first['markup']);
    assert_true(!str_contains(implode("\n", $first['warnings']), 'expected source bytes'));

    $second = CardStyleContract::enforce(
        $first['markup'],
        'borderless',
        'page-home--shifted-wrapper-offsets',
    );
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq($second, CardStyleContract::enforce(
        $second['markup'],
        'borderless',
        'page-home--shifted-wrapper-offsets',
    ));
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
        'delivered=residual card markup',
        "assigned card_style 'flush'",
        'outer card padding remains inset',
        'direct image radius remains',
        'outer_padding={"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}',
        'image_radius="16px"',
        'content, and siblings were retained',
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
            'margin' => ['left' => '1REM !important', 'right' => 'calc(1rem) /* generated */'],
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
            'border' => ['radius' => '+2.4e1px !important'],
            'spacing' => ['padding' => [
                'top' => 'calc(8px) !important', 'right' => '8.0px /* generated */',
                'bottom' => '+8px', 'left' => '8px',
            ]],
        ],
    ], card_contract_image('calc(16px) /* generated */')
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

test('card contract distinguishes painted surfaces and boxes from radius-only geometry', function () {
    $bodyPadding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $framePadding = ['top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px'];
    $flushAttrs = [
        'className' => 'card-style--flush card-flush',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ];
    $flush = card_contract_group(
        $flushAttrs,
        card_contract_image() . card_contract_body(
            ['style' => ['spacing' => ['padding' => $bodyPadding]]],
            'card-body',
        ),
        'wp-block-group card-style--flush card-flush',
    );
    $flushBorderOnly = card_contract_group(
        $flushAttrs + ['borderColor' => 'contrast'],
        card_contract_image() . card_contract_body(
            ['style' => ['spacing' => ['padding' => $bodyPadding]]],
            'card-body',
        ),
        'wp-block-group has-contrast-border-color has-border-color card-style--flush card-flush',
    );
    $overlap = card_contract_group([
        'className' => 'card-style--overlap card-flush',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ], card_contract_image() . card_contract_body([
        'backgroundColor' => 'base',
        'style' => ['spacing' => [
            'padding' => $bodyPadding,
            'margin' => ['left' => '1rem', 'right' => '1rem'],
        ]],
    ], 'card-body overlap-up'), 'wp-block-group card-style--overlap card-flush');
    $framedAttrs = [
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '24px'],
            'spacing' => ['padding' => $framePadding],
        ],
    ];
    $framed = card_contract_group(
        $framedAttrs,
        card_contract_image('16px') . '<!-- wp:paragraph --><p>Radius only.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--framed',
    );
    $transparentFramed = card_contract_group(
        array_replace_recursive($framedAttrs, [
            'style' => ['color' => ['background' => 'transparent'], 'shadow' => 'none'],
        ]),
        card_contract_image('16px') . '<!-- wp:paragraph --><p>Transparent.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--framed',
    );

    foreach ([
        'flush-radius' => ['flush', $flush, 'outer_surface='],
        'flush-border-only' => ['flush', $flushBorderOnly, 'outer_surface='],
        'overlap-radius' => ['overlap', $overlap, 'outer_surface='],
        'framed-radius' => ['framed', $framed, 'outer_box_style_border_radius='],
        'framed-transparent' => ['framed', $transparentFramed, 'outer_box_style_color_background='],
    ] as $case => [$style, $card, $field]) {
        $first = CardStyleContract::enforce($card, $style, "page-home--{$case}");
        $cleanupCount = $style === 'overlap' ? 2 : ($style === 'flush' ? 1 : 0);
        assert_eq($cleanupCount, count($first['repairs']));
        assert_eq(1 + $cleanupCount, count($first['warnings']));
        assert_eq($cleanupCount === 0, $card === $first['markup']);
        $warnings = implode("\n", $first['warnings']);
        assert_contains($field, $warnings);
        assert_contains($style === 'framed' ? 'actual visual paint' : 'painted background surface', $warnings);
        $second = CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$case}",
        );
        assert_eq($first['markup'], $second['markup']);
        assert_eq($second, CardStyleContract::enforce(
            $second['markup'],
            $style,
            "page-home--{$case}",
        ));
    }

    foreach ([
        'border' => [
            $framedAttrs + ['borderColor' => 'contrast'],
            'wp-block-group has-contrast-border-color has-border-color card-style--framed',
        ],
        'shadow' => [
            array_replace_recursive($framedAttrs, ['style' => ['shadow' => 'var:preset|shadow|natural']]),
            'wp-block-group card-style--framed',
        ],
    ] as $case => [$attrs, $htmlClasses]) {
        $card = card_contract_group(
            $attrs,
            card_contract_image('16px') . '<!-- wp:paragraph --><p>Painted.</p><!-- /wp:paragraph -->',
            $htmlClasses,
        );
        $result = CardStyleContract::enforce($card, 'framed', "page-home--framed-{$case}");
        assert_eq($card, $result['markup']);
        assert_eq([], $result['warnings'], "{$case} supplies actual framed paint");
    }

    $borderlessRadius = card_contract_group([
        'className' => 'card-style--borderless',
        'style' => ['border' => ['radius' => '20px']],
    ], card_contract_image() . '<!-- wp:paragraph --><p>Residue.</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless');
    $borderless = CardStyleContract::enforce(
        $borderlessRadius,
        'borderless',
        'page-home--borderless-radius-residue',
    );
    assert_eq($borderlessRadius, $borderless['markup']);
    assert_contains('outer_box_style_border_radius="20px"', $borderless['warnings'][0]);
});

test('card contract rejects definitely transparent CSS while accepting WordPress border paint', function () {
    $padding = ['top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px'];
    $base = [
        'className' => 'card-style--framed',
        'style' => [
            'border' => ['radius' => '24px'],
            'spacing' => ['padding' => $padding],
        ],
    ];
    $theme = card_contract_paint_theme();
    $card = static function (array $extra, string $case) use ($base, $theme): array {
        $attrs = array_replace_recursive($base, $extra);
        $markup = card_contract_group(
            $attrs,
            card_contract_image('16px') . '<!-- wp:paragraph --><p>PAINT-' . $case . '</p><!-- /wp:paragraph -->',
            'wp-block-group card-style--framed',
        );
        return [$markup, CardStyleContract::enforce(
            $markup,
            'framed',
            "page-home--paint-{$case}",
            themeJson: $theme,
        )];
    };

    $transparentCases = [
        'hex-alpha' => ['style' => ['color' => ['background' => '#ffffff00']]],
        'modern-rgb' => ['style' => ['color' => ['background' => 'rgb(0 0 0 / 0%)']]],
        'commented-transparent' => ['style' => ['color' => [
            'background' => 'transparent /* generated reset */',
        ]]],
        'calculated-alpha' => ['style' => ['color' => [
            'background' => 'rgb(0 0 0 / calc(0))',
        ]]],
        'arithmetic-zero-alpha' => ['style' => ['color' => [
            'background' => 'rgb(0 0 0 / calc(1 - 1))',
        ]]],
        'missing-alpha' => ['style' => ['color' => ['background' => 'rgb(0 0 0 / none)']]],
        'negative-alpha' => ['style' => ['color' => ['background' => 'rgb(0 0 0 / -1)']]],
        'negative-percent-alpha' => ['style' => ['color' => ['background' => 'rgb(0 0 0 / -10%)']]],
        'exponent-alpha' => ['style' => ['color' => ['background' => 'rgb(0 0 0 / 0e0)']]],
        'transparent-color-mix' => ['style' => ['color' => [
            'background' => 'color-mix(in srgb, transparent 100%, red 0%)',
        ]]],
        'inferred-zero-color-mix-weight' => ['style' => ['color' => [
            'background' => 'color-mix(in srgb, red, transparent 100%)',
        ]]],
        'reversed-inferred-zero-color-mix-weight' => ['style' => ['color' => [
            'background' => 'color-mix(in srgb, transparent 100%, red)',
        ]]],
        'zero-sum-color-mix' => ['style' => ['color' => [
            'background' => 'color-mix(in srgb, red 0%, blue 0%)',
        ]]],
        'negative-color-mix-weight' => ['style' => ['color' => [
            'background' => 'color-mix(in srgb, red -1%, transparent)',
        ]]],
        'invalid-cartesian-hue-method' => ['style' => ['color' => [
            'background' => 'color-mix(in srgb shorter hue, red, blue)',
        ]]],
        'important-transparent' => ['style' => ['color' => [
            'background' => 'transparent !important',
        ]]],
        'undefined-background-variable' => ['style' => ['color' => [
            'background' => 'var(--definitely-undefined)',
        ]]],
        'unresolved-background-variable-with-visible-fallback' => ['style' => ['color' => [
            'background' => 'var(--surface, red)',
        ]]],
        'unresolved-shorthand-variable-with-visible-fallback' => ['style' => ['color' => [
            'gradient' => 'var(--surface, linear-gradient(red, blue))',
        ]]],
        'malformed-background-color' => ['style' => ['color' => [
            'background' => 'rgb(banana)',
        ]]],
        'invalid-rgb-angle' => ['style' => ['color' => ['background' => 'rgb(0deg 0 0)']]],
        'invalid-rgb-calc' => ['style' => ['color' => ['background' => 'rgb(calc(1 +) 0 0)']]],
        'invalid-rgb-alpha-calc' => ['style' => ['color' => [
            'background' => 'rgb(0 0 0 / calc(1 1))',
        ]]],
        'legacy-rgb-none-component' => ['style' => ['color' => [
            'background' => 'rgb(none,0,0)',
        ]]],
        'legacy-rgba-none-component' => ['style' => ['color' => [
            'background' => 'rgba(none,0,0,1)',
        ]]],
        'legacy-hsl-none-component' => ['style' => ['color' => [
            'background' => 'hsl(none,100%,50%)',
        ]]],
        'legacy-rgba-none-alpha' => ['style' => ['color' => [
            'background' => 'rgba(0,0,0,none)',
        ]]],
        'invalid-rgb-min' => ['style' => ['color' => ['background' => 'rgb(min(1,) 0 0)']]],
        'invalid-lab-angle' => ['style' => ['color' => ['background' => 'lab(50% 0deg 0)']]],
        'invalid-color-profile-unit' => ['style' => ['color' => [
            'background' => 'color(display-p3 1deg 0 0)',
        ]]],
        'unsupported-custom-profile' => ['style' => ['color' => [
            'background' => 'color(--foo 1 0 0)',
        ]]],
        'unsupported-device-cmyk' => ['style' => ['color' => [
            'background' => 'device-cmyk(0 0 0 0)',
        ]]],
        'scheme-dependent-color' => ['style' => ['color' => [
            'background' => 'light-dark(transparent, red)',
        ]]],
        'legacy-rgba-percent' => ['style' => ['color' => ['background' => 'rgba(0,0,0,0%)']]],
        'gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(to right, transparent 0%, #fff0 100%)',
        ]]],
        'functional-gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(transparent, rgb(0 0 0 / calc(0)))',
        ]]],
        'undefined-gradient-variable' => ['style' => ['color' => [
            'gradient' => 'var(--definitely-undefined)',
        ]]],
        'undefined-gradient-stop-variable' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(var(--definitely-undefined), transparent)',
        ]]],
        'unresolved-gradient-stop-variable-with-visible-fallback' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(var(--stop, red), blue)',
        ]]],
        'malformed-gradient-color' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(rgb(banana / 0), transparent)',
        ]]],
        'malformed-gradient-empty-stop' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red,,blue)',
        ]]],
        'malformed-linear-gradient-prelude' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(in garbage, red, blue)',
        ]]],
        'malformed-radial-gradient-prelude' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle garbage, red, blue)',
        ]]],
        'malformed-conic-gradient-prelude' => ['style' => ['color' => [
            'gradient' => 'conic-gradient(from garbage, red, blue)',
        ]]],
        'malformed-gradient-stop-suffix' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red garbage, blue)',
        ]]],
        'malformed-gradient-stop-unit' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red 10garbage, blue)',
        ]]],
        'cross-family-linear-prelude' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(circle, red, blue)',
        ]]],
        'cross-family-radial-prelude' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(to right, red, blue)',
        ]]],
        'cross-family-conic-prelude' => ['style' => ['color' => [
            'gradient' => 'conic-gradient(circle, red, blue)',
        ]]],
        'linear-position-prelude' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(at center, red, blue)',
        ]]],
        'invalid-conic-angle-unit' => ['style' => ['color' => [
            'gradient' => 'conic-gradient(from 10px, red, blue)',
        ]]],
        'duplicate-radial-shape' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle circle, red, blue)',
        ]]],
        'malformed-radial-size' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle +++++, red, blue)',
        ]]],
        'negative-circle-radius' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle -1px, red, blue)',
        ]]],
        'negative-ellipse-length-radius' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(ellipse -1px 2px, red, blue)',
        ]]],
        'percentage-circle-radius' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle 50%, red, blue)',
        ]]],
        'negative-percentage-circle-radius' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle -1%, red, blue)',
        ]]],
        'negative-ellipse-percentage-radius' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(ellipse -1% 2%, red, blue)',
        ]]],
        'invalid-linear-hue-method' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(45deg in srgb shorter hue, red, blue)',
        ]]],
        'invalid-gradient-math-tail' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red calc(banana), blue)',
        ]]],
        'unresolved-gradient-tail' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red var(--definitely-undefined), blue)',
        ]]],
        'unresolved-calculated-gradient-tail' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red calc(var(--definitely-undefined)), blue)',
        ]]],
        'consecutive-gradient-hints' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red, 25%, 50%, blue)',
        ]]],
        'important-none-gradient' => ['style' => ['color' => ['gradient' => 'none !important']]],
        'shadow' => ['style' => ['shadow' => '0 4px 8px rgb(0 0 0 / .0)']],
        'commented-none-shadow' => ['style' => ['shadow' => 'none /* generated reset */']],
        'calculated-shadow-alpha' => ['style' => ['shadow' => '0 4px 8px rgb(0 0 0 / calc(0))']],
        'many-transparent-shadows' => ['style' => ['shadow' => implode(
            ', ',
            array_fill(0, 65, '0 0 1px transparent'),
        )]],
        'long-transparent-shadow' => ['style' => ['shadow' =>
            '0 0 1px transparent /*' . str_repeat(' bounded evidence ', 260) . '*/']],
        'important-none-shadow' => ['style' => ['shadow' => 'none !important']],
        'undefined-shadow-variable' => ['style' => ['shadow' => 'var(--definitely-undefined)']],
        'unresolved-shadow-variable-with-visible-fallback' => [
            'style' => ['shadow' => 'var(--shadow, 0 4px 8px red)'],
        ],
        'malformed-shadow' => ['style' => ['shadow' => 'SECRET-SHADOW']],
        'malformed-shadow-color' => ['style' => ['shadow' => '0 4px 8px rgb(banana)']],
        'malformed-shadow-empty-layer' => ['style' => ['shadow' => '0 2px 4px red,']],
        'malformed-shadow-math' => ['style' => ['shadow' => '0 4px calc(banana) red']],
        'negative-calculated-shadow-blur' => ['style' => ['shadow' => '0 0 calc(-1px) red']],
        'multi-shadow' => ['style' => ['shadow' => '0 0 2px transparent, 0 4px 8px #00000000']],
        'border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'transparent', 'style' => 'solid', 'width' => '2px',
        ]]],
        'important-transparent-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'transparent !important',
            'style' => 'solid', 'width' => '2px',
        ]]],
        'zero-width-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'solid', 'width' => '0',
        ]]],
        'zero-viewport-width-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'solid', 'width' => '0vw',
        ]]],
        'calculated-zero-width-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'solid', 'width' => 'calc(0vw)',
        ]]],
        'arithmetic-zero-width-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'solid', 'width' => 'calc(1px - 1px)',
        ]]],
        'fallback-zero-width-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'solid',
            'width' => 'var(--definitely-undefined, 0px)',
        ]]],
        'fallback-none-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff',
            'style' => 'var(--definitely-undefined, none)', 'width' => '2px',
        ]]],
        'unresolved-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff',
            'style' => 'var(--definitely-undefined)', 'width' => '2px',
        ]]],
        'fallback-transparent-border-color' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'var(--definitely-undefined, transparent)',
            'style' => 'solid', 'width' => '2px',
        ]]],
        'unresolved-border-color-with-visible-fallback' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'var(--border-color, red)',
            'style' => 'solid', 'width' => '2px',
        ]]],
        'unresolved-border-style-with-visible-fallback' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red',
            'style' => 'var(--border-style, solid)', 'width' => '2px',
        ]]],
        'unresolved-border-width-with-positive-fallback' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red',
            'style' => 'solid', 'width' => 'var(--border-width, 2px)',
        ]]],
        'important-base-transparent-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'transparent !important', 'style' => 'solid', 'width' => '2px',
            'top' => ['color' => 'red'],
        ]]],
        'important-base-none-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red', 'style' => 'none !important', 'width' => '2px',
            'top' => ['style' => 'solid'],
        ]]],
        'important-base-zero-border' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red', 'style' => 'solid', 'width' => '0 !important',
            'top' => ['width' => '2px'],
        ]]],
        'invalid-side-color-falls-back' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'transparent', 'style' => 'solid', 'width' => '2px',
            'top' => ['color' => 'banana'],
        ]]],
        'invalid-side-style-falls-back' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red', 'style' => 'none', 'width' => '2px',
            'top' => ['style' => 'banana'],
        ]]],
        'invalid-side-width-falls-back' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red', 'style' => 'solid', 'width' => '0',
            'top' => ['width' => 'banana'],
        ]]],
        'reverted-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'revert', 'width' => '2px',
        ]]],
        'commented-reverted-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'revert /* reset */', 'width' => '2px',
        ]]],
        'inherited-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'inherit', 'width' => '2px',
        ]]],
        'important-none-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'style' => 'none !important', 'width' => '2px',
        ]]],
        'transparent-current-color-background' => ['style' => ['color' => [
            'text' => 'transparent', 'background' => 'currentColor',
        ]]],
        'transparent-current-color-shadow' => ['style' => [
            'color' => ['text' => 'transparent'],
            'shadow' => '0 4px 8px',
        ]],
        'transparent-current-color-border' => ['style' => [
            'color' => ['text' => 'transparent'],
            'border' => ['radius' => '24px', 'color' => 'currentColor', 'style' => 'solid', 'width' => '2px'],
        ]],
    ];
    foreach ($transparentCases as $case => $attrs) {
        [$markup, $result] = $card($attrs, $case);
        assert_eq($markup, $result['markup']);
        assert_eq([], $result['repairs']);
        assert_eq(1, count($result['warnings']), "{$case} is definitely unpainted");
        assert_contains('actual visual paint', $result['warnings'][0]);
        assert_true(
            str_contains($result['warnings'][0], 'style.color.')
                || str_contains($result['warnings'][0], 'style.shadow=')
                || str_contains($result['warnings'][0], 'style.border.'),
            "{$case} keeps the attempted authored paint",
        );
    }

    $paintedCases = [
        'visible-alpha' => ['style' => ['color' => ['background' => '#ffffff01']]],
        'legacy-rgba-alias-without-alpha' => ['style' => ['color' => [
            'background' => 'rgba(0,0,0)',
        ]]],
        'legacy-hsla-alias-without-alpha' => ['style' => ['color' => [
            'background' => 'hsla(0,100%,50%)',
        ]]],
        'modern-rgb-none-component' => ['style' => ['color' => [
            'background' => 'rgb(none 0 0)',
        ]]],
        'mixed-gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(transparent, #fff)',
        ]]],
        'named-gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red, blue)',
        ]]],
        'current-color-gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(currentColor, transparent)',
        ]]],
        'color-mix-gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(color-mix(in srgb, transparent 50%, red), transparent)',
        ]]],
        'level-four-gradient' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(45deg in oklab, red, blue)',
        ]]],
        'calculated-gradient-hint' => ['style' => ['color' => [
            'gradient' => 'linear-gradient(red, calc(50%), blue)',
        ]]],
        'calculated-negative-circle-radius' => ['style' => ['color' => [
            'gradient' => 'radial-gradient(circle calc(-1px), red, blue)',
        ]]],
        'painted-light-dark' => ['style' => ['color' => [
            'background' => 'light-dark(red, blue)',
        ]]],
        'system-color' => ['style' => ['color' => [
            'background' => 'CanvasText',
        ]]],
        'background-shorthand-color' => ['style' => ['color' => [
            'gradient' => 'red',
        ]]],
        'preset-custom-gradient' => ['style' => ['color' => [
            'gradient' => 'var:preset|gradient|cool-to-warm-spectrum',
        ]]],
        'mixed-shadow' => ['style' => ['shadow' => '0 0 2px transparent, 0 4px 8px #0001']],
        'color-mix-shadow' => ['style' => ['shadow' => '0 4px 8px color-mix(in srgb, transparent 50%, red)']],
        'named-shadow' => ['style' => ['shadow' => '0 4px 8px red']],
        'width-only-border' => ['style' => ['border' => ['radius' => '24px', 'width' => '2px']]],
        'revert-layer-border-style' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'red', 'style' => 'revert-layer', 'width' => '2px',
        ]]],
        'preset-border' => ['borderColor' => 'contrast'],
        'numeric-zero-with-color' => ['style' => ['border' => [
            'radius' => '24px', 'color' => '#fff', 'width' => 0,
        ]]],
        'important-side-border-wins' => ['style' => ['border' => [
            'radius' => '24px', 'color' => 'transparent', 'style' => 'solid', 'width' => '2px',
            'top' => ['color' => 'red !important'],
        ]]],
    ];
    foreach ($paintedCases as $case => $attrs) {
        [$markup, $result] = $card($attrs, $case);
        assert_eq($markup, $result['markup']);
        assert_eq([], $result['warnings'], "{$case} paints under the frozen WordPress serializer/CSS");
    }
});

test('card contract follows serialized background cascade and rejects unsupported paint shapes', function () {
    $padding = ['top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px'];
    $theme = card_contract_paint_theme();
    $make = static function (
        array $attrs,
        string $case,
        string $html = 'wp-block-group card-style--framed',
    ) use ($padding, $theme): array {
        $attrs = array_replace_recursive([
            'className' => 'card-style--framed',
            'style' => [
                'border' => ['radius' => '24px'],
                'spacing' => ['padding' => $padding],
            ],
        ], $attrs);
        $markup = card_contract_group(
            $attrs,
            card_contract_image('16px')
                . '<!-- wp:paragraph --><p>CASCADE-' . $case . '</p><!-- /wp:paragraph -->',
            $html,
        );
        return [$markup, CardStyleContract::enforce(
            $markup,
            'framed',
            "page-home--cascade-{$case}",
            themeJson: $theme,
        )];
    };

    foreach ([
        'preset-color-reset-by-gradient' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'gradient' => 'linear-gradient(transparent, #0000)',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'preset-color-reset-by-transparent-shorthand' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'gradient' => 'transparent !important',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'important-transparent-shorthand-beats-later-color' => [
            ['style' => ['color' => [
                'gradient' => 'transparent !important',
                'background' => 'red',
            ]]],
            'wp-block-group card-style--framed',
        ],
        'shorthand-color-is-overridden-by-later-transparent-color' => [
            ['style' => ['color' => [
                'gradient' => 'red',
                'background' => 'transparent',
            ]]],
            'wp-block-group card-style--framed',
        ],
        'invalid-gradient-suppresses-preset-color-class' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'gradient' => 'not-a-gradient',
            ]]],
            'wp-block-group has-background card-style--framed',
        ],
        'malformed-gradient-suppresses-preset-color-class' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'gradient' => 'linear-gradient(rgb(banana / 0), transparent)',
            ]]],
            'wp-block-group has-background card-style--framed',
        ],
        'empty-gradient-suppresses-preset-color-class' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'gradient' => 'linear-gradient(red,blue,)',
            ]]],
            'wp-block-group has-background card-style--framed',
        ],
        'revert-layer-shorthand-has-no-suppressed-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => ['gradient' => 'revert-layer']]],
            'wp-block-group has-background card-style--framed',
        ],
        'numeric-background' => [['style' => ['color' => ['background' => 1]]], null],
        'boolean-background' => [['style' => ['color' => ['background' => true]]], null],
        'numeric-gradient' => [['style' => ['color' => ['gradient' => 1]]], null],
        'numeric-shadow' => [['style' => ['shadow' => 1]], null],
        'boolean-shadow' => [['style' => ['shadow' => true]], null],
        'overbound-transparent-gradient' => [['style' => ['color' => [
            'gradient' => 'linear-gradient(' . implode(
                ', ',
                array_fill(0, 1025, 'transparent'),
            ) . ')',
        ]]], null],
        'important-undefined-color-variable-overrides-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'background' => 'var(--definitely-undefined) !important',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'undefined-shorthand-variable-overrides-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'gradient' => 'var(--definitely-undefined)',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
    ] as $case => [$attrs, $html]) {
        [$markup, $result] = $make($attrs, $case, $html ?? 'wp-block-group card-style--framed');
        assert_eq($markup, $result['markup']);
        assert_eq([], $result['repairs']);
        assert_eq(1, count($result['warnings']), "{$case} cannot certify framed paint");
        assert_contains('actual visual paint', $result['warnings'][0]);
    }

    foreach ([
        'preset-color-beats-normal-undefined-variable' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'background' => 'var(--definitely-undefined)',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'preset-color-beats-normal-transparent' => [
            ['backgroundColor' => 'base', 'style' => ['color' => ['background' => 'transparent']]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'preset-gradient-under-transparent-color' => [
            ['gradient' => 'cool-to-warm-spectrum', 'style' => ['color' => ['background' => 'transparent']]],
            'wp-block-group has-cool-to-warm-spectrum-gradient-background has-background card-style--framed',
        ],
        'visible-color-after-transparent-gradient' => [
            ['style' => ['color' => [
                'gradient' => 'linear-gradient(transparent, #0000)',
                'background' => 'red',
            ]]],
            'wp-block-group card-style--framed',
        ],
        'important-shorthand-color-beats-later-transparent-color' => [
            ['style' => ['color' => [
                'gradient' => 'red !important',
                'background' => 'transparent',
            ]]],
            'wp-block-group card-style--framed',
        ],
        'invalid-gradient-preserves-preset-gradient' => [
            ['gradient' => 'cool-to-warm-spectrum', 'style' => ['color' => ['gradient' => 'not-a-gradient']]],
            'wp-block-group has-cool-to-warm-spectrum-gradient-background has-background card-style--framed',
        ],
        'invalid-background-preserves-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => ['background' => 1]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'malformed-zero-alpha-preserves-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'background' => 'rgb(banana / 0)',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'malformed-color-mix-preserves-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => [
                'background' => 'color-mix(in srgb, transparent banana, red 0%)',
            ]]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
        'revert-layer-color-preserves-preset' => [
            ['backgroundColor' => 'base', 'style' => ['color' => ['background' => 'revert-layer']]],
            'wp-block-group has-base-background-color has-background card-style--framed',
        ],
    ] as $case => [$attrs, $html]) {
        [$markup, $result] = $make($attrs, $case, $html);
        assert_eq($markup, $result['markup']);
        assert_eq([], $result['repairs']);
        assert_eq([], $result['warnings'], "{$case} leaves visible serialized paint");
    }

    $numericRadius = card_contract_group([
        'className' => 'card-style--borderless',
        'style' => ['border' => ['radius' => 12]],
    ], card_contract_image()
        . '<!-- wp:paragraph --><p>NUMERIC-RADIUS-DROPPED</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless');
    $numericRadiusResult = CardStyleContract::enforce(
        $numericRadius,
        'borderless',
        'page-home--numeric-radius',
    );
    assert_eq($numericRadius, $numericRadiusResult['markup']);
    assert_eq([], $numericRadiusResult['warnings'], 'StyleEngine drops a top-level numeric box radius');

    foreach ([
        'negative-radius' => '-2px',
        'invalid-radius' => 'banana',
        'invalid-zero-unit' => '0foo',
        'negative-numeric-corner' => ['topLeft' => -2],
        'negative-calculated-radius' => 'calc(-1px)',
        'arithmetic-zero-radius' => 'calc(1px - 1px)',
        'minimum-zero-radius' => 'min(0px, 0px)',
        'clamped-zero-radius' => 'clamp(0px, 0px, 0px)',
        'unsupported-nested-corner-radius' => ['topLeft' => ['nested' => '20px']],
    ] as $case => $radius) {
        $markup = card_contract_group([
            'className' => 'card-style--borderless',
            'style' => ['border' => ['radius' => $radius]],
        ], card_contract_image()
            . '<!-- wp:paragraph --><p>INVALID-RADIUS-' . $case . '</p><!-- /wp:paragraph -->',
            'wp-block-group card-style--borderless');
        $result = CardStyleContract::enforce(
            $markup,
            'borderless',
            "page-home--{$case}",
        );
        assert_eq($markup, $result['markup']);
        assert_eq([], $result['warnings'], "{$case} is ignored by the frozen CSS serializer/browser");
    }

    foreach ([
        'fallback-zero-radius' => 'var(--definitely-undefined, 0px)',
        'unresolved-positive-fallback-radius' => 'var(--radius, 20px)',
    ] as $case => $radius) {
        $markup = card_contract_group([
            'className' => 'card-style--borderless',
            'style' => ['border' => ['radius' => $radius]],
        ], card_contract_image()
            . '<!-- wp:paragraph --><p>UNKNOWN-RADIUS-' . $case . '</p><!-- /wp:paragraph -->',
            'wp-block-group card-style--borderless');
        $result = CardStyleContract::enforce($markup, 'borderless', "page-home--{$case}");
        assert_contains('outer_box_style_border_radius=', implode("\n", $result['warnings']));
    }
});

test('stale body surface warnings include only actual cascade contributors', function () {
    $padding = ['top' => '1rem', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem'];
    $body = card_contract_body([
        'backgroundColor' => 'INERT-PRESET-BACKGROUND',
        'gradient' => 'PAINTED-PRESET-GRADIENT',
        'style' => [
            'color' => [
                'background' => 'red',
                'gradient' => 'rgb(banana)',
            ],
            'spacing' => ['padding' => $padding],
        ],
    ], 'card-body');
    $card = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush card-flush',
        'style' => ['border' => ['radius' => '16px'], 'spacing' => ['blockGap' => '0']],
    ], card_contract_image() . $body,
        'wp-block-group has-base-background-color has-background card-style--flush card-flush');

    $result = CardStyleContract::enforce(
        $card,
        'flush',
        'page-home--body-surface-evidence',
        themeJson: card_contract_paint_theme(),
    );
    $warnings = implode("\n", $result['warnings']);
    assert_contains('body_background_gradient="PAINTED-PRESET-GRADIENT"', $warnings);
    assert_true(!str_contains($warnings, 'body_background_backgroundColor='));
    assert_true(!str_contains($warnings, 'body_background_style_color_gradient='));
    assert_true(!str_contains($warnings, 'body_background_style_color_background='));
});

test('borderless residue warnings keep each authored box field independently actionable', function () {
    $longGradient = 'linear-gradient(' . implode(', ', array_fill(0, 24, '#123456 0%')) . ')';
    $card = card_contract_group([
        'className' => 'card-style--borderless',
        'style' => [
            'color' => ['gradient' => $longGradient],
            'border' => ['radius' => '24px'],
            'shadow' => '0 1px 2px red',
        ],
    ], card_contract_image() . '<!-- wp:paragraph --><p>BOX-EVIDENCE</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless');

    $result = CardStyleContract::enforce($card, 'borderless', 'page-home--box-evidence');
    $warnings = implode("\n", $result['warnings']);
    assert_contains('outer_box_style_color_gradient=', $warnings);
    assert_contains('outer_box_style_border_radius="24px"', $warnings);
    assert_contains('outer_box_style_shadow="0 1px 2px red"', $warnings);

    $inertBorder = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--borderless',
        'style' => ['border' => ['style' => 'none', 'width' => '2px']],
    ], card_contract_image() . '<!-- wp:paragraph --><p>INERT-BORDER</p><!-- /wp:paragraph -->',
        'wp-block-group has-base-background-color has-background card-style--borderless');
    $inert = CardStyleContract::enforce($inertBorder, 'borderless', 'page-home--inert-border');
    assert_contains('outer_box_backgroundColor="base"', $inert['warnings'][0]);
    assert_true(!str_contains($inert['warnings'][0], 'outer_box_style_border'));

    $supportedBorder = card_contract_group([
        'className' => 'card-style--borderless',
        'style' => ['border' => [
            'color' => 'red', 'style' => 'solid', 'width' => '2px', 'foo' => 'ignored',
        ]],
    ], card_contract_image() . '<!-- wp:paragraph --><p>SUPPORTED-BORDER</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless');
    $supported = CardStyleContract::enforce(
        $supportedBorder,
        'borderless',
        'page-home--supported-border',
    );
    $supportedWarnings = implode("\n", $supported['warnings']);
    assert_contains('outer_box_style_border_color="red"', $supportedWarnings);
    assert_contains('outer_box_style_border_style="solid"', $supportedWarnings);
    assert_contains('outer_box_style_border_width="2px"', $supportedWarnings);
    assert_true(!str_contains($supportedWarnings, 'style_border_foo'));

    $transparentBorder = card_contract_group([
        'className' => 'card-style--borderless',
        'style' => ['border' => ['color' => 'transparent', 'style' => 'solid', 'width' => '2px']],
    ], card_contract_image() . '<!-- wp:paragraph --><p>TRANSPARENT-BORDER</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless');
    $transparent = CardStyleContract::enforce(
        $transparentBorder,
        'borderless',
        'page-home--transparent-border',
    );
    assert_eq([], $transparent['warnings']);
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

    assert_true($mismatch !== $mismatchResult['markup'], 'the mismatch remains while unsafe behavior is removed');
    assert_eq(1, count($mismatchResult['repairs']));
    assert_eq(2, count($mismatchResult['warnings']));
    $warning = implode("\n", $mismatchResult['warnings']);
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
    assert_true($zeroBody !== $bodyResult['markup']);
    assert_eq(1, count($bodyResult['repairs']));
    assert_eq(2, count($bodyResult['warnings']));
    $bodyWarnings = implode("\n", $bodyResult['warnings']);
    assert_contains('body_padding=', $bodyWarnings);
    assert_contains('padding on all four sides', $bodyWarnings);
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
            'warning_fragments' => ['body_top_margin=', 'body_background_backgroundColor='],
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
            'warning_fragments' => ['body_top_margin=', 'body_background_backgroundColor='],
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
            'warning_fragments' => ['body_top_margin=', 'body_background_backgroundColor='],
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

        $cleanupCount = $style === 'overlap' ? 2 : ($style === 'flush' ? 1 : 0);
        assert_eq($cleanupCount === 0, $card === $first['markup']);
        assert_eq($cleanupCount, count($first['repairs']));
        assert_eq(1 + $cleanupCount, count($first['warnings']));
        $warnings = implode("\n", $first['warnings']);
        foreach ($case['warning_fragments'] as $fragment) {
            assert_contains($fragment, $warnings);
        }
        if ($style !== 'overlap') {
            assert_true(
                !str_contains($warnings, 'body_side_margins='),
                'normal-priority side margins are neutralized by the frozen body reset',
            );
        }

        $second = CardStyleContract::enforce(
            $first['markup'],
            $style,
            "page-home--{$style}-residue",
        );
        assert_eq($first['markup'], $second['markup']);
        assert_eq($second, CardStyleContract::enforce(
            $second['markup'],
            $style,
            "page-home--{$style}-residue",
        ), "{$style} delivered residue warning is a fixed point");
    }

    $importantBody = card_contract_body([
        'style' => ['spacing' => [
            'padding' => $padding,
            'margin' => ['left' => '1rem !important', 'right' => '1rem !important'],
        ]],
    ], 'card-body');
    $importantCard = card_contract_group(
        $cases['flush']['outer'],
        card_contract_image() . $importantBody,
        $cases['flush']['outer_html'],
    );
    $important = CardStyleContract::enforce(
        $importantCard,
        'flush',
        'page-home--flush-important-side-margin',
    );
    assert_contains('body_side_margins=', implode("\n", $important['warnings']));
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

    foreach ([
        'invalid-token' => 'banana',
        'negative-length' => '-1px',
        'calculated-negative' => 'calc(-1px)',
        'initial-value' => 'initial',
        'unresolved-variable' => 'var(--definitely-undefined)',
        'unresolved-variable-with-positive-fallback' => 'var(--space, 1rem)',
    ] as $case => $value) {
        $padding = ['top' => $value, 'right' => $value, 'bottom' => $value, 'left' => $value];
        $invalid = card_contract_group([
            'backgroundColor' => 'base',
            'className' => 'card-style--flush',
            'style' => [
                'border' => ['radius' => '16px'],
                'spacing' => ['blockGap' => '0'],
            ],
        ], card_contract_image() . card_contract_body([
            'style' => ['spacing' => ['padding' => $padding]],
        ]));
        $first = CardStyleContract::enforce($invalid, 'flush', "page-home--padding-{$case}");
        assert_eq($invalid, $first['markup']);
        assert_eq([], $first['repairs']);
        assert_eq(1, count($first['warnings']));
        assert_contains('padding on all four sides', $first['warnings'][0]);
        assert_eq($first, CardStyleContract::enforce(
            $first['markup'],
            'flush',
            "page-home--padding-{$case}",
        ));
    }

    $blockedWithInertResidue = card_contract_group([
        'backgroundColor' => 'base',
        'className' => 'card-style--flush',
        'style' => [
            'border' => ['radius' => 'banana'],
            'spacing' => [
                'blockGap' => '0',
                'padding' => [
                    'top' => 'banana', 'right' => 'calc(-1px)',
                    'bottom' => 'var(--definitely-undefined)', 'left' => 'initial',
                ],
            ],
        ],
    ], card_contract_image('calc(-1px)') . card_contract_body([
        'style' => ['spacing' => ['padding' => ['top' => '1rem']]],
    ]));
    $blockedResult = CardStyleContract::enforce(
        $blockedWithInertResidue,
        'flush',
        'page-home--blocked-inert-residue',
    );
    assert_eq($blockedWithInertResidue, $blockedResult['markup']);
    assert_eq([], $blockedResult['repairs']);
    assert_eq(1, count($blockedResult['warnings']));
    assert_contains('padding on all four sides', $blockedResult['warnings'][0]);
    assert_true(!str_contains($blockedResult['warnings'][0], 'outer_padding='));
    assert_true(!str_contains($blockedResult['warnings'][0], 'image_radius='));

    $inertPadding = card_contract_group([
        'className' => 'card-style--borderless',
        'style' => ['spacing' => ['padding' => [
            'top' => 'banana', 'right' => 'calc(-1px)', 'foo' => '20px',
        ]]],
    ], card_contract_image() . '<!-- wp:paragraph --><p>INERT PADDING</p><!-- /wp:paragraph -->',
        'wp-block-group card-style--borderless');
    $inertResult = CardStyleContract::enforce(
        $inertPadding,
        'borderless',
        'page-home--inert-padding',
    );
    assert_eq([], $inertResult['warnings']);
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
    assert_contains('delivered=residual card markup', $warnings);
    exec('rm -rf ' . escapeshellarg($tmp));
});
