#!/usr/bin/env node

/**
 * Fail unless the running Node matches the runtime the frozen artifacts were
 * derived from.
 *
 * The artifacts record the version, platform, architecture, v8 and ICU of the
 * runtime that produced them, so verifying against a different one compares
 * bytes that were never meant to match. CI runs this before `oracle:verify`;
 * run it locally before regenerating.
 */

const fs = require('fs');
const path = require('path');

const MANIFEST = path.join(
	__dirname,
	'../../tests/fixtures/block-fixer/oracle-manifest.json'
);

const frozen = JSON.parse(fs.readFileSync(MANIFEST, 'utf8')).fingerprint.node;
const running = {
	version: process.version,
	platform: process.platform,
	architecture: process.arch,
	v8: process.versions.v8,
	icu: process.versions.icu,
};

const drifted = Object.keys(running).filter(
	(key) => frozen[key] !== running[key]
);

if (drifted.length === 0) {
	console.log(
		`runtime matches the frozen fingerprint (${running.version}, ${running.platform}/${running.architecture})`
	);
	process.exit(0);
}

for (const key of drifted) {
	console.error(`  ${key}: frozen ${frozen[key]}, running ${running[key]}`);
}
console.error(
	'\nIf the running value is the surprising one, install the pinned Node.\n' +
		'If the frozen value is, the artifacts were regenerated from the wrong\n' +
		'environment — see docs/block-fixer-oracle.md, "Which environment to\n' +
		'regenerate from".'
);
process.exit(1);
