<?php
declare(strict_types=1);

use Automattic\SiteBuild\DesignFloor;

/** @return array<string, mixed> */
function design_floor_theme(string $file): array
{
    $decoded = json_decode((string) file_get_contents(repo_path('tests/fixtures/design-floor/' . $file)), true);
    assert_true(is_array($decoded), $file . ' must decode');
    return $decoded;
}

function design_floor_markup(string $file): string
{
    return (string) file_get_contents(repo_path('tests/fixtures/design-floor/' . $file));
}

/** @param list<array{rule:string, detail:string, path:string}> $findings */
function design_floor_rules(array $findings): array
{
    return array_values(array_unique(array_column($findings, 'rule')));
}

function design_floor_has_rule(array $findings, string $rule): bool
{
    return in_array($rule, array_column($findings, 'rule'), true);
}

function design_floor_flush_card(string $inner): string
{
    return '<!-- wp:group {"className":"card-style--flush"} -->'
        . '<div class="wp-block-group card-style--flush">' . $inner
        . '</div><!-- /wp:group -->';
}

test('planted fixture fires every markup and theme rule once each (G5)', function () {
    $findings = DesignFloor::check(
        design_floor_markup('all-defects.html'),
        design_floor_theme('theme-defects.json'),
    );
    $expected = array_merge(DesignFloor::MARKUP_RULES, DesignFloor::THEME_RULES);
    $fired = design_floor_rules($findings);
    sort($expected);
    sort($fired);
    assert_eq($expected, $fired, 'N of N rules fired');
    assert_eq(count($expected), count($fired));
});

test('clean fixture reports zero findings (G5)', function () {
    $findings = DesignFloor::check(
        design_floor_markup('clean.html'),
        design_floor_theme('theme-clean.json'),
    );
    assert_eq([], $findings);
});

test('nested-cards fires on card-style wrappers and ignores card-body', function () {
    $nested = design_floor_flush_card(
        '<!-- wp:group {"className":"card-style--framed"} -->'
        . '<div class="wp-block-group card-style--framed"></div><!-- /wp:group -->'
    );
    assert_true(design_floor_has_rule(DesignFloor::check($nested, []), DesignFloor::RULE_NESTED_CARDS));

    $body = design_floor_flush_card(
        '<!-- wp:group {"className":"card-body"} -->'
        . '<div class="wp-block-group card-body"><!-- wp:paragraph --><p>Copy</p><!-- /wp:paragraph --></div>'
        . '<!-- /wp:group -->'
    );
    assert_true(!design_floor_has_rule(DesignFloor::check($body, []), DesignFloor::RULE_NESTED_CARDS));
});

test('gradient-text needs clip plus gradient and does not fire on clip alone', function () {
    $both = '<!-- wp:heading {"style":{"color":{"gradient":"linear-gradient(90deg,#000,#fff)"}}} -->'
        . '<h2 class="wp-block-heading" style="background:linear-gradient(90deg,#000,#fff);background-clip:text">Hi</h2>'
        . '<!-- /wp:heading -->';
    assert_true(design_floor_has_rule(DesignFloor::check($both, []), DesignFloor::RULE_GRADIENT_TEXT));

    $clipOnly = '<!-- wp:heading -->'
        . '<h2 class="wp-block-heading" style="-webkit-background-clip:text;background-clip:text">Hi</h2>'
        . '<!-- /wp:heading -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($clipOnly, []), DesignFloor::RULE_GRADIENT_TEXT));
});

test('justified-text fires on body copy and not on a heading', function () {
    $body = '<!-- wp:paragraph {"align":"justify"} -->'
        . '<p class="has-text-align-justify">Justified reading copy.</p><!-- /wp:paragraph -->';
    assert_true(design_floor_has_rule(DesignFloor::check($body, []), DesignFloor::RULE_JUSTIFIED_TEXT));

    $heading = '<!-- wp:heading {"style":{"typography":{"textAlign":"justify"}}} -->'
        . '<h2 style="text-align:justify">Title</h2><!-- /wp:heading -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($heading, []), DesignFloor::RULE_JUSTIFIED_TEXT));
});

