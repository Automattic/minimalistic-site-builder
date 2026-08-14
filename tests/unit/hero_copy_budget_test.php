<?php
declare(strict_types=1);

use Automattic\SiteBuild\HeroCopyBudget;

test('hero copy budget retains one headline, one paragraph, and only the authoritative action', function () {
    $headline = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">One clear promise</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>The one supporting thought stays byte-for-byte.</p><!-- /wp:paragraph -->';
    $overflow = '<!-- wp:paragraph --><p>This extra line is generated clutter.</p><!-- /wp:paragraph -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button -->';
    $primary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    $media = '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/subject.jpg" alt="Subject" /></figure><!-- /wp:image -->';
    $markup = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:group {"className":"hero-composition__copy"} --><div class="wp-block-group hero-composition__copy">'
        . $headline . $standfirst . $overflow
        . '<!-- wp:buttons --><div class="wp-block-buttons">' . $secondary . $primary . '</div><!-- /wp:buttons -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group {"className":"hero-composition__media"} --><div class="wp-block-group hero-composition__media">'
        . $media . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $first = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    foreach ([$headline, $standfirst, $primary, $media] as $survivor) {
        assert_contains($survivor, $first['markup']);
    }
    assert_true(!str_contains($first['markup'], $overflow));
    assert_true(!str_contains($first['markup'], $secondary));
    assert_eq(2, count($first['warnings']));
    $joined = implode("\n", $first['warnings']);
    foreach ([
        "file='theme/parts/page-home--hero.html'",
        'This extra line is generated clutter.',
        'Contact us',
        'delivered=removed',
        'disposition=',
    ] as $context) {
        assert_contains($context, $joined);
    }

    $second = HeroCopyBudget::enforce($first['markup'], $action, 'page-home--hero');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([], $second['warnings']);
});

test('hero copy budget coalesces nested removal spans without touching following siblings', function () {
    $keep = '<!-- wp:paragraph --><p>KEEP SIBLING</p><!-- /wp:paragraph -->';
    $nestedOverflow = '<!-- wp:heading {"level":2} --><h2>Outer generated line '
        . '<!-- wp:paragraph --><p>Nested generated line</p><!-- /wp:paragraph -->'
        . '</h2><!-- /wp:heading -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . $keep . $nestedOverflow
        . '</div><!-- /wp:group -->';

    $result = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_contains($keep, $result['markup']);
    assert_true(!str_contains($result['markup'], 'Outer generated line'));
    assert_true(!str_contains($result['markup'], 'Nested generated line'));
    assert_contains('</div><!-- /wp:group -->', $result['markup']);
    assert_eq(2, count($result['warnings']));
});

test('hero copy budget retains a malformed excess boundary that contains surviving content', function () {
    $nestedMedia = '<!-- wp:image --><figure class="wp-block-image"><img src="theme:./assets/keep.jpg" alt="Keep me" /></figure><!-- /wp:image -->';
    $overflow = '<!-- wp:heading {"level":2} --><h2>Overflow heading' . $nestedMedia . '</h2><!-- /wp:heading -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $overflow
        . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_eq($markup, $first['markup'], 'the enclosing text block is not removed with nested media');
    assert_contains($nestedMedia, $first['markup']);
    assert_eq(1, count($first['warnings']));
    assert_contains('delivered="Overflow heading"', $first['warnings'][0]);
    assert_contains('residual overrun was queued for later repair', $first['warnings'][0]);

    $second = HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero');
    assert_eq($first, $second, 'the isolated-loss boundary and warning reach a fixed point');
});

