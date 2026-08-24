<?php
declare(strict_types=1);

use Automattic\SiteBuild\FontCatalog;
use Automattic\SiteBuild\FontMonoculture;
use Automattic\SiteBuild\Steps\DesignDirectionStep;

function monoculture_catalog(): FontCatalog
{
    static $catalog = null;
    return $catalog ??= FontCatalog::load(repo_path('data/google-fonts/catalog.json'));
}

test('every curated replacement resolves in the shipped catalog', function () {
    // A substitution naming a family the build cannot fetch is worse than the
    // monoculture face it replaced. This is what keeps the hand-picked shelf
    // honest when the catalog is regenerated.
    $catalog = monoculture_catalog();
    $missing = [];
    foreach (FontMonoculture::pool() as $category => $names) {
        foreach ($names as $name) {
            if ($catalog->resolve($name) === null) {
                $missing[] = "{$category}/{$name}";
            }
        }
    }
    assert_eq([], $missing, 'every pooled family is in the catalog');
});

test('no curated replacement is itself a monoculture face', function () {
    foreach (FontMonoculture::pool() as $category => $names) {
        foreach ($names as $name) {
            assert_true(
                !FontMonoculture::isOverused($name),
                "{$name} would substitute one default for another",
            );
        }
    }
});

test('the overused list covers this pipeline own reflexes and the wider monoculture', function () {
    // Ours, measured across 128 builds.
    foreach (['Archivo', 'Archivo Black', 'Playfair Display', 'Cormorant Garamond', 'Fraunces'] as $mine) {
        assert_true(FontMonoculture::isOverused($mine), "{$mine} is one of ours");
    }
    // The one that got through when the list was only ours: naming five faces
    // in the prompt moved the very next round onto Space Grotesk twice.
    assert_true(FontMonoculture::isOverused('Space Grotesk'), 'the face that escaped the first list');
    foreach (['Inter', 'Instrument Serif', 'Geist', 'Plus Jakarta Sans', 'Mona Sans'] as $theirs) {
        assert_true(FontMonoculture::isOverused($theirs), "{$theirs} is on the wider list");
    }
    assert_true(!FontMonoculture::isOverused('Chivo'), 'a face nobody over-reaches for passes');
    assert_true(FontMonoculture::isOverused('  sPaCe GrOtEsK  '), 'matching is case and space insensitive');
});

test('a substitution keeps the family category', function () {
    $catalog = monoculture_catalog();
    // Serif in, serif out — the point is to leave the monoculture, not to
    // overrule what the direction asked for.
    $serif = FontMonoculture::substitute('Playfair Display', 'a-site', $catalog);
    assert_true($serif !== null);
    assert_true(
        in_array($serif, FontMonoculture::pool()['serif'], true),
        "{$serif} came from the serif shelf",
    );

    $sans = FontMonoculture::substitute('Space Grotesk', 'a-site', $catalog);
    assert_true(
        in_array($sans, FontMonoculture::pool()['sans-serif'], true),
        "{$sans} came from the sans shelf",
    );
});

test('a display face never reaches a body slot', function () {
    // Anton at 16px is unreadable body copy; substituting one in would trade a
    // dull page for a broken one.
    $catalog = monoculture_catalog();
    $displayOnly = ['Anton', 'Bebas Neue', 'Oswald', 'Yeseva One', 'Abril Fatface', 'Alfa Slab One'];
    $leaks = [];
    foreach (['Space Grotesk', 'Archivo', 'Inter', 'Roboto', 'Montserrat', 'Playfair Display'] as $family) {
        for ($i = 0; $i < 40; $i++) {
            $got = FontMonoculture::substitute($family, "seed{$i}", $catalog, 'body');
            if ($got !== null && in_array($got, $displayOnly, true)) {
                $leaks[] = "{$family}/seed{$i} => {$got}";
            }
        }
    }
    assert_eq([], $leaks, 'no display face reached a body slot across 240 seeds');
});

test('substitution is deterministic per site but varies between sites', function () {
    // Reproducible builds need the same seed to give the same answer; avoiding
    // a NEW monoculture needs different sites to land differently.
    $catalog = monoculture_catalog();
    $a1 = FontMonoculture::substitute('Space Grotesk', 'climate-care-blog', $catalog);
    $a2 = FontMonoculture::substitute('Space Grotesk', 'climate-care-blog', $catalog);
    assert_eq($a1, $a2, 'one site rebuilds identically');

    $seen = [];
    foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta'] as $site) {
        $seen[FontMonoculture::substitute('Space Grotesk', $site, $catalog)] = true;
    }
    assert_true(count($seen) > 1, 'one overused face does not map onto one replacement');
});

test('a family nobody over-reaches for is left alone', function () {
    $catalog = monoculture_catalog();
    assert_eq(null, FontMonoculture::substitute('Spectral', 'x', $catalog));
    assert_eq(null, FontMonoculture::substitute('', 'x', $catalog));
});

test('the direction pass swaps the family and drops the optical-size axis', function () {
    // `axes` committed an opsz range for the family the model named. Carrying
    // it onto a different face promises an axis the replacement may not have.
    $warnings = [];
    $direction = DesignDirectionStep::substituteMonocultureFonts(
        [
            'type' => [
                'heading' => ['family' => 'Space Grotesk', 'weights' => [400, 700], 'axes' => ['opsz' => ['min' => 9, 'max' => 144]]],
                'body'    => ['family' => 'Chivo', 'weights' => [400], 'axes' => []],
                'accent'  => ['family' => '', 'weights' => [], 'axes' => []],
            ],
        ],
        'bicycle-store',
        monoculture_catalog(),
        $warnings,
    );

    assert_true($direction['type']['heading']['family'] !== 'Space Grotesk', 'the monoculture face is gone');
    assert_eq([], $direction['type']['heading']['axes'], 'the opsz range did not follow');
    assert_eq([400, 700], $direction['type']['heading']['weights'], 'weights survive — faces() resolves the nearest');
    assert_eq('Chivo', $direction['type']['body']['family'], 'a clean slot is untouched');
    assert_eq('', $direction['type']['accent']['family'], 'an empty slot is untouched');

    assert_eq(1, count($warnings), 'one swap, one durable record');
    assert_contains('type.heading.family', $warnings[0]);
    assert_contains('Space Grotesk', $warnings[0]);
});
