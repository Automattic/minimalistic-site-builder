<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockDocumentRecovery;
use Automattic\SiteBuild\Toon;
use Automattic\SiteBuild\ToonBlockAttrs;
use Automattic\SiteBuild\Units\GeneratedMarkup;

// --- encode / decode (pure PHP serializer) ---------------------------------

test('Toon encode/decode nested block-like attrs (the multi-page failure shape)', function () {
    $attrs = [
        'align' => 'center',
        'textColor' => 'base',
        'fontSize' => 'caption',
        'style' => [
            'spacing' => [
                'margin' => [
                    'top' => 'var:preset|spacing|md',
                ],
            ],
            'elements' => [
                'link' => [
                    'color' => [
                        'text' => 'var:preset|color|base',
                    ],
                    ':hover' => [
                        'color' => [
                            'text' => 'var:preset|color|accent',
                        ],
                    ],
                ],
            ],
        ],
    ];
    $toon = Toon::encode($attrs);
    assert_true(str_contains($toon, 'align: center'), $toon);
    assert_true(str_contains($toon, 'style:'), $toon);
    // Deep keys with ":" must be quoted in TOON.
    assert_true(str_contains($toon, '":hover"') || str_contains($toon, ':hover'), $toon);

    $back = Toon::decode($toon);
    assert_eq($attrs, $back);
});

test('Toon encode matches official CLI shape for the failing attr object', function () {
    $attrs = [
        'align' => 'center',
        'textColor' => 'base',
        'style' => [
            'spacing' => [
                'margin' => [
                    'top' => 'var:preset|spacing|md',
                ],
            ],
            'elements' => [
                'link' => [
                    'color' => [
                        'text' => 'var:preset|color|base',
                    ],
                    ':hover' => [
                        'color' => [
                            'text' => 'var:preset|color|accent',
                        ],
                    ],
                ],
            ],
        ],
    ];
    // Reference encoding from `npx @toon-format/cli` (checked during design).
    $reference = <<<'TOON'
align: center
textColor: base
style:
  spacing:
    margin:
      top: "var:preset|spacing|md"
  elements:
    link:
      color:
        text: "var:preset|color|base"
      ":hover":
        color:
          text: "var:preset|color|accent"
TOON;
    $decodedRef = Toon::decode($reference);
    assert_eq($attrs, $decodedRef, 'must decode reference TOON from the official tool');
    assert_eq($attrs, Toon::roundTrip($attrs));
});

test('Toon round-trips primitives, lists, and empty structures', function () {
    assert_eq(['a' => 1, 'b' => true, 'c' => null], Toon::roundTrip(['a' => 1, 'b' => true, 'c' => null]));
    assert_eq(['tags' => ['a', 'b', 'c']], Toon::roundTrip(['tags' => ['a', 'b', 'c']]));
    assert_eq(['empty' => []], Toon::roundTrip(['empty' => []]));
    assert_eq('hello', Toon::roundTrip('hello'));
    assert_eq(42, Toon::roundTrip(42));
});

