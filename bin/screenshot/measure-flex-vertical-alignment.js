#!/usr/bin/env node
'use strict';

/**
 * Measure text baselines and visible-box centers inside known flex rows.
 *
 * Usage:
 *   node bin/screenshot/measure-flex-vertical-alignment.js <case> <url> [options]
 *
 * Cases: amber-ember-nav, calm-lantern-nav, sunny-ember-nav,
 *        silver-summit-hero-ctas
 * Options:
 *   --width=<px>     viewport width (default 1366)
 *   --height=<px>    viewport height (default 1000)
 *   --chrome=<path>  Chrome/Chromium executable (or CHROME/CHROME_BIN)
 */

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright-core');
const { version: playwrightVersion } = require('playwright-core/package.json');

const CASES = Object.freeze({
  'amber-ember-nav': Object.freeze({
    row: 'header nav.blocks-engine-css-owned-layout',
    items: '.navlinks a',
    expectedItems: 4,
  }),
  'calm-lantern-nav': Object.freeze({
    row: 'header nav.blocks-engine-css-owned-layout',
    items: '.navlinks a, nav.blocks-engine-css-owned-layout > .wp-block-buttons:last-of-type .wp-block-button__link',
    expectedItems: 5,
  }),
  'sunny-ember-nav': Object.freeze({
    row: 'header nav.blocks-engine-css-owned-layout',
    items: '.navlinks a',
    expectedItems: 5,
  }),
  'silver-summit-hero-ctas': Object.freeze({
    row: '#hero .actions.blocks-engine-css-owned-layout',
    items: '#hero .actions.blocks-engine-css-owned-layout > .wp-block-buttons .wp-block-button__link',
    expectedItems: 2,
  }),
});

function positiveInteger(raw, option) {
  const value = Number(raw);
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`${option} must be a positive integer: ${raw}`);
  }
  return value;
}

function parseArgs(argv) {
  const positional = [];
  const opts = {
    width: 1366,
    height: 1000,
    chrome: process.env.CHROME || process.env.CHROME_BIN,
  };
  for (const arg of argv) {
    if (arg.startsWith('--width=')) opts.width = positiveInteger(arg.slice(8), '--width');
    else if (arg.startsWith('--height=')) opts.height = positiveInteger(arg.slice(9), '--height');
    else if (arg.startsWith('--chrome=')) opts.chrome = arg.slice(9);
    else if (arg.startsWith('--')) throw new Error(`unknown option: ${arg}`);
    else positional.push(arg);
  }
  opts.caseName = positional[0];
  opts.url = positional[1];
  if (!Object.hasOwn(CASES, opts.caseName || '')) {
    throw new Error(`case must be one of: ${Object.keys(CASES).join(', ')}`);
  }
  if (!opts.url) throw new Error('url is required');
  return opts;
}

function findOnPath(bin) {
  for (const dir of (process.env.PATH || '').split(path.delimiter)) {
    if (!dir) continue;
    const candidate = path.join(dir, bin);
    try {
      fs.accessSync(candidate, fs.constants.X_OK);
      return candidate;
    } catch { /* keep looking */ }
  }
  return null;
}

function findChrome(explicit) {
  if (explicit) {
    if (explicit.includes('/') || explicit.includes('\\')) {
      if (fs.existsSync(explicit)) return explicit;
      throw new Error(`Chrome/Chromium executable does not exist: ${explicit}`);
    }
    const resolved = findOnPath(explicit);
    if (resolved) return resolved;
    throw new Error(`Could not find Chrome/Chromium executable named ${explicit}`);
  }
  for (const candidate of [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ]) {
    if (fs.existsSync(candidate)) return candidate;
  }
  throw new Error('Could not find Chrome/Chromium. Pass --chrome=<path>.');
}

function round(value) {
  return Math.round(value * 1000) / 1000;
}

function summarizeMeasurements(items) {
  if (items.length === 0) throw new Error('cannot summarize zero items');
  const baselines = items.map((item) => item.baseline_from_row_top_px);
  const centers = items.map((item) => item.box_center_from_row_center_px);
  return {
    baseline_spread_px: round(Math.max(...baselines) - Math.min(...baselines)),
    box_center_range_px: [round(Math.min(...centers)), round(Math.max(...centers))],
  };
}

