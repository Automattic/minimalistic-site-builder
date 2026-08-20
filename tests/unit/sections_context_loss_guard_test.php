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
    // It must say what to fix next without depending on another PR's tooling.
    assert_contains('completeBatch() forwards cached_prefixes', $warning);
    assert_contains('reports their billed input usage', $warning);
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

/*
 * What counts as a billed input token is not agreed between hosts, and reading
 * the wrong convention inverts this guard: a host reporting raw Messages API
 * usage bills a conformant cached prefix almost entirely as cache creation (on
 * the probe) or cache reads (on everything after it), leaving an `input_tokens`
 * delta indistinguishable from a discarded layer. So the delta is measured
 * across both conventions.
 */

test('a host reporting raw Messages API usage is not accused for a cache write', function () {
    // The conformant case under the raw convention: the 2,400-token prefix is
    // billed as cache CREATION on the probe, so input_tokens stays tiny.
    $before = ['input_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];
    $after  = ['input_tokens' => 180, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 2400];

    assert_eq(2400, SectionsStep::billedInputDelta($before, $after));
    assert_eq(null, SectionsStep::contextLossWarning(guard_layers(), 2400));
});

test('a host reporting raw Messages API usage is not accused for a cache hit', function () {
    // The same prefix on a warm cache. Under the raw convention the better the
    // caching works the smaller input_tokens gets, which is exactly when a
    // naive reading would shout loudest.
    $before = ['input_tokens' => 180, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 2400];
    $after  = ['input_tokens' => 360, 'cache_read_input_tokens' => 2400, 'cache_creation_input_tokens' => 2400];

    assert_eq(2400, SectionsStep::billedInputDelta($before, $after));
});

test('a host folding cache tokens into input_tokens is read without double counting', function () {
    // AnthropicClient's convention: input_tokens already contains the cache
    // figures. Summing them in would inflate the reading and hide a real
    // collapse, so the larger of the two readings is taken, not their sum.
    $before = ['input_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];
    $after  = ['input_tokens' => 2580, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 2400];

    assert_eq(2580, SectionsStep::billedInputDelta($before, $after), 'the folded total, not 4980');
});

test('a host that drops the layers is still accused under either convention', function () {
    // No cache traffic at all, because nothing cacheable was ever sent.
    $before = ['input_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];
    $after  = ['input_tokens' => 180, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];

    $observed = SectionsStep::billedInputDelta($before, $after);
    assert_eq(180, $observed);
    assert_true(SectionsStep::contextLossWarning(guard_layers(), $observed) !== null);
});

test('a host reporting no cache fields at all is read from input_tokens alone', function () {
    // OpenAiCompatibleClient omits both cache keys; absent must read as zero
    // rather than throwing or poisoning the comparison.
    $observed = SectionsStep::billedInputDelta(['input_tokens' => 500], ['input_tokens' => 3100]);

    assert_eq(2600, $observed);
    assert_eq(null, SectionsStep::contextLossWarning(guard_layers(), $observed));
});
