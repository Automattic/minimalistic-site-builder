<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Units\ListThumbContract;

/** @param array<string,mixed> $attrs */
function list_thumb_test_comment(string $name, array $attrs, bool $close = false): string
{
    if ($close) {
        return "<!-- /wp:{$name} -->";
    }
    if ($attrs === []) {
        return "<!-- wp:{$name} -->";
    }
    $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "<!-- wp:{$name} {$json} -->";
}

/**
 * @param array<string,mixed> $rowAttrs
 * @param mixed               $textStyle
 */
function list_thumb_test_row(
    array $rowAttrs = ['className' => 'list-thumb-flush'],
    mixed $textStyle = ['spacing' => ['padding' => ['top' => 'var:preset|spacing|sm']]],
    string $extraColumn = '',
): string {
    $rowClass = 'wp-block-columns';
    if (is_string($rowAttrs['className'] ?? null) && trim($rowAttrs['className']) !== '') {
        $rowClass .= ' ' . trim($rowAttrs['className']);
    }
    $media = list_thumb_test_comment('column', ['width' => '18%'])
        . '<div class="wp-block-column" style="flex-basis:18%">'
        . list_thumb_test_comment('image', ['className' => 'card-media-thumb'])
        . '<figure class="wp-block-image card-media-thumb"><img src="thumb.jpg" alt=""/></figure>'
        . list_thumb_test_comment('image', [], true)
        . '</div>'
        . list_thumb_test_comment('column', [], true);
    $textAttrs = ['width' => '82%', 'style' => $textStyle];
    $text = list_thumb_test_comment('column', $textAttrs)
        . '<div class="wp-block-column" style="flex-basis:82%">'
        . list_thumb_test_comment('heading', ['level' => 3])
        . '<h3 class="wp-block-heading">Row title</h3>'
        . list_thumb_test_comment('heading', [], true)
        . list_thumb_test_comment('paragraph', [])
        . '<p>One concise line.</p>'
        . list_thumb_test_comment('paragraph', [], true)
        . '</div>'
        . list_thumb_test_comment('column', [], true);

    return list_thumb_test_comment('columns', $rowAttrs)
        . '<div class="' . $rowClass . '">'
        . $media . $text . $extraColumn
        . '</div>'
        . list_thumb_test_comment('columns', [], true);
}

test('list-thumb contract installs mobile and text-rhythm invariants to a fixed point', function () {
    $sibling = '<!-- wp:paragraph {"className":"untouched-sibling"} -->'
        . '<p class="untouched-sibling">Keep these exact bytes.</p><!-- /wp:paragraph -->';
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . list_thumb_test_row()
        . $sibling
        . '</div><!-- /wp:group -->';

    $first = ListThumbContract::enforce($markup, 'page-home--schedule');

    assert_eq([], $first['warnings']);
    assert_eq(1, count($first['repairs']));
    assert_eq('list-thumb-row-normalized', $first['repairs'][0]['code']);
    assert_contains('wp:group[0] > wp:columns[0]', $first['repairs'][0]['path']);
    assert_contains($sibling, $first['markup'], 'a sibling remains byte-for-byte intact');

    $document = BlockMarkup::parse($first['markup']);
    $row = array_values(array_filter(
        $document->indices(),
        static fn (int $index): bool => $document->name($index) === 'columns',
    ))[0];
    $columns = array_values(array_filter(
        $document->children($row),
        static fn (int $index): bool => $document->name($index) === 'column',
    ));
    assert_eq(false, $document->attrs($row)['isStackedOnMobile'] ?? null);
    assert_contains('is-not-stacked-on-mobile', $document->ownHtml($row));
    assert_eq(
        'var:preset|spacing|xs',
        $document->attrs($columns[1])['style']['spacing']['blockGap'] ?? null,
    );
    assert_eq(
        'var:preset|spacing|sm',
        $document->attrs($columns[1])['style']['spacing']['padding']['top'] ?? null,
        'unrelated text-column spacing survives',
    );

    $second = ListThumbContract::enforce($first['markup'], 'page-home--schedule');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq([], $second['warnings']);
});

test('list-thumb contract repairs a healthy row while isolating ambiguous sibling anatomy', function () {
    $thirdColumn = list_thumb_test_comment('column', ['width' => '10%'])
        . '<div class="wp-block-column" style="flex-basis:10%"><p>Unexpected.</p></div>'
        . list_thumb_test_comment('column', [], true);
    $good = list_thumb_test_row();
    $ambiguous = list_thumb_test_row(extraColumn: $thirdColumn);
    $markup = $good . "\n" . $ambiguous;

    $result = ListThumbContract::enforce($markup, 'page-home--index');

    assert_eq(1, count($result['repairs']));
    assert_eq(1, count($result['warnings']));
    assert_contains($ambiguous, $result['markup'], 'only the ambiguous row is retained byte-for-byte');
    foreach ([
        "file='theme/parts/page-home--index.html'",
        "block='wp:columns[1]'",
        'authored=',
        'delivered=unchanged',
        'disposition=',
        '3 direct columns and 3 direct blocks instead of exactly two columns',
    ] as $context) {
        assert_contains($context, $result['warnings'][0]);
    }
});

