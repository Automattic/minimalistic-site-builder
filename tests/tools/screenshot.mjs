// Full-page screenshot of a URL with the chromium already on this machine.
// Usage: node tests/tools/screenshot.mjs <url> <out.png> [width]
import { chromium } from 'playwright-core';

const [url, out, widthArg] = process.argv.slice(2);
if (!url || !out) {
  console.error('usage: screenshot.mjs <url> <out.png> [width]');
  process.exit(2);
}
const width = Number(widthArg ?? 1440);
const executablePath = process.env.CHROMIUM ?? '/opt/homebrew/bin/chromium';

const browser = await chromium.launch({ executablePath, headless: true });
try {
  const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  await page.goto(url, { waitUntil: 'networkidle', timeout: 120000 });
  // A full-page capture does not scroll, so WordPress's loading="lazy"
  // images below the fold would stay blank: walk the page first.
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 600) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 100));
    }
    window.scrollTo(0, 0);
  });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
  await page.screenshot({ path: out, fullPage: true });
  console.log(out);
} finally {
  await browser.close();
}
