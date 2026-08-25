<?php
declare(strict_types=1);

use Automattic\SiteBuild\DirectionExecutability;

/** A direction carrying one narrative. */
function de_direction(string $description, string $device = 'hairline-rule'): array
{
    return ['description' => $description, 'device' => $device];
}

test('a narrative promising hand-drawn ornament is reported as a delivered defect', function () {
    // tbilisi4's real direction (BIGR-884). The build ships one hairline rule;
    // the brief promised a lattice, tendrils and rosettes used as band
    // separators, list bullets and a repeating border strip.
    $problems = DirectionExecutability::problems(de_direction(
        'The page is set on a warm, undyed linen ground — #EFE3CF. Across it runs a quiet '
        . 'lattice of hand-drawn ornament borrowed from Kakhetian textile borders and the '
        . 'carved wooden balconies of Old Town: thin zigzag chains, grape-leaf tendrils, and '
        . 'small eight-point rosettes drawn at hairline weight in a soft clay wash, used '
        . 'sparingly as band separators, list bullets, and a repeating border strip.'
    ));

    assert_eq(1, count($problems), 'one actionable row, not one per phrase');
    $row = $problems[0];
    assert_contains("file='designDirection.json'", $row);
    assert_contains('path="description"', $row);
    assert_contains('delivered=not executed', $row);
    // The row must name what the build CAN draw, so it is actionable alone.
    assert_contains('hairline-rule', $row);
    assert_contains('section-numeral', $row);
    assert_contains('stamp', $row);
    // And quote the narrative, so the promise is locatable.
    assert_contains('hand-drawn ornament', $row);
    assert_contains('tendrils', $row);
});

test('a direction with no device says so in the warning', function () {
    $row = DirectionExecutability::problems(de_direction(
        'Delicate filigree runs along every band edge.',
        'none',
    ))[0];
    assert_contains('committed no device', $row);
});

test('a narrative that rules decoration OUT is not a promise', function () {
    // The single largest false-positive source across the cohort: a direction
    // promising LESS decoration is describing exactly what the build ships.
    foreach ([
        'Absolutely no gradients, no glow, no ornament beyond the rule and the mono numerals.',
        'The accent is never used for running text or ornament.',
        'Ornament comes from the loom, not from clip art.',
        'Restrained throughout: no ornate flourishes, no borrowed pattern tiles.',
        'Type carries the heritage rather than illustrated motifs.',
        'The palette is used without any hand-drawn ornament at all.',
    ] as $description) {
        assert_eq([], DirectionExecutability::problems(de_direction($description)), $description);
    }
});

test('ordinary narrative language is never flagged', function () {
    // Every one of these is real text from the delivered demo cohort. A
    // warning nobody can act on is worse than no warning.
    foreach ([
        // Colour metaphors — the dominant register of these narratives.
        'a dense blue-grey that reads as stone rather than black',
        'the colour of wet granite and steel proofing racks, not black',
        'a cream that reads as printed paper warmed by a CRT',
        'the color of a fixer tray under a safelight',
        // Words that name something the build DOES ship.
        'used for rules, section labels, small-caps eyebrows, and thin architectural frames',
        'hairline dividers and the thin scanline rules that separate bands',
        'used for icon strokes, section eyebrows, key numerals, active states',
        'carrying section labels, hairline dividers, glyph clusters',
        'edges of panels are drawn with 1px walnut rules rather than shadows',
        // "drawn from" — the most common innocuous use of the bare verb.
        'a fired brick-clay red taken from qvevri-earth, drawn from churchkhela dye',
        'two structural hues drawn from the cream ground',
        // A photographic subject, not a page element.
        'a Copenhagen studio hand-blowing table lamps from reclaimed glass',
        'dish blocks set in unequal columns so the eye zigzags',
    ] as $description) {
        assert_eq([], DirectionExecutability::problems(de_direction($description)), $description);
    }
});

test('a bare ornament noun needs no qualifier, a generic noun does', function () {
    // Nouns with no innocuous reading stand alone.
    foreach (['filigree', 'rosettes', 'scrollwork', 'a damask repeat', 'woodcut marks'] as $phrase) {
        assert_true(
            DirectionExecutability::problems(de_direction("Bands carry {$phrase} at the seam.")) !== [],
            $phrase
        );
    }
    // Generic nouns are only a promise once something says they were drawn.
    assert_eq([], DirectionExecutability::problems(de_direction('Thin rules separate the bands.')));
    assert_true(
        DirectionExecutability::problems(de_direction('Hand-drawn rules separate the bands.')) !== [],
        'the same noun, now promised as artwork'
    );
});

test('a restraint in an earlier clause cannot excuse a later promise', function () {
    $problems = DirectionExecutability::problems(de_direction(
        'There are no gradients anywhere. Every band edge carries hand-drawn botanical motifs.'
    ));
    assert_true($problems !== [], 'the promise in the second sentence still counts');
});

test('an empty or absent narrative is silent', function () {
    assert_eq([], DirectionExecutability::problems([]));
    assert_eq([], DirectionExecutability::problems(de_direction('')));
    assert_eq([], DirectionExecutability::problems(de_direction('   ')));
});

test('findings are de-duplicated, ordered and quoted with context', function () {
    // A repeated ornament noun is one promise, not three.
    assert_eq(
        1,
        count(DirectionExecutability::findings(
            'Filigree opens the page, then more filigree, then filigree again.'
        )),
        'the repeated noun is reported once'
    );

    // Two distinct promises are both reported, in the order they appear.
    $found = DirectionExecutability::findings(
        'A quiet lattice of hand-drawn ornament opens the page, and rosettes close it.'
    );
    assert_eq(2, count($found), 'both distinct promises are reported');
    assert_true(
        strpos($found[0], 'hand-drawn') !== false,
        'the earlier promise comes first'
    );
    foreach ($found as $row) {
        assert_true(str_ends_with($row, '…'), 'each finding is an excerpt');
        assert_true(strlen($row) < 140, 'and a short one');
    }
});
