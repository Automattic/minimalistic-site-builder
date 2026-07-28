#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { installDomEnvironment } = require('./block-fixer/lib/domEnvironment');
const {
  detectDroppedContentRecords,
} = require('./block-fixer/lib/droppedContentDetector');
const {
  MAX_ORACLE_PASSES,
  transformToFixedPoint,
} = require('./block-fixer/lib/fixedPoint');
const {
  CASES,
  REQUIRED_CAPABILITIES,
  REVIEWED_CASE_EXCLUSIONS,
  REVIEWED_DEPRECATIONS,
} = require('./block-fixer/lib/fixtureCases');
const { SUPPORTED_BLOCKS } = require('./block-fixer/lib/supportManifest');
const {
  getOracleInstrumentation,
  installOracleInstrumentation,
  resetOracleInstrumentation,
} = require('./block-fixer/lib/oracleInstrumentation');
const {
  REPOSITORY_ROOT,
  sha256,
  sha256File,
} = require('./block-fixer/lib/oracleFingerprint');
const { scanObservedBlocks } = require('./block-fixer/lib/registrySnapshot');

const GENERATOR_VERSION = '1.0.0';
const FIXTURES_ROOT = path.join(REPOSITORY_ROOT, 'tests', 'fixtures', 'block-fixer');
const CASES_ROOT = path.join(FIXTURES_ROOT, 'cases');
const RENDERER_PROBES = path.join(FIXTURES_ROOT, 'renderer-probes.json');

function stableJson(value) {
  return JSON.stringify(value, null, 2) + '\n';
}

function relativeFixturePath(file) {
  return path.relative(REPOSITORY_ROOT, file).split(path.sep).join('/');
}

function assertOrWrite(file, content, update) {
  if (update) {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, content);
    return;
  }
  if (!fs.existsSync(file)) {
    throw new Error(`Missing fixture artifact ${relativeFixturePath(file)}; run with --update`);
  }
  const existing = fs.readFileSync(file, 'utf8');
  if (existing !== content) {
    throw new Error(`Fixture drift in ${relativeFixturePath(file)}; review an explicit --update`);
  }
}

function listHtmlFiles(root) {
  const files = [];
  for (const subdirectory of ['pages', 'parts', 'templates']) {
    const directory = path.join(root, subdirectory);
    if (!fs.existsSync(directory)) continue;
    for (const name of fs.readdirSync(directory)) {
      if (name.endsWith('.html')) {
        files.push(path.join(directory, name));
      }
    }
  }
  return files.sort((left, right) => (
    path.relative(root, left).localeCompare(path.relative(root, right), 'en')
  ));
}

function seedCaseInput(definition, inputRoot, update) {
  if (definition.files) {
    for (const [relative, content] of Object.entries(definition.files)) {
      assertOrWrite(path.join(inputRoot, relative), content, update);
    }
  }
  if (definition.advisorySource) {
    const target = path.join(inputRoot, definition.advisoryTarget);
    if (!fs.existsSync(target)) {
      if (!update) {
        throw new Error(`Missing imported advisory fixture ${relativeFixturePath(target)}`);
      }
      const source = path.join(REPOSITORY_ROOT, definition.advisorySource);
      if (!fs.existsSync(source)) {
        throw new Error(
          `Cannot seed ${definition.name}; advisory source ${definition.advisorySource} is unavailable`
        );
      }
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.writeFileSync(target, fs.readFileSync(source));
    }
  }
  const files = listHtmlFiles(inputRoot);
  if (files.length === 0) {
    throw new Error(`Case ${definition.name} has no input HTML files`);
  }
  return files;
}

function prepareRuntime() {
  installDomEnvironment({ forwardJsdomErrors: false });
  installOracleInstrumentation();
  const noop = () => {};
  for (const method of [
    'log', 'info', 'warn', 'debug', 'dir', 'trace', 'error',
    'group', 'groupCollapsed', 'groupEnd',
  ]) {
    console[method] = noop;
  }
  const blockFixer = require('./block-fixer/lib/blockFixer');
  blockFixer.initializeBlockRegistry({ throwOnError: true });
  return (html) => blockFixer.fixBlocksInTemplate(html, { throwOnError: true });
}

