'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const REPOSITORY_ROOT = path.resolve(__dirname, '..', '..', '..');
const BLOCK_FIXER_ROOT = path.resolve(__dirname, '..');

const ORACLE_PACKAGES = [
  '@wordpress/blocks',
  '@wordpress/block-library',
  '@wordpress/block-editor',
  '@wordpress/block-serialization-default-parser',
  '@wordpress/style-engine',
  '@wordpress/rich-text',
  '@wordpress/element',
  'react',
  'react-dom',
  'jsdom',
];

const ORACLE_CONTAINER = Object.freeze({
  image: 'docker.io/library/node:22.19.0-bookworm-slim',
  indexDigest: 'sha256:4a4884e8a44826194dff92ba316264f392056cbe243dcc9fd3551e71cea02b90',
  linuxAmd64Digest: 'sha256:cff78eb5aa1cf27dc2b6aeea9d31366415a43e9a9ea0ddec00d780b2b66fad0f',
  sourceRevision: '8a6ee0d86da85db74f5bcf99c9dc6ef87203592f',
});

function sha256(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

function sha256File(file) {
  return sha256(fs.readFileSync(file));
}

function packageVersion(name) {
  const packageFile = require.resolve(`${name}/package.json`, {
    paths: [BLOCK_FIXER_ROOT],
  });
  return JSON.parse(fs.readFileSync(packageFile, 'utf8')).version;
}

function createOracleFingerprint() {
  const packages = {};
  for (const name of ORACLE_PACKAGES) {
    packages[name] = packageVersion(name);
  }
  const implementationSources = {};
  for (const relative of [
    'bin/block-fixer/fix-templates.js',
    'bin/block-fixer/oracle.js',
    'bin/block-fixer/lib/blockFixer.js',
    'bin/block-fixer/lib/paragraphFixer.js',
    'bin/block-fixer/lib/domEnvironment.js',
    'bin/block-fixer/lib/droppedContentDetector.js',
    'bin/block-fixer/lib/fixedPoint.js',
    'bin/block-fixer/lib/oracleFingerprint.js',
  ]) {
    implementationSources[relative] = sha256File(path.join(REPOSITORY_ROOT, relative));
  }
  return {
    node: {
      version: process.version,
      platform: process.platform,
      architecture: process.arch,
      v8: process.versions.v8,
      icu: process.versions.icu,
    },
    container: ORACLE_CONTAINER,
    packageLockSha256: sha256File(path.join(REPOSITORY_ROOT, 'package-lock.json')),
    packages,
    implementationSources,
    instrumentation: {
      version: require('./oracleInstrumentation').INSTRUMENTATION_VERSION,
      sourceSha256: sha256File(path.join(__dirname, 'oracleInstrumentation.js')),
      captures: [
        'validation-result',
        'built-in-custom-class-recovery',
        'built-in-anchor-recovery',
        'built-in-aria-label-recovery',
        'deprecation-index-and-before-after',
      ],
    },
  };
}

module.exports = {
  BLOCK_FIXER_ROOT,
  ORACLE_CONTAINER,
  ORACLE_PACKAGES,
  REPOSITORY_ROOT,
  createOracleFingerprint,
  sha256,
  sha256File,
};
