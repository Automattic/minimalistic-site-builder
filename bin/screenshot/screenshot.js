#!/usr/bin/env node
'use strict';

/**
 * Capture a full-page screenshot of a URL with a headless browser.
 *
 *   node bin/screenshot/screenshot.js <url> <outfile.png> [options]
 *
 * The headache this solves: a plain `fullPage` capture renders the whole
 * document height in one shot, but it never *scrolls* the viewport. Images far
 * down a tall page that use `loading="lazy"` (or a JS lazy-loader keyed off the
 * scroll/IntersectionObserver) are still un-fetched at capture time, so they
 * show up as empty boxes — even though the asset exists on disk.
 *
 * The fix: before capturing, scroll the page top-to-bottom in steps to trip
 * every lazy-load trigger, promote any remaining `loading="lazy"` images to
 * eager, then wait for all `document.images` to actually finish decoding. Only
 * then do we screenshot.
 *
 * Driven with Playwright (playwright-core) against the system Chrome — no
 * bundled-browser download — so it can screenshot a generated theme served by
 * bin/playground.php.
 *
 * Options:
 *   --width=<px>     Viewport width (default 1366, or SHOT_WIDTH).
 *   --no-scroll      Skip the lazy-load scroll/wait (reproduces the old bug).
 *   --chrome=<path>  Chrome/Chromium executable (or set CHROME/CHROME_BIN).
 *   --timeout=<ms>   Per-image load wait budget (default 15000).
 *   --nav-timeout=<ms>  Navigation budget (default 60000).
 *   --idle-timeout=<ms> How long to let the network settle after navigating
 *                    (default 15000). Exceeding it captures the painted page
 *                    rather than failing — many sites never go idle at all.
 *   --scale=<f>      Device scale factor (default 1). Below 1 the page still
 *                    lays out at --width CSS pixels but the bitmap shrinks, so
 *                    desktop breakpoints still apply. Vision cost scales with
 *                    pixel AREA, so --scale=0.5 is a 4x saving.
 *   --slices=<n>     Instead of one full-page image, write up to n viewport-tall
 *                    slices from the top as <out>-1.png … <out>-n.png. Vision
 *                    models downscale to a fixed long edge, so a 1280x14641
 *                    full-page strip arrives ~137px wide and illegible; slices
 *                    stay under the limit and keep their detail. Default 1,
 *                    which writes exactly <out> full-page as before.
 *
 * Every written path is echoed to stdout, one per line.
 */

const { chromium } = require('playwright-core');

/** Capture viewport height, and the height of one --slices slice. */
const VIEWPORT_HEIGHT = 900;

function parseArgs(argv) {
  const positiveInteger = (raw) => {
    const value = Number(raw);
    return Number.isInteger(value) && value > 0 ? value : null;
  };
  const envWidth = positiveInteger(process.env.SHOT_WIDTH || '');
  const opts = {
    width: envWidth ?? 1366,
    scroll: true,
    timeout: 15000,
    navTimeout: 60000,
    idleTimeout: 15000,
    scale: 1,
    slices: 1,
    chrome: process.env.CHROME || process.env.CHROME_BIN,
  };
  const positional = [];
  for (const a of argv) {
    if (a === '--no-scroll') opts.scroll = false;
    else if (a.startsWith('--width=')) {
      opts.width = positiveInteger(a.slice(8));
      if (opts.width === null) throw new Error(`--width must be a positive integer: ${a}`);
    }
    else if (a.startsWith('--slices=')) {
      opts.slices = positiveInteger(a.slice(9));
      if (opts.slices === null) throw new Error(`--slices must be a positive integer: ${a}`);
    }
    else if (a.startsWith('--nav-timeout=')) {
      opts.navTimeout = positiveInteger(a.slice(14));
      if (opts.navTimeout === null) throw new Error(`--nav-timeout must be a positive integer (ms): ${a}`);
    }
    else if (a.startsWith('--idle-timeout=')) {
      opts.idleTimeout = positiveInteger(a.slice(15));
      if (opts.idleTimeout === null) throw new Error(`--idle-timeout must be a positive integer (ms): ${a}`);
    }
    else if (a.startsWith('--scale=')) {
      const value = Number(a.slice(8));
      if (!Number.isFinite(value) || value <= 0) throw new Error(`--scale must be a positive number: ${a}`);
      opts.scale = value;
    }
    else if (a.startsWith('--chrome=')) opts.chrome = a.slice(9);
    else if (a.startsWith('--timeout=')) {
      opts.timeout = positiveInteger(a.slice(10));
      if (opts.timeout === null) throw new Error(`--timeout must be a positive integer (ms): ${a}`);
    }
    else if (a.startsWith('--')) throw new Error(`unknown option: ${a}`);
    else positional.push(a);
  }
  opts.url = positional[0];
  opts.out = positional[1];
  return opts;
}

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
 * Scroll the document so lazy-loaders fire for every section, then return the
 * viewport to the top. Runs inside the page. maxY of 0 means the whole page;
 * a positive value stops there, so slicing the top of a very long page does
 * not pay to scroll through the rest of it.
 */
