'use strict';

// Read a proof's pattern-output.json and validate every saved block with the
// repository's pinned Gutenberg runtime. Does not normalize or repair output.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { installDomEnvironment } = require('../../bin/block-fixer/lib/domEnvironment');
installDomEnvironment({ forwardJsdomErrors: false });
const { initializeBlockRegistry } = require('../../bin/block-fixer/lib/blockFixer');
initializeBlockRegistry({ throwOnError: true });
const { parse } = require('@wordpress/blocks');
const output = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
let count = 0;
function validate(blocks, path) {
    for (const [index, block] of blocks.entries()) {
        const location = `${path}/${index}:${block.name}`;
        assert.equal(block.isValid, true, `${location}: ${JSON.stringify(block.validationIssues)}`);
        count++;
        validate(block.innerBlocks, location);
    }
}
for (const layout of output.layouts) {
    const blocks = parse(layout.markup);
    assert.ok(blocks.length > 0, `${layout.id} must contain blocks`);
    validate(blocks, layout.id);
}
process.stdout.write(`${count} Gutenberg blocks valid across ${output.layouts.length} layouts\n`);
