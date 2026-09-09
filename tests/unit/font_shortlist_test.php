<?php
declare(strict_types=1);

use Automattic\SiteBuild\ConceptSeeds;
use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\FontMonoculture;
use Automattic\SiteBuild\FontShortlist;

test('every shelved shortlist family resolves in the shipped catalog', function () {
    $catalog = FontCatalog::load();
    $missing = [];
    foreach (FontShortlist::shelves() as $register => $names) {
        foreach ($names as $name) {
            if ($catalog->resolve($name) === null) {
                $missing[] = "{$register}/{$name}";
            }
        }
    }
    assert_eq([], $missing, 'every shelved family is in the catalog');
});

test('every letterform tradition has a shelf deep enough to sample from', function () {
    $catalog = FontCatalog::load();
    foreach (ConceptSeeds::TYPE_REGISTERS as $register) {
        $candidates = FontShortlist::candidates($register, 'depth-check', $catalog);
        assert_eq(FontShortlist::SAMPLE, count($candidates), "{$register} yields a full sample");
        assert_eq($candidates, array_unique($candidates), "{$register} sample has no duplicate");
        foreach ($candidates as $name) {
            assert_true(!FontMonoculture::isOverused($name), "{$name} is not a monoculture face");
        }
    }
});

test('the shortlist sample is deterministic per site and rotates between sites', function () {
    $catalog = FontCatalog::load();
    $one = FontShortlist::candidates('slab', 'tbilisi', $catalog);
    assert_eq($one, FontShortlist::candidates('slab', 'tbilisi', $catalog), 'same site, same sample');

    // With SAMPLE consecutive names from a longer shelf, at least one other
    // identifier must see a different window — otherwise there is no
    // rotation and the shortlist is a new fixed anchor.
    $rotated = false;
    foreach (['atlas', 'hearth', 'lumen', 'pulso', 'portfolio'] as $other) {
        if (FontShortlist::candidates('slab', $other, $catalog) !== $one) {
            $rotated = true;
            break;
        }
    }
    assert_true($rotated, 'different sites see different windows');
});

test('the prompt paragraph names the candidates and stays silent for a degraded seed', function () {
    $catalog = FontCatalog::load();
    $paragraph = FontShortlist::promptParagraph('didone', 'tbilisi', $catalog);
    foreach (FontShortlist::candidates('didone', 'tbilisi', $catalog) as $name) {
        assert_contains($name, $paragraph);
    }
    assert_contains('pick from it, or go beyond it', $paragraph);
    assert_eq('', FontShortlist::promptParagraph('', 'tbilisi', $catalog));
    assert_eq('', FontShortlist::promptParagraph('cursive-futurist', 'tbilisi', $catalog));
});

test('a product tradition in a sans letterform asks for medium display weight and tight tracking (frm W5b)', function () {
    $catalog = FontCatalog::load();
    foreach (FontShortlist::PRODUCT_REGISTERS as $register) {
        foreach (FontShortlist::PRODUCT_TYPE_REGISTERS as $typeRegister) {
            $paragraph = FontShortlist::promptParagraph($typeRegister, 'zova', $catalog, $register);
            assert_contains('MEDIUM weight', $paragraph, "{$register}/{$typeRegister}");
            assert_contains('`type_treatment: "tight"`', $paragraph);
        }
    }
    assert_true(!str_contains(FontShortlist::promptParagraph('grotesque', 'zova', $catalog, 'heritage'), 'MEDIUM weight'), 'a heritage concept keeps its own weight');
    assert_true(!str_contains(FontShortlist::promptParagraph('didone', 'zova', $catalog, 'modernist'), 'MEDIUM weight'), 'a serif tradition keeps its own weight');
    assert_true(!str_contains(FontShortlist::promptParagraph('grotesque', 'zova', $catalog), 'MEDIUM weight'), 'the default register adds nothing');

    // A condensed grotesque is the same product voice at a narrower width. A
    // generated modernist portfolio landed on Encode Sans Condensed, missed the
    // sentence, and shipped uppercase display type at weight 700.
    assert_contains('condensed', implode(' ', FontShortlist::PRODUCT_TYPE_REGISTERS));
    $condensed = FontShortlist::promptParagraph('condensed', 'ovest', $catalog, 'modernist');
    assert_contains('MEDIUM weight', $condensed);
    assert_contains('do not reach for uppercase', $condensed, 'the caps reflex is named for a narrow face');
    assert_contains('weight 700 or 800', $condensed, '700 was the delivered weight, so the rule must name it');
    // The caps traditions keep their own voice: the register list still gates it.
    foreach (['poster', 'brutalist', 'heritage', 'editorial', 'archival'] as $register) {
        assert_true(
            !str_contains(FontShortlist::promptParagraph('condensed', 'ovest', $catalog, $register), 'MEDIUM weight'),
            "a {$register} concept keeps its own condensed weight",
        );
    }
    // Serif and mono traditions stay outside the rule under every product register.
    foreach (['didone', 'slab', 'humanist', 'transitional', 'mono', 'script', 'display-serif'] as $typeRegister) {
        foreach (FontShortlist::PRODUCT_REGISTERS as $register) {
            assert_true(
                !str_contains(FontShortlist::promptParagraph($typeRegister, 'zova', $catalog, $register), 'MEDIUM weight'),
                "{$register}/{$typeRegister} keeps its own weight",
            );
        }
    }

    // The reference-corpus grotesks and geometrics that are not monoculture faces are shelved.
    $shelves = FontShortlist::shelves();
    foreach (['Inter Tight', 'Albert Sans', 'Be Vietnam Pro', 'Host Grotesk'] as $name) {
        assert_true(in_array($name, $shelves['grotesque'], true), "{$name} on the grotesque shelf");
    }
    foreach (['Funnel Sans', 'Funnel Display'] as $name) {
        assert_true(in_array($name, $shelves['geometric'], true), "{$name} on the geometric shelf");
    }
    foreach (['Instrument Sans', 'Geist', 'Plus Jakarta Sans'] as $name) {
        assert_true(!in_array($name, $shelves['grotesque'], true), "{$name} stays off the shelf: monoculture list");
    }
});
