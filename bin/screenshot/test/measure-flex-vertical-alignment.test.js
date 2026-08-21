#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const {
  CASES,
  parseArgs,
  summarizeMeasurements,
} = require('../measure-flex-vertical-alignment');

test('flex alignment measurer freezes four rendered cases and a 1366x1000 viewport', () => {
  assert.deepEqual(Object.keys(CASES), [
    'amber-ember-nav',
    'calm-lantern-nav',
    'sunny-ember-nav',
    'silver-summit-hero-ctas',
  ]);
  const opts = parseArgs(['amber-ember-nav', 'http://127.0.0.1:9400/']);
  assert.equal(opts.width, 1366);
  assert.equal(opts.height, 1000);
  assert.match(CASES['calm-lantern-nav'].items, /:scope/);
  assert.match(CASES['silver-summit-hero-ctas'].items, /:scope/);
});

test('flex alignment measurer accepts only positive integer viewport dimensions', () => {
  for (const value of ['100px', '1.5', '0', '-1', '', 'NaN', 'Infinity']) {
    assert.throws(
      () => parseArgs(['amber-ember-nav', 'http://example.test', `--width=${value}`]),
      /positive integer/,
    );
    assert.throws(
      () => parseArgs(['amber-ember-nav', 'http://example.test', `--height=${value}`]),
      /positive integer/,
    );
  }
});

test('flex alignment measurer rejects unknown cases and options', () => {
  assert.throws(() => parseArgs(['unknown', 'http://example.test']), /case must be one of/);
  assert.throws(
    () => parseArgs(['amber-ember-nav', 'http://example.test', '--wat']),
    /unknown option/,
  );
});

test('flex alignment summary preserves baseline spread and signed center range', () => {
  assert.deepEqual(summarizeMeasurements([
    { baseline_from_row_top_px: 67.563, box_center_from_row_center_px: -1.25 },
    { baseline_from_row_top_px: 81.219, box_center_from_row_center_px: 14.8596 },
  ]), {
    baseline_spread_px: 13.656,
    box_center_range_px: [-1.25, 14.86],
  });
});