test('list-thumb contract preserves the framed variant while normalizing shared row invariants', function () {
    $rowAttrs = [
        'verticalAlignment' => 'center',
        'style' => [
            'border' => ['width' => '1px'],
            'spacing' => ['padding' => 'var:preset|spacing|sm'],
        ],
    ];
    $markup = list_thumb_test_row($rowAttrs);

    $result = ListThumbContract::enforce($markup, 'page-home--framed-index');

    assert_eq([], $result['warnings']);
    assert_eq(1, count($result['repairs']));
    assert_true(!str_contains($result['markup'], 'list-thumb-flush'), 'the framed/flush choice is preserved');
    $document = BlockMarkup::parse($result['markup']);
    $row = $document->topLevel();
    assert_true(is_int($row));
    $attrs = $document->attrs($row) ?? [];
    assert_eq(false, $attrs['isStackedOnMobile'] ?? null);
    assert_eq('center', $attrs['verticalAlignment'] ?? null);
    assert_eq('var:preset|spacing|sm', $attrs['style']['spacing']['padding'] ?? null);
});

test('list-thumb contract deep-merges duplicate style keys before adding the text gap', function () {
    $markup = list_thumb_test_row();
    $ordinaryTextOpener = '<!-- wp:column {"width":"82%","style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}}}} -->';
    $duplicateTextOpener = '<!-- wp:column {"width":"82%",'
        . '"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm"}}},'
        . '"style":{"color":{"text":"#123456"}}} -->';
    $markup = str_replace($ordinaryTextOpener, $duplicateTextOpener, $markup);
    assert_true(str_contains($markup, $duplicateTextOpener), 'fixture carries duplicate style keys');

    $first = ListThumbContract::enforce($markup, 'page-home--duplicates');

    assert_eq([], $first['warnings']);
    assert_eq(
        ['list-thumb-row-normalized', 'duplicate-block-attribute-keys-merged'],
        array_column($first['repairs'], 'code'),
    );
    assert_eq(['textColumn.style'], $first['repairs'][1]['paths']);
    $document = BlockMarkup::parse($first['markup']);
    $row = $document->topLevel();
    assert_true(is_int($row));
    $columns = $document->children($row);
    $textAttrs = $document->attrs($columns[1]) ?? [];
    assert_eq(
        'var:preset|spacing|sm',
        $textAttrs['style']['spacing']['padding']['top'] ?? null,
        'the first style declaration survives',
    );
    assert_eq('#123456', $textAttrs['style']['color']['text'] ?? null, 'the second style declaration survives');
    assert_eq('var:preset|spacing|xs', $textAttrs['style']['spacing']['blockGap'] ?? null);

    $second = ListThumbContract::enforce($first['markup'], 'page-home--duplicates');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['repairs']);
    assert_eq([], $second['warnings']);
});

test('list-thumb contract keeps malformed text styling but still prevents mobile stacking', function () {
    $markup = list_thumb_test_row(textStyle: 'malformed-style');

    $first = ListThumbContract::enforce($markup, 'page-home--menu');

    assert_eq(1, count($first['repairs']));
    assert_eq(1, count($first['warnings']));
    assert_contains('"isStackedOnMobile":false', $first['markup']);
    assert_contains('is-not-stacked-on-mobile', $first['markup']);
    assert_contains(
        '<!-- wp:column {"width":"82%","style":"malformed-style"} -->',
        $first['markup'],
        'the unmergeable text-column attributes remain intact',
    );
    foreach ([
        "file='theme/parts/page-home--menu.html'",
        'wp:column[1]',
        'text style.spacing=',
        'delivered=unchanged',
        'install blockGap=var:preset|spacing|xs',
    ] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }
    assert_true(
        !str_contains($first['repairs'][0]['disposition'], 'text rhythm'),
        'a partial repair reports only the invariants it actually delivered',
    );
    assert_true(!array_key_exists('textBlockGap', $first['repairs'][0]['delivered']));

    $second = ListThumbContract::enforce($first['markup'], 'page-home--menu');
    assert_eq($first['markup'], $second['markup'], 'the degraded delivery also reaches a byte fixed point');
    assert_eq([], $second['repairs']);
    assert_eq($first['warnings'], $second['warnings']);
});

