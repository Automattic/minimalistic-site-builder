<?php
declare(strict_types=1);

use Automattic\SiteBuild\DirectionExecutability;

/** A direction carrying one narrative. */
function de_direction(string $description, string $device = 'hairline-rule'): array
{
    return ['description' => $description, 'device' => $device];
}

/** Every delivered demo direction, so the cohort result is locked by a test. */
function de_cohort(): array
{
    $directions = [];
    foreach (glob(repo_path('projects') . '/*/designDirection.json') ?: [] as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded) && is_string($decoded['description'] ?? null)) {
            $directions[basename(dirname($file))] = $decoded;
        }
    }
    ksort($directions);
    return $directions;
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

    assert_eq(1, count($problems), 'one actionable row');
    $row = $problems[0];
    assert_contains("file='designDirection.json'", $row);
    assert_contains('path="description"', $row);
    assert_contains('delivered=not executed', $row);
    // The row must name what the build CAN draw, so it is actionable alone.
    assert_contains('hairline-rule', $row);
    assert_contains('section-numeral', $row);
    assert_contains('stamp', $row);
    assert_contains('hand-drawn ornament', $row);
});

test('a direction with no device says so in the warning', function () {
    $row = DirectionExecutability::problems(de_direction(
        'Delicate filigree runs along every band edge.',
        'none',
    ))[0];
    assert_contains('committed no device', $row);
});

test('a narrative that NEGATES decoration is not a promise', function () {
    // The single largest false-positive source across the cohort: a direction
    // promising LESS decoration is describing exactly what the build ships.
    foreach ([
        'Absolutely no gradients, no glow, no ornament beyond the rule and the mono numerals.',
        'The accent is never used for running text or ornament.',
        'Type carries the heritage rather than illustrated motifs.',
        'The palette is used without any hand-drawn ornament at all.',
        'Ornament comes from the loom, and not from clip art.',
        'There is nothing engraved and no filigree anywhere.',
    ] as $description) {
        assert_eq([], DirectionExecutability::problems(de_direction($description)), $description);
    }
});

test('a HEDGE is not a negation — a softened promise is still a promise', function () {
    // Regression: an earlier version treated `sparingly`/`restrained`/`only`
    // as restraint. tbilisi4's own text is "used sparingly as band separators",
    // so it was caught only because that word happened to fall after the noun
    // instead of before it. Luck, not design.
    foreach ([
        'Ornament is used sparingly, as hand-drawn rosettes at each band seam.',
        'The page is restrained, but every band edge carries hand-drawn botanical motifs.',
        'Everything is flat except where hand-drawn tendrils frame the menu.',
        'Nothing is glossy, and hand-drawn rosettes mark every band.',
        'A quiet, subtle filigree runs along the seam.',
    ] as $description) {
        assert_true(
            DirectionExecutability::problems(de_direction($description)) !== [],
            "still a promise: {$description}"
        );
    }
});

test('a negation is scoped to its own clause, not the whole sentence', function () {
    // These narratives set up a contrast and then make the promise on the far
    // side of a colon. Reading the whole sentence let that `never`/`not`
    // cancel a promise it has nothing to do with, which hid three real cases.
    foreach ([
        'Ornament is structural, never sprinkled: flat vector blocks of Georgian '
            . 'borjgali rosettes and manuscript marginal knotwork.',
        // tbilisi3, verbatim.
        'Ornament comes from the loom, not from clip art: a narrow repeating band of '
            . 'Georgian carpet geometry (zigzag chevrons, stepped diamonds, tiny hooked '
            . 'crosses) rendered as a 24px-tall wine-on-parchment strip separates major '
            . 'sections, and section numbers sit inside a small wax-seal roundel.',
    ] as $description) {
        assert_true(
            DirectionExecutability::problems(de_direction($description)) !== [],
            "the promise after the colon still counts: {$description}"
        );
    }
});

test('a restraint in an earlier SENTENCE cannot hide a later promise', function () {
    // Regression: the bare-noun scan used to look at only the FIRST occurrence
    // of each noun, so an occurrence inside a restraint clause made every
    // later occurrence invisible.
    assert_true(
        DirectionExecutability::problems(de_direction(
            'There is no filigree in the header. Every single band carries filigree at the seam.'
        )) !== [],
        'the second sentence is still a promise'
    );
    assert_true(
        DirectionExecutability::problems(de_direction(
            'There are no gradients anywhere. Every band edge carries hand-drawn botanical motifs.'
        )) !== [],
        'the same, through the qualified-noun rule'
    );
});

