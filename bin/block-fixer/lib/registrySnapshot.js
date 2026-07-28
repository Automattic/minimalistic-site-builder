'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { parse: grammarParse } = require('@wordpress/block-serialization-default-parser');
const { SUPPORTED_BLOCKS } = require('./supportManifest');

const INNER_SENTINEL = 'BLOCK_FIXER_INNER_SENTINEL_7f69d59a';

function snapshotValue(value, seen = new WeakSet()) {
  if (value === undefined) return { __type: 'undefined' };
  if (typeof value === 'function') return { __type: 'function', name: value.name || '' };
  if (typeof value === 'symbol') return { __type: 'symbol', value: String(value) };
  if (typeof value === 'bigint') return { __type: 'bigint', value: String(value) };
  if (typeof value === 'number' && !Number.isFinite(value)) {
    return { __type: 'number', value: String(value) };
  }
  if (value === null || typeof value !== 'object') return value;
  if (value instanceof RegExp) {
    return { __type: 'regexp', source: value.source, flags: value.flags };
  }
  if (seen.has(value)) return { __type: 'circular' };
  seen.add(value);
  if (Array.isArray(value)) return value.map((item) => snapshotValue(item, seen));
  const result = {};
  for (const key of Object.keys(value)) {
    result[key] = snapshotValue(value[key], seen);
  }
  return result;
}

function collectSourceInventory(attributes, prefix = '', output = []) {
  for (const [name, schema] of Object.entries(attributes || {})) {
    const attributePath = prefix ? `${prefix}.${name}` : name;
    if (schema && typeof schema === 'object') {
      output.push({
        attributePath,
        source: schema.source ?? null,
        selector: schema.selector ?? null,
        attribute: schema.attribute ?? null,
        type: schema.type ?? null,
      });
      if (schema.query && typeof schema.query === 'object') {
        collectSourceInventory(schema.query, `${attributePath}[]`, output);
      }
    }
  }
  return output;
}

function probe(type, attributes, innerBlocks, blocks) {
  try {
    const block = blocks.createBlock(type.name, attributes, innerBlocks);
    const element = blocks.getSaveElement(type, block.attributes, block.innerBlocks);
    const saveContent = blocks.getSaveContent(type, block.attributes, block.innerBlocks);
    return {
      kind: element === null
        ? 'null'
        : saveContent.includes(INNER_SENTINEL) ? 'inner-blocks' : 'element',
      attributes: snapshotValue(block.attributes),
      saveContent,
      serialized: blocks.serialize([block]),
    };
  } catch (error) {
    return {
      kind: 'error',
      error: { name: error.name, message: error.message },
    };
  }
}

function saveProbes(type, blocks) {
  const sentinel = blocks.createBlock('core/paragraph', { content: INNER_SENTINEL });
  const probes = {
    default: probe(type, {}, [], blocks),
    innerBlocks: probe(type, {}, [sentinel], blocks),
  };
  const manifest = SUPPORTED_BLOCKS[type.name];
  if (manifest) {
    probes.manifest = manifest.probes.map((attributes, index) => ({
      index,
      ...probe(type, attributes, [sentinel], blocks),
    }));
  }
  return probes;
}

function captureHookTrace() {
  const { filters } = require('@wordpress/hooks');
  const trace = {};
  for (const hookName of Object.keys(filters).sort()) {
    if (!hookName.startsWith('blocks.')) continue;
    trace[hookName] = (filters[hookName].handlers || []).map((handler, index) => ({
      index,
      namespace: handler.namespace,
      priority: handler.priority,
    }));
  }
  return trace;
}

function readPhpSupportedManifest(file) {
  const source = fs.readFileSync(file, 'utf8');
  const entries = [];
  const pattern = /'([^']+)'\s*=>\s*SaveStrategy::([A-Z_]+)/g;
  for (const match of source.matchAll(pattern)) {
    entries.push([match[1], match[2]]);
  }
  if (entries.length === 0) {
    throw new Error(`No supported blocks found in ${file}`);
  }
  return entries;
}

