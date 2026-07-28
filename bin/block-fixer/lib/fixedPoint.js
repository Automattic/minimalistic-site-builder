'use strict';

const MAX_ORACLE_PASSES = 5;

class NonConvergenceError extends Error {
  constructor(maxPasses) {
    super(`[block-fixer oracle] transformation did not converge within ${maxPasses} passes`);
    this.name = 'NonConvergenceError';
    this.maxPasses = maxPasses;
  }
}

/**
 * Apply the exact one-pass CLI transform until the bytes stop changing.
 *
 * `transform().changed` is authoritative: the production CLI deliberately
 * leaves the original bytes in place when it is false, even if a helper's
 * returned `html` differs after pre-parse paragraph repair.
 */
function transformToFixedPoint(input, transform, { maxPasses = MAX_ORACLE_PASSES } = {}) {
  if (!Number.isInteger(maxPasses) || maxPasses < 1) {
    throw new TypeError('maxPasses must be a positive integer');
  }
  if (typeof transform !== 'function') {
    throw new TypeError('transform must be a function');
  }

  let current = input;
  const iterations = [];
  for (let pass = 1; pass <= maxPasses; pass++) {
    const result = transform(current);
    if (!result || typeof result.html !== 'string' || typeof result.changed !== 'boolean') {
      throw new TypeError('transform must return { html: string, changed: boolean }');
    }
    const next = result.changed ? result.html : current;
    iterations.push({
      pass,
      changed: result.changed,
      byteChanged: next !== current,
      fixedIssues: Array.isArray(result.fixedIssues) ? [...result.fixedIssues] : [],
      compatibilityRepairs: Array.isArray(result.compatibilityRepairs)
        ? result.compatibilityRepairs.map((repair) => ({ ...repair }))
        : [],
      instrumentation: result.instrumentation || null,
    });
    if (next === current) {
      return {
        html: current,
        passes: pass,
        iterations,
      };
    }
    current = next;
  }

  throw new NonConvergenceError(maxPasses);
}

module.exports = {
  MAX_ORACLE_PASSES,
  NonConvergenceError,
  transformToFixedPoint,
};
