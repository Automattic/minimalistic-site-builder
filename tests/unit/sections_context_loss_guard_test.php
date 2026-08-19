<?php
declare(strict_types=1);

use Automattic\SiteBuild\Steps\SectionsStep;

/**
 * Unit tests for the runtime guard that notices a host discarding the section
 * cache layers.
 *
 * SectionUnit ships the site spec, theme JSON, design direction and page
 * outline as `cached_prefixes` and sends only the per-section brief as
 * `prompt`. A host that accepts the field and drops it still returns
 * well-formed markup, so the build cannot tell from the response — the only
 * available signal is that far too few input tokens were billed.
 *
 * The threshold matters in both directions. Missing a real collapse ships a
 * themeless site; crying wolf on a healthy host trains people to ignore
 * warnings.json. So these cover the accusing case AND several ways the guard
 * must stay quiet.
 */

/** ~2,400 tokens of layer, the realistic size for a section build layer. */
function guard_layers(): array
{
    return [str_repeat('Design direction and theme context. ', 200), str_repeat('Full page outline. ', 100)];
}

test('a host that drops the cache layers is accused, with actionable context', function () {
    $layers = guard_layers();
    // A dropping host bills only the tiny warm prompt plus system preamble.
    $warning = SectionsStep::contextLossWarning($layers, 180);

    assert_true($warning !== null, 'a collapsed input count must be reported');
    assert_contains('discard cached_prefixes', $warning);
    assert_contains('without the theme or the design direction', $warning);
    // AGENTS.md requires actionable file/block/value/disposition context.
    assert_contains("file 'theme/parts/*.html'", $warning);
    assert_contains('disposition=', $warning);
    // It must say what to do next, not just that something is wrong.
    assert_contains('bin/llm-conformance.php', $warning);
});

test('a conformant host is not accused', function () {
    $layers = guard_layers();
    $bytes = strlen($layers[0]) + strlen($layers[1]);
    // A host that sent the layers bills at least the layers themselves.
    $conformant = intdiv($bytes, 4) + 250;

    assert_eq(null, SectionsStep::contextLossWarning($layers, $conformant));
});

test('a denser tokenizer than the estimate still passes', function () {
    $layers = guard_layers();
    $bytes = strlen($layers[0]) + strlen($layers[1]);
    // The guard estimates 4 bytes/token. A provider packing 6 bytes/token bills
    // fewer tokens for the same text and must NOT be accused of dropping them.
    $denser = intdiv($bytes, 6);

    assert_eq(null, SectionsStep::contextLossWarning($layers, $denser), 'tokenizer variance is not a defect');
});

test('layers too small to measure stay silent rather than guess', function () {
    // Below the floor, system-prompt overhead swamps the signal, so a zero
    // reading proves nothing.
    assert_eq(null, SectionsStep::contextLossWarning(['tiny layer'], 0));
    assert_eq(null, SectionsStep::contextLossWarning([], 0));
});

test('the accusation names both the expected and the billed token counts', function () {
    $layers = ['x' => str_repeat('a', 8000)];
    $warning = SectionsStep::contextLossWarning(array_values($layers), 42);

    assert_true($warning !== null);
    assert_contains('2000 cached_prefixes tokens', $warning, 'reports the estimate it compared against');
    assert_contains('42 input tokens billed', $warning, 'reports what the host actually billed');
});

test('the guard sits exactly at the halfway threshold without flapping', function () {
    // 8000 bytes -> 2000 estimated tokens; the ratio is 0.5, so 1000 is the
    // first passing value and 999 the last failing one.
    $layers = [str_repeat('a', 8000)];

    assert_eq(null, SectionsStep::contextLossWarning($layers, 1000), 'at the threshold: silent');
    assert_true(SectionsStep::contextLossWarning($layers, 999) !== null, 'just below: reported');
});
