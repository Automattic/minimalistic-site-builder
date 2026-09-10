'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { existsSync } = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { chromium } = require('playwright-core');

const root = path.resolve(__dirname, '../../..');
const executablePath = [process.env.CHROME_PATH, '/usr/bin/google-chrome', chromium.executablePath()]
  .find((file) => file && existsSync(file));

function fixture(variant, nested, direction) {
  const row = `<div class="wp-block-columns alignwide is-not-stacked-on-mobile ${variant === 'flush' ? 'list-thumb-flush' : ''}" style="${variant === 'framed' ? 'padding:16px;border:1px solid' : ''}">
    <div class="wp-block-column" style="flex-basis:18%"><figure class="card-media-thumb"><img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='144' height='144'/%3E"></figure></div>
    <div class="wp-block-column" style="flex-basis:82%;padding:16px"><h3>Menu item</h3><p>A short description identifies the dish and its main ingredients.</p></div>
  </div>`;
  const items = `${row}<hr class="wp-block-separator alignwide">${row}`;
  return `<html dir="${direction}"><body><section class="wp-block-group alignfull section-composition--list-with-thumbnails is-layout-constrained">
    <div class="wp-block-group alignwide copy-flush is-layout-constrained"><h2>Signature dishes</h2><p>One short introduction.</p></div>
    ${nested ? `<div class="wp-block-group alignwide is-layout-constrained">${items}</div>` : items}
    </section><section class="control is-layout-constrained"><div class="wp-block-group alignwide copy-flush is-layout-constrained"><h2>Other section</h2><p>Another short introduction.</p></div></section></body></html>`;
}

// These rules represent Core's constrained layout and column proportions.
const coreCss = `
  * { box-sizing: border-box; }
  :root { font: 16px Arial; --wp--style--block-gap:32px; }
  body { margin:0; padding:24px; }
  h2,h3,p,figure { margin:0; }
  .is-layout-constrained > * { max-width:680px; margin-left:auto !important; margin-right:auto !important; }
  .is-layout-constrained > .alignwide { max-width:1560px; }
  .wp-block-columns { display:flex; flex-wrap:nowrap; gap:32px; margin-block:24px; }
  .wp-block-column { flex-grow:1; min-width:0; }
  .wp-block-column h3 { font-size:24px; }
  .wp-block-separator { width:100%; }
  @media(max-width:781px) { .wp-block-columns:not(.is-not-stacked-on-mobile) { flex-wrap:wrap; } }
`;

test('thumbnail lists stay narrow while introduction containers keep the standard width', { skip: !executablePath }, async () => {
  const css = execFileSync('php', ['-r',
    'require "src/bootstrap.php"; echo (new ReflectionClass(Automattic\\SiteBuild\\Steps\\ScaffoldThemeStep::class))->getConstant("STYLE_CSS");',
  ], { cwd: root, encoding: 'utf8' });
  const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    for (const width of [375, 768, 1440, 1920, 2560]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const variant of ['plain', 'framed', 'flush']) {
        for (const nested of [false, true]) {
          for (const direction of ['ltr', 'rtl']) {
            await page.setContent(fixture(variant, nested, direction));
            await page.addStyleTag({ content: css + coreCss });
            const result = await page.evaluate(() => {
              const box = (node) => {
                const rect = node.getBoundingClientRect();
                return { left: rect.left, right: rect.right, width: rect.width, top: rect.top };
              };
              const section = document.querySelector('.section-composition--list-with-thumbnails');
              return {
                intro: box(section.firstElementChild),
                copy: [...section.firstElementChild.children].map(box),
                container: box(section.children[1]),
                items: [...section.querySelectorAll('.wp-block-columns, hr')].map(box),
                rows: [...section.querySelectorAll('.wp-block-columns')].map((row) => [...row.children].map(box)),
                thumbs: [...section.querySelectorAll('figure')].map(box),
                control: box(document.querySelector('.control > div')),
                controlCopy: [...document.querySelector('.control > div').children].map(box),
                overflow: document.documentElement.scrollWidth > innerWidth,
              };
            });
            const label = `${width}/${variant}/${nested}/${direction}`;
            assert.equal(result.overflow, false, label);
            assert.deepEqual(result.intro, { ...result.control, top: result.intro.top }, label);
            for (const [index, copy] of result.copy.entries()) {
              const control = result.controlCopy[index];
              assert.equal(copy.width, control.width, label);
              assert.equal(copy.left, control.left, label);
            }
            assert.ok(result.container.width < 850, label);
            for (const item of result.items) {
              assert.ok(Math.abs(item.left - result.container.left) < 1, label);
              assert.ok(Math.abs(item.right - result.container.right) < 1, label);
            }
            for (const thumb of result.thumbs) assert.ok(thumb.width <= 144.1, label);
            for (const [media, copy] of result.rows) {
              assert.ok(Math.abs(media.top - copy.top) < 1, label);
              assert.ok(direction === 'ltr' ? media.right <= copy.left : copy.right <= media.left, label);
            }
            if (width >= 1440) assert.ok(result.intro.width > result.container.width, label);
          }
        }
      }
    }
  } finally {
    await browser.close();
  }
});