async function autoScroll(page, step = 600, pause = 150, maxY = 0) {
  await page.evaluate(async ({ step, pause, maxY }) => {
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
        if (maxY > 0 && y >= maxY) break;
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
  }, { step, pause, maxY });
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

/**
 * Write the capture(s) and return every path written. One full-page image by
 * default; with --slices=n, up to n viewport-tall clips from the top, stopping
 * early once the page runs out of content.
 */
async function capture(page, opts) {
  if (opts.slices === 1) {
    await page.screenshot({ path: opts.out, fullPage: true });
    return [opts.out];
  }

  const path = require('path');
  const ext = path.extname(opts.out);
  const stem = ext ? opts.out.slice(0, -ext.length) : opts.out;
  const pageHeight = await page.evaluate(() => document.documentElement.scrollHeight);

  const written = [];
  for (let i = 0; i < opts.slices; i++) {
    const y = i * VIEWPORT_HEIGHT;
    if (i > 0 && y >= pageHeight) break;
    const out = `${stem}-${i + 1}${ext}`;
    await page.screenshot({
      path: out,
      fullPage: true,
      clip: { x: 0, y, width: opts.width, height: Math.min(VIEWPORT_HEIGHT, pageHeight - y) },
    });
    written.push(out);
  }
  return written;
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (!opts.url || !opts.out) {
    process.stderr.write(
      'Usage: node bin/screenshot/screenshot.js <url> <outfile.png> ' +
      '[--width=1366] [--no-scroll] [--chrome=<path>] [--timeout=15000] [--slices=1]\n');
    process.exit(1);
  }

  const browser = await chromium.launch({
    executablePath: findChrome(opts.chrome),
    headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'],
  });
  try {
    // Emulate prefers-reduced-motion: the motion kit's accessibility contract
    // (assets/motion/motion.css) serves reduced-motion visitors a fully
    // static, fully visible page, so the capture can never race a scroll
    // reveal and photograph opacity:0 sections or mid-flight transforms.
    // deviceScaleFactor, not a smaller viewport: the page still lays out at
    // opts.width CSS pixels (so desktop breakpoints apply) and only the output
    // bitmap shrinks. Capturing at a narrow width would analyze a phone layout.
    const page = await browser.newPage({
      viewport: { width: opts.width, height: VIEWPORT_HEIGHT },
      deviceScaleFactor: opts.scale,
      reducedMotion: 'reduce',
    });
    // Third-party pages routinely never go idle — analytics beacons, chat
    // widgets and polling keep a request in flight forever — so waiting for
    // networkidle as a navigation condition fails on sites that painted fine
    // seconds earlier. Navigate first, then give the network a bounded chance
    // to settle. The scroll and image waits below are the real safety net.
    await page.goto(opts.url, { waitUntil: 'domcontentloaded', timeout: opts.navTimeout });
    await page.waitForLoadState('networkidle', { timeout: opts.idleTimeout }).catch(() => {
      process.stderr.write('network never went idle; capturing the painted page\n');
    });

    if (opts.scroll) {
      // Slicing only captures the top of the page, so only trip the
      // lazy-loaders that far — one extra screenful for safety.
      const scrollTo = opts.slices === 1 ? 0 : (opts.slices + 1) * VIEWPORT_HEIGHT;
      await autoScroll(page, 600, 150, scrollTo);
      await waitForImages(page, opts.timeout);
    }

    const written = await capture(page, opts);
    for (const out of written) process.stdout.write(`${out}\n`);
    process.stderr.write(
      `Saved ${written.join(', ')}${opts.scroll ? '' : ' (lazy-load scroll skipped)'}\n`);
  } finally {
    await browser.close();
  }
}

if (require.main === module) {
  main().catch((err) => {
    process.stderr.write(`screenshot failed: ${err.message}\n`);
    process.exit(1);
  });
}

module.exports = { parseArgs };
