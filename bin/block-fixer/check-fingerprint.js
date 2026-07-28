#!/usr/bin/env node

/**
 * Fail unless the running Node matches the runtime the frozen artifacts were
 * derived from.
 *
 * The artifacts record the version, platform, architecture, v8 and ICU of the
 * runtime that produced them, so verifying against a different one compares
 * bytes that were never meant to match. CI runs this before `oracle:verify`;
 * run it locally before regenerating.
 *
 * Deliberately reads nothing out of node_modules: it runs before `npm ci`, so
 * the answer arrives before a confusing install failure can bury it.
 */

const fs = require('node:fs');
const path = require('node:path');
const { REPOSITORY_ROOT } = require('./lib/oracleFingerprint');

const MANIFEST = path.join(
	REPOSITORY_ROOT,
	'tests/fixtures/block-fixer/oracle-manifest.json'
);

/** How to read each field the fingerprint can record, by its manifest name. */
const READERS = {
	version: () => process.version,
	platform: () => process.platform,
	architecture: () => process.arch,
	v8: () => process.versions.v8,
	icu: () => process.versions.icu,
};

const frozen = JSON.parse(fs.readFileSync(MANIFEST, 'utf8')).fingerprint.node;

// Driven by what the manifest records rather than by a second list kept here,
// so a field added to the fingerprint gets compared instead of silently
// skipped — which is exactly how a wrong architecture went unnoticed once.
const unreadable = Object.keys(frozen).filter((field) => !(field in READERS));
if (unreadable.length > 0) {
	console.error(
		`The frozen fingerprint records ${unreadable.join(', ')}, which this ` +
			'script cannot read. Add it to READERS.'
	);
	process.exit(1);
}

const drifted = Object.entries(frozen)
	.map(([field, want]) => ({ field, want, got: READERS[field]() }))
	.filter(({ want, got }) => want !== got);

if (drifted.length === 0) {
	console.log(
		`runtime matches the frozen fingerprint (${frozen.version}, ${frozen.platform}/${frozen.architecture})`
	);
	process.exit(0);
}

for (const { field, want, got } of drifted) {
	console.error(`  ${field}: frozen ${want}, running ${got}`);
}
console.error(
	'\nIf the running value is the surprising one, install the pinned Node.\n' +
		'If the frozen value is, the artifacts were regenerated from the wrong\n' +
		'environment — see docs/block-fixer-oracle.md, "Which environment to\n' +
		'regenerate from".'
);
process.exit(1);
