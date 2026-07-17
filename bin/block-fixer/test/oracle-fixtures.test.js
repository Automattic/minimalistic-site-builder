'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');
const { installDomEnvironment } = require('../lib/domEnvironment');
const { detectDroppedContentRecords } = require('../lib/droppedContentDetector');
const { transformToFixedPoint } = require('../lib/fixedPoint');
const { installOracleInstrumentation } = require('../lib/oracleInstrumentation');
const { REVIEWED_DEPRECATIONS } = require('../lib/fixtureCases');
const { SUPPORTED_BLOCKS } = require('../lib/supportManifest');
const {
  assertSupportedManifestMatches,
} = require('../lib/registrySnapshot');

const root = path.resolve(__dirname, '..', '..', '..');
const fixtures = path.join(root, 'tests', 'fixtures', 'block-fixer');

function sha256File(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function listHtmlFiles(directory) {
  const result = [];
  for (const subdirectory of ['parts', 'templates']) {
    const child = path.join(directory, subdirectory);
    if (!fs.existsSync(child)) continue;
    for (const name of fs.readdirSync(child).sort()) {
      if (name.endsWith('.html')) result.push(path.join(child, name));
    }
  }
  return result.sort((a, b) => path.relative(directory, a).localeCompare(
    path.relative(directory, b),
    'en'
  ));
}

function prepareOracle() {
  installDomEnvironment({ forwardJsdomErrors: false });
  installOracleInstrumentation();
  const noop = () => {};
  for (const method of [
    'log', 'info', 'warn', 'debug', 'dir', 'trace', 'error',
    'group', 'groupCollapsed', 'groupEnd',
  ]) console[method] = noop;
  const fixer = require('../lib/blockFixer');
  fixer.initializeBlockRegistry({ throwOnError: true });
  return fixer.fixBlocksInTemplate;
}

test('registered runtime fingerprint and reviewed support manifest are consistent', () => {
  const runtimeFile = path.join(fixtures, 'registered-runtime.json');
  const runtime = JSON.parse(fs.readFileSync(runtimeFile));
  const manifest = JSON.parse(fs.readFileSync(path.join(fixtures, 'oracle-manifest.json')));
  const coverage = JSON.parse(fs.readFileSync(path.join(fixtures, 'coverage.json')));
  assert.equal(runtime.counts.registered, 106);
  assert.equal(runtime.counts.supported, Object.keys(SUPPORTED_BLOCKS).length);
  assert.equal(runtime.counts.observedUnregistered, 1);
  assert.equal(runtime.counts.observedRegistered, Object.keys(SUPPORTED_BLOCKS).length);
  assert.deepEqual(runtime.observedUnregistered, ['core/figure']);
  assert.deepEqual(coverage.uncoveredSupportedBlocks, []);
  assert.deepEqual(coverage.uncoveredCapabilities, []);
  assert.deepEqual(coverage.deprecations.unreviewed, []);
  assert.deepEqual(coverage.deprecations.unobservedReviewed, []);
  assert.deepEqual(coverage.deprecations.reviewed, REVIEWED_DEPRECATIONS);
  assert.deepEqual(
    coverage.deprecations.observed,
    Object.keys(REVIEWED_DEPRECATIONS).sort()
  );
  assert.deepEqual(
    Object.entries(coverage.supportedBlocks)
      .filter(([, cases]) => cases.length === 0)
      .map(([name]) => name),
    []
  );
  const rendererFile = path.join(fixtures, 'renderer-probes.json');
  const rendererSnapshot = JSON.parse(fs.readFileSync(rendererFile));
  const rendererIds = new Set();
  const supportedStaticBlocks = runtime.supported
    .filter((entry) => entry.strategy === 'STATIC_RENDERER')
    .map((entry) => entry.name)
    .sort();
  const probedStaticBlocks = new Set();
  let staticRendererCases = 0;
  for (const probe of rendererSnapshot.cases) {
    assert.equal(typeof probe.id, 'string');
    assert.equal(rendererIds.has(probe.id), false, `duplicate renderer probe ${probe.id}`);
    rendererIds.add(probe.id);
    assert.equal(Object.hasOwn(probe, 'error'), false, probe.id);
    assert.equal(typeof probe.expected, 'string', probe.id);
    if (supportedStaticBlocks.includes(probe.name)) {
      probedStaticBlocks.add(probe.name);
      staticRendererCases++;
    }
  }
  const rendererCoverage = {
    oracleCases: rendererSnapshot.cases.length,
    staticRendererCases,
    supportedStaticBlocks,
    probedStaticBlocks: [...probedStaticBlocks].sort(),
    uncoveredStaticBlocks: supportedStaticBlocks.filter((name) => !probedStaticBlocks.has(name)),
  };
  assert.equal(rendererSnapshot.schemaVersion, 1);
  assert.equal(rendererSnapshot.cases.length, 101);
  assert.equal(staticRendererCases, 90);
  assert.deepEqual(rendererSnapshot.coverage, rendererCoverage);
  assert.deepEqual(coverage.rendererProbes, {
    schemaVersion: rendererSnapshot.schemaVersion,
    sha256: sha256File(rendererFile),
    ...rendererCoverage,
  });
  assert.deepEqual(rendererCoverage.uncoveredStaticBlocks, []);
  for (const [name, block] of Object.entries(runtime.registered)) {
    for (const probes of Object.values(block.saveProbes)) {
      for (const probe of Array.isArray(probes) ? probes : [probes]) {
        assert.notEqual(probe.kind, 'error', `${name} has a failed save probe`);
      }
    }
  }
  for (const supported of runtime.supported.filter((entry) => (
    entry.strategy === 'DYNAMIC_NULL'
  ))) {
    assert.ok(
      runtime.registered[supported.name].saveProbes.manifest.every((probe) => (
        probe.kind === 'null'
      )),
      `${supported.name} must have a verified null save`
    );
  }
  assert.equal(sha256File(runtimeFile), manifest.registry.runtimeJsonSha256);
  assert.equal(
    sha256File(path.join(root, 'src', 'BlockSerializer', 'Registry', 'generated-registry.php')),
    manifest.registry.generatedPhpSha256
  );
  assertSupportedManifestMatches(
    path.join(root, 'src', 'BlockSerializer', 'Registry', 'supported-blocks.php')
  );
});

test('every committed input reaches its golden fixed point and is idempotent', () => {
  const transform = prepareOracle();
  const casesRoot = path.join(fixtures, 'cases');
  for (const caseName of fs.readdirSync(casesRoot).sort()) {
    const caseRoot = path.join(casesRoot, caseName);
    if (!fs.existsSync(path.join(caseRoot, 'case.json'))) continue;
    const metadata = JSON.parse(fs.readFileSync(path.join(caseRoot, 'case.json')));
    const report = JSON.parse(fs.readFileSync(path.join(caseRoot, 'report.json')));
    const repairs = JSON.parse(fs.readFileSync(path.join(caseRoot, 'repairs.json')));
    assert.equal(repairs.k, repairs.repairs.length, caseName);
    assert.equal(repairs.secondInvocation.k, 0, caseName);
    for (const inputFile of listHtmlFiles(path.join(caseRoot, 'input'))) {
      const relative = path.relative(path.join(caseRoot, 'input'), inputFile);
      const input = fs.readFileSync(inputFile, 'utf8');
      const expected = fs.readFileSync(path.join(caseRoot, 'expected', relative), 'utf8');
      const expectedReport = report.files.find((entry) => entry.path === relative.split(path.sep).join('/'));
      assert.ok(expectedReport, `${caseName}/${relative} missing report row`);
      if (!input.includes('<!-- wp:')) {
        assert.equal(expected, input, `${caseName}/${relative}`);
        assert.equal(expectedReport.status, 'skip', `${caseName}/${relative}`);
        continue;
      }
      const result = transformToFixedPoint(input, transform);
      assert.equal(result.html, expected, `${caseName}/${relative}`);
      const expectedFile = metadata.oracle.files.find((entry) => (
        entry.path === relative.split(path.sep).join('/')
      ));
      assert.equal(result.passes, expectedFile.passes, `${caseName}/${relative}`);
      const second = transformToFixedPoint(result.html, transform);
      assert.equal(second.html, result.html, `${caseName}/${relative}`);
      assert.equal(second.passes, 1, `${caseName}/${relative}`);
      assert.deepEqual(
        detectDroppedContentRecords(input, result.html),
        expectedReport.dropped,
        `${caseName}/${relative}`
      );
    }
  }
  const regression = JSON.parse(fs.readFileSync(path.join(
    casesRoot,
    'tbilisi25-footer-fixed-point',
    'case.json'
  )));
  assert.equal(regression.oracle.files[0].passes, 3);
});

test('built-in and deprecation instrumentation is present in reviewed cases', () => {
  const repairsCase = JSON.parse(fs.readFileSync(path.join(
    fixtures,
    'cases',
    'group-html-attribute-repairs',
    'case.json'
  )));
  const positiveRepairFile = repairsCase.oracle.files.find((file) => (
    file.path === 'parts/content.html'
  ));
  assert.ok(positiveRepairFile, 'positive built-in repair fixture is missing');
  const codes = new Set(
    positiveRepairFile.iterations.flatMap((iteration) => (
      iteration.instrumentation.builtInRepairs.map((repair) => repair.code)
    ))
  );
  assert.deepEqual([...codes].sort(), [
    'anchor-recovery',
    'aria-label-recovery',
    'custom-class-recovery',
  ]);
  const convergenceCase = JSON.parse(fs.readFileSync(path.join(
    fixtures,
    'cases',
    'tbilisi25-footer-fixed-point',
    'case.json'
  )));
  assert.ok(convergenceCase.oracle.files[0].iterations.some((iteration) => (
    iteration.instrumentation.deprecations.length > 0
  )));
});
