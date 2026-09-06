#!/usr/bin/env node
'use strict';

/**
 * Capture one screenshot per delivered section archetype on a served site.
 *
 *   node bin/screenshot/sections.js <url> <outdir> [options]
 *
 * The archetype gallery (bin/archetypes.php capture) needs images per archetype,
 * not one image per page. Every generated part carries the marker class its
 * catalog owns — `hero-composition--<id>`, `section-composition--<id>` — and
 * the header and footer are the template parts around them. This walks those
 * elements, screenshots each one on its own, and prints a JSON manifest of
 * what it captured so the PHP side can pair an image with a catalog entry.
 *
 * Lazy-load handling is shared with screenshot.js: scroll the whole document,
 * promote lazy images, wait for decoding, and only then capture.
 *
 * Options:
 *   --width=<px>     Viewport width (default 1366, or SHOT_WIDTH).
 *   --prefix=<s>     Filename prefix for every image (default "site").
 *   --chrome=<path>  Chrome/Chromium executable (or set CHROME/CHROME_BIN).
 *   --timeout=<ms>   Per-image load wait budget (default 15000).
 *
 * Prints to stdout: {"captures":[{"family","archetype","file","width","height"}]}
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

/* The lazy-load helpers below are lifted from screenshot.js verbatim so this
   command stays standalone: a catalog capture must never depend on an edit to
   the shared full-page screenshot tool. */


function findChrome(explicit) {
  const fs = require('fs');
  const path = require('path');
  const findOnPath = (bin) => {
    for (const dir of (process.env.PATH || '').split(path.delimiter)) {
      if (!dir) continue;
      const candidate = path.join(dir, bin);
      try {
        fs.accessSync(candidate, fs.constants.X_OK);
        return candidate;
      } catch { /* ignore */ }
    }
    return null;
  };

  if (explicit) {
    if (explicit.includes('/') || explicit.includes('\\')) {
      if (fs.existsSync(explicit)) return explicit;
      throw new Error(`Chrome/Chromium executable does not exist: ${explicit}`);
    }
    const resolved = findOnPath(explicit);
    if (resolved) return resolved;
    throw new Error(`Could not find Chrome/Chromium executable named ${explicit}. Pass --chrome=<path>.`);
  }

  const candidates = [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ];
  for (const c of candidates) {
    try { if (fs.existsSync(c)) return c; } catch { /* ignore */ }
  }
  throw new Error('Could not find Chrome/Chromium. Pass --chrome=<path> or set CHROME/CHROME_BIN.');
}

/**
 * Scroll the whole document so lazy-loaders fire for every section, then return
 * the viewport to the top. Runs inside the page.
 */
async function autoScroll(page, step = 600, pause = 150) {
  await page.evaluate(async ({ step, pause }) => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const root = document.documentElement;
    const body = document.body;
    const previousRootScrollBehavior = root.style.scrollBehavior;
    const previousBodyScrollBehavior = body?.style.scrollBehavior;

    root.style.scrollBehavior = 'auto';
    if (body) body.style.scrollBehavior = 'auto';

    try {
      let lastHeight = -1;
      for (let y = 0; ; y += step) {
        window.scrollTo(0, y);
        await sleep(pause);
        const height = document.documentElement.scrollHeight;
        if (y + window.innerHeight >= height) {
          // Reached the bottom, but lazy content may have grown the page. Do
          // one more loop if the height changed, otherwise stop.
          if (height === lastHeight) break;
          lastHeight = height;
        }
      }
    } finally {
      window.scrollTo(0, 0);
      root.style.scrollBehavior = previousRootScrollBehavior;
      if (body) body.style.scrollBehavior = previousBodyScrollBehavior;
    }
  }, { step, pause });
}

/**
 * Promote any still-lazy images to eager and wait for every image on the page
 * to finish loading (or fail, or hit the per-image timeout). Runs in the page.
 */
async function waitForImages(page, timeout) {
  await page.evaluate(async (timeout) => {
    const imgs = Array.from(document.images);
    const isDone = (img) => img.complete && img.naturalWidth > 0;
    const waitOne = (img) => {
      img.loading = 'eager';
      if (img.dataset) {
        if (img.dataset.srcset) img.srcset = img.dataset.srcset;
        if (img.dataset.sizes) img.sizes = img.dataset.sizes;
        if (img.dataset.src) img.src = img.dataset.src;
      }
      if (isDone(img)) return Promise.resolve();
      return new Promise((resolve) => {
        const timer = setTimeout(resolve, timeout);
        const finish = () => { clearTimeout(timer); resolve(); };
        img.addEventListener('load', finish, { once: true });
        img.addEventListener('error', finish, { once: true });
        if (img.decode) img.decode().then(finish, () => {});
      });
    };
    await Promise.all(imgs.map(waitOne));
  }, timeout);
}