test('side-tab fires on a thick left card border and skips a 1px full box', function () {
    $tab = '<!-- wp:group {"className":"card-style--framed","style":{"border":{"left":{"color":"#c00","width":"4px"}}}} -->'
        . '<div class="wp-block-group card-style--framed"></div><!-- /wp:group -->';
    assert_true(design_floor_has_rule(DesignFloor::check($tab, []), DesignFloor::RULE_SIDE_TAB));

    $item = '<!-- wp:list --><ul><!-- wp:list-item {"style":{"border":{"left":{"width":"6px","color":"#111"}}}} -->'
        . '<li style="border-left:6px solid #111">Item</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
    assert_true(design_floor_has_rule(DesignFloor::check($item, []), DesignFloor::RULE_SIDE_TAB));

    $box = '<!-- wp:group {"className":"card-style--framed","style":{"border":{"width":"4px","color":"#111"}}} -->'
        . '<div class="wp-block-group card-style--framed"></div><!-- /wp:group -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($box, []), DesignFloor::RULE_SIDE_TAB));

    $hairline = '<!-- wp:group {"className":"card-style--framed","style":{"border":{"left":{"width":"1px","color":"#c00"}}}} -->'
        . '<div class="wp-block-group card-style--framed"></div><!-- /wp:group -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($hairline, []), DesignFloor::RULE_SIDE_TAB));
});

test('all-caps-body fires on a long paragraph and skips labels and buttons', function () {
    $long = '<!-- wp:paragraph {"style":{"typography":{"textTransform":"uppercase"}}} -->'
        . '<p style="text-transform:uppercase">This paragraph is a long run of body copy set entirely in uppercase letters so the detector has wrapping text rather than a short label.</p>'
        . '<!-- /wp:paragraph -->';
    assert_true(design_floor_has_rule(DesignFloor::check($long, []), DesignFloor::RULE_ALL_CAPS_BODY));

    $label = '<!-- wp:paragraph {"fontSize":"caption","style":{"typography":{"textTransform":"uppercase"}}} -->'
        . '<p class="has-caption-font-size" style="text-transform:uppercase">Kicker line</p><!-- /wp:paragraph -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($label, []), DesignFloor::RULE_ALL_CAPS_BODY));

    $button = '<!-- wp:button {"style":{"typography":{"textTransform":"uppercase"}}} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link" style="text-transform:uppercase">Book now</a></div>'
        . '<!-- /wp:button -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($button, []), DesignFloor::RULE_ALL_CAPS_BODY));
});

test('skipped-heading fires on h2 to h4 and not on h2 to h3', function () {
    $skip = '<!-- wp:heading {"level":2} --><h2>A</h2><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":4} --><h4>B</h4><!-- /wp:heading -->';
    assert_true(design_floor_has_rule(DesignFloor::check($skip, []), DesignFloor::RULE_SKIPPED_HEADING));

    $ok = '<!-- wp:heading {"level":2} --><h2>A</h2><!-- /wp:heading -->'
        . '<!-- wp:heading {"level":3} --><h3>B</h3><!-- /wp:heading -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($ok, []), DesignFloor::RULE_SKIPPED_HEADING));
});

test('kicker-above-heading fires on a caption kicker and skips a standfirst', function () {
    $kicker = '<!-- wp:paragraph {"fontSize":"caption","style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.2em"}}} -->'
        . '<p class="has-caption-font-size">Studio hours</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":2} --><h2>Visit</h2><!-- /wp:heading -->';
    assert_true(design_floor_has_rule(DesignFloor::check($kicker, []), DesignFloor::RULE_KICKER_ABOVE_HEADING));

    $standfirst = '<!-- wp:paragraph --><p>A mixed-case standfirst under nothing, then a heading.</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":2} --><h2>Visit</h2><!-- /wp:heading -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($standfirst, []), DesignFloor::RULE_KICKER_ABOVE_HEADING));
});