test('hero copy budget removes a dedicated painted wrapper emptied by excess text removal', function () {
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>Standfirst survives</p><!-- /wp:paragraph -->';
    $overflow = '<!-- wp:paragraph --><p>Overflow inside paint</p><!-- /wp:paragraph -->';
    $paintedWrapper = '<!-- wp:group {"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px","bottom":"20px"}}}} -->'
        . '<div class="wp-block-group has-accent-background-color has-background" style="padding-top:20px;padding-bottom:20px">'
        . $overflow . '</div><!-- /wp:group -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $headline . $standfirst . $paintedWrapper
        . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_contains($headline . $standfirst, $first['markup']);
    assert_true(!str_contains($first['markup'], 'Overflow inside paint'));
    assert_true(!str_contains($first['markup'], 'backgroundColor'));
    assert_true(!str_contains($first['markup'], 'padding-top:20px'));
    assert_eq(2, count($first['warnings']));
    assert_contains("block='wp:paragraph[2]'", $first['warnings'][0]);
    foreach (["block='wp:group[2]'", 'backgroundColor', 'padding-top:20px', '<!-- wp:group'] as $context) {
        assert_contains($context, $first['warnings'][1]);
    }
    assert_contains('dead UI', $first['warnings'][1]);
    assert_eq(
        ['markup' => $first['markup'], 'warnings' => []],
        HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero'),
        'the wrapper cleanup reaches a fixed point',
    );
});

test('hero copy budget prefers a correctly placed post-headline standfirst', function () {
    $preHeadline = '<!-- wp:paragraph --><p>Ambiguous pre-headline line</p><!-- /wp:paragraph -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>Correctly placed standfirst</p><!-- /wp:paragraph -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $preHeadline . $headline . $standfirst
        . '</div><!-- /wp:group -->';

    $result = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_true(!str_contains($result['markup'], $preHeadline));
    assert_contains($headline . $standfirst, $result['markup']);
    assert_eq(1, count($result['warnings']));
    assert_contains('Ambiguous pre-headline line', $result['warnings'][0]);
});

test('hero copy budget never selects text nested inside an action as the standfirst', function () {
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $nestedDecoration = '<!-- wp:paragraph --><p>Nested secondary decoration</p><!-- /wp:paragraph -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/contact/">Contact wrapper ' . $nestedDecoration . '</a>'
        . '</div><!-- /wp:button -->';
    $standfirst = '<!-- wp:paragraph --><p>REAL SUPPORT STAYS</p><!-- /wp:paragraph -->';
    $primary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/work/">Explore the work</a>'
        . '</div><!-- /wp:button -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $headline . $secondary . $standfirst . $primary
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $first = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    assert_contains($headline, $first['markup']);
    assert_contains($standfirst, $first['markup'], 'the real sibling standfirst retains the support slot');
    assert_contains($primary, $first['markup']);
    assert_true(!str_contains($first['markup'], 'Nested secondary decoration'));
    assert_true(!str_contains($first['markup'], '/contact/'));
    assert_eq(1, count($first['warnings']));
    assert_contains('/contact/', $first['warnings'][0]);
    assert_eq(
        ['markup' => $first['markup'], 'warnings' => []],
        HeroCopyBudget::enforce($first['markup'], $action, 'page-home--hero'),
        'the action-owned copy boundary reaches a fixed point',
    );
});

test('hero copy budget counts direct text under buttons without losing the authoritative action', function () {
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>Real standfirst</p><!-- /wp:paragraph -->';
    $overflow = '<!-- wp:paragraph --><p>Malformed buttons copy</p><!-- /wp:paragraph -->';
    $primary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/work/">Explore the work</a>'
        . '</div><!-- /wp:button -->';
    $buttons = '<!-- wp:buttons --><div class="wp-block-buttons">'
        . $overflow . $primary . '</div><!-- /wp:buttons -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $headline . $standfirst . $buttons
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $first = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    assert_contains($headline . $standfirst, $first['markup']);
    assert_contains($primary, $first['markup']);
    assert_true(!str_contains($first['markup'], 'Malformed buttons copy'));
    assert_eq(1, count($first['warnings']));
    assert_contains("block='wp:paragraph[2]'", $first['warnings'][0]);
    assert_contains('Malformed buttons copy', $first['warnings'][0]);
    assert_eq(
        ['markup' => $first['markup'], 'warnings' => []],
        HeroCopyBudget::enforce($first['markup'], $action, 'page-home--hero'),
    );
});

