#!/usr/bin/env node
'use strict';

/*
 * Development-only adapter used by block_renderer_differential.php. It keeps
 * the JavaScript runtime on the oracle side of the boundary and returns the
 * fully normalized attributes that a PHP save renderer receives in
 * production, plus the canonical save-content bytes for comparison.
 */

const fs = require('node:fs');
const path = require('node:path');
const { installDomEnvironment } = require(path.join(
  __dirname,
  '../../bin/block-fixer/lib/domEnvironment'
));

installDomEnvironment({ forwardJsdomErrors: false });
const noop = () => {};
for (const method of [
  'log', 'info', 'warn', 'debug', 'dir', 'trace', 'error',
  'group', 'groupCollapsed', 'groupEnd',
]) {
  console[method] = noop;
}

const { initializeBlockRegistry } = require(path.join(
  __dirname,
  '../../bin/block-fixer/lib/blockFixer'
));
initializeBlockRegistry({ throwOnError: true });
const blocks = require('@wordpress/blocks');

function create(specification) {
  return blocks.createBlock(
    specification.name,
    specification.attributes || {},
    (specification.innerBlocks || []).map(create)
  );
}

function probe(specification, index) {
  try {
    const block = create(specification);
    const element = blocks.getSaveElement(
      block.name,
      block.attributes,
      block.innerBlocks
    );
    return {
      id: specification.id ?? index,
      name: block.name,
      attributes: block.attributes,
      innerSerialized: blocks.serialize(block.innerBlocks),
      saveKind: element === null ? 'null' : 'element',
      expected: blocks.getSaveContent(
        block.name,
        block.attributes,
        block.innerBlocks
      ),
    };
  } catch (error) {
    return {
      id: specification.id ?? index,
      name: specification.name ?? null,
      error: `${error.name}: ${error.message}`,
    };
  }
}

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const specifications = Array.isArray(input) ? input : input.cases;
if (!Array.isArray(specifications)) {
  throw new TypeError('stdin must contain a cases array');
}
process.stdout.write(JSON.stringify({
  runtime: {
    version: process.version,
    platform: process.platform,
    architecture: process.arch,
    v8: process.versions.v8,
    icu: process.versions.icu,
  },
  cases: specifications.map(probe),
}) + '\n');
