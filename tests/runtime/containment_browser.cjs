// NODE_PATH=<screenshot dependencies>/node_modules node tests/runtime/containment_browser.cjs
// Real browser reproduction of the Super Coaching Bauhaus auto-sized flex child.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright-core');
const repo = path.resolve(__dirname, '../..');
const authored = fs.readFileSync(path.join(repo, 'tests/fixtures/page-styles-containment.css'), 'utf8');
const delivered = execFileSync('php', ['-r', 'require "src/bootstrap.php"; echo Automattic\\SiteBuild\\Steps\\PageStylesStep::dropOffendingDeclarations($argv[1])[0];', authored], {cwd:repo, encoding:'utf8'});
(async () => {
    const executablePath = process.env.CHROME_BINARY || (process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : undefined);
    const browser = await chromium.launch({executablePath, headless:true});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        const render = async css => page.setContent(`<style>
          body {margin:0;font-family:Arial,sans-serif} main {width: min(90vw,600px)}
          h1 {font-size:48px;margin:0;overflow-wrap:normal;word-break:normal}
          ${css}</style><main><section class="design-hero-copy"><div class="design-hero-headline"><h1>When your best just isn't good enough</h1></div><p class="design-hero-support">Business coaching in Plymouth, NH.</p></section></main>`);
        const width = () => page.locator('.design-hero-headline').evaluate(el => el.getBoundingClientRect().width);
        await render(authored);
        assert.ok(await width() < 1, 'fixture reproduces the original desktop collapse');
        await render(delivered);
        for (const viewport of [1440,1024,782,781,390,320,1440]) {
            await page.setViewportSize({width:viewport,height:1000});
            const bounds = await page.locator('.design-hero-headline').evaluate(el => ({width:el.getBoundingClientRect().width,parent:el.parentElement.getBoundingClientRect().width,containment:getComputedStyle(el).containerType}));
            assert.ok(bounds.width > 250, `headline has readable width at ${viewport}: ${bounds.width}`);
            assert.ok(bounds.width <= bounds.parent + 1, `headline remains in its parent at ${viewport}`);
            assert.equal(bounds.containment, 'normal');
            assert.equal(await page.locator('h1').textContent(), "When your best just isn't good enough");
        }
        console.log('PASS: original containment collapse reproduced; production CSS salvage restores desktop/mobile headline width across 7 viewport transitions.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1});