/** Minimum element height worth treating as a real capture, in CSS pixels. */
const MIN_HEIGHT = 8;

/** Smallest header strip worth showing, in CSS pixels. */
const OVERLAY_STRIP = 240;

/** How much of what sits under the header the header strip keeps for context. */
const CONTEXT_STRIP = 180;

/**
 * Remove the WordPress admin bar. The runner boots the site logged in, so
 * every header strip would otherwise open with 32px of Playground chrome —
 * which no visitor of the generated site ever sees.
 */
async function hideAdminBar(page) {
  await page.evaluate(() => {
    document.getElementById('wpadminbar')?.remove();
    document.documentElement.style.setProperty('margin-top', '0', 'important');
    document.body.classList.remove('admin-bar');
    const style = document.createElement('style');
    style.textContent = 'html { margin-top: 0 !important; } #wpadminbar { display: none !important; }';
    document.head.appendChild(style);
  });
}

/**
 * Stop the header painting over every capture that follows it.
 *
 * The trusted header shell is `position: sticky` (or fixed, for an overlay
 * header that solidifies), so it floats at the top of the viewport. Playwright
 * scrolls each element to the top of the viewport before photographing it, and
 * the floating bar lands exactly over the first band of that element — a
 * section crop then opens with the site header sitting on its own heading.
 *
 * visibility, not display: the header keeps its box, so nothing below it
 * reflows and every element is captured at the size it really has. Call this
 * AFTER the header's own capture.
 */
async function suppressHeader(page) {
  await page.evaluate(() => {
    const root = document.querySelector('.wp-site-blocks') || document.body;
    const header = root.querySelector(':scope > header')
      || document.querySelector('header.site-header-shell')
      || document.querySelector('.site-header-shell')
      || document.querySelector('header');
    if (header === null) return;
    header.setAttribute('data-catalog-suppressed', '');
    const style = document.createElement('style');
    style.textContent = '[data-catalog-suppressed] { visibility: hidden !important; }';
    document.head.appendChild(style);
  });
}

/** Full document height, so a header strip never asks for pixels the page lacks. */
async function pageHeight(page) {
  return page.evaluate(() => Math.round(document.documentElement.scrollHeight));
}

function parseArgs(argv) {
  const positiveInteger = (raw) => {
    const value = Number(raw);
    return Number.isInteger(value) && value > 0 ? value : null;
  };
  const envWidth = positiveInteger(process.env.SHOT_WIDTH || '');
  const opts = {
    width: envWidth ?? 1366,
    prefix: 'site',
    timeout: 15000,
    chrome: process.env.CHROME || process.env.CHROME_BIN,
  };
  const positional = [];
  for (const a of argv) {
    if (a.startsWith('--width=')) {
      opts.width = positiveInteger(a.slice(8));
      if (opts.width === null) throw new Error(`--width must be a positive integer: ${a}`);
    } else if (a.startsWith('--prefix=')) opts.prefix = a.slice(9);
    else if (a.startsWith('--chrome=')) opts.chrome = a.slice(9);
    else if (a.startsWith('--timeout=')) {
      opts.timeout = positiveInteger(a.slice(10));
      if (opts.timeout === null) throw new Error(`--timeout must be a positive integer (ms): ${a}`);
    } else if (a.startsWith('--')) throw new Error(`unknown option: ${a}`);
    else positional.push(a);
  }
  opts.url = positional[0];
  opts.outdir = positional[1];
  return opts;
}

/**
 * List the capture targets in document order. Runs in the page and returns
 * plain data; the caller re-queries each target by index to capture it.
 */
