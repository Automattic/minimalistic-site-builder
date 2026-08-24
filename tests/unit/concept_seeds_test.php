<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConceptSeeds;

/** @return array{text:string,ground:?string,register:?string,accent:?string} */
function concept_seed_at(string $title, ?string $ground, ?string $register, ?string $accent): array
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
        concept_seed_at('Hearth Light — a warm heritage bakery.', 'light', 'heritage', 'warm'),
        concept_seed_at('Copper Morning — a warm heritage bakery.', 'light', 'heritage', 'warm'),
        concept_seed_at('Night Kitchen — a dark modernist counter.', 'dark', 'modernist', 'cool'),
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
        concept_seed_at('One', 'dark', 'editorial', 'jewel'),
        concept_seed_at('Two', 'dark', 'editorial', 'jewel'),
        concept_seed_at('Three', 'dark', 'editorial', 'jewel'),
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
        concept_seed_at('One', 'light', 'heritage', 'warm'),
        concept_seed_at('Two', 'light', 'heritage', null),
        concept_seed_at('Three', null, null, null),
    ];
    $warnings = [];
    assert_eq(3, count(ConceptSeeds::distinct($seeds, $warnings)));
    assert_eq([], $warnings);
});

test('ConceptSeeds::sharedGround reports a round that explores one half of the brief', function () {
    $light = [
        concept_seed_at('One', 'light', 'heritage', 'warm'),
        concept_seed_at('Two', 'light', 'modernist', 'cool'),
    ];
    assert_eq('light', ConceptSeeds::sharedGround($light));

    $both = [
        concept_seed_at('One', 'light', 'heritage', 'warm'),
        concept_seed_at('Two', 'dark', 'modernist', 'cool'),
    ];
    assert_eq(null, ConceptSeeds::sharedGround($both));

    assert_eq(null, ConceptSeeds::sharedGround([concept_seed_at('Alone', 'dark', 'editorial', 'jewel')]), 'one seed shares nothing');
    assert_eq(null, ConceptSeeds::sharedGround([concept_seed_at('One', null, null, null), concept_seed_at('Two', null, null, null)]));
});

test('ConceptSeeds::distinct drops a byte-identical seed even when no coordinates were named', function () {
    // A null axis key is not evidence of sameness, but the pick lands on the
    // sentence. Two copies of the same sentence are one world wearing two slots.
    $seeds = [
        concept_seed_at('Hearth Light — a warm heritage bakery.', null, null, null),
        concept_seed_at('Hearth Light — a warm heritage bakery.', null, null, null),
        concept_seed_at('Night Kitchen — a late diner.', null, null, null),
    ];
    $warnings = [];
    $pool = ConceptSeeds::distinct($seeds, $warnings);

    assert_eq(2, count($pool), 'the repeat never reaches the pick');
    assert_eq('Hearth Light — a warm heritage bakery.', $pool[0]['text']);
    assert_eq('Night Kitchen — a late diner.', $pool[1]['text']);
    assert_eq(1, count($warnings));
    assert_contains('Hearth Light', $warnings[0]);
    assert_contains('disposition dropped', $warnings[0]);
});

test('ConceptSeeds::distinct keeps three byte-identical seeds as one world', function () {
    $seeds = [
        concept_seed_at('Hearth Light — a warm heritage bakery.', null, null, null),
        concept_seed_at('Hearth Light — a warm heritage bakery.', null, null, null),
        concept_seed_at('Hearth Light — a warm heritage bakery.', null, null, null),
    ];
    $warnings = [];
    $pool = ConceptSeeds::distinct($seeds, $warnings);

    assert_eq(3, count($pool), 'the round is kept whole');
    assert_eq(1, count($warnings));
    assert_contains('describe one world', $warnings[0]);
    assert_contains('disposition tolerated', $warnings[0]);
});

test('ConceptSeeds::sharedGround does not let one named ground speak for the round', function () {
    $seeds = [
        concept_seed_at('One', 'light', 'heritage', 'warm'),
        concept_seed_at('Two', null, null, null),
        concept_seed_at('Three', null, null, null),
    ];
    assert_eq(null, ConceptSeeds::sharedGround($seeds), 'a missing ground is not a vote');
});

test('ConceptSeeds::lockedFromBrief stays empty when the user did not name a look', function () {
    $locked = ConceptSeeds::lockedFromBrief('A cozy neighborhood bakery');
    assert_eq([], $locked['registers']);
    assert_eq([], $locked['accents']);
    assert_eq('', ConceptSeeds::lockedLabelsPrompt('A cozy neighborhood bakery'));
});

