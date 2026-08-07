<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\RootTextAlignmentConflictDetector;

test('root text alignment preflight catches heading inline values the serializer would silently drop', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['right', 'justify', 'inherit'] as $inline) {
        $markup = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:' . $inline . '">Title</h2><!-- /wp:heading -->';
        $conflicts = $detector->detect($markup);

        assert_eq(1, count($conflicts), "heading text-align:{$inline} is not silently replaced");
        assert_contains('core/heading at 0', $conflicts[0]);
        assert_contains('has-text-align-center', $conflicts[0]);
        assert_contains($inline, $conflicts[0]);
    }
});

test('root text alignment preflight catches multiple heading classes with inline CSS', function () {
    $markup = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center '
        . 'has-text-align-right" style="text-align:left">Title</h2><!-- /wp:heading -->';

    $conflicts = (new RootTextAlignmentConflictDetector())->detect($markup);
    assert_eq(1, count($conflicts));
    assert_contains('has-text-align-center', $conflicts[0]);
    assert_contains('has-text-align-right', $conflicts[0]);
    assert_contains('left', $conflicts[0]);
});

test('root text alignment preflight respects effective inline cascade and importance', function () {
    $detector = new RootTextAlignmentConflictDetector();
    $matchingWinner = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right;text-align:center">Title</h2><!-- /wp:heading -->';
    $importantConflict = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right!important;text-align:center">Title</h2><!-- /wp:heading -->';
    $escapedImportantConflict = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right!\\69mportant;text-align:center">Title</h2><!-- /wp:heading -->';
    $escapedMatchingValue = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:\\63 enter">Title</h2><!-- /wp:heading -->';

    assert_eq([], $detector->detect($matchingWinner));
    assert_eq([], $detector->detect($escapedMatchingValue));
    $conflicts = $detector->detect($importantConflict);
    assert_eq(1, count($conflicts));
    assert_contains('right!important', $conflicts[0]);
    assert_eq(1, count($detector->detect($escapedImportantConflict)));
});

test('root text alignment preflight accepts supported direct inline values', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['left', 'right', 'center'] as $value) {
        $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"' . $value . '"}}} -->'
            . '<h2 style="text-align:' . $value . '">Title</h2><!-- /wp:heading -->';
        assert_eq([], $detector->detect($markup), "{$value} has a durable block-support mirror");
    }

    $escaped = '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->'
        . '<h2 style="text-align:\\63 enter">Title</h2><!-- /wp:heading -->';
    assert_eq([], $detector->detect($escaped), 'escaped direct identifiers remain deterministic');
});

test('valid CSS values outside the frozen block support cannot masquerade as durable mirrors', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['start', 'end', 'justify', 'match-parent', 'justify-all'] as $value) {
        $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"' . $value . '"}}} -->'
            . '<h2 style="text-align:' . $value . '">Title</h2><!-- /wp:heading -->';
        assert_eq(
            1,
            count($detector->detect($markup)),
            "{$value} would be dropped by the frozen heading renderer",
        );
    }
});

test('root text alignment preflight rejects matching invalid and runtime-dependent inline values', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach ([
        'banana',
        'inherit',
        'initial',
        'unset',
        'revert',
        'revert-layer',
        'var(--align)',
        'env(safe-area-inset-left)',
        'center left',
    ] as $value) {
        $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"' . $value . '"}}} -->'
            . '<h2 style="text-align:' . $value . '">Title</h2><!-- /wp:heading -->';
        $conflicts = $detector->detect($markup);
        assert_eq(1, count($conflicts), "{$value} cannot be treated as a safe alignment mirror");
        assert_contains('root text-alignment signals conflict', $conflicts[0]);
    }
});