test('tiny-text fires when body is under 0.75rem and skips caption-only tininess', function () {
    $tinyBody = [
        'settings' => ['typography' => ['fontSizes' => [
            ['slug' => 'caption', 'name' => 'Caption', 'size' => '0.875rem'],
            ['slug' => 'body', 'name' => 'Body', 'size' => '0.7rem'],
            ['slug' => 'display', 'name' => 'Display', 'size' => '3rem'],
        ]]],
        'styles' => ['typography' => ['fontSize' => 'var:preset|font-size|body']],
    ];
    assert_true(design_floor_has_rule(DesignFloor::check('', $tinyBody), DesignFloor::RULE_TINY_TEXT));

    $tinyCaption = [
        'settings' => ['typography' => ['fontSizes' => [
            ['slug' => 'caption', 'name' => 'Caption', 'size' => '0.6rem'],
            ['slug' => 'body', 'name' => 'Body', 'size' => '1.125rem'],
            ['slug' => 'display', 'name' => 'Display', 'size' => '3rem'],
        ]]],
        'styles' => ['typography' => ['fontSize' => 'var:preset|font-size|body']],
    ];
    assert_true(!design_floor_has_rule(DesignFloor::check('', $tinyCaption), DesignFloor::RULE_TINY_TEXT));
});

test('wide-tracking fires above 0.08em on body and skips missing tracking', function () {
    $wide = [
        'styles' => ['typography' => ['letterSpacing' => '0.12em']],
    ];
    assert_true(design_floor_has_rule(DesignFloor::check('', $wide), DesignFloor::RULE_WIDE_TRACKING));

    $ok = [
        'styles' => ['typography' => ['letterSpacing' => '0.04em']],
    ];
    assert_true(!design_floor_has_rule(DesignFloor::check('', $ok), DesignFloor::RULE_WIDE_TRACKING));
});

test('tight-leading fires below 1.3 and skips the scaffold 1.6', function () {
    $tight = ['styles' => ['typography' => ['lineHeight' => '1.2']]];
    assert_true(design_floor_has_rule(DesignFloor::check('', $tight), DesignFloor::RULE_TIGHT_LEADING));

    $ok = ['styles' => ['typography' => ['lineHeight' => '1.6']]];
    assert_true(!design_floor_has_rule(DesignFloor::check('', $ok), DesignFloor::RULE_TIGHT_LEADING));
});

test('flat-type-hierarchy fires when the scale ratio is under 2 and skips the default scale', function () {
    $flat = [
        'settings' => ['typography' => ['fontSizes' => [
            ['slug' => 'body', 'name' => 'Body', 'size' => '1rem'],
            ['slug' => 'display', 'name' => 'Display', 'size' => '1.5rem'],
        ]]],
    ];
    assert_true(design_floor_has_rule(DesignFloor::check('', $flat), DesignFloor::RULE_FLAT_TYPE_HIERARCHY));

    $ok = design_floor_theme('theme-clean.json');
    assert_true(!design_floor_has_rule(DesignFloor::check('', $ok), DesignFloor::RULE_FLAT_TYPE_HIERARCHY));
});

test('warningRow matches the validate-theme authored/delivered/disposition shape', function () {
    $row = DesignFloor::warningRow('plugin/pages/home.html', [
        'rule' => DesignFloor::RULE_NESTED_CARDS,
        'detail' => 'card wrapper nested inside another card wrapper',
        'path' => 'wp:group[0]/wp:group[0]',
    ]);
    assert_contains('design-floor: file=plugin/pages/home.html', $row);
    assert_contains('rule=nested-cards', $row);
    assert_contains('path=wp:group[0]/wp:group[0]', $row);
    assert_contains('delivered=unchanged', $row);
    assert_contains('disposition=reported, not repaired', $row);
});

test('empty theme.json and empty markup yield no findings', function () {
    assert_eq([], DesignFloor::check('', []));
});

test('section prompt bans kickers above headings', function () {
    $section = (string) file_get_contents(repo_path('prompts/section.md'));
    assert_contains('Eyebrows are banned', $section);
    assert_contains('no brief earns it back', $section);
    assert_true(!str_contains($section, 'Eyebrows are rationed'));
    assert_contains('Never put an eyebrow or kicker line above the row heading', $section);
});

