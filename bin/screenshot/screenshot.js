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
 *   --motion         Capture with prefers-reduced-motion: no-preference, i.e.
 *                    the branch real visitors get. Off by default; see the
 *                    reducedMotion note at the capture below. Trades the
 *                    default's freedom from reveal races for that coverage;
 *                    the capture visits every reveal target and waits for
 *                    finite entrances before taking the full-page image.
 *   --chrome=<path>  Chrome/Chromium executable (or set CHROME/CHROME_BIN).
 *   --timeout=<ms>   Per-image load wait budget (default 15000).
 */

const { chromium } = require('playwright-core');

function parseArgs(argv) {
  const positiveInteger = (raw) => {
    const value = Number(raw);
    return Number.isInteger(value) && value > 0 ? value : null;
  };
  const envWidth = positiveInteger(process.env.SHOT_WIDTH || '');
  const opts = {
    width: envWidth ?? 1366,
    scroll: true,
    motion: false,
    timeout: 15000,
    chrome: process.env.CHROME || process.env.CHROME_BIN,
  };
  const positional = [];
  for (const a of argv) {
    if (a === '--no-scroll') opts.scroll = false;
    else if (a === '--motion') opts.motion = true;
    else if (a.startsWith('--width=')) {
      opts.width = positiveInteger(a.slice(8));
      if (opts.width === null) throw new Error(`--width must be a positive integer: ${a}`);
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

/**
 * Exercise every scroll-reveal target and wait for finite entrance animations
 * to settle before a full-page motion capture. A full-page screenshot does not
 * itself scroll the viewport, and the coarse lazy-load walk can jump over a
 * short target's IntersectionObserver trigger. Capturing immediately after
 * that walk produced evidence with random cards and whole sections still at
 * opacity:0.
 *
 * This never adds reveal classes or changes authored CSS. A genuinely broken
 * target therefore remains hidden in the screenshot; the helper only gives
 * the site's own driver and animations enough viewport time to do their work.
 */
async function settleMotion(page, timeout) {
  await page.evaluate(async (timeout) => {
    const selector = [
      '.reveal',
      '.reveal-up',
      '.reveal-fade',
      '.reveal-scale',
      '.reveal-blur',
      '.reveal-wipe',
      '.reveal-wipe-up',
      '.reveal-aperture',
      '.reveal-zoom',
      '.stagger-children > *',
      '.count-up',
    ].join(',');
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const targets = Array.from(document.querySelectorAll(selector));
    const deadline = Date.now() + timeout;

    for (const target of targets) {
      target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
      await sleep(80);
    }

    const root = document.documentElement;
    while (Date.now() < deadline
      && root.classList.contains('motion-js')
      && targets.some((target) => target.classList.contains('motion-target')
        && !target.classList.contains('is-visible'))) {
      await sleep(50);
    }

    const remaining = Math.max(0, deadline - Date.now());
    const finiteAnimations = document.getAnimations().filter((animation) => {
      const timing = animation.effect && animation.effect.getTiming
        ? animation.effect.getTiming()
        : null;
      return !timing || timing.iterations !== Infinity;
    });
    await Promise.race([
      Promise.allSettled(finiteAnimations.map((animation) => animation.finished)),
      sleep(remaining),
    ]);

    window.scrollTo(0, 0);
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  }, timeout);
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (!opts.url || !opts.out) {
    process.stderr.write(
      'Usage: node bin/screenshot/screenshot.js <url> <outfile.png> ' +
      '[--width=1366] [--no-scroll] [--motion] [--chrome=<path>] [--timeout=15000]\n');
    process.exit(1);
  }

  const browser = await chromium.launch({
    executablePath: findChrome(opts.chrome),
    headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--hide-scrollbars'],
  });
  try {
    // Emulate prefers-reduced-motion by default: the motion kit's
    // accessibility contract (assets/motion/motion.css) serves reduced-motion
    // visitors a fully static, fully visible page, so the capture can never
    // race a scroll reveal and photograph opacity:0 sections or mid-flight
    // transforms.
    //
    // That default is also a blind spot. Every rule the kit and the generated
    // CSS put inside `@media (prefers-reduced-motion: no-preference)` is
    // switched OFF for the capture, so a defect that only exists in the branch
    // real visitors get never reaches the evidence: pulso2 shipped a hero whose
    // copy was clipped to nothing and photographed perfectly (BIGR-881). Pass
    // --motion to capture the branch a default visitor actually sees.
    const page = await browser.newPage({
      viewport: { width: opts.width, height: 900 },
      reducedMotion: opts.motion ? 'no-preference' : 'reduce',
    });
    await page.goto(opts.url, { waitUntil: 'networkidle', timeout: 60000 });

    if (opts.scroll) {
      await autoScroll(page);
      await waitForImages(page, opts.timeout);
    }
    if (opts.motion && opts.scroll) {
      await settleMotion(page, Math.max(4000, Math.min(opts.timeout, 10000)));
    }

    await page.screenshot({ path: opts.out, fullPage: true });
    const notes = [
      opts.scroll ? null : 'lazy-load scroll skipped',
      opts.motion ? 'motion enabled' : null,
    ].filter(Boolean);
    process.stderr.write(`Saved ${opts.out}${notes.length ? ` (${notes.join('; ')})` : ''}\n`);
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

module.exports = { parseArgs, settleMotion };