function committedCaseNames() {
  return fs.readdirSync(CASES_ROOT)
    .filter((name) => fs.statSync(path.join(CASES_ROOT, name)).isDirectory())
    .filter((name) => fs.existsSync(path.join(CASES_ROOT, name, 'case.json')))
    .sort();
}

function assertCommittedCaseInventory() {
  const generated = CASES.map((definition) => definition.name);
  const generatedSet = new Set(generated);
  if (generatedSet.size !== generated.length) {
    const duplicates = generated.filter((name, index) => generated.indexOf(name) !== index);
    throw new Error(`Duplicate generated fixture definitions: ${[...new Set(duplicates)].join(', ')}`);
  }

  const excluded = Object.keys(REVIEWED_CASE_EXCLUSIONS);
  const overlap = excluded.filter((name) => generatedSet.has(name));
  if (overlap.length > 0) {
    throw new Error(`Fixture cases cannot be both generated and excluded: ${overlap.join(', ')}`);
  }

  for (const [name, exclusion] of Object.entries(REVIEWED_CASE_EXCLUSIONS)) {
    if (!['degradation-policy', 'runtime-divergence'].includes(exclusion.kind)) {
      throw new Error(`Reviewed fixture exclusion ${name} has invalid kind ${exclusion.kind}`);
    }
    if (typeof exclusion.reason !== 'string' || exclusion.reason.trim() === '') {
      throw new Error(`Reviewed fixture exclusion ${name} needs a non-empty reason`);
    }
    const mismatches = exclusion.runtimeMismatches;
    if (!mismatches || Object.keys(mismatches).length === 0) {
      throw new Error(`Reviewed fixture exclusion ${name} needs an exact runtime mismatch`);
    }
    for (const [file, hash] of Object.entries(mismatches)) {
      if (!file.endsWith('.html') || !/^[a-f0-9]{64}$/.test(hash)) {
        throw new Error(`Reviewed runtime mismatch ${name}/${file} is malformed`);
      }
    }
  }

  const committed = committedCaseNames();
  const expected = [...generated, ...excluded].sort();
  const missing = expected.filter((name) => !committed.includes(name));
  const unexpected = committed.filter((name) => !expected.includes(name));
  if (missing.length > 0 || unexpected.length > 0) {
    throw new Error(
      'Committed fixture inventory differs from generated definitions plus reviewed exclusions'
      + `; missing: ${missing.join(', ') || '(none)'}`
      + `; unexpected: ${unexpected.join(', ') || '(none)'}`
    );
  }
}

function fixedPoint(original, transform) {
  return transformToFixedPoint(original, (html) => {
    resetOracleInstrumentation();
    const result = transform(html);
    return {
      ...result,
      instrumentation: getOracleInstrumentation(),
    };
  });
}

function countableCodes(iterations) {
  const codes = new Set();
  for (const iteration of iterations) {
    for (const repair of iteration.compatibilityRepairs || []) {
      codes.add(repair.code);
    }
    for (const repair of iteration.instrumentation?.builtInRepairs || []) {
      codes.add(repair.code);
    }
    for (const deprecation of iteration.instrumentation?.deprecations || []) {
      codes.add(`deprecation:${deprecation.blockName}:${deprecation.deprecationIndex}`);
    }
  }
  return [...codes].sort();
}

function createRepairsFile(definition) {
  return {
    schemaVersion: 1,
    reviewed: true,
    k: definition.repairs.length,
    repairs: definition.repairs,
    secondInvocation: {
      k: 0,
      repairs: [],
    },
  };
}