test('design-direction offers no numeral device and no numbered-index idiom', function () {
    // BIGR-949: the model must never add sequence numbers as decoration.
    $direction = (string) file_get_contents(repo_path('prompts/design-direction.md'));
    assert_true(!str_contains($direction, 'section-numeral'));
    assert_true(!str_contains($direction, '`"index"`'));
    // The bare word "index" is common in prose, so the bare-word check is
    // scoped to the lines that define the item_pattern vocabulary. The
    // hyphen-aware lookarounds permit "numbered-index" in the ban prose.
    foreach (preg_grep('/item_pattern/', explode("\n", $direction)) as $line) {
        assert_true(
            preg_match('/(?<![\w-])index(?![\w-])/', $line) === 0,
            'the item_pattern vocabulary must not offer a bare index token',
        );
    }
    assert_contains('banned unless the SITE BRIEF explicitly asks for visible numbering', $direction);
});

test('side-tab skips an unnamed group with a thick left border', function () {
    $group = '<!-- wp:group {"style":{"border":{"left":{"width":"8px","color":"#c00"}}}} -->'
        . '<div class="wp-block-group" style="border-left:8px solid #c00"></div><!-- /wp:group -->';
    assert_true(!design_floor_has_rule(DesignFloor::check($group, []), DesignFloor::RULE_SIDE_TAB));
});

test('a throwing markup rule emits scan-failed and the other six still report', function () {
    $findings = DesignFloor::check(
        design_floor_markup('all-defects.html'),
        [],
        null,
        DesignFloor::RULE_NESTED_CARDS,
    );
    assert_true(design_floor_has_rule($findings, DesignFloor::RULE_SCAN_FAILED));
    $scan = array_values(array_filter(
        $findings,
        static fn (array $row): bool => $row['rule'] === DesignFloor::RULE_SCAN_FAILED,
    ));
    assert_eq(1, count($scan), 'exactly one scan-failed');
    assert_eq('nested-cards', $scan[0]['path']);
    assert_contains('injected DesignFloor fault for nested-cards', $scan[0]['detail']);
    assert_true(!design_floor_has_rule($findings, DesignFloor::RULE_NESTED_CARDS));
    $survivors = array_values(array_diff(DesignFloor::MARKUP_RULES, [DesignFloor::RULE_NESTED_CARDS]));
    foreach ($survivors as $rule) {
        assert_true(design_floor_has_rule($findings, $rule), $rule . ' survived the sibling throw');
    }
});

test('unparseable markup emits exactly one scan-failed and theme.json rules still run', function () {
    $findings = DesignFloor::check(
        '<!-- wp:paragraph --><p>Unparseable on purpose.</p><!-- /wp:paragraph -->',
        design_floor_theme('theme-defects.json'),
        static function (string $markup): never {
            throw new RuntimeException('parse boom');
        },
    );
    $scan = array_values(array_filter(
        $findings,
        static fn (array $row): bool => $row['rule'] === DesignFloor::RULE_SCAN_FAILED,
    ));
    assert_eq(1, count($scan));
    assert_eq('parse', $scan[0]['path']);
    assert_contains('parse boom', $scan[0]['detail']);
    foreach (DesignFloor::MARKUP_RULES as $rule) {
        assert_true(!design_floor_has_rule($findings, $rule), $rule . ' cannot run after parse failure');
    }
    foreach (DesignFloor::THEME_RULES as $rule) {
        assert_true(design_floor_has_rule($findings, $rule), $rule . ' still runs after parse failure');
    }
});

test('non-empty unparseable markup never returns an empty finding list', function () {
    $findings = DesignFloor::check(
        '<!-- wp:heading --><h2>Present</h2><!-- /wp:heading -->',
        [],
        static function (string $markup): never {
            throw new RuntimeException('parse boom');
        },
    );
    assert_true($findings !== [], 'a failed scan is not a clean page');
    assert_true(design_floor_has_rule($findings, DesignFloor::RULE_SCAN_FAILED));
});
