<?php
declare(strict_types=1);

use Automattic\SiteBuild\FontMonoculture;

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