function validateReviewedRepairs(file, definition, observedCodes) {
  if (!fs.existsSync(file)) {
    throw new Error(`Missing reviewed repairs file ${relativeFixturePath(file)}`);
  }
  const repairs = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (repairs.reviewed !== true || repairs.k !== repairs.repairs?.length) {
    throw new Error(`Invalid reviewed repair contract in ${relativeFixturePath(file)}`);
  }
  const expectedCodes = new Set(repairs.repairs.map((repair) => repair.code));
  for (const code of expectedCodes) {
    if (!observedCodes.has(code)) {
      throw new Error(
        `Reviewed repair ${code} in ${definition.name} was not observed by the pinned oracle`
      );
    }
  }
  if (repairs.secondInvocation?.k !== 0 || repairs.secondInvocation?.repairs?.length !== 0) {
    throw new Error(`Second invocation repair expectation must be empty in ${definition.name}`);
  }
  return repairs;
}

function generateCase(definition, transform, update) {
  const caseRoot = path.join(CASES_ROOT, definition.name);
  const inputRoot = path.join(caseRoot, 'input');
  const expectedRoot = path.join(caseRoot, 'expected');
  const inputFiles = seedCaseInput(definition, inputRoot, update);
  const reportFiles = [];
  const oracleFiles = [];
  let changed = 0;
  let eligible = 0;
  let droppedCount = 0;
  const observedCodes = new Set();

  for (const inputFile of inputFiles) {
    const relative = path.relative(inputRoot, inputFile).split(path.sep).join('/');
    const original = fs.readFileSync(inputFile, 'utf8');
    let canonical = original;
    let result = null;
    let second = null;
    let status = 'skip';
    let dropped = [];

    if (original.includes('<!-- wp:')) {
      eligible++;
      result = fixedPoint(original, transform);
      canonical = result.html;
      second = fixedPoint(canonical, transform);
      if (second.html !== canonical || second.passes !== 1) {
        throw new Error(`Oracle fixed point is not idempotent for ${definition.name}/${relative}`);
      }
      status = canonical === original ? 'ok' : 'FIXED';
      if (status === 'FIXED') changed++;
      dropped = detectDroppedContentRecords(original, canonical);
      droppedCount += dropped.length;
      for (const code of countableCodes(result.iterations)) observedCodes.add(code);
    }

    assertOrWrite(path.join(expectedRoot, relative), canonical, update);
    reportFiles.push({ path: relative, status, dropped });
    oracleFiles.push({
      path: relative,
      passes: result?.passes ?? 0,
      secondInvocationPasses: second?.passes ?? 0,
      inputSha256: sha256(original),
      outputSha256: sha256(canonical),
      iterations: result?.iterations ?? [],
    });
  }

  const repairsFile = path.join(caseRoot, 'repairs.json');
  if (update && !fs.existsSync(repairsFile)) {
    assertOrWrite(repairsFile, stableJson(createRepairsFile(definition)), true);
  }
  const repairs = validateReviewedRepairs(repairsFile, definition, observedCodes);
  const report = {
    schemaVersion: 1,
    totals: { N: changed, M: eligible, D: droppedCount, T: 1 },
    files: reportFiles,
  };
  const caseMetadata = {
    schemaVersion: 1,
    name: definition.name,
    milestone: definition.milestone,
    capabilities: definition.capabilities,
    oracle: {
      generatorVersion: GENERATOR_VERSION,
      maxPasses: MAX_ORACLE_PASSES,
      observedCodes: [...observedCodes].sort(),
      files: oracleFiles,
    },
    reviewedRepairCount: repairs.k,
    provenance: definition.advisorySource
      ? {
        kind: 'advisory-corpus-import',
        source: definition.advisorySource,
        importedInputSha256: sha256File(path.join(inputRoot, definition.advisoryTarget)),
      }
      : { kind: 'committed-dirty-seed' },
  };
  assertOrWrite(path.join(caseRoot, 'report.json'), stableJson(report), update);
  assertOrWrite(path.join(caseRoot, 'case.json'), stableJson(caseMetadata), update);
  return {
    definition,
    report,
    caseMetadata,
    observedCodes: [...observedCodes].sort(),
  };
}

