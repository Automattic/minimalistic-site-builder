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
