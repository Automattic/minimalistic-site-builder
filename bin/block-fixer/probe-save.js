#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const { installDomEnvironment } = require('./lib/domEnvironment');

function prepareRuntime() {
  installDomEnvironment({ forwardJsdomErrors: false });
  const noop = () => {};
  for (const method of [
    'log', 'info', 'warn', 'debug', 'dir', 'trace', 'error',
    'group', 'groupCollapsed', 'groupEnd',
  ]) {
    console[method] = noop;
  }
  const { initializeBlockRegistry } = require('./lib/blockFixer');
  initializeBlockRegistry({ throwOnError: true });
  return require('@wordpress/blocks');
}

function createProbeBlock(specification, blocks) {
  if (!specification || typeof specification.name !== 'string') {
    throw new TypeError('each probe block needs a string name');
  }
  return blocks.createBlock(
    specification.name,
    specification.attributes || {},
    (specification.innerBlocks || []).map((inner) => createProbeBlock(inner, blocks))
  );
}

function probeCases(cases) {
  const blocks = prepareRuntime();
  return cases.map((probe, index) => {
    try {
      const block = createProbeBlock(probe, blocks);
      const saveElement = blocks.getSaveElement(
        block.name,
        block.attributes,
        block.innerBlocks
      );
      return {
        id: probe.id ?? index,
        name: block.name,
        saveKind: saveElement === null ? 'null' : 'element',
        saveContent: blocks.getSaveContent(
          block.name,
          block.attributes,
          block.innerBlocks
        ),
        serialized: blocks.serialize([block]),
      };
    } catch (error) {
      return {
        id: probe.id ?? index,
        name: probe?.name || null,
        error: {
          name: error.name,
          message: error.message,
        },
      };
    }
  });
}

function main() {
  try {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    const cases = Array.isArray(input) ? input : input.cases;
    if (!Array.isArray(cases)) {
      throw new TypeError('stdin must be a JSON array or an object with a cases array');
    }
    process.stdout.write(JSON.stringify({ cases: probeCases(cases) }) + '\n');
    return 0;
  } catch (error) {
    process.stderr.write(`[probe-save] ${error.stack || error.message}\n`);
    return 1;
  }
}

if (require.main === module) {
  process.exitCode = main();
}

module.exports = {
  createProbeBlock,
  main,
  probeCases,
};
