#!/usr/bin/env node

/**
 * Block validation fixer — CLI runner.
 *
 * Port of the telex block-fixer (server/scripts/block-fixer) to a one-shot CLI.
 * telex runs the fixer as a warm HTTP sidecar (fix-html.js); the builder runs it
 * once after the landing-page step, so a plain CLI is a better fit. The fixing
 * lib/blockFixer.js and lib/paragraphFixer.js originated in telex. Shared
 * environment/report modules and development instrumentation now live beside
 * them so the pinned one-pass transform can also drive the fixed-point oracle.
 *
 * What it does: parses each block-markup file with @wordpress/blocks and
 * re-serializes it, so the saved HTML matches exactly what WordPress save()
 * produces. This repairs the style/attribute/element-order mismatches that
 * cause "this block contains unexpected or invalid content" in the editor and
 * Playground for AI-generated markup.
 *
 * Usage:
 *   node fix-templates.js <themeDir> [<themeDir> ...]
 *
 * Each <themeDir> is a block-theme root; this production-compatibility runner
 * applies one transform pass to every
 * templates/*.html, parts/*.html, and pages/*.html file under it in place.
 * Exits non-zero only on a hard error; files with no block markup are skipped.
 * Development tools use oracle.js to repeat this exact pass to the
 * five-pass-capped fixed point.
 */

const fs = require('node:fs');
const path = require('node:path');
const { installDomEnvironment } = require('./lib/domEnvironment');
const { detectDroppedContent } = require('./lib/droppedContentDetector');

// @wordpress/blocks is extremely chatty: for every block it auto-fixes it calls
// console.groupCollapsed + console.info('...%o...', blockType), dumping the whole
// block-type object. On a fresh build that repairs many blocks this is hundreds
// of KB. Silence the noise entirely (it is purely informational) so neither
// stdout nor stderr is polluted — we write our own report via process.stdout.
// Genuine errors still come through console.error.
function silenceWordPressConsole() {
  const noop = () => {};
  for (const method of [
    'log', 'info', 'warn', 'debug', 'dir', 'trace',
    'group', 'groupCollapsed', 'groupEnd',
  ]) {
    console[method] = noop;
  }
}

function loadFixBlocksInTemplate() {
  installDomEnvironment();
  return require('./lib/blockFixer.js').fixBlocksInTemplate;
}

/** Collect templates/*.html, parts/*.html, and pages/*.html under a theme directory. */
function collectFiles(themeDir) {
  const files = [];
  for (const sub of ['templates', 'parts', 'pages']) {
    const dir = path.join(themeDir, sub);
    if (!fs.existsSync(dir)) continue;
    for (const name of fs.readdirSync(dir)) {
      if (name.endsWith('.html')) files.push(path.join(dir, name));
    }
  }
  return files.sort((left, right) => (
    path.relative(themeDir, left).localeCompare(path.relative(themeDir, right), 'en')
  ));
}

function runCli(themeDirs, {
  fixBlocksInTemplate = loadFixBlocksInTemplate(),
  writeLine = (line) => process.stdout.write(line + '\n'),
  writeError = (line) => console.error(line),
} = {}) {
  let totalFiles = 0;
  let totalChanged = 0;
  let totalIssues = 0;
  let totalDropped = 0;
  let exitCode = 0;
  const report = [];

  for (const themeDir of themeDirs) {
    if (!fs.existsSync(themeDir)) {
      writeError(`[fix-templates] theme dir not found: ${themeDir}`);
      exitCode = 1;
      continue;
    }
    if (!fs.statSync(themeDir).isDirectory()) {
      writeError(`[fix-templates] theme path is not a directory: ${themeDir}`);
      exitCode = 1;
      continue;
    }

    for (const file of collectFiles(themeDir)) {
      const rel = path.relative(themeDir, file);
      const original = fs.readFileSync(file, 'utf-8');

      if (!original.includes('<!-- wp:')) {
        report.push({ file: rel, status: 'skipped (no block markup)' });
        continue;
      }

      totalFiles++;
      const result = fixBlocksInTemplate(original);

      const issues = result.fixedIssues || [];
      const dropped = result.changed ? detectDroppedContent(original, result.html) : [];
      if (result.changed) {
        fs.writeFileSync(file, result.html, 'utf-8');
        totalChanged++;
        totalIssues += issues.length;
        totalDropped += dropped.length;
      }

      report.push({
        file: rel,
        status: result.changed ? 'fixed' : 'ok',
        issues,
        dropped,
      });
    }
  }

  // Human-readable report on stdout.
  for (const r of report) {
    const tag = r.status === 'fixed' ? 'FIXED ' : r.status === 'ok' ? 'ok    ' : 'skip  ';
    writeLine(`  ${tag} ${r.file}`);
    for (const loss of r.dropped || []) {
      writeLine(`         ! ${loss}`);
    }
    for (const issue of r.issues || []) {
      writeLine(`         - ${issue}`);
    }
  }
  const summary =
    `\n[fix-templates] ${totalChanged}/${totalFiles} file(s) re-serialized,` +
      ` ${totalIssues} issue(s) fixed,` +
      ` ${totalDropped} style/class value(s) dropped across ${themeDirs.length} theme(s).`;
  writeLine(summary);
  return {
    exitCode,
    report,
    summary: summary.trimStart(),
    totals: {
      changed: totalChanged,
      files: totalFiles,
      issues: totalIssues,
      dropped: totalDropped,
      themes: themeDirs.length,
    },
  };
}

function main(argv = process.argv.slice(2)) {
  if (argv.length === 0) {
    console.error('Usage: node fix-templates.js <themeDir> [<themeDir> ...]');
    return 2;
  }
  silenceWordPressConsole();
  try {
    return runCli(argv).exitCode;
  } catch (error) {
    console.error('[fix-templates] hard error:', error);
    return 1;
  }
}

if (require.main === module) {
  process.exitCode = main();
}

module.exports = {
  collectFiles,
  main,
  runCli,
  silenceWordPressConsole,
};