test('list-thumb contract adds the behavior class only to the saved root wrapper', function () {
    $markup = str_replace(
        '<div class="wp-block-columns list-thumb-flush">',
        '<div><span class="sentinel">Prefix.</span>',
        list_thumb_test_row(),
    );

    $result = ListThumbContract::enforce($markup, 'page-home--root-class');

    assert_eq([], $result['warnings']);
    assert_contains('<div class="is-not-stacked-on-mobile"><span class="sentinel">', $result['markup']);
    assert_true(
        !str_contains($result['markup'], 'class="sentinel is-not-stacked-on-mobile"'),
        'a descendant class attribute cannot authorize the root rewrite',
    );
});

test('list-thumb contract retains a row whose saved root class is ambiguous', function () {
    $markup = str_replace(
        '<div class="wp-block-columns list-thumb-flush">',
        '<div class="wp-block-columns" class="list-thumb-flush">',
        list_thumb_test_row(),
    );

    $first = ListThumbContract::enforce($markup, 'page-home--ambiguous-root');

    assert_eq($markup, $first['markup']);
    assert_eq([], $first['repairs']);
    assert_eq(1, count($first['warnings']));
    foreach ([
        "file='theme/parts/page-home--ambiguous-root.html'",
        "block='wp:columns[0]'",
        'saved wp:columns root class attribute could not be safely inspected',
        'delivered=unchanged',
        'pre-normalization bytes',
    ] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }

    assert_eq($first, ListThumbContract::enforce($first['markup'], 'page-home--ambiguous-root'));
});

test('list-thumb contract quarantines a healthy nested row inside an ambiguous outer row', function () {
    $inner = list_thumb_test_row();
    $nestedColumn = list_thumb_test_comment('column', [])
        . '<div class="wp-block-column">' . $inner . '</div>'
        . list_thumb_test_comment('column', [], true);
    $markup = list_thumb_test_row(extraColumn: $nestedColumn);

    $first = ListThumbContract::enforce($markup, 'page-home--nested');

    assert_eq($markup, $first['markup'], 'the ambiguous outer transaction stays byte-for-byte intact');
    assert_eq([], $first['repairs']);
    assert_eq(2, count($first['warnings']));
    assert_contains('3 direct columns and 3 direct blocks', $first['warnings'][0]);
    assert_contains('overlaps another recognized list-thumb row', $first['warnings'][1]);

    $second = ListThumbContract::enforce($first['markup'], 'page-home--nested');
    assert_eq($first, $second, 'the quarantined delivery reaches a fixed point');
});

test('list-thumb contract does not normalize a two-column media layout as a text row', function () {
    $markup = list_thumb_test_row();
    $text = list_thumb_test_comment('heading', ['level' => 3])
        . '<h3 class="wp-block-heading">Row title</h3>'
        . list_thumb_test_comment('heading', [], true)
        . list_thumb_test_comment('paragraph', [])
        . '<p>One concise line.</p>'
        . list_thumb_test_comment('paragraph', [], true);
    $ordinaryImage = list_thumb_test_comment('image', [])
        . '<figure class="wp-block-image"><img src="second.jpg" alt=""/></figure>'
        . list_thumb_test_comment('image', [], true);
    $markup = str_replace($text, $ordinaryImage, $markup);

    $result = ListThumbContract::enforce($markup, 'page-home--media-pair');

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    assert_contains('text column must contain direct heading and paragraph blocks only', $result['warnings'][0]);
});

test('list-thumb contract leaves ordinary columns alone', function () {
    $markup = '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column --><div class="wp-block-column"><p>Plain.</p></div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column"><p>Columns.</p></div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';

    assert_eq(
        ['markup' => $markup, 'repairs' => [], 'warnings' => []],
        ListThumbContract::enforce($markup, 'page-home--plain'),
    );
});

test('list-thumb contract degrades a parser failure without throwing', function () {
    $markup = list_thumb_test_row();
    $result = ListThumbContract::enforce(
        $markup,
        'page-home--broken',
        static fn (string $_markup): BlockMarkup => throw new RuntimeException('synthetic parser failure'),
    );

    assert_eq($markup, $result['markup']);
    assert_eq([], $result['repairs']);
    assert_eq(1, count($result['warnings']));
    foreach ([
        "file='theme/parts/page-home--broken.html'",
        "block='generated section document'",
        'authored=',
        'delivered=original section markup',
        'inspection_error=',
        'synthetic parser failure',
        'pre-transformation bytes were retained',
    ] as $context) {
        assert_contains($context, $result['warnings'][0]);
    }
});