async function findTargets(page) {
  return page.evaluate(({ minHeight }) => {
    const markerOf = (element, prefix) => {
      for (const name of element.classList) {
        if (name.startsWith(prefix)) return name.slice(prefix.length);
      }
      return '';
    };
    const root = document.querySelector('.wp-site-blocks') || document.body;
    const targets = [];

    const header = root.querySelector(':scope > header') || document.querySelector('header');
    if (header) targets.push({ family: 'header', archetype: '', selectorIndex: 0, tag: 'header' });

    const compositions = Array.from(
      document.querySelectorAll('[class*="hero-composition--"], [class*="section-composition--"]')
    );
    compositions.forEach((element, index) => {
      const hero = markerOf(element, 'hero-composition--');
      const section = markerOf(element, 'section-composition--');
      if (!hero && !section) return;
      targets.push({
        family: hero ? 'hero' : 'section',
        archetype: hero || section,
        selectorIndex: index,
        tag: 'composition',
      });
    });

    const footer = root.querySelector(':scope > footer') || document.querySelector('footer');
    if (footer) targets.push({ family: 'footer', archetype: '', selectorIndex: 0, tag: 'footer' });

    // Report the measured height so the caller can flag an empty capture.
    return targets.map((target) => {
      let element = null;
      if (target.tag === 'header') element = root.querySelector(':scope > header') || document.querySelector('header');
      else if (target.tag === 'footer') element = root.querySelector(':scope > footer') || document.querySelector('footer');
      else element = compositions[target.selectorIndex];
      const box = element ? element.getBoundingClientRect() : { width: 0, height: 0 };
      return { ...target, width: Math.round(box.width), height: Math.round(box.height) };
    }).filter((target) => target.width > 0 || target.height > minHeight);
  }, { minHeight: MIN_HEIGHT });
}

/** Re-resolve one target to a Playwright element handle. */
async function handleFor(page, target) {
  if (target.tag === 'header' || target.tag === 'footer') {
    return await page.$(`.wp-site-blocks > ${target.tag}`) ?? await page.$(target.tag);
  }
  const all = await page.$$('[class*="hero-composition--"], [class*="section-composition--"]');
  return all[target.selectorIndex] ?? null;
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (!opts.url || !opts.outdir) {
    process.stderr.write(
      'Usage: node bin/screenshot/sections.js <url> <outdir> ' +
      '[--width=1366] [--prefix=site] [--chrome=<path>] [--timeout=15000]\n');
    process.exit(1);
  }
  fs.mkdirSync(opts.outdir, { recursive: true });

  const browser = await chromium.launch({
    executablePath: findChrome(opts.chrome),
    headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'],
  });
  const captures = [];
  try {
    // Reduced motion, like screenshot.js: a static page cannot photograph a
    // reveal mid-flight, and a half-revealed band is useless as catalog art.
    const page = await browser.newPage({
      viewport: { width: opts.width, height: 900 },
      reducedMotion: 'reduce',
    });
    await page.goto(opts.url, { waitUntil: 'networkidle', timeout: 60000 });
    await hideAdminBar(page);
    await autoScroll(page);
    await waitForImages(page, opts.timeout);
    await page.evaluate(() => window.scrollTo(0, 0));

    const targets = await findTargets(page);
    const used = new Map();
    let headerSuppressed = false;
    for (const target of targets) {
      // findTargets returns document order, so the header is photographed
      // first and every later capture runs without the floating bar.
      if (target.family !== 'header' && !headerSuppressed) {
        await suppressHeader(page);
        headerSuppressed = true;
      }
      const handle = await handleFor(page, target);
      if (handle === null) continue;
      const name = [target.family, target.archetype].filter(Boolean).join('-');
      const seen = (used.get(name) ?? 0) + 1;
      used.set(name, seen);
      const file = path.join(
        opts.outdir,
        `${opts.prefix}-${name}${seen > 1 ? `-${seen}` : ''}.png`
      );

      let box = await handle.boundingBox();
      if (target.family === 'header') {
        // A header alone is a thin bar, and a minimal-overlay one reports no
        // box at all because it paints over the hero. Photograph the top strip
        // in both cases: the bar plus the first slice of what sits under it,
        // which is the only way the archetype reads as a composition.
        const bar = box === null ? 0 : Math.round(box.height);
        const height = Math.min(await pageHeight(page), Math.max(bar + CONTEXT_STRIP, OVERLAY_STRIP));
        await page.screenshot({ path: file, clip: { x: 0, y: 0, width: opts.width, height } });
        box = { width: opts.width, height };
      } else if (box === null || box.height < MIN_HEIGHT) {
        continue;
      } else {
        await handle.screenshot({ path: file });
      }
      captures.push({
        family: target.family,
        archetype: target.archetype,
        file,
        width: Math.round(box.width),
        height: Math.round(box.height),
      });
    }
  } finally {
    await browser.close();
  }
  process.stdout.write(JSON.stringify({ captures }, null, 2) + '\n');
}

if (require.main === module) {
  main().catch((err) => {
    process.stderr.write(`section capture failed: ${err.message}\n`);
    process.exit(1);
  });
}

module.exports = { parseArgs };