test('hero copy budget retains raw media and closes nested removal safety bidirectionally', function () {
    $rawInner = '<!-- wp:paragraph --><p>Raw inner overflow'
        . '<img src="theme:./assets/keep.jpg" alt="Keep raw media">'
        . '</p><!-- /wp:paragraph -->';
    $plainInner = '<!-- wp:paragraph --><p>Plain sibling overflow</p><!-- /wp:paragraph -->';
    $outer = '<!-- wp:heading {"level":2} --><h2>Outer overflow'
        . $rawInner . $plainInner . '</h2><!-- /wp:heading -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $outer
        . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_eq($markup, $first['markup']);
    assert_contains('theme:./assets/keep.jpg', $first['markup']);
    assert_eq(3, count($first['warnings']));
    assert_contains('theme:./assets/keep.jpg', implode("\n", $first['warnings']));
    assert_contains('raw non-text payload', implode("\n", $first['warnings']));
    assert_eq($first, HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero'));
});

test('hero copy budget rebinds a retained path after an earlier safe sibling removal', function () {
    $safeOverflow = '<!-- wp:paragraph --><p>Safe overflow is removed</p><!-- /wp:paragraph -->';
    $rawOverflow = '<!-- wp:paragraph --><p>Raw overflow stays'
        . '<img src="theme:./assets/keep-path-image.jpg" alt="Keep path image">'
        . '</p><!-- /wp:paragraph -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $safeOverflow . $rawOverflow
        . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_true(!str_contains($first['markup'], 'Safe overflow is removed'));
    assert_contains('keep-path-image.jpg', $first['markup']);
    assert_eq(2, count($first['warnings']));
    assert_contains("block='wp:paragraph[2]'", $first['warnings'][1]);
    $second = HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero');
    assert_eq($first['markup'], $second['markup']);
    assert_eq([$first['warnings'][1]], $second['warnings']);
});

test('hero copy budget never lets malformed or empty candidates displace visible copy', function () {
    $malformedHeading = '<!-- wp:heading {"level":[1]} --><h2>Malformed level</h2><!-- /wp:heading -->';
    $emptyHeading = '<!-- wp:heading {"level":1} --><h1></h1><!-- /wp:heading -->';
    $headline = '<!-- wp:heading {"level":1} --><h1>Visible headline</h1><!-- /wp:heading -->';
    $emptyParagraph = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
    $standfirst = '<!-- wp:paragraph --><p>Useful support</p><!-- /wp:paragraph -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $malformedHeading . $emptyHeading . $headline . $emptyParagraph . $standfirst
        . '</div><!-- /wp:group -->';

    $result = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_contains($headline, $result['markup']);
    assert_contains($standfirst, $result['markup']);
    assert_true(!str_contains($result['markup'], $malformedHeading));
    assert_true(!str_contains($result['markup'], $emptyHeading));
    assert_true(!str_contains($result['markup'], $emptyParagraph));
});

test('hero copy budget reports a retained non-H1 headline as a residual contract defect', function () {
    $heading = '<!-- wp:heading {"level":2} --><h2>Best available headline</h2><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>Useful support</p><!-- /wp:paragraph -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . $heading . $standfirst
        . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_eq($markup, $first['markup']);
    assert_eq(1, count($first['warnings']));
    foreach ([
        "file='theme/parts/page-home--hero.html'",
        "block='wp:heading[1]'",
        'Best available headline',
        'not one visible level-1 heading',
        'queued for later repair',
    ] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }
    assert_eq($first, HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero'));
});

test('hero copy budget matches a primary action to its own button instead of a descendant', function () {
    $primary = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore the work</a></div><!-- /wp:button -->';
    $outer = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact wrapper '
        . $primary . '</a></div><!-- /wp:button -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $outer
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $first = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    assert_eq($markup, $first['markup'], 'unsafe enclosing control is retained instead of deleting the primary');
    assert_true(Automattic\SiteBuild\Units\GeneratedMarkup::containsPrimaryAction($first['markup'], $action));
    assert_eq(1, count($first['warnings']));
    assert_contains('residual overrun was queued for later repair', $first['warnings'][0]);
    assert_eq($first, HeroCopyBudget::enforce($first['markup'], $action, 'page-home--hero'));
});

test('hero copy budget retains an excess button that owns raw media', function () {
    $secondary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/contact/">Contact us'
        . '<img src="theme:./assets/keep-control-art.jpg" alt="Keep control art">'
        . '</a></div><!-- /wp:button -->';
    $primary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/work/">Explore the work</a>'
        . '</div><!-- /wp:button -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $secondary . $primary
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $first = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    assert_eq($markup, $first['markup']);
    assert_contains('theme:./assets/keep-control-art.jpg', $first['markup']);
    assert_eq(1, count($first['warnings']));
    foreach (['Contact us', '/contact/', 'keep-control-art.jpg', 'raw non-text payload'] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }
    assert_eq($first, HeroCopyBudget::enforce($first['markup'], $action, 'page-home--hero'));
});

test('hero copy budget freezes descendant edits under an unsafe retained boundary', function () {
    $headline = '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->';
    $standfirst = '<!-- wp:paragraph --><p>Support</p><!-- /wp:paragraph -->';
    $overflow = '<!-- wp:paragraph --><p>Overflow</p><!-- /wp:paragraph -->';
    $outer = '<!-- wp:heading {"level":2} --><h2>'
        . $headline . $standfirst . $overflow
        . '</h2><!-- /wp:heading -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">' . $outer . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_eq($markup, $first['markup']);
    assert_contains('HeadlineSupportOverflow', implode("\n", $first['warnings']));
    assert_eq($first, HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero'));
});

test('hero copy budget removes a buttons wrapper when its last excess action is removed', function () {
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div><!-- /wp:button -->';
    $buttons = '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $buttons
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $result = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    assert_true(!str_contains($result['markup'], 'wp:button'));
    assert_eq(2, count($result['warnings']));
    assert_contains("block='wp:button[1]'", $result['warnings'][0]);
    assert_contains('/contact/', $result['warnings'][0]);
    assert_contains('<!-- wp:button -->', $result['warnings'][0]);
    assert_contains("block='wp:buttons[1]'", $result['warnings'][1]);
    assert_contains('<!-- wp:buttons -->', $result['warnings'][1]);
    assert_contains('/contact/', $result['warnings'][1]);
    assert_contains('became empty', $result['warnings'][1]);
});

test('hero copy budget warns separately with markup for a styled wrapper removed around an excess action', function () {
    $primary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/work/">Explore the work</a>'
        . '</div><!-- /wp:button -->';
    $secondary = '<!-- wp:button --><div class="wp-block-button">'
        . '<a class="wp-block-button__link" href="/contact/">Contact us</a>'
        . '</div><!-- /wp:button -->';
    $primaryWrapper = '<!-- wp:buttons --><div class="wp-block-buttons">'
        . $primary . '</div><!-- /wp:buttons -->';
    $styledWrapper = '<!-- wp:buttons {"backgroundColor":"accent","style":{"spacing":{"padding":{"top":"20px"}}}} -->'
        . '<div class="wp-block-buttons has-accent-background-color has-background" style="padding-top:20px">'
        . $secondary . '</div><!-- /wp:buttons -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Standfirst</p><!-- /wp:paragraph -->'
        . $primaryWrapper . $styledWrapper
        . '</div><!-- /wp:group -->';
    $action = [
        'label' => 'Explore the work',
        'intent' => 'Help visitors reach the current work',
        'destination' => '/work/',
    ];

    $first = HeroCopyBudget::enforce($markup, $action, 'page-home--hero');

    assert_contains($primaryWrapper, $first['markup']);
    assert_true(!str_contains($first['markup'], '/contact/'));
    assert_true(!str_contains($first['markup'], 'backgroundColor'));
    assert_eq(2, count($first['warnings']));
    [$buttonWarning, $wrapperWarning] = $first['warnings'];
    foreach (["block='wp:button[2]'", '/contact/', '<!-- wp:button -->', 'Contact us'] as $context) {
        assert_contains($context, $buttonWarning);
    }
    foreach (["block='wp:buttons[2]'", 'backgroundColor', 'padding-top:20px', '<!-- wp:buttons'] as $context) {
        assert_contains($context, $wrapperWarning);
    }
    assert_eq(
        ['markup' => $first['markup'], 'warnings' => []],
        HeroCopyBudget::enforce($first['markup'], $action, 'page-home--hero'),
    );
});

test('hero copy budget removes a pre-existing empty buttons wrapper actionably', function () {
    $buttons = '<!-- wp:buttons --><div class="wp-block-buttons"></div><!-- /wp:buttons -->';
    $markup = '<!-- wp:group {"className":"hero-composition__copy"} -->'
        . '<div class="wp-block-group hero-composition__copy">'
        . '<!-- wp:heading {"level":1} --><h1>Headline</h1><!-- /wp:heading -->'
        . $buttons
        . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::enforce($markup, null, 'page-home--hero');

    assert_true(!str_contains($first['markup'], 'wp:buttons'));
    assert_eq(1, count($first['warnings']));
    assert_contains("block='wp:buttons[1]'", $first['warnings'][0]);
    assert_contains('delivered=removed', $first['warnings'][0]);
    assert_eq([], HeroCopyBudget::enforce($first['markup'], null, 'page-home--hero')['warnings']);
});

test('empty hero buttons repair removes only zero-button wrappers and reaches a fixed point', function () {
    $button = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/work/">Explore work</a></div><!-- /wp:button -->';
    $kept = '<!-- wp:buttons --><div class="wp-block-buttons">' . $button . '</div><!-- /wp:buttons -->';
    $empty = '<!-- wp:buttons {"className":"design-actions"} --><div class="wp-block-buttons design-actions"></div><!-- /wp:buttons -->';
    $markup = '<!-- wp:group --><div class="wp-block-group">' . $kept . $empty . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::removeEmptyButtonsWrappers($markup, 'page-home--hero');

    assert_contains($kept, $first['markup']);
    assert_true(!str_contains($first['markup'], $empty));
    assert_eq(1, count($first['warnings']));
    foreach (["block='wp:buttons[2]'", 'design-actions', 'delivered=removed', 'dead layout space'] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }
    assert_eq(
        ['markup' => $first['markup'], 'warnings' => []],
        HeroCopyBudget::removeEmptyButtonsWrappers($first['markup'], 'page-home--hero'),
    );
});

test('zero-button hero wrapper unwraps non-button children without losing their bytes', function () {
    $paragraph = '<!-- wp:paragraph {"className":"keep-me"} --><p class="keep-me">Visible raw sibling stays.</p><!-- /wp:paragraph -->';
    $raw = '<span class="raw-note">Raw payload stays too.</span>';
    $wrapper = '<!-- wp:buttons {"className":"wrong-container"} -->'
        . '<div class="wp-block-buttons wrong-container">' . $paragraph . $raw . '</div>'
        . '<!-- /wp:buttons -->';
    $markup = '<!-- wp:group --><div class="wp-block-group">' . $wrapper . '</div><!-- /wp:group -->';

    $first = HeroCopyBudget::removeEmptyButtonsWrappers($markup, 'page-home--hero');

    assert_true(!str_contains($first['markup'], 'wp:buttons'));
    assert_true(!str_contains($first['markup'], 'wp-block-buttons'));
    assert_contains($paragraph, $first['markup']);
    assert_contains($raw, $first['markup']);
    assert_eq(1, substr_count($first['markup'], $paragraph));
    assert_eq(1, substr_count($first['markup'], $raw));
    assert_eq(1, count($first['warnings']));
    foreach (["block='wp:buttons[1]'", 'wrong-container', 'delivered=unwrapped', 'child block bytes were retained'] as $context) {
        assert_contains($context, $first['warnings'][0]);
    }
    assert_eq(
        ['markup' => $first['markup'], 'warnings' => []],
        HeroCopyBudget::removeEmptyButtonsWrappers($first['markup'], 'page-home--hero'),
    );
});