test('opaque inline values participate conservatively in cascade and importance', function () {
    $detector = new RootTextAlignmentConflictDetector();
    $comment = '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->';
    $closer = 'Title</h2><!-- /wp:heading -->';

    assert_eq([], $detector->detect(
        $comment . '<h2 style="text-align:var(--align);text-align:center">' . $closer,
    ));
    assert_eq(1, count($detector->detect(
        $comment . '<h2 style="text-align:center!important;text-align:var(--align)">' . $closer,
    )), 'heading save would drop the winning important priority');
    assert_eq(1, count($detector->detect(
        $comment . '<h2 style="text-align:var(--align)!important;text-align:center">' . $closer,
    )));
});

test('root text alignment preflight treats effective all resets as ambiguous', function () {
    $detector = new RootTextAlignmentConflictDetector();
    $resetWins = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:center;all:unset">Title</h2><!-- /wp:heading -->';
    $alignmentWins = '<!-- wp:heading --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="all:unset;text-align:center">Title</h2><!-- /wp:heading -->';

    assert_eq(1, count($detector->detect($resetWins)));
    assert_eq([], $detector->detect($alignmentWins));
});

test('inert comments beside the saved root do not hide alignment conflicts', function () {
    $markup = '<!-- wp:heading --><!-- note --><h2 class="wp-block-heading has-text-align-center" '
        . 'style="text-align:right">Title</h2><!-- /wp:heading -->';

    assert_eq(1, count((new RootTextAlignmentConflictDetector())->detect($markup)));
});

test('root text alignment preflight compares saved classes with comment signals', function () {
    $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"right"}}} -->'
        . '<h2 class="wp-block-heading has-text-align-center">Title</h2><!-- /wp:heading -->';

    $conflicts = (new RootTextAlignmentConflictDetector())->detect($markup);
    assert_eq(1, count($conflicts));
    assert_contains('saved-root class has-text-align-center', $conflicts[0]);
    assert_contains('comment style.typography.textAlign:right', $conflicts[0]);
});

test('heading align and non-exact comment enums cannot mirror rendered text alignment', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach ([
        '<!-- wp:heading {"align":"right"} -->'
            . '<h2 style="text-align:right">Title</h2><!-- /wp:heading -->',
        '<!-- wp:heading {"textAlign":"RIGHT"} -->'
            . '<h2 style="text-align:right">Title</h2><!-- /wp:heading -->',
        '<!-- wp:heading {"style":{"typography":{"textAlign":" right "}}} -->'
            . '<h2 style="text-align:right">Title</h2><!-- /wp:heading -->',
    ] as $markup) {
        assert_eq(1, count($detector->detect($markup)));
    }
});

test('heading registered align suppresses a legacy textAlign mirror', function () {
    $markup = '<!-- wp:heading {"textAlign":"left","align":"center"} -->'
        . '<h2 class="wp-block-heading" style="text-align:left">Title</h2>'
        . '<!-- /wp:heading -->';

    $conflicts = (new RootTextAlignmentConflictDetector())->detect($markup);
    assert_eq(1, count($conflicts));
    assert_contains('has no durable comment/class mirror', $conflicts[0]);
    assert_contains('inline text-align:left', $conflicts[0]);
});

test('heading invalid canonical alignment cannot hide a rendered alignment loss', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['true', 'false', '0', 'null', '""'] as $invalid) {
        $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":' . $invalid
            . '}}} --><h2 class="wp-block-heading has-text-align-center" '
            . 'style="text-align:center">Title</h2><!-- /wp:heading -->';
        $conflicts = $detector->detect($markup);
        assert_eq(1, count($conflicts), "canonical {$invalid} cannot mirror rendered center");
        assert_contains('root text-alignment signals conflict', $conflicts[0]);
    }
});

test('heading class and comment mirrors cannot preserve inline important priority', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['center!important', 'center!\\69mportant'] as $inline) {
        $markup = '<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->'
            . '<h2 class="wp-block-heading has-text-align-center" style="text-align:'
            . $inline . '">Title</h2><!-- /wp:heading -->';
        $conflicts = $detector->detect($markup);
        assert_eq(1, count($conflicts), "{$inline} priority cannot be silently dropped");
        assert_contains($inline, $conflicts[0]);
    }

    $paragraph = '<!-- wp:paragraph {"style":{"typography":{"textAlign":"center"}}} -->'
        . '<p class="has-text-align-center" style="text-align:center!important">Copy</p>'
        . '<!-- /wp:paragraph -->';
    assert_eq([], $detector->detect($paragraph), 'paragraph save preserves the important style');
});

