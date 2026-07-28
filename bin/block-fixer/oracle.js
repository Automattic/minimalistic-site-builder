#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { collectFiles } = require('./fix-templates');
const { installDomEnvironment } = require('./lib/domEnvironment');
const { detectDroppedContentRecords } = require('./lib/droppedContentDetector');
const { MAX_ORACLE_PASSES, transformToFixedPoint } = require('./lib/fixedPoint');

function prepareTransform() {
  installDomEnvironment({ forwardJsdomErrors: false });
  const noop = () => {};
  for (const method of [
    'log', 'info', 'warn', 'debug', 'dir', 'trace', 'error',
    'group', 'groupCollapsed', 'groupEnd',
  ]) console[method] = noop;
  const fixer = require('./lib/blockFixer');
  fixer.initializeBlockRegistry({ throwOnError: true });
  return (html) => fixer.fixBlocksInTemplate(html, { throwOnError: true });
}

/** Fixed-point oracle over one or more disposable theme directory copies. */
function runOracle(themeDirs, { write = true, transform = prepareTransform() } = {}) {
  const staged = [];
  const files = [];
  let N = 0;
  let M = 0;
  let D = 0;

  for (const themeDir of themeDirs) {
    if (!fs.existsSync(themeDir) || !fs.statSync(themeDir).isDirectory()) {
      throw new Error(`theme directory is missing or invalid: ${themeDir}`);
    }
    for (const file of collectFiles(themeDir)) {
      const relative = path.relative(themeDir, file).split(path.sep).join('/');
      const original = fs.readFileSync(file, 'utf8');
      if (!original.includes('<!-- wp:')) {
        files.push({ themeDir, path: relative, status: 'skip', passes: 0, dropped: [] });
        continue;
      }
      M++;
      const fixedPoint = transformToFixedPoint(original, transform, {
        maxPasses: MAX_ORACLE_PASSES,
      });
      const changed = fixedPoint.html !== original;
      const dropped = detectDroppedContentRecords(original, fixedPoint.html);
      if (changed) N++;
      D += dropped.length;
      staged.push({ file, original, canonical: fixedPoint.html });
      files.push({
        themeDir,
        path: relative,
        status: changed ? 'FIXED' : 'ok',
        passes: fixedPoint.passes,
        dropped,
      });
    }
  }

  if (write) {
    for (const entry of staged) {
      if (entry.original !== entry.canonical) {
        fs.writeFileSync(entry.file, entry.canonical, 'utf8');
      }
    }
  }
  return {
    schemaVersion: 1,
    maxPasses: MAX_ORACLE_PASSES,
    totals: { N, M, D, T: themeDirs.length },
    files,
  };
}

function main(argv = process.argv.slice(2)) {
  if (argv.length === 0) {
    process.stderr.write('Usage: node oracle.js <disposable-theme-dir> [<dir> ...]\n');
    return 2;
  }
  try {
    process.stdout.write(JSON.stringify(runOracle(argv), null, 2) + '\n');
    return 0;
  } catch (error) {
    process.stderr.write(`[block-fixer oracle] ${error.stack || error.message}\n`);
    return 1;
  }
}

if (require.main === module) {
  process.exitCode = main();
}

module.exports = {
  main,
  runOracle,
};
