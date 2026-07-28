'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  MAX_ORACLE_PASSES,
  NonConvergenceError,
  transformToFixedPoint,
} = require('../lib/fixedPoint');

test('fixed-point wrapper observes the terminating no-op pass', () => {
  const result = transformToFixedPoint('a', (html) => (
    html === 'a'
      ? { html: 'b', changed: true, fixedIssues: ['first'] }
      : { html: 'b', changed: false, fixedIssues: [] }
  ));
  assert.equal(result.html, 'b');
  assert.equal(result.passes, 2);
  assert.deepEqual(result.iterations.map((item) => item.byteChanged), [true, false]);
});

test('changed=false preserves CLI bytes even when helper html differs', () => {
  const result = transformToFixedPoint('original', () => ({
    html: 'pre-fixed-but-not-written',
    changed: false,
  }));
  assert.equal(result.html, 'original');
  assert.equal(result.passes, 1);
});

test('fixed-point wrapper fails hard after five changing passes', () => {
  assert.throws(
    () => transformToFixedPoint('a', (html) => ({
      html: html === 'a' ? 'b' : 'a',
      changed: true,
    })),
    (error) => error instanceof NonConvergenceError
      && error.maxPasses === MAX_ORACLE_PASSES
  );
});

test('fixed-point wrapper rejects malformed transforms and caps', () => {
  assert.throws(() => transformToFixedPoint('', () => null), TypeError);
  assert.throws(() => transformToFixedPoint('', () => ({ html: '', changed: false }), {
    maxPasses: 0,
  }), TypeError);
});
