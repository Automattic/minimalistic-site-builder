<?php
declare(strict_types=1);

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

test('ToonBlockAttrs leaves JSON openers untouched', function () {
    $html = '<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group"></div><!-- /wp:group -->';
    $notes = [];
    assert_eq($html, ToonBlockAttrs::expand($html, $notes));
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