function assertSupportedManifestMatches(phpManifestFile) {
  const phpEntries = readPhpSupportedManifest(phpManifestFile);
  const jsEntries = Object.entries(SUPPORTED_BLOCKS).map(([name, value]) => [
    name,
    value.strategy,
  ]);
  if (JSON.stringify(phpEntries) !== JSON.stringify(jsEntries)) {
    throw new Error(
      'JS oracle support manifest differs from reviewed PHP supported-blocks.php\n'
      + `PHP: ${JSON.stringify(phpEntries)}\nJS:  ${JSON.stringify(jsEntries)}`
    );
  }
}

function collectRawNames(blocks, names) {
  for (const block of blocks) {
    if (block.blockName) {
      names.add(block.blockName.includes('/') ? block.blockName : `core/${block.blockName}`);
    }
    collectRawNames(block.innerBlocks || [], names);
  }
}

function scanObservedBlocks(fixturesRoot) {
  const names = new Set();
  const casesRoot = path.join(fixturesRoot, 'cases');
  if (!fs.existsSync(casesRoot)) return [];
  for (const caseName of fs.readdirSync(casesRoot).sort()) {
    for (const subdirectory of ['parts', 'templates']) {
      const directory = path.join(casesRoot, caseName, 'input', subdirectory);
      if (!fs.existsSync(directory)) continue;
      for (const name of fs.readdirSync(directory).sort()) {
        if (!name.endsWith('.html')) continue;
        collectRawNames(
          grammarParse(fs.readFileSync(path.join(directory, name), 'utf8')),
          names
        );
      }
    }
  }
  return [...names].sort();
}

function buildRegistrySnapshot(fixturesRoot) {
  const blocks = require('@wordpress/blocks');
  const types = blocks.getBlockTypes();
  const registered = {};
  for (const type of types) {
    registered[type.name] = {
      apiVersion: type.apiVersion ?? null,
      attributeOrder: Object.keys(type.attributes || {}),
      attributes: snapshotValue(type.attributes || {}),
      supports: snapshotValue(type.supports || {}),
      sourceInventory: collectSourceInventory(type.attributes || {}),
      saveProbes: saveProbes(type, blocks),
    };
  }
  const registeredNames = Object.keys(registered);
  const supported = Object.entries(SUPPORTED_BLOCKS).map(([name, value]) => ({
    name,
    strategy: value.strategy,
  }));
  for (const entry of supported) {
    if (!registered[entry.name]) {
      throw new Error(`Supported block ${entry.name} is absent from registered runtime`);
    }
    if (entry.strategy === 'DYNAMIC_NULL') {
      const kinds = registered[entry.name].saveProbes.manifest.map((item) => item.kind);
      if (kinds.some((kind) => kind !== 'null')) {
        throw new Error(`DYNAMIC_NULL probe for ${entry.name} rendered: ${kinds.join(', ')}`);
      }
    }
  }

  const observed = scanObservedBlocks(fixturesRoot);
  const observedRegistered = observed.filter((name) => registered[name]);
  const observedUnregistered = observed.filter((name) => !registered[name]);
  const supportedNames = new Set(supported.map((entry) => entry.name));
  const unsupportedObserved = observedRegistered.filter((name) => !supportedNames.has(name));
  if (unsupportedObserved.length > 0) {
    throw new Error(`Observed registered blocks are unsupported: ${unsupportedObserved.join(', ')}`);
  }

  return {
    schemaVersion: 1,
    counts: {
      registered: registeredNames.length,
      supported: supported.length,
      observed: observed.length,
      observedRegistered: observedRegistered.length,
      observedUnregistered: observedUnregistered.length,
    },
    registeredUniverse: registeredNames,
    supported,
    observed,
    observedRegistered,
    observedUnregistered,
    hookTrace: captureHookTrace(),
    registered,
  };
}

module.exports = {
  INNER_SENTINEL,
  assertSupportedManifestMatches,
  buildRegistrySnapshot,
  captureHookTrace,
  collectSourceInventory,
  readPhpSupportedManifest,
  scanObservedBlocks,
  snapshotValue,
};