function reviewedDeprecationCoverage(results) {
  const observed = [...new Set(results.flatMap((result) => (
    result.observedCodes.filter((code) => code.startsWith('deprecation:'))
  )))].sort();
  const reviewed = Object.keys(REVIEWED_DEPRECATIONS).sort();
  return {
    observed,
    reviewed: Object.fromEntries(reviewed.map((code) => [
      code,
      REVIEWED_DEPRECATIONS[code],
    ])),
    unreviewed: observed.filter((code) => !Object.hasOwn(REVIEWED_DEPRECATIONS, code)),
    unobservedReviewed: reviewed.filter((code) => !observed.includes(code)),
  };
}

function rendererProbeCoverage() {
  if (!fs.existsSync(RENDERER_PROBES)) {
    throw new Error(
      `Missing renderer probe snapshot ${relativeFixturePath(RENDERER_PROBES)}; `
      + 'restore the reviewed snapshot (there is currently no bulk generator; '
      + 'bin/block-fixer/probe-save.js only probes JSON supplied on stdin)'
    );
  }
  const snapshot = JSON.parse(fs.readFileSync(RENDERER_PROBES, 'utf8'));
  if (snapshot.schemaVersion !== 1 || !Array.isArray(snapshot.cases)) {
    throw new Error('Invalid renderer probe snapshot schema');
  }
  const ids = new Set();
  const supportedStaticBlocks = Object.entries(SUPPORTED_BLOCKS)
    .filter(([, manifest]) => manifest.strategy === 'STATIC_RENDERER')
    .map(([name]) => name)
    .sort();
  const supportedStaticSet = new Set(supportedStaticBlocks);
  const probedStatic = new Set();
  let staticRendererCases = 0;
  for (const probe of snapshot.cases) {
    if (!probe || typeof probe.id !== 'string' || probe.id === ''
        || typeof probe.name !== 'string' || probe.name === '') {
      throw new Error('Renderer probe snapshot contains a malformed case');
    }
    if (ids.has(probe.id)) {
      throw new Error(`Renderer probe snapshot contains duplicate id ${probe.id}`);
    }
    ids.add(probe.id);
    if (Object.hasOwn(probe, 'error') || typeof probe.expected !== 'string') {
      throw new Error(`Renderer probe ${probe.id} has no reviewed successful output`);
    }
    if (supportedStaticSet.has(probe.name)) {
      staticRendererCases++;
      probedStatic.add(probe.name);
    }
  }
  const probedStaticBlocks = [...probedStatic].sort();
  const uncoveredStaticBlocks = supportedStaticBlocks.filter((name) => !probedStatic.has(name));
  const computed = {
    oracleCases: snapshot.cases.length,
    staticRendererCases,
    supportedStaticBlocks,
    probedStaticBlocks,
    uncoveredStaticBlocks,
  };
  if (JSON.stringify(snapshot.coverage) !== JSON.stringify(computed)) {
    throw new Error('Renderer probe snapshot coverage metadata does not match its cases');
  }
  return {
    schemaVersion: snapshot.schemaVersion,
    sha256: sha256File(RENDERER_PROBES),
    ...computed,
  };
}