test('ConceptSeeds::lockedFromBrief picks up a look the user named, not the topic cliché', function () {
    $luxury = ConceptSeeds::lockedFromBrief('A luxury bakery in Lisbon');
    assert_eq(['luxury'], $luxury['registers']);
    assert_eq([], $luxury['accents']);
    assert_contains('`"luxury"`', ConceptSeeds::lockedLabelsPrompt('A luxury bakery in Lisbon'));
    assert_contains('register', ConceptSeeds::lockedLabelsPrompt('A luxury bakery in Lisbon'));

    $pastel = ConceptSeeds::lockedFromBrief('pastel neon signage for a night cafe');
    assert_eq(['pastel', 'neon'], $pastel['accents']);

    $playful = ConceptSeeds::lockedFromBrief('Whimsical, please — a toy studio.');
    assert_eq(['playful'], $playful['registers']);

    $plain = ConceptSeeds::lockedFromBrief('a no-frills parts depot');
    assert_eq(['utilitarian'], $plain['registers']);

    // brutalist and art-deco are universal registers now (BIGR-871), so naming
    // them locks nothing extra — the seed model could already reach for them.
    assert_eq([], ConceptSeeds::lockedFromBrief('Brutalism, please — a concrete studio.')['registers']);
    assert_eq([], ConceptSeeds::lockedFromBrief('an art-deco hotel bar')['registers']);
});

test('ConceptSeeds::lockedFromBrief does not treat food-organic as a design look', function () {
    $locked = ConceptSeeds::lockedFromBrief('An organic bakery');
    assert_eq([], $locked['registers']);
    assert_eq([], $locked['accents']);
});

test('ConceptSeeds::normalize accepts a locked extra label and still drops one the brief did not name', function () {
    $locked = ['registers' => ['utilitarian'], 'accents' => ['pastel']];
    $kept = ConceptSeeds::normalize(
        ['seed' => 'Concrete Loaf', 'ground' => 'dark', 'register' => 'Utilitarian', 'accent' => 'Pastel'],
        $locked,
    );
    assert_eq('utilitarian', $kept['register']);
    assert_eq('pastel', $kept['accent']);

    $foreign = ConceptSeeds::normalize(
        ['seed' => 'Concrete Loaf', 'register' => 'utilitarian', 'accent' => 'pastel'],
    );
    assert_eq(null, $foreign['register'], 'utilitarian is not on the universal list');
    assert_eq(null, $foreign['accent']);
});

test('ConceptSeeds::distinct drops a repeat that used a locked extra label', function () {
    $seeds = [
        concept_seed_at('One', 'light', 'luxury', 'warm'),
        concept_seed_at('Two', 'light', 'luxury', 'warm'),
        concept_seed_at('Three', 'dark', 'modernist', 'cool'),
    ];
    $warnings = [];
    $pool = ConceptSeeds::distinct($seeds, $warnings);
    assert_eq(2, count($pool));
    assert_contains('light/luxury/warm', $warnings[0]);
});

test('ConceptSeeds::normalize reads the tint the seed commits its ground to', function () {
    $seed = ConceptSeeds::normalize([
        'seed'   => 'Ink & Brass — a deep blue reading room.',
        'ground' => 'dark',
        'tint'   => ' Cool ',
    ]);
    assert_eq('cool', $seed['tint'], 'tint is case and space insensitive like the other axes');

    foreach (['warm', 'cool', 'violet', 'green', 'blush', 'neutral'] as $tint) {
        $read = ConceptSeeds::normalize(['seed' => 'S', 'tint' => $tint]);
        assert_eq($tint, $read['tint'], "{$tint} is a first-class ground family");
    }
});

test('ConceptSeeds::normalize drops a tint outside the vocabulary and says nothing', function () {
    $seed = ConceptSeeds::normalize(['seed' => 'Sea Glass', 'tint' => 'chartreuse']);
    assert_eq(null, $seed['tint']);
    assert_eq(null, ConceptSeeds::normalize('Sea Glass')['tint'], 'a bare string commits no tint');
});

test('ConceptSeeds::axisKey leaves tint out, so the dedup key stays the triple', function () {
    // A fourth coordinate would make two seeds look distinct more easily and
    // quietly weaken the collapse guard. Same triple, different tint, still
    // one world.
    $a = ConceptSeeds::normalize(['seed' => 'One', 'ground' => 'light', 'register' => 'editorial', 'accent' => 'warm', 'tint' => 'warm']);
    $b = ConceptSeeds::normalize(['seed' => 'Two', 'ground' => 'light', 'register' => 'editorial', 'accent' => 'warm', 'tint' => 'cool']);
    assert_eq(ConceptSeeds::axisKey($a), ConceptSeeds::axisKey($b));

    $warnings = [];
    assert_eq(2, count(ConceptSeeds::distinct([$a, $b], $warnings)), 'a pool of two is never narrowed to one');
});
