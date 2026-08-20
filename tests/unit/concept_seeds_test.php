<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConceptSeeds;

/** @return array{text:string,ground:?string,register:?string,accent:?string} */
function seed_at(string $title, ?string $ground, ?string $register, ?string $accent): array
{
    return ['text' => $title, 'ground' => $ground, 'register' => $register, 'accent' => $accent];
}

test('ConceptSeeds::normalize reads the object shape and its coordinates', function () {
    $seed = ConceptSeeds::normalize([
        'seed'     => ' Forge & Flame — a dark smithy of a page. ',
        'ground'   => 'Dark',
        'register' => ' heritage ',
        'accent'   => 'EARTH',
    ]);
    assert_eq('Forge & Flame — a dark smithy of a page.', $seed['text']);
    assert_eq('dark', $seed['ground'], 'axis values are case and space insensitive');
    assert_eq('heritage', $seed['register']);
    assert_eq('earth', $seed['accent']);
});

test('ConceptSeeds::normalize still accepts a bare string, with no coordinates', function () {
    $seed = ConceptSeeds::normalize(' Forge & Flame ');
    assert_eq('Forge & Flame', $seed['text']);
    assert_eq(null, $seed['ground']);
    assert_eq(null, $seed['register']);
    assert_eq(null, $seed['accent']);
});

test('ConceptSeeds::normalize drops an axis outside its vocabulary instead of trusting it', function () {
    // An invented word must never make two identical seeds look like two ideas.
    $seed = ConceptSeeds::normalize(['seed' => 'Sea Glass', 'ground' => 'twilight', 'accent' => 'pastel']);
    assert_eq('Sea Glass', $seed['text']);
    assert_eq(null, $seed['ground']);
    assert_eq(null, $seed['accent']);
});

test('ConceptSeeds::normalize rejects what carries no seed text', function () {
    assert_eq(null, ConceptSeeds::normalize('   '));
    assert_eq(null, ConceptSeeds::normalize(['ground' => 'light']));
    assert_eq(null, ConceptSeeds::normalize(['seed' => '  ']));
    assert_eq(null, ConceptSeeds::normalize(123));
    assert_eq(null, ConceptSeeds::normalize(null));
});

test('ConceptSeeds::distinct drops the seed that repeats an earlier world, and says so', function () {
    $seeds = [
        seed_at('Hearth Light — a warm heritage bakery.', 'light', 'heritage', 'warm'),
        seed_at('Copper Morning — a warm heritage bakery.', 'light', 'heritage', 'warm'),
        seed_at('Night Kitchen — a dark modernist counter.', 'dark', 'modernist', 'cool'),
    ];
    $warnings = [];
    $pool = ConceptSeeds::distinct($seeds, $warnings);

    assert_eq(2, count($pool), 'the repeat never reaches the pick');
    assert_eq('Hearth Light — a warm heritage bakery.', $pool[0]['text'], 'the first of a pair survives');
    assert_eq('Night Kitchen — a dark modernist counter.', $pool[1]['text']);
    assert_eq(1, count($warnings));
    assert_contains('Copper Morning', $warnings[0]);
    assert_contains('Hearth Light', $warnings[0], 'the warning names the twin it repeats');
    assert_contains('light/heritage/warm', $warnings[0]);
    assert_contains('disposition dropped', $warnings[0]);
});

test('ConceptSeeds::distinct keeps a round whose seeds all describe one world', function () {
    // A brief that fixes the palette, era and mood SHOULD come back as one
    // world three times: that is the model obeying the user, and narrowing to a
    // single seed would not make the round any wider.
    $seeds = [
        seed_at('One', 'dark', 'editorial', 'jewel'),
        seed_at('Two', 'dark', 'editorial', 'jewel'),
        seed_at('Three', 'dark', 'editorial', 'jewel'),
    ];
    $warnings = [];
    $pool = ConceptSeeds::distinct($seeds, $warnings);

    assert_eq(3, count($pool), 'the round is kept whole');
    assert_eq(1, count($warnings));
    assert_contains('describe one world', $warnings[0]);
    assert_contains('disposition tolerated', $warnings[0]);
});

test('ConceptSeeds::distinct never drops a seed that left a coordinate unstated', function () {
    // An unstated axis is not evidence of sameness.
    $seeds = [
        seed_at('One', 'light', 'heritage', 'warm'),
        seed_at('Two', 'light', 'heritage', null),
        seed_at('Three', null, null, null),
    ];
    $warnings = [];
    assert_eq(3, count(ConceptSeeds::distinct($seeds, $warnings)));
    assert_eq([], $warnings);
});

test('ConceptSeeds::sharedGround reports a round that explores one half of the brief', function () {
    $light = [
        seed_at('One', 'light', 'heritage', 'warm'),
        seed_at('Two', 'light', 'modernist', 'cool'),
    ];
    assert_eq('light', ConceptSeeds::sharedGround($light));

    $both = [
        seed_at('One', 'light', 'heritage', 'warm'),
        seed_at('Two', 'dark', 'modernist', 'cool'),
    ];
    assert_eq(null, ConceptSeeds::sharedGround($both));

    assert_eq(null, ConceptSeeds::sharedGround([seed_at('Alone', 'dark', 'editorial', 'jewel')]), 'one seed shares nothing');
    assert_eq(null, ConceptSeeds::sharedGround([seed_at('One', null, null, null), seed_at('Two', null, null, null)]));
});
