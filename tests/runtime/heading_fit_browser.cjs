// Real layout regression: node tests/runtime/heading_fit_browser.cjs
// Uses the existing screenshot workspace's Playwright; CHROME_BINARY is optional.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const repo = path.resolve(__dirname, '../..');
const css = execFileSync('php', ['-r', `require 'src/bootstrap.php'; echo (new ReflectionClass(Automattic\\SiteBuild\\Steps\\ScaffoldThemeStep::class))->getConstant('STYLE_CSS'); echo Automattic\\SiteBuild\\Steps\\PageStylesStep::WORD_WRAP_CSS;`], { cwd: repo, encoding: 'utf8' });
const script = path.join(repo, 'assets/heading-fit.js');
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
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(`<style>${css}
          body { margin: 0; font-family: Arial, sans-serif; }
          .hero-composition--authored { width: 90%; margin: auto; }
          .columns { display: grid; grid-template-columns: 2fr 1fr; gap: 40px; }
          .copy { min-width: 0; padding: 0 24px; }
          h1,h2 { margin: 0; font-weight: 900; }
          #velocity { font-size: 71px; letter-spacing: -.05em; }
          #purposeful { font-size: 50px; }
          #words { font-size: 32px; }
          #cover { display: flex; justify-content: center; }
          #cover .copy { width: auto; max-width: 90%; }
          #cover h2 { font-size: 45px; }
          #hidden { display: none; width: 180px; }
          #hidden h2 { font-size: 70px; }
          #grouped { width:403px; font-size:128px; line-height:.8; }
          #long-groups { width:180px; font-size:32px; }
          @media(max-width:781px) { .columns { grid-template-columns: 1fr; } }
        </style>
        <section class="hero-composition--authored"><div class="columns"><div>Image region</div><div class="copy">
          <h1 id="velocity" class="wp-block-heading">Velocity</h1>
        </div></div></section>
        <section class="hero-composition--authored"><h1 id="purposeful" class="wp-block-heading">Meri<br><em>dian</em></h1>
          <div style="width:180px"><h2 id="words" class="wp-block-heading">Bread made slowly</h2></div>
          <div id="cover"><div class="copy"><h2 id="intrinsic" class="wp-block-heading">Stone keeps time</h2></div></div>
          <div id="hidden"><h2 class="wp-block-heading">Velocity</h2></div>
          <h1 id="grouped" class="wp-block-heading">When Your Best<br>Just Isn't<br><mark>Good Enough</mark></h1>
          <h2 id="long-groups" class="wp-block-heading">This long line has far too many short words to fit on one line at a sane size<br>Next line</h2>
        </section><h2 id="outside" style="font-size:70px">Unrelated heading</h2>`);
        const originals = await page.locator('#purposeful,#words,#outside,#long-groups').evaluateAll(els => els.map(el => el.outerHTML));
        if (fs.existsSync(script)) await page.addScriptTag({ path: script });
        const settle = () => page.evaluate(() => document.fonts.ready.then(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))));
        const check = async selector => page.locator(selector).evaluate(el => {
            const box = el.getBoundingClientRect();
            const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
            const lines = new Set();
            const rects = [];
            while (walker.nextNode()) {
                const node = walker.currentNode;
                for (let i = 0; i < node.length; i++) {
                    if (/\s/.test(node.data[i])) continue;
                    const range = document.createRange(); range.setStart(node, i); range.setEnd(node, i + 1);
                    for (const r of range.getClientRects()) { lines.add(Math.round(r.top)); rects.push({left:r.left,right:r.right}); }
                }
            }
            return { lines: lines.size, fits: rects.every(r => r.left >= box.left - 1 && r.right <= box.right + 1), width:box.width, size:parseFloat(getComputedStyle(el).fontSize), wrap:getComputedStyle(el).overflowWrap };
        });
        for (const width of [1440, 1024, 900, 820, 782, 781, 390, 320, 1440]) {
            await page.setViewportSize({width,height:900}); await settle();
            const actual = await check('#velocity');
            assert.equal(actual.wrap, 'normal', `whole-word rule at ${width}`);
            assert.equal(actual.lines, 1, `Velocity never splits at ${width}`);
            assert.equal(actual.fits, true, `Velocity fits its actual column at ${width}`);
            if (width === 1440) assert.equal(actual.size, 71, 'restores the authored size when it fits');
        }
        assert.deepEqual(await page.locator('#purposeful,#words,#outside,#long-groups').evaluateAll(els => els.map(el => el.outerHTML)), originals, 'purposeful breaks, naturally wrapping phrases, unreadably long groups and unrelated headings stay byte-identical');
        assert.equal((await check('#purposeful')).lines, 2, 'purposeful break remains');
        assert.equal((await check('#grouped')).lines, 3, 'explicit headline groups fit instead of becoming a tower of words');
        assert.ok((await check('#grouped')).size >= 16, 'group fitting remains readable');
        assert.ok((await check('#words')).lines > 1, 'natural wrapping at spaces remains');
        assert.ok((await check('#intrinsic')).width > 200, 'content-sized cover does not collapse');
        await page.locator('#hidden').evaluate(el => { el.style.display = 'block'; }); await settle();
        assert.equal((await check('#hidden h2')).fits, true, 'newly visible heading fits');
        await page.locator('.columns').evaluate(el => { el.style.gridTemplateColumns = '3fr 1fr'; }); await settle();
        assert.equal((await check('#velocity')).fits, true, 'container resize without viewport change refits');
        await page.locator('#velocity').evaluate(el => { el.style.fontFamily = 'monospace'; });
        await page.evaluate(() => document.fonts.dispatchEvent(new Event('loadingdone'))); await settle();
        assert.equal((await check('#velocity')).fits, true, 'font load refit is stable');
        assert.deepEqual(errors, []);
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
        console.log('PASS heading fit and layout: explicit groups, readability fallback, 9 viewport transitions, natural wrapping, intrinsic layout, hidden reveal, container resize, font events, responsive left/right spacing ownership');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