function coverage(results) {
  const capabilityCases = {};
  const supportedBlocks = {};
  const strategies = {};
  for (const [name, manifest] of Object.entries(SUPPORTED_BLOCKS)) {
    supportedBlocks[name] = [];
    strategies[manifest.strategy] ||= [];
  }
  for (const result of results) {
    const caseName = result.definition.name;
    for (const capability of result.definition.capabilities) {
      capabilityCases[capability] ||= [];
      capabilityCases[capability].push(caseName);
      if (capability.startsWith('block:')) {
        const blockName = capability.slice('block:'.length);
        if (supportedBlocks[blockName]) {
          supportedBlocks[blockName].push(caseName);
          const strategy = SUPPORTED_BLOCKS[blockName].strategy;
          if (!strategies[strategy].includes(caseName)) strategies[strategy].push(caseName);
        }
      }
    }
  }
  const observed = scanObservedBlocks(FIXTURES_ROOT);
  const uncoveredCapabilities = REQUIRED_CAPABILITIES.filter((capability) => (
    !Object.hasOwn(capabilityCases, capability)
  ));
  const registeredRuntimeFile = path.join(FIXTURES_ROOT, 'registered-runtime.json');
  const registeredRuntime = fs.existsSync(registeredRuntimeFile)
    ? JSON.parse(fs.readFileSync(registeredRuntimeFile, 'utf8'))
    : null;
  return {
    schemaVersion: 1,
    cases: Object.fromEntries(results.map((result) => [
      result.definition.name,
      {
        milestone: result.definition.milestone,
        capabilities: result.definition.capabilities,
      },
    ])),
    reviewedCaseExclusions: REVIEWED_CASE_EXCLUSIONS,
    capabilityCases,
    requiredCapabilities: REQUIRED_CAPABILITIES,
    uncoveredCapabilities,
    supportedBlocks,
    strategies,
    observed,
    deprecations: reviewedDeprecationCoverage(results),
    rendererProbes: rendererProbeCoverage(),
    uncoveredSupportedBlocks: Object.entries(supportedBlocks)
      .filter(([, cases]) => cases.length === 0)
      .map(([name]) => name),
    registeredSnapshot: registeredRuntime
      ? {
        count: registeredRuntime.counts.registered,
        sha256: sha256File(registeredRuntimeFile),
      }
      : null,
  };
}

function main(argv = process.argv.slice(2)) {
  const unknown = argv.filter((argument) => argument !== '--update');
  if (unknown.length > 0) {
    process.stderr.write(`Unknown argument(s): ${unknown.join(', ')}\n`);
    return 2;
  }
  const update = argv.includes('--update');
  try {
    assertCommittedCaseInventory();
    const transform = prepareRuntime();
    const results = CASES.map((definition) => generateCase(definition, transform, update));
    const coverageMetadata = coverage(results);
    if (coverageMetadata.uncoveredSupportedBlocks.length > 0) {
      throw new Error(
        `Uncovered supported blocks: ${coverageMetadata.uncoveredSupportedBlocks.join(', ')}`
      );
    }
    if (coverageMetadata.uncoveredCapabilities.length > 0) {
      throw new Error(
        `Uncovered required capabilities: ${coverageMetadata.uncoveredCapabilities.join(', ')}`
      );
    }
    if (coverageMetadata.deprecations.unreviewed.length > 0) {
      throw new Error(
        `Unreviewed observed deprecations: ${coverageMetadata.deprecations.unreviewed.join(', ')}`
      );
    }
    if (coverageMetadata.deprecations.unobservedReviewed.length > 0) {
      throw new Error(
        'Reviewed deprecations without a committed observation: '
        + coverageMetadata.deprecations.unobservedReviewed.join(', ')
      );
    }
    if (coverageMetadata.rendererProbes.uncoveredStaticBlocks.length > 0) {
      throw new Error(
        'Supported static renderers without committed probes: '
        + coverageMetadata.rendererProbes.uncoveredStaticBlocks.join(', ')
      );
    }
    assertOrWrite(
      path.join(FIXTURES_ROOT, 'coverage.json'),
      stableJson(coverageMetadata),
      update
    );
    process.stdout.write(
      `[block-fixer fixtures] ${update ? 'updated' : 'verified'} ${results.length} case(s).\n`
    );
    return 0;
  } catch (error) {
    process.stderr.write(`[block-fixer fixtures] ${error.stack || error.message}\n`);
    return 1;
  }
}

if (require.main === module) {
  process.exitCode = main();
}

module.exports = {
  GENERATOR_VERSION,
  assertCommittedCaseInventory,
  committedCaseNames,
  coverage,
  fixedPoint,
  generateCase,
  main,
  rendererProbeCoverage,
  reviewedDeprecationCoverage,
};