test('paragraph inline alignment is its own durable signal', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['justify', 'start', 'end', 'var(--align)', 'var(--ALIGN)'] as $value) {
        $canonical = '<!-- wp:paragraph {"style":{"typography":{"textAlign":"'
            . $value . '"}}} --><p style="text-align:' . $value . '">Copy</p>'
            . '<!-- /wp:paragraph -->';
        $inlineOnly = '<!-- wp:paragraph --><p style="text-align:' . $value . '">Copy</p>'
            . '<!-- /wp:paragraph -->';
        assert_eq([], $detector->detect($canonical));
        assert_eq([], $detector->detect($inlineOnly));
    }
});

test('paragraph comparison folds CSS keywords but retains custom-property case', function () {
    $detector = new RootTextAlignmentConflictDetector();
    $uppercaseKeyword = '<!-- wp:paragraph {"style":{"typography":{"textAlign":"CENTER"}}} -->'
        . '<p style="text-align:center">Copy</p><!-- /wp:paragraph -->';
    $uppercaseWideKeyword = '<!-- wp:paragraph {"style":{"typography":{'
        . '"textAlign":"INHERIT"}}} --><p style="text-align:inherit">Copy</p>'
        . '<!-- /wp:paragraph -->';
    $escapedKeyword = '<!-- wp:paragraph {"style":{"typography":{"textAlign":"center"}}} -->'
        . '<p style="text-align:\\63 enter">Copy</p><!-- /wp:paragraph -->';
    $uppercaseFunction = '<!-- wp:paragraph {"style":{"typography":{'
        . '"textAlign":"VAR(--Align)"}}} --><p style="text-align:var(--Align)">Copy</p>'
        . '<!-- /wp:paragraph -->';
    $differentVariable = '<!-- wp:paragraph {"style":{"typography":{'
        . '"textAlign":"var(--Align)"}}} --><p style="text-align:var(--align)">Copy</p>'
        . '<!-- /wp:paragraph -->';

    assert_eq([], $detector->detect($uppercaseKeyword));
    assert_eq([], $detector->detect($uppercaseWideKeyword));
    assert_eq([], $detector->detect($escapedKeyword));
    assert_eq([], $detector->detect($uppercaseFunction));
    assert_eq(
        [],
        $detector->detect($differentVariable),
        'the preserved paragraph inline value remains browser-dominant over inert metadata',
    );
});

test('paragraph layout align values do not masquerade as text-alignment signals', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach (['wide', 'full'] as $layout) {
        $markup = '<!-- wp:paragraph {"align":"' . $layout . '"} -->'
            . '<p class="align' . $layout . '" style="text-align:center">Copy</p>'
            . '<!-- /wp:paragraph -->';
        assert_eq([], $detector->detect($markup));
    }
});

test('unsupported paragraph canonical alignment stays inert without warning noise', function () {
    $markup = '<!-- wp:paragraph {"style":{"typography":{"textAlign":"banana"}}} -->'
        . '<p class="has-text-align-center">Copy</p><!-- /wp:paragraph -->';

    assert_eq([], (new RootTextAlignmentConflictDetector())->detect($markup));
});

