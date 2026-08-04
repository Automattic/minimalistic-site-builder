#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const { afterEach, test } = require('node:test');
const { parseArgs } = require('../screenshot');

const originalShotWidth = process.env.SHOT_WIDTH;

afterEach(() => {
  if (originalShotWidth === undefined) delete process.env.SHOT_WIDTH;
  else process.env.SHOT_WIDTH = originalShotWidth;
});

test('parseArgs accepts positive integer width and timeout values', () => {
  delete process.env.SHOT_WIDTH;
  const opts = parseArgs(['https://example.com', 'shot.png', '--width=1024', '--timeout=2500']);
  assert.equal(opts.width, 1024);
  assert.equal(opts.timeout, 2500);
});

test('parseArgs rejects partial, fractional, and non-positive numeric flags', () => {
  for (const value of ['100px', '1.5', '0', '-1', '', 'NaN', 'Infinity']) {
    assert.throws(() => parseArgs([`--width=${value}`]), /positive integer/);
    assert.throws(() => parseArgs([`--timeout=${value}`]), /positive integer/);
  }
});

test('SHOT_WIDTH uses the same strict integer parsing and otherwise falls back', () => {
  process.env.SHOT_WIDTH = '900';
  assert.equal(parseArgs([]).width, 900);

  process.env.SHOT_WIDTH = '900px';
  assert.equal(parseArgs([]).width, 1366);
});