test('Toon tabular array form round-trips uniform objects', function () {
    $data = [
        'users' => [
            ['id' => 1, 'name' => 'Ada', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
        ],
    ];
    $toon = Toon::encode($data);
    assert_true(str_contains($toon, 'users[2]{id,name,role}:'), $toon);
    assert_eq($data, Toon::decode($toon));
});

// --- block attr expansion --------------------------------------------------

test('ToonBlockAttrs expands multi-line TOON openers to JSON', function () {
    $hybrid = <<<'HTML'
<!-- wp:paragraph
align: center
textColor: base
fontSize: caption
style:
  spacing:
    margin:
      top: "var:preset|spacing|md"
  elements:
    link:
      color:
        text: "var:preset|color|base"
      ":hover":
        color:
          text: "var:preset|color|accent"
-->
<p class="has-text-align-center">Or visit our contact page.</p>
<!-- /wp:paragraph -->
HTML;
    $notes = [];
    $out = ToonBlockAttrs::expand($hybrid, $notes);
    assert_true($notes !== [], 'should note the conversion');
    assert_true(preg_match('/<!-- wp:paragraph \{.*\} -->/', $out) === 1, $out);
    assert_true(str_contains($out, '"align":"center"'), $out);
    assert_true(str_contains($out, '":hover"') || str_contains($out, 'hover'), $out);
    assert_true(str_contains($out, '<!-- /wp:paragraph -->'), $out);
    // Body preserved.
    assert_true(str_contains($out, 'Or visit our contact page.'), $out);
});

test('ToonBlockAttrs leaves JSON openers untouched only when requireToon is false', function () {
    $html = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $notes = [];
    assert_eq($html, ToonBlockAttrs::expand($html, $notes, false));
    assert_eq([], $notes);
});

test('ToonBlockAttrs accepts optional toon marker keyword', function () {
    $hybrid = <<<'HTML'
<!-- wp:spacer toon
height: 24px
/-->
HTML;
    $notes = [];
    $out = ToonBlockAttrs::expand($hybrid, $notes);
    assert_true(str_contains($out, '<!-- wp:spacer {'), $out);
    assert_true(str_contains($out, '"height":"24px"'), $out);
    assert_true(str_contains($out, '/-->'), $out);
});

test('GeneratedMarkup accepts a full section written with TOON attrs', function () {
    $section = <<<'HTML'
<!-- wp:group
anchor: booking-next-steps
align: full
backgroundColor: contrast
textColor: base
style:
  spacing:
    margin:
      top: "0"
      bottom: "0"
layout:
  type: constrained
-->
<div class="wp-block-group alignfull has-base-color has-contrast-background-color has-text-color has-background" id="booking-next-steps">
<!-- wp:heading
textAlign: center
level: 2
textColor: base
fontSize: section-title
-->
<h2 class="wp-block-heading has-text-align-center has-base-color has-text-color has-section-title-font-size">Book your stay</h2>
<!-- /wp:heading -->

<!-- wp:paragraph
align: center
textColor: base
fontSize: caption
style:
  spacing:
    margin:
      top: "var:preset|spacing|md"
  elements:
    link:
      color:
        text: "var:preset|color|base"
      ":hover":
        color:
          text: "var:preset|color|accent"
-->
<p class="has-text-align-center has-base-color has-text-color has-caption-font-size"><a href="/contact/">Or visit our contact page for more ways to reach us.</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
HTML;

    $out = GeneratedMarkup::normalize($section, 'toon-proto');
    assert_true(str_starts_with($out, '<!-- wp:group {'), $out);
    assert_true(str_contains($out, '"anchor":"booking-next-steps"'), $out);
    // The deep elements.link.:hover path must be valid JSON now (the failure mode).
    assert_true(str_contains($out, '":hover"'), $out);
    assert_true(str_contains($out, '<!-- /wp:group -->'), $out);
});

test('ToonBlockAttrs rejects JSON openers when TOON is mandatory', function () {
    $json = '<!-- wp:group {"align":"full"} --><div class="wp-block-group"></div><!-- /wp:group -->';
    assert_throws(
        fn () => ToonBlockAttrs::expand($json, $notes, true),
        'JSON attrs must fail under requireToon',
    );
});

test('GeneratedMarkup rejects JSON openers as non-TOON model output', function () {
    $json = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
    assert_throws(
        fn () => GeneratedMarkup::normalize($json, 'p'),
        'normalize enforces mandatory TOON',
    );
});

test('ToonBlockAttrs can still pass through JSON when requireToon is false', function () {
    $json = '<!-- wp:group {"align":"full"} --><div class="wp-block-group"></div><!-- /wp:group -->';
    $notes = [];
    assert_eq($json, ToonBlockAttrs::expand($json, $notes, false));
});

test('repairs HTML-shaped paragraph comments from Fuego signature-drinks', function () {
    // Model emitted <!-- wp:paragraph> … </wp:paragraph --> instead of real
    // Gutenberg delimiters mid-section (Cascarilla de Coyoacán card).
    $broken = <<<'HTML'
<!-- wp:group
layout:
  type: constrained
-->
<div class="wp-block-group">
<!-- wp:heading -->
<h3 class="wp-block-heading">Cascarilla de Coyoacán</h3>
<!-- /wp:heading -->

<!-- wp:paragraph>
</wp:paragraph -->
<!-- wp:paragraph -->
<p>Infusión fría de cascarilla.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
HTML;
    $out = GeneratedMarkup::normalize($broken, 'fuego-sig');
    assert_true(!str_contains($out, '<!-- wp:paragraph>'), 'no HTML-shaped openers left');
    assert_true(!str_contains($out, '</wp:paragraph'), 'no HTML-shaped closers left');
    assert_contains('Cascarilla de Coyoacán', $out);
    assert_contains('Infusión fría', $out);
    assert_contains('<!-- wp:group {"layout":{"type":"constrained"}} -->', $out);
});

test('inserts missing group closer before column (Lumen self-care-toolkits shape)', function () {
    // Model closed the group DIV then jumped to /wp:column, omitting
    // <!-- /wp:group --> (and the column shell </div>).
    $broken = <<<'HTML'
<!-- wp:columns
align: wide
-->
<div class="wp-block-columns">
<!-- wp:column
width: 32%
-->
<div class="wp-block-column" style="flex-basis:32%">
<!-- wp:group
className: hover-lift
layout:
  type: constrained
-->
<div class="wp-block-group hover-lift">
<!-- wp:paragraph -->
<p>Body copy.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph
fontSize: caption
-->
<p class="has-caption-font-size"><a href="/resources/">Download the log</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column
width: 38%
-->
<div class="wp-block-column" style="flex-basis:38%">
<!-- wp:paragraph -->
<p>Next column.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
HTML;
    $out = GeneratedMarkup::normalize($broken, 'lumen-toolkits');
    // Count openers with a name boundary so "wp:columns" is not matched as "wp:column".
    preg_match_all('/<!--\s*wp:group\b/', $out, $go);
    preg_match_all('/<!--\s*\/wp:group\s*-->/', $out, $gc);
    preg_match_all('/<!--\s*wp:column\b/', $out, $co);
    preg_match_all('/<!--\s*\/wp:column\s*-->/', $out, $cc);
    assert_eq(count($go[0]), count($gc[0]), 'every group has a closer');
    assert_eq(count($co[0]), count($cc[0]), 'every column has a closer');
    assert_contains('Download the log', $out);
    assert_contains('Next column.', $out);
});

test('inserts missing buttons wrapper div (Saltline next-step-cta shape)', function () {
    // Model opened <div class="wp-block-buttons"> then closed <!-- /wp:buttons -->
    // without </div> — assertComplete rejects non-block content in the child zone.
    $broken = <<<'HTML'
<!-- wp:group
layout:
  type: constrained
-->
<div class="wp-block-group">
<!-- wp:buttons
layout:
  type: flex
  justifyContent: center
-->
<div class="wp-block-buttons"><!-- wp:button
backgroundColor: primary
textColor: accent
-->
<div class="wp-block-button"><a class="wp-block-button__link has-accent-color has-primary-background-color" href="/contact/">Lock in your dates</a></div>
<!-- /wp:button -->

<!-- wp:button
className: is-style-outline
textColor: contrast
-->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-contrast-color" href="/life-aboard/">See life aboard</a></div>
<!-- /wp:button -->
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
HTML;
    $out = GeneratedMarkup::normalize($broken, 'saltline-cta');
    assert_contains('Lock in your dates', $out);
    assert_contains('See life aboard', $out);
    assert_true(
        (bool) preg_match('/<\/div>\s*<!--\s*\/wp:buttons\s*-->/', $out),
        'buttons shell </div> must precede /wp:buttons',
    );
    BlockDocumentRecovery::assertComplete($out);
});