async function wordpressVersion(page, url) {
  try {
    const response = await page.request.get(new URL('/wp-admin/about.php', url).href);
    const html = await response.text();
    const match = html.match(/WordPress\s+(\d+\.\d+(?:\.\d+)?)/i)
      || html.match(/version-([0-9]+(?:-[0-9]+){1,2})/i);
    return match ? match[1].replaceAll('-', '.') : null;
  } catch {
    return null;
  }
}

async function measure(page, config) {
  return page.evaluate(({ rowSelector, itemSelector, expectedItems }) => {
    const row = document.querySelector(rowSelector);
    if (!(row instanceof HTMLElement)) throw new Error(`row not found: ${rowSelector}`);
    const anchors = Array.from(document.querySelectorAll(itemSelector));
    if (anchors.length !== expectedItems) {
      throw new Error(`expected ${expectedItems} items, found ${anchors.length}: ${itemSelector}`);
    }
    const rowRect = row.getBoundingClientRect();
    const rowStyle = getComputedStyle(row);
    const markerStyle = [
      'display:inline-block',
      'width:0',
      'height:0',
      'padding:0',
      'margin:0',
      'border:0',
      'vertical-align:baseline',
    ].join(';');
    const rounded = (value) => Math.round(value * 1000) / 1000;
    const items = anchors.map((anchor) => {
      const marker = document.createElement('span');
      marker.setAttribute('style', markerStyle);
      marker.setAttribute('aria-hidden', 'true');
      anchor.append(marker);
      const markerRect = marker.getBoundingClientRect();
      const itemRect = anchor.getBoundingClientRect();
      marker.remove();

      let directRowChild = anchor;
      while (directRowChild.parentElement && directRowChild.parentElement !== row) {
        directRowChild = directRowChild.parentElement;
      }
      if (directRowChild.parentElement !== row) {
        throw new Error(`item is not inside row: ${anchor.textContent}`);
      }
      const directRect = directRowChild.getBoundingClientRect();
      const directStyle = getComputedStyle(directRowChild);

      return {
        label: (anchor.textContent || '').replace(/\s+/g, ' ').trim(),
        baseline_from_row_top_px: rounded(markerRect.bottom - rowRect.top),
        box_top_from_row_top_px: rounded(itemRect.top - rowRect.top),
        box_bottom_from_row_top_px: rounded(itemRect.bottom - rowRect.top),
        box_center_from_row_center_px: rounded(
          ((itemRect.top + itemRect.bottom) / 2) - ((rowRect.top + rowRect.bottom) / 2),
        ),
        direct_row_child: {
          tag: directRowChild.tagName.toLowerCase(),
          class: directRowChild.className,
          margin_block_start: directStyle.marginBlockStart,
          margin_block_end: directStyle.marginBlockEnd,
          top_from_row_top_px: rounded(directRect.top - rowRect.top),
          bottom_from_row_top_px: rounded(directRect.bottom - rowRect.top),
        },
      };
    });
    return {
      row: {
        selector: rowSelector,
        display: rowStyle.display,
        align_items: rowStyle.alignItems,
        width_px: rounded(rowRect.width),
        height_px: rounded(rowRect.height),
      },
      items,
      font_status: document.fonts.status,
    };
  }, {
    rowSelector: config.row,
    itemSelector: config.items,
    expectedItems: config.expectedItems,
  });
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  const executablePath = findChrome(opts.chrome);
  const browser = await chromium.launch({
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'],
  });
  try {
    const page = await browser.newPage({
      viewport: { width: opts.width, height: opts.height },
      reducedMotion: 'reduce',
    });
    await page.goto(opts.url, { waitUntil: 'networkidle', timeout: 60000 });
    await page.evaluate(() => document.fonts.ready);
    const measurements = await measure(page, CASES[opts.caseName]);
    const result = {
      schema: 'minimalistic-site-builder/flex-vertical-alignment-measurement/v1',
      case: opts.caseName,
      generated_at: new Date().toISOString(),
      url: opts.url,
      viewport: { width_px: opts.width, height_px: opts.height },
      runtime: {
        chrome: browser.version(),
        playwright: playwrightVersion,
        wordpress: await wordpressVersion(page, opts.url),
      },
      method: 'zero-size inline-block baseline marker; marker bottom minus row top',
      ...measurements,
      summary: summarizeMeasurements(measurements.items),
    };
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`flex alignment measurement failed: ${error.message}\n`);
    process.exit(1);
  });
}

module.exports = { CASES, parseArgs, summarizeMeasurements };
