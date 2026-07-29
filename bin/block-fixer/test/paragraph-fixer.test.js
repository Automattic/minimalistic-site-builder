'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { installDomEnvironment } = require('../lib/domEnvironment');
const {
  fixNestedParagraphsDetailed,
} = require('../lib/paragraphFixer');

function withoutConsoleNoise(callback) {
  const methods = [
    'log', 'info', 'warn', 'debug', 'dir', 'trace', 'error',
    'group', 'groupCollapsed', 'groupEnd',
  ];
  const originals = Object.fromEntries(methods.map((method) => [method, console[method]]));
  for (const method of methods) console[method] = () => {};
  try {
    return callback();
  } finally {
    for (const method of methods) console[method] = originals[method];
  }
}

test('paragraph helper preserves greater-than characters inside quoted attributes', () => {
  const input =
    '<!-- wp:paragraph --><p class="outer"><p title="1 > 0" data-check=\'2 > 1\'>Hi</p></p><!-- /wp:paragraph -->';
  const result = withoutConsoleNoise(() => fixNestedParagraphsDetailed(input));

  assert.equal(result.count, 1);
  assert.equal(
    result.html,
    '<!-- wp:paragraph --><p class="outer" title="1 > 0" data-check="2 > 1">Hi</p><!-- /wp:paragraph -->'
  );
});

test('full block transform preserves greater-than characters inside quoted attributes', () => {
  installDomEnvironment({ forwardJsdomErrors: false });
  const { fixBlocksInTemplate } = require('../lib/blockFixer');
  const input =
    '<!-- wp:paragraph --><p><p title="1 > 0">Hi</p></p><!-- /wp:paragraph -->';

  const result = withoutConsoleNoise(() => fixBlocksInTemplate(input));

  assert.equal(
    result.html,
    '<!-- wp:paragraph -->\n<p title="1 &gt; 0">Hi</p>\n<!-- /wp:paragraph -->'
  );
  assert.ok(result.compatibilityRepairs.some((repair) => (
    repair.code === 'nested-paragraph'
      && repair.stage === 'pre-parse'
      && repair.count === 1
  )));
});

test('oracle strict mode propagates a transform failure', () => {
  installDomEnvironment({ forwardJsdomErrors: false });
  const { fixBlocksInTemplate } = require('../lib/blockFixer');
  withoutConsoleNoise(() => {
    assert.throws(
      () => fixBlocksInTemplate(null, { throwOnError: true }),
      TypeError
    );
  });
});
