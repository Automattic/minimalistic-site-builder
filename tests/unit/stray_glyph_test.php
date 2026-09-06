<?php
declare(strict_types=1);

use Automattic\SiteBuild\StrayGlyph;
use Automattic\SiteBuild\Steps\SectionsStep;

test('a lone glyph from a script the site language does not use leaves the delivered copy (frm PR-9b)', function () {
    $markup = '<h2 class="wp-block-heading">Ink, grid and the long北 light of Amsterdam</h2><p>Ten years in Tokyo (東京) and one α test.</p>';
    $out = StrayGlyph::strip($markup, 'en');
    assert_eq('<h2 class="wp-block-heading">Ink, grid and the long light of Amsterdam</h2><p>Ten years in Tokyo (東京) and one  test.</p>', $out['markup']);
    assert_eq(2, $out['removed'], 'the CJK glyph and the lone Greek letter; the two-character place name stays');

    assert_eq(0, StrayGlyph::strip($markup, 'ja')['removed'], 'a non-Latin site is left alone');
    assert_eq(0, StrayGlyph::strip($markup, "the SITE SPEC's own language")['removed'], 'an unknown language is left alone');
    assert_eq(0, StrayGlyph::strip('<p class="has-text-align-center">Plain copy, no slip.</p>', 'en-GB')['removed']);
    assert_eq(0, StrayGlyph::strip('<p class="project-grid__tags">Identity · Editorial · 2025 — 12 µm</p>', 'en')['removed'], 'the middle dot, the dash and the micro sign are punctuation and symbols, not stray glyphs');
    assert_true(StrayGlyph::latinScript('pt-BR'));
    assert_true(!StrayGlyph::latinScript('zh-Hant'));

    $warnings = [];
    $files = SectionsStep::stripStrayGlyphs(['parts/home-section-3.html' => $markup, 'parts/home-section-4.html' => '<p>Clean.</p>'], 'en', $warnings);
    assert_eq('<p>Clean.</p>', $files['parts/home-section-4.html']);
    assert_eq(1, count($warnings));
    assert_contains("file='theme/parts/home-section-3.html'; block='text'; authored=2 lone glyph(s)", $warnings[0]);
});
