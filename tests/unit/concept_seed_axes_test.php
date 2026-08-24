<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConceptSeeds;

test('utilitarian is off the universal list, so the random pick cannot land on it', function () {
    // BIGR-871: the pick is uniform, so every word on this list wins a share of
    // builds whose brief never asked for it. A no-frills world is the one that
    // reads as a failure rather than a choice.
    assert_true(
        !in_array('utilitarian', ConceptSeeds::REGISTERS, true),
        'utilitarian is not a register the seed model may reach for unprompted',
    );
    $seed = ConceptSeeds::normalize(['seed' => 'Plain Work', 'register' => 'utilitarian']);
    assert_eq(null, $seed['register'], 'an unasked-for utilitarian reads as no register at all');
});

test('a brief that asks for utilitarian still gets it', function () {
    // The ticket's second half: retire it from the rotation, keep it available
    // when the user names it.
    foreach (['a utilitarian parts catalogue', 'a no-frills tool shop', 'something practical and plain'] as $brief) {
        assert_true(
            in_array('utilitarian', ConceptSeeds::lockedFromBrief($brief)['registers'], true),
            "the brief \"{$brief}\" unlocks utilitarian",
        );
    }
    assert_eq(
        [],
        ConceptSeeds::lockedFromBrief('a bakery website')['registers'],
        'and a brief that did not ask stays clean',
    );

    $seed = ConceptSeeds::normalize(
        ['seed' => 'Plain Work', 'register' => 'utilitarian'],
        ConceptSeeds::lockedFromBrief('a utilitarian parts catalogue'),
    );
    assert_eq('utilitarian', $seed['register'], 'the unlocked word is a first-class register again');
});

test('retiring utilitarian did not make utilitarian seeds undedupable', function () {
    // The trap in the obvious fix. Deleting the word outright would send it
    // through axis() as out-of-vocabulary, which nulls the register, which
    // nulls axisKey(), which exempts the seed from the triple check entirely —
    // making a repeated utilitarian world HARDER to drop, not easier. Moving it
    // to the locked extras keeps the coordinate real whenever it is in play.
    $locked = ConceptSeeds::lockedFromBrief('a no-frills utilitarian workshop');
    $a = ConceptSeeds::normalize(['seed' => 'Bench One', 'ground' => 'light', 'register' => 'utilitarian', 'accent' => 'neutral'], $locked);
    $b = ConceptSeeds::normalize(['seed' => 'Bench Two', 'ground' => 'light', 'register' => 'utilitarian', 'accent' => 'neutral'], $locked);
    $c = ConceptSeeds::normalize(['seed' => 'Ink Room', 'ground' => 'dark', 'register' => 'editorial', 'accent' => 'jewel'], $locked);

    assert_true($a['register'] !== null, 'the locked word produces a real coordinate');
    assert_eq(ConceptSeeds::axisKey($a), ConceptSeeds::axisKey($b), 'two utilitarian worlds share one key');

    $warnings = [];
    $pool = ConceptSeeds::distinct([$a, $b, $c], $warnings);
    assert_eq(2, count($pool), 'the repeated utilitarian world is dropped from the pick');
    assert_eq(1, count($warnings), 'and the drop is recorded');
});

test('art-deco and brutalist are proposable without the brief naming them', function () {
    // They were brief-locked extras. A designer proposes both unprompted, and
    // holding them back cost the spread two of its least reflexive worlds.
    foreach (['art-deco', 'brutalist'] as $promoted) {
        assert_true(in_array($promoted, ConceptSeeds::REGISTERS, true), "{$promoted} is universal now");
        assert_eq(
            $promoted,
            ConceptSeeds::normalize(['seed' => 'S', 'register' => $promoted])['register'],
            "{$promoted} reads without a locked brief",
        );
    }
    // Promotion must not have left a duplicate behind: a word in both lists
    // would show up twice in the prompt paragraph the brief builds.
    $locked = ConceptSeeds::lockedFromBrief('a brutalist art deco luxury site');
    assert_eq(['luxury'], $locked['registers'], 'only the still-extra word locks');
});

test('the register vocabulary spans more than the four quiet traditions', function () {
    // The audited collapse was not one bad word; it was a five-word list whose
    // usable half all described restraint.
    foreach (['heritage', 'modernist', 'editorial', 'expressive'] as $original) {
        assert_true(in_array($original, ConceptSeeds::REGISTERS, true), "{$original} survived the expansion");
    }
    assert_true(count(ConceptSeeds::REGISTERS) >= 12, 'the spread has room to diverge');
    assert_eq(
        count(ConceptSeeds::REGISTERS),
        count(array_unique(ConceptSeeds::REGISTERS)),
        'no word appears twice',
    );
});

test('ConceptSeeds::normalize reads the type register the seed sets its lettering in', function () {
    $seed = ConceptSeeds::normalize([
        'seed'          => 'Ink & Brass — a deep blue reading room.',
        'type_register' => ' Didone ',
    ]);
    assert_eq('didone', $seed['type_register'], 'type_register is case and space insensitive like the other axes');

    foreach (ConceptSeeds::TYPE_REGISTERS as $family) {
        $read = ConceptSeeds::normalize(['seed' => 'S', 'type_register' => $family]);
        assert_eq($family, $read['type_register'], "{$family} is a first-class letterform tradition");
    }

    assert_eq(
        'display-serif',
        ConceptSeeds::normalize(['seed' => 'S', 'type_register' => 'display serif'])['type_register'],
        'a spaced spelling normalises onto the hyphenated word',
    );
});

test('ConceptSeeds::normalize drops a type register outside the vocabulary and says nothing', function () {
    $seed = ConceptSeeds::normalize(['seed' => 'Sea Glass', 'type_register' => 'squiggly']);
    assert_eq(null, $seed['type_register']);
    assert_eq(null, ConceptSeeds::normalize('Sea Glass')['type_register'], 'a bare string commits no type register');
});

test('ConceptSeeds::axisKey leaves type_register out, so the dedup key stays the triple', function () {
    // Same reasoning as tint: a fifth coordinate would make two seeds look
    // distinct more easily and quietly weaken the collapse guard.
    $a = ConceptSeeds::normalize(['seed' => 'One', 'ground' => 'light', 'register' => 'editorial', 'accent' => 'warm', 'type_register' => 'didone']);
    $b = ConceptSeeds::normalize(['seed' => 'Two', 'ground' => 'light', 'register' => 'editorial', 'accent' => 'warm', 'type_register' => 'grotesque']);
    assert_eq(ConceptSeeds::axisKey($a), ConceptSeeds::axisKey($b));

    $warnings = [];
    assert_eq(2, count(ConceptSeeds::distinct([$a, $b], $warnings)), 'a pool of two is never narrowed to one');
});
