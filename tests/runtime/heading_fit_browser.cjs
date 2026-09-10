// Real layout regression: node tests/runtime/heading_fit_browser.cjs
// Uses the existing screenshot workspace's Playwright; CHROME_BINARY is optional.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const repo = path.resolve(__dirname, '../..');
const layoutMarkup = '<div class="wp-block-group design-region" style="padding-right:20px"><div class="wp-block-group design-copy">Copy</div></div>'
    + '<div class="wp-block-cover design-photo" style="min-height:68vh">Image slot</div>';
const layoutCss = '.design-copy {width:400px; margin-left:0; margin-right:auto;} .design-region {padding-right:48%;}'
    + '@media(max-width:781px) {.design-region {padding-right:20px;} .design-copy {width:200px; margin-left:auto; margin-right:0;}}'
    + '.design-photo {min-height:84vh;} @media(max-width:599px){.design-photo {min-height:42vh;}}';
const repairedLayoutCss = execFileSync('php', ['-r', 'require "src/bootstrap.php"; echo Automattic\\SiteBuild\\AuthoredLayoutCss::reconcile($argv[1], $argv[2])["css"];', layoutCss, layoutMarkup], {cwd:repo,encoding:'utf8'});

(async () => {
    const executablePath = process.env.CHROME_BINARY || (process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : undefined);
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1024, height: 900 } });
        await page.setContent(`<style>body{margin:0}.design-region {box-sizing:border-box; width:100%;padding-left:20px;display:flex}
          .design-region > * {margin-left:auto!important;margin-right:auto!important}
          ${repairedLayoutCss}</style>${layoutMarkup}`);
        for (const width of [1440,1024,390,1440]) {
            await page.setViewportSize({width,height:900});
            const box = await page.locator('.design-copy').boundingBox();
            assert.equal(Math.round(box.x), width < 782 ? 170 : 20, `authored ${width < 782 ? 'right' : 'left'} position beats Core margins at ${width}`);
            assert.equal(await page.locator('.design-region').evaluate(el=>Math.round(parseFloat(getComputedStyle(el).paddingRight))), width < 782 ? 20 : Math.round(width * .48), 'responsive CSS padding beats inline padding');
            assert.equal(await page.locator('.design-photo').evaluate(el=>Math.round(parseFloat(getComputedStyle(el).minHeight))), width < 600 ? 378 : 756, 'responsive image-slot height beats saved inline cover height');
        }
        const centeredCss = execFileSync('php', ['-r', 'require "src/bootstrap.php"; echo Automattic\\SiteBuild\\AuthoredLayoutCss::reconcile($argv[1], $argv[2])["css"];', '.design-region {padding-inline:20px;} .design-copy {width:400px;margin-inline:auto;}', layoutMarkup], {cwd:repo,encoding:'utf8'});
        await page.addStyleTag({content:centeredCss});
        assert.equal(Math.round((await page.locator('.design-copy').boundingBox()).x), 520, 'intentional centered composition stays centered');
        console.log('PASS layout ownership: responsive left/right and intentional centered layouts, padding, and cover minimum height');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