test('reading-copy alignment with no sole expected root fails closed', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach ([
        '<!-- wp:heading -->lead<h2 style="text-align:right">Title</h2><!-- /wp:heading -->',
        '<!-- wp:heading --><h2>One</h2><h2 class="has-text-align-center">Two</h2>'
            . '<!-- /wp:heading -->',
        '<!-- wp:paragraph {"style":{"typography":{"textAlign":"center"}}} -->'
            . '<div>Wrong root</div><!-- /wp:paragraph -->',
    ] as $markup) {
        $conflicts = $detector->detect($markup);
        assert_eq(1, count($conflicts));
        assert_contains('has no sole expected saved root', $conflicts[0]);
    }

    assert_eq(
        [],
        $detector->detect('<!-- wp:heading -->lead<h2>Title</h2><!-- /wp:heading -->'),
        'an unrelated malformed root remains owned by ordinary structural validation',
    );
});

test('root text alignment preflight requires a durable mirror for inline-only alignment', function () {
    $markup = '<!-- wp:heading --><h2 class="wp-block-heading" '
        . 'style="text-align:center">Title</h2><!-- /wp:heading -->';

    $conflicts = (new RootTextAlignmentConflictDetector())->detect($markup);
    assert_eq(1, count($conflicts));
    assert_contains('has no durable comment/class mirror', $conflicts[0]);
    assert_contains('inline text-align:center', $conflicts[0]);
});

test('root text alignment preflight isolates contradictory paragraph signals outside the reviewed shape', function () {
    $reviewed = '<!-- wp:paragraph {"align":"center","style":{"typography":{'
        . '"textAlign":"justify"}}} --><p class="has-text-align-center" '
        . 'style="text-align:justify">Copy</p><!-- /wp:paragraph -->';
    $htmlOnly = '<!-- wp:paragraph --><p class="has-text-align-center" '
        . 'style="text-align:justify">Copy</p><!-- /wp:paragraph -->';
    $extraLegacySignal = '<!-- wp:paragraph {"align":"center","textAlign":"right",'
        . '"style":{"typography":{"textAlign":"justify"}}} -->'
        . '<p class="has-text-align-center" style="text-align:justify">Copy</p>'
        . '<!-- /wp:paragraph -->';
    $reviewedDelivered = '<!-- wp:paragraph {"align":"center","style":{"typography":{'
        . '"textAlign":"justify"}}} --><p>Copy</p><!-- /wp:paragraph -->';

    $detector = new RootTextAlignmentConflictDetector();
    assert_eq([], $detector->detect($reviewed));
    assert_eq([], $detector->detect($htmlOnly));
    assert_eq([], $detector->detect($extraLegacySignal));
    assert_eq(
        [],
        $detector->detect($reviewedDelivered),
        'the exact comment-only fixed point is inert and needs no duplicate warning',
    );
});

test('paragraph preflight rejects lossy duplicate style-map projections and accepts equal cascades', function () {
    $detector = new RootTextAlignmentConflictDetector();
    foreach ([
        'text-align:right!important;text-align:center',
        'all:unset!important;all:initial',
        'all:unset;text-align:right;all:initial',
        'text-align:right;all:unset;text-align:center',
        'text-align:right!important;color:red;text-align:right',
        'text-align:right;text-align:banana',
        'direction:rtl!important;text-align:start;direction:ltr',
        '--a:right!important;text-align:var(--a);--a:left',
    ] as $style) {
        $markup = '<!-- wp:paragraph --><p style="' . $style . '">Copy</p>'
            . '<!-- /wp:paragraph -->';
        $conflicts = $detector->detect($markup);
        assert_eq(1, count($conflicts), "{$style} is isolated before serialization");
        assert_contains('style-map projection changes effective text alignment', $conflicts[0]);
    }

    foreach ([
        'text-align:right;text-align:center',
        'text-align:right;color:red;text-align:right',
        'text-align:right;text-align:var(--missing)',
        'text-align:ri/**/ght;text-align:center',
        'text-/**/align:right;text-align:center',
        'text-align:right!im/**/portant;text-align:center',
        'text-align:right;TEXT-ALIGN:banana',
    ] as $style) {
        $markup = '<!-- wp:paragraph --><p style="' . $style . '">Copy</p>'
            . '<!-- /wp:paragraph -->';
        assert_eq([], $detector->detect($markup), "{$style} remains a harmless projection");
    }
});
