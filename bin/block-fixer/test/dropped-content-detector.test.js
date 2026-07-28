'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  detectDroppedContent,
  detectDroppedContentRecords,
  styleDeclarationCounts,
} = require('../lib/droppedContentDetector');

test('detector preserves style-then-class order and occurrence losses', () => {
  const before = '<div class="keep lost lost" style="Height: 20px; color:red; height:20px"></div>';
  const after = '<div class="keep lost" style="height:20px"></div>';
  assert.deepEqual(detectDroppedContentRecords(before, after), [
    {
      kind: 'style',
      value: 'height:20px',
      lost: 1,
      line: 'DROPPED style `height:20px` — not mirrored in the block comment JSON attributes',
    },
    {
      kind: 'style',
      value: 'color:red',
      lost: 1,
      line: 'DROPPED style `color:red` — not mirrored in the block comment JSON attributes',
    },
    {
      kind: 'class',
      value: 'lost',
      lost: 1,
      line: 'DROPPED class `lost` — not mirrored in the block comment JSON attributes',
    },
  ]);
});

test('detector normalizes only whitespace around the first colon', () => {
  assert.deepEqual(
    detectDroppedContent(
      `<div style='padding-top: var(--x:y);background:url(data:image/png;base64:a)'></div>`,
      `<div style="padding-top:var(--x:y);background:url(data:image/png;base64:a)"></div>`
    ),
    []
  );
  assert.equal(styleDeclarationCounts('<div style="COLOR : Red"></div>').get('color:Red'), 1);
});

test('detector reports repeated loss as one record with xN', () => {
  assert.deepEqual(
    detectDroppedContent('<i class="gone gone"></i><b class="gone"></b>', ''),
    ['DROPPED class `gone` (x3) — not mirrored in the block comment JSON attributes']
  );
});

test('detector preserves numeric-string style and class values', () => {
  assert.deepEqual(
    detectDroppedContent('<p class="0" style="0">x</p>', '<p>x</p>'),
    [
      'DROPPED style `0` — not mirrored in the block comment JSON attributes',
      'DROPPED class `0` — not mirrored in the block comment JSON attributes',
    ]
  );
});