test('ordinary narrative language is never flagged', function () {
    // Every one of these is real text from the delivered demo cohort, or one
    // edit away from it. A warning nobody can act on is worse than none.
    foreach ([
        // Colour metaphors — the dominant register of these narratives.
        'a dense blue-grey that reads as stone rather than black',
        'the colour of wet granite and steel proofing racks, not black',
        'a cream that reads as printed paper warmed by a CRT',
        // Words naming something the build DOES ship.
        'used for rules, section labels, small-caps eyebrows, and thin architectural frames',
        'hairline dividers and the thin scanline rules that separate bands',
        'used for icon strokes, section eyebrows, key numerals, active states',
        'carrying section labels, hairline dividers, glyph clusters',
        'edges of panels are drawn with 1px walnut rules rather than shadows',
        'Ornament is restrained and earned: thin double rules in bronze-olive above section titles.',
        // "drawn from" — the commonest innocuous use of the bare verb.
        'a fired brick-clay red taken from qvevri-earth, drawn from churchkhela dye',
        // A photographic subject or a type description, not a page element.
        'a Copenhagen studio hand-blowing table lamps from reclaimed glass',
        'Photographs of hand-painted shopfronts line the alternating bands.',
        'Headlines feel like a hand-painted bakery transom, set in bands of warm mass.',
        'Cards are framed, echoing the hand-painted signs, with a 1px border on each frame.',
    ] as $description) {
        assert_eq([], DirectionExecutability::problems(de_direction($description)), $description);
    }
});

test('a bare ornament noun stands alone; a generic one needs a qualifier or a placement', function () {
    foreach (['filigree', 'rosettes', 'scrollwork', 'a damask repeat', 'knotwork'] as $phrase) {
        assert_true(
            DirectionExecutability::problems(de_direction("Bands carry {$phrase} at the seam.")) !== [],
            $phrase
        );
    }
    // A decoration noun on its own is not a commitment to render one.
    assert_eq([], DirectionExecutability::problems(de_direction(
        'The tradition is ornamental and warm.'
    )));
    assert_true(
        DirectionExecutability::problems(de_direction(
            'A hand-drawn motif opens each band.'
        )) !== [],
        'made by hand'
    );
    assert_true(
        DirectionExecutability::problems(de_direction(
            'A grapevine motif is used as a section divider.'
        )) !== [],
        'placed on the page'
    );
});

test('hyphen compounding does not hide an ornament noun', function () {
    // tbilisi's "a repeating grapevine-and-tendril fret" slipped through a
    // lookbehind that forbade a preceding hyphen.
    assert_true(
        DirectionExecutability::problems(de_direction(
            'A repeating grapevine-and-tendril fret divides the major sections.'
        )) !== [],
        'compounded noun still matches'
    );
});

test('evidence survives multibyte narrative text', function () {
    // Regression: excerpts were cut on BYTE boundaries and then passed through
    // preg_replace('//u'), which returns null on invalid UTF-8 — silently
    // replacing the whole evidence string with nothing. These narratives are
    // full of em dashes and curly quotes.
    for ($pad = 25; $pad <= 40; $pad++) {
        $description = str_repeat('z', $pad) . '— ' . str_repeat('w', $pad)
            . ' filigree edges every band of the page.';
        $found = DirectionExecutability::findings($description);
        assert_eq(1, count($found), "pad {$pad}");
        assert_contains('filigree', $found[0], "evidence survives at pad {$pad}");
    }
});

test('one sentence is one finding, however many ornament words it holds', function () {
    $found = DirectionExecutability::findings(
        'A lattice of hand-drawn ornament with filigree and rosettes opens the page.'
    );
    assert_eq(1, count($found), 'not one window per matched word');

    $two = DirectionExecutability::findings(
        'Filigree opens the page. Rosettes close it.'
    );
    assert_eq(2, count($two), 'two sentences, two findings');
    assert_contains('Filigree', $two[0], 'in source order');
});

test('an empty or absent narrative is silent', function () {
    assert_eq([], DirectionExecutability::problems([]));
    assert_eq([], DirectionExecutability::problems(de_direction('')));
    assert_eq([], DirectionExecutability::problems(de_direction('   ')));
});

test('the delivered demo cohort holds its measured verdict', function () {
    // Without this the tuning can silently regress: the whole value of this
    // class is its precision/recall on real generated narratives, and no
    // synthetic case measures that. Update the lists deliberately, never by
    // accident.
    $cohort = de_cohort();
    if ($cohort === []) {
        return; // a checkout with no built demos has nothing to measure
    }

    $expected = [
        'naturaleza3' => true,  // hand-drawn botanical illustrations off band edges
        'pulso3'      => true,  // surreal ornament, palm silhouettes as vector outlines
        'tbilisi'     => true,  // ornamental motif, grapevine-and-tendril fret
        'tbilisi2'    => true,  // grapevine motif used as a section divider
        'tbilisi3'    => true,  // Georgian carpet geometry as a 24px strip
        'tbilisi4'    => true,  // lattice of hand-drawn ornament, tendrils, rosettes
        'tbilisi5'    => true,  // borjgali rosettes, interlace, manuscript knotwork
    ];

    foreach ($cohort as $slug => $direction) {
        $flagged = DirectionExecutability::problems($direction) !== [];
        $want = $expected[$slug] ?? false;
        assert_eq($want, $flagged, ($want ? 'expected a promise in ' : 'expected NO promise in ') . $slug);
    }

    foreach (array_keys($expected) as $slug) {
        assert_true(isset($cohort[$slug]), "cohort is missing {$slug}");
        // Exactly one row per project: the operative sentence, not three
        // overlapping windows onto the same clause.
        assert_eq(1, count(DirectionExecutability::problems($cohort[$slug])), "one row for {$slug}");
    }
});
