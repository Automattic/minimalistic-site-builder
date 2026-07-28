'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');
const assert = require('node:assert/strict');
const { collectFiles, runCli } = require('../fix-templates');

test('CLI module is importable without executing main', () => {
  const result = spawnSync(
    process.execPath,
    ['-e', "require('./bin/block-fixer/fix-templates.js')"],
    { cwd: path.resolve(__dirname, '..', '..', '..'), encoding: 'utf8' }
  );
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout, '');
  assert.equal(result.stderr, '');
});

test('collectFiles returns normalized lexical paths across parts and templates', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'block-fixer-cli-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  fs.mkdirSync(path.join(root, 'parts'));
  fs.mkdirSync(path.join(root, 'templates'));
  fs.writeFileSync(path.join(root, 'templates', 'z.html'), '');
  fs.writeFileSync(path.join(root, 'parts', 'b.html'), '');
  fs.writeFileSync(path.join(root, 'parts', 'a.html'), '');
  assert.deepEqual(
    collectFiles(root).map((file) => path.relative(root, file).split(path.sep).join('/')),
    ['parts/a.html', 'parts/b.html', 'templates/z.html']
  );
});

test('runCli retains report grammar and shared dropped-content records', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'block-fixer-run-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  fs.mkdirSync(path.join(root, 'parts'));
  fs.writeFileSync(
    path.join(root, 'parts', 'changed.html'),
    '<!-- wp:test --><div class="lost" style="height:20px"></div><!-- /wp:test -->'
  );
  fs.writeFileSync(path.join(root, 'parts', 'skip.html'), '<main>plain</main>');
  const lines = [];
  const result = runCli([root], {
    fixBlocksInTemplate: () => ({
      html: '<!-- wp:test --><div></div><!-- /wp:test -->',
      changed: true,
      fixedIssues: ['test: repaired'],
    }),
    writeLine: (line) => lines.push(line),
    writeError: (line) => assert.fail(line),
  });
  assert.deepEqual(result.totals, {
    changed: 1, files: 1, issues: 1, dropped: 2, themes: 1,
  });
  assert.match(result.summary, /^\[fix-templates\] 1\/1 file\(s\) re-serialized,/);
  assert.ok(lines.some((line) => line.includes('DROPPED style `height:20px`')));
  assert.ok(lines.some((line) => line.includes('DROPPED class `lost`')));
  assert.deepEqual(result.report.map((entry) => entry.status), [
    'fixed',
    'skipped (no block markup)',
  ]);
});
