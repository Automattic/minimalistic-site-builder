'use strict';

const path = require('node:path');

const INSTRUMENTATION_VERSION = '1.0.0';
const DEPRECATION_INDEX = Symbol('blockFixerDeprecationIndex');

let installed = false;
let activeDeprecation = null;
let trace = emptyTrace();

function emptyTrace() {
  return {
    validations: [],
    builtInRepairs: [],
    deprecations: [],
  };
}

function snapshot(value, seen = new WeakSet()) {
  if (value === undefined) return { __type: 'undefined' };
  if (typeof value === 'number' && !Number.isFinite(value)) {
    return { __type: String(value) };
  }
  if (typeof value === 'function') return { __type: 'function', name: value.name || '' };
  if (typeof value === 'symbol') return { __type: 'symbol', value: String(value) };
  if (value === null || typeof value !== 'object') return value;
  if (seen.has(value)) return { __type: 'circular' };
  seen.add(value);
  if (Array.isArray(value)) return value.map((item) => snapshot(item, seen));
  const result = {};
  for (const key of Object.keys(value)) {
    result[key] = snapshot(value[key], seen);
  }
  return result;
}

function sameValue(left, right) {
  return JSON.stringify(snapshot(left)) === JSON.stringify(snapshot(right));
}

function blocksSummary(blocks) {
  return (blocks || []).map((block) => ({
    name: block.name || null,
    attributes: snapshot(block.attributes || {}),
    innerBlocks: blocksSummary(block.innerBlocks || []),
  }));
}

function internalModule(packageDir, relativePath) {
  return path.join(packageDir, 'build', ...relativePath.split('/'));
}

/**
 * Install development-only wrappers before @wordpress/blocks is imported.
 * Wrappers call the pinned implementation and record byte-affecting built-in
 * repairs plus the exact successful deprecation index.
 */
function installOracleInstrumentation() {
  if (installed) return;

  const blocksEntry = require.resolve('@wordpress/blocks');
  if (require.cache[blocksEntry]) {
    throw new Error('Oracle instrumentation must be installed before @wordpress/blocks is imported');
  }

  const packageDir = path.dirname(require.resolve('@wordpress/blocks/package.json'));
  const validationPath = internalModule(packageDir, 'api/validation/index.cjs');
  const builtInPath = internalModule(
    packageDir,
    'api/parser/apply-built-in-validation-fixes.cjs'
  );
  const deprecatedPath = internalModule(
    packageDir,
    'api/parser/apply-block-deprecated-versions.cjs'
  );

  const validationExports = require(validationPath);
  const originalValidateBlock = validationExports.validateBlock;
  require.cache[validationPath].exports = {
    ...validationExports,
    validateBlock(block, blockType) {
      const result = originalValidateBlock(block, blockType);
      const index = blockType && blockType[DEPRECATION_INDEX];
      if (activeDeprecation && Number.isInteger(index) && result[0]) {
        activeDeprecation.matched.add(index);
      }
      trace.validations.push({
        blockName: blockType?.name || block?.name || null,
        deprecationIndex: Number.isInteger(index) ? index : null,
        valid: Boolean(result[0]),
        issueCount: Array.isArray(result[1]) ? result[1].length : 0,
      });
      return result;
    },
  };

  const builtInExports = require(builtInPath);
  const originalBuiltIn = builtInExports.applyBuiltInValidationFixes;
  require.cache[builtInPath].exports = {
    ...builtInExports,
    applyBuiltInValidationFixes(block, blockType) {
      const before = block.attributes || {};
      const result = originalBuiltIn(block, blockType);
      const after = result.attributes || {};
      for (const [key, code] of [
        ['className', 'custom-class-recovery'],
        ['ariaLabel', 'aria-label-recovery'],
        ['anchor', 'anchor-recovery'],
      ]) {
        if (!sameValue(before[key], after[key])) {
          trace.builtInRepairs.push({
            blockName: blockType?.name || block?.name || null,
            code,
            phase: activeDeprecation ? 'deprecation' : 'current',
            before: snapshot(before[key]),
            after: snapshot(after[key]),
          });
        }
      }
      return result;
    },
  };

  const deprecatedExports = require(deprecatedPath);
  const originalDeprecated = deprecatedExports.applyBlockDeprecatedVersions;
  require.cache[deprecatedPath].exports = {
    ...deprecatedExports,
    applyBlockDeprecatedVersions(block, rawBlock, blockType) {
      const definitions = blockType?.deprecated || [];
      const instrumentedType = definitions.length === 0
        ? blockType
        : {
          ...blockType,
          deprecated: definitions.map((definition, index) => ({
            ...definition,
            [DEPRECATION_INDEX]: index,
          })),
        };
      const context = { matched: new Set() };
      const previous = activeDeprecation;
      activeDeprecation = context;
      let result;
      try {
        result = originalDeprecated(block, rawBlock, instrumentedType);
      } finally {
        activeDeprecation = previous;
      }
      for (const index of [...context.matched].sort((a, b) => a - b)) {
        trace.deprecations.push({
          blockName: blockType?.name || block?.name || null,
          deprecationIndex: index,
          beforeAttributes: snapshot(block.attributes || {}),
          afterAttributes: snapshot(result.attributes || {}),
          beforeInnerBlocks: blocksSummary(block.innerBlocks || []),
          afterInnerBlocks: blocksSummary(result.innerBlocks || []),
        });
      }
      return result;
    },
  };

  installed = true;
}

function resetOracleInstrumentation() {
  trace = emptyTrace();
}

function getOracleInstrumentation() {
  return snapshot(trace);
}

module.exports = {
  INSTRUMENTATION_VERSION,
  getOracleInstrumentation,
  installOracleInstrumentation,
  resetOracleInstrumentation,
};
