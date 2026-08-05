<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\Steps\SectionsStep;
use Automattic\SiteBuild\Tests\FakeLlm;
use Automattic\SiteBuild\Units\CardStyleContract;
use Automattic\SiteBuild\Units\SectionUnit;

function card_contract_image(?string $radius = null): string
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

    foreach (['generic' => $generic, 'masonry' => $masonry] as $case => $markup) {
        $result = CardStyleContract::enforce($markup, 'flush', "page-home--{$case}");
        assert_eq($markup, $result['markup'], "{$case} crop stays byte-identical");
        assert_eq([], $result['repairs']);
        assert_eq([], $result['warnings']);
    }
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
                'top' => '2rem', 'right' => '2rem', 'bottom' => '2rem', 'left' => '2rem',
            ]],
        ],
    ], card_contract_image('2px')
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
