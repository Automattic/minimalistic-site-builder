#!/usr/bin/env node

/**
 * Block validation fixer — CLI runner.
 *
 * Port of the telex block-fixer (server/scripts/block-fixer) to a one-shot CLI.
 * telex runs the fixer as a warm HTTP sidecar (fix-html.js); the builder runs it
 * once after the landing-page step, so a plain CLI is a better fit. The fixing
 * logic itself is unchanged — lib/blockFixer.js and lib/paragraphFixer.js are
 * copied verbatim from telex.
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
 * Each <themeDir> is a block-theme root; the runner fixes every
 * templates/*.html and parts/*.html file under it in place. Exits non-zero only
 * on a hard error; files with no block markup are skipped.
 */

const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

// ── Set up the DOM globals @wordpress/blocks needs, BEFORE importing it.
// (Mirrors the worker setup in telex's fix-html.js.)
const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', {
  url: 'http://localhost',
  pretendToBeVisual: true,
});

global.window = dom.window;
global.document = dom.window.document;
global.DOMParser = dom.window.DOMParser;
global.XMLSerializer = dom.window.XMLSerializer;
global.Node = dom.window.Node;
global.Element = dom.window.Element;
global.HTMLElement = dom.window.HTMLElement;
global.getComputedStyle = dom.window.getComputedStyle;
global.MutationObserver = dom.window.MutationObserver;
global.requestAnimationFrame = (cb) => setTimeout(cb, 16);
global.cancelAnimationFrame = (id) => clearTimeout(id);
global.matchMedia = () => ({
  matches: false,
  addListener: () => {},
  removeListener: () => {},
  addEventListener: () => {},
  removeEventListener: () => {},
});
global.ResizeObserver = class ResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
};

Object.defineProperty(global, 'navigator', {
  value: dom.window.navigator,
  writable: true,
  configurable: true,
});

// @wordpress/blocks is extremely chatty: for every block it auto-fixes it calls
// console.groupCollapsed + console.info('...%o...', blockType), dumping the whole
// block-type object. On a fresh build that repairs many blocks this is hundreds
// of KB. Silence the noise entirely (it is purely informational) so neither
// stdout nor stderr is polluted — we write our own report via process.stdout.
// Genuine errors still come through console.error.
const noop = () => {};
console.log = noop;
console.info = noop;
console.warn = noop;
console.debug = noop;
console.dir = noop;
console.trace = noop;
console.group = noop;
console.groupCollapsed = noop;
console.groupEnd = noop;

const out = (line) => process.stdout.write(line + '\n');

const { fixBlocksInTemplate } = require('./lib/blockFixer.js');

// ── Dropped-content detection.
//
// Re-serialization regenerates each block's HTML from its comment JSON
// attributes, so any inline style declaration or class token that exists only
// in the authored HTML is silently deleted (issue #48: card cropping, padding
// and shadows lost). Diff the pre/post markup per file and report every loss
// prominently so regressions are visible in each build's fix-blocks.log.

/** Count each `prop:value` declaration across all style="..." attributes. */
function styleDeclarationCounts(html) {
  const counts = new Map();
  for (const m of html.matchAll(/\bstyle\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
    const value = m[1] ?? m[2] ?? '';
    for (const raw of value.split(';')) {
      const decl = raw.trim();
      if (!decl) continue;
      // Gutenberg canonicalizes harmless whitespace around the declaration
      // colon (e.g. `padding-top: var(...)` → `padding-top:var(...)`). Compare
      // that semantic spelling so formatting-only changes are not reported as
      // lost CSS and do not trip the PHP build gate.
      const colon = decl.indexOf(':');
      const normalized = colon === -1
        ? decl
        : decl.slice(0, colon).trim().toLowerCase() + ':' + decl.slice(colon + 1).trim();
      counts.set(normalized, (counts.get(normalized) || 0) + 1);
    }
  }
  return counts;
}

/** Count each class token across all class="..." attributes. */
function classTokenCounts(html) {
  const counts = new Map();
  for (const m of html.matchAll(/\bclass\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
    const value = m[1] ?? m[2] ?? '';
    for (const token of value.split(/\s+/)) {
      if (token) counts.set(token, (counts.get(token) || 0) + 1);
    }
  }
  return counts;
}

/** Values whose occurrence count decreased from before to after. */
function droppedValues(before, after) {
  const dropped = [];
  for (const [value, count] of before) {
    const remaining = after.get(value) || 0;
    if (remaining < count) dropped.push({ value, lost: count - remaining });
  }
  return dropped;
}

/**
 * Human-readable loss report for one file: every style declaration and class
 * token present in the original markup but absent (or less frequent) after
 * re-serialization. Empty array when nothing was lost.
 */
function detectDroppedContent(original, fixed) {
  const lines = [];
  for (const d of droppedValues(styleDeclarationCounts(original), styleDeclarationCounts(fixed))) {
    lines.push(
      `DROPPED style \`${d.value}\`` +
        (d.lost > 1 ? ` (x${d.lost})` : '') +
        ' — not mirrored in the block comment JSON attributes'
    );
  }
  for (const d of droppedValues(classTokenCounts(original), classTokenCounts(fixed))) {
    lines.push(
      `DROPPED class \`${d.value}\`` +
        (d.lost > 1 ? ` (x${d.lost})` : '') +
        ' — not mirrored in the block comment JSON attributes'
    );
  }
  return lines;
}

/**
 * Collect templates/*.html and parts/*.html under a theme directory — plus
 * pages/*.html, so the content plugin's directory (whose block markup lives
 * in pages/) can be re-serialized through the same entry point.
 */
function collectFiles(themeDir) {
  const out = [];
  for (const sub of ['templates', 'parts', 'pages']) {
    const dir = path.join(themeDir, sub);
    if (!fs.existsSync(dir)) continue;
    for (const name of fs.readdirSync(dir)) {
      if (name.endsWith('.html')) out.push(path.join(dir, name));
    }
  }
  return out;
}

function main() {
  const themeDirs = process.argv.slice(2);
  if (themeDirs.length === 0) {
    console.error('Usage: node fix-templates.js <themeDir> [<themeDir> ...]');
    process.exit(2);
  }

  let totalFiles = 0;
  let totalChanged = 0;
  let totalIssues = 0;
  let totalDropped = 0;
  const report = [];

  for (const themeDir of themeDirs) {
    if (!fs.existsSync(themeDir)) {
      console.error(`[fix-templates] theme dir not found: ${themeDir}`);
      process.exitCode = 1;
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
    out(`  ${tag} ${r.file}`);
    for (const loss of r.dropped || []) {
      out(`         ! ${loss}`);
    }
    for (const issue of r.issues || []) {
      out(`         - ${issue}`);
    }
  }
  out(
    `\n[fix-templates] ${totalChanged}/${totalFiles} file(s) re-serialized,` +
      ` ${totalIssues} issue(s) fixed,` +
      ` ${totalDropped} style/class value(s) dropped across ${themeDirs.length} theme(s).`
  );
}

main();
