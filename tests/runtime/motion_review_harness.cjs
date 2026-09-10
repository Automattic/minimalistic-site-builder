'use strict';
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const read = (_, file) => fs.readFileSync(path.join(root, file), 'utf8');
(async () => {
const browser = await chromium.launch({executablePath:process.argv[2], headless:true, args:['--no-sandbox']});
try {
const page = await browser.newPage({viewport:{width:1280,height:800}});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const variables=':root{--wp--preset--color--base:#fff;--wp--preset--color--contrast:#111;--wp--preset--color--band:#222;--wp--preset--color--accent:#36f}body{margin:0}';
await page.setContent(`<style>${variables}${read(root,'assets/motion/motion.css')}${read(root,'assets/motion/profiles/dramatic.css')}</style><p id="marquee" class="marquee"><a id="original-link" href="#target">See our work</a> <span class="emph">today</span></p><div style="height:1200px"></div><h2 class="count-up" id="count">98<span class="emph">%</span></h2><div id="target" style="height:1000px"></div>`);
await page.addScriptTag({content:read(root,'assets/motion/motion.js')});
await page.waitForFunction(()=>document.querySelector('.marquee--built'));
assert.equal(await page.locator('#original-link').count(),1);
await page.locator('#original-link').focus();
assert.equal(await page.locator('.marquee__track').evaluate(e=>getComputedStyle(e).animationPlayState),'paused');
assert.equal(await page.locator('#original-link').getAttribute('href'),'#target');
await page.locator('#count').scrollIntoViewIfNeeded();
await page.waitForFunction(()=>document.querySelector('#count').dataset.countStarted==='true');
await page.waitForFunction(()=>document.querySelector('#count .emph')?.textContent==='%');
assert.equal(await page.locator('#count').textContent(),'98%');
assert.deepEqual(errors,[]);
console.log('PASS: marquee links, focus pause, duplicate IDs, and count-up inline markup');

// Preference changes happen after setup as well as before page load.
const preferenceFailures = [];
async function preferenceCase(name, run) {
    const dynamic = await browser.newPage({viewport:{width:800,height:600},reducedMotion:'no-preference'});
    try {
        const pageErrors = [];
        dynamic.on('pageerror', error => pageErrors.push(error.message));
        await run(dynamic);
        assert.deepEqual(pageErrors, []);
        console.log('PASS: ' + name);
    } catch (error) {
        preferenceFailures.push(name + ': ' + error.message);
    } finally {
        await dynamic.close();
    }
}
async function preferenceFixture(dynamic, counter = true) {
    await dynamic.setContent(`<style>${variables}${read(root,'assets/motion/motion.css')}${read(root,'assets/motion/profiles/dramatic.css')}:root{--motion-count-duration:5000ms}</style><p id="dynamic-marquee" class="marquee"><a id="kept-link" href="#dynamic-count">See our work</a> <em>today</em></p><p id="authored-tabindex" class="marquee" tabindex="-1">Keep this phrase</p>${counter ? '<div style="height:1500px"></div><h2 id="dynamic-count" class="count-up">98<span class="emph">%</span></h2><div style="height:1500px"></div>' : ''}`);
    await dynamic.evaluate(() => {
        window.keptLink = document.querySelector('#kept-link');
        window.originalMarquee = document.querySelector('#dynamic-marquee').innerHTML;
        window.originalCounter = document.querySelector('#dynamic-count')?.innerHTML;
        window.originalPercent = document.querySelector('#dynamic-count .emph');
        window.keptLink.addEventListener('click', event => { event.preventDefault(); window.linkClicked = true; });
    });
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    await dynamic.waitForSelector('.marquee--built');
}
await preferenceCase('reduced motion restores marquee nodes, focus and pending counters', async dynamic => {
    await preferenceFixture(dynamic);
    await dynamic.locator('#kept-link').focus();
    await dynamic.emulateMedia({reducedMotion:'reduce'});
    await dynamic.waitForTimeout(100);
    assert.equal(await dynamic.locator('#dynamic-marquee').innerHTML(), await dynamic.evaluate(() => window.originalMarquee));
    assert.equal(await dynamic.evaluate(() => document.activeElement === window.keptLink), true);
    assert.equal(await dynamic.locator('#dynamic-marquee').getAttribute('tabindex'), null);
    assert.equal(await dynamic.locator('#authored-tabindex').getAttribute('tabindex'), '-1');
    await dynamic.locator('#kept-link').click();
    assert.equal(await dynamic.evaluate(() => window.linkClicked), true);
    await dynamic.locator('#dynamic-count').scrollIntoViewIfNeeded();
    await dynamic.waitForTimeout(150);
    assert.equal(await dynamic.locator('#dynamic-count').getAttribute('data-count-started'), null);
    assert.equal(await dynamic.locator('#dynamic-count').innerHTML(), await dynamic.evaluate(() => window.originalCounter));
});
await preferenceCase('reduced motion cancels an active counter and restores its original nodes', async dynamic => {
    await preferenceFixture(dynamic);
    await dynamic.locator('#dynamic-count').scrollIntoViewIfNeeded();
    await dynamic.waitForFunction(() => document.querySelector('#dynamic-count').dataset.countStarted === 'true' && document.querySelector('#dynamic-count').textContent !== '98%');
    await dynamic.emulateMedia({reducedMotion:'reduce'});
    await dynamic.waitForTimeout(100);
    assert.equal(await dynamic.locator('#dynamic-count').innerHTML(), await dynamic.evaluate(() => window.originalCounter));
    assert.equal(await dynamic.evaluate(() => document.querySelector('#dynamic-count .emph') === window.originalPercent), true);
    await dynamic.waitForTimeout(150);
    assert.equal(await dynamic.locator('#dynamic-count').textContent(), '98%');
});
await preferenceCase('marquee-only pages restore once across repeated preference changes', async dynamic => {
    await preferenceFixture(dynamic, false);
    for (const preference of ['reduce', 'no-preference', 'reduce']) {
        await dynamic.emulateMedia({reducedMotion:preference});
        await dynamic.waitForTimeout(100);
    }
    assert.equal(await dynamic.locator('#dynamic-marquee').innerHTML(), await dynamic.evaluate(() => window.originalMarquee));
    assert.equal(await dynamic.locator('#kept-link').count(), 1);
    assert.equal(await dynamic.locator('.marquee__track').count(), 0);
});

await preferenceCase('a restored marquee stays fully readable when motion is enabled again', async dynamic => {
    await dynamic.setViewportSize({width:400,height:600});
    await dynamic.setContent(`<style>${variables}${read(root,'assets/motion/motion.css')}</style><p class="marquee is-long-line">Bellwether Press · Fathom Optics · Quarter Tone Records · Northline Ferry · Aperture Trust</p>`);
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    for (const reducedMotion of ['reduce', 'no-preference']) {
        await dynamic.emulateMedia({reducedMotion});
        await dynamic.waitForTimeout(100);
    }
    assert.equal(await dynamic.locator('.marquee').evaluate(el => el.scrollWidth <= el.clientWidth), true);
    assert.equal(await dynamic.locator('.marquee__track').count(), 0);
});
await preferenceCase('word reveal remains visible after keyboard focus leaves', async dynamic => {
    await dynamic.setContent(`<style>${read(root,'assets/motion/motion.css')}:root{--motion-hero-delay:10s}</style><h1 class="word-reveal"><a id="headline-link" href="#">Read this headline</a></h1><button id="next">Next</button>`);
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    await dynamic.locator('#headline-link').focus();
    await dynamic.locator('#next').focus();
    assert.equal(await dynamic.locator('.word-reveal__word').first().evaluate(el => getComputedStyle(el).opacity), '1');
    assert.equal(await dynamic.locator('.word-reveal__word').first().evaluate(el => getComputedStyle(el).animationName), 'none');
});
await preferenceCase('sticky cards retain preset and nested text colors on their section surface', async dynamic => {
    await dynamic.setContent(`<style>${variables}${read(root,'assets/motion/motion.css')}.has-base-color{color:#fff!important}</style><section style="background:#222;color:white"><div><div class="sticky-stack"><div class="has-base-color" style="background-color:transparent"><p>First card</p></div><div><p class="has-base-color">Nested text</p></div><div style="background:#def;color:#123">Authored surface</div></div></div></section>`);
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    const cards = await dynamic.locator('.sticky-stack > *').evaluateAll(els => els.map(el => ({background:getComputedStyle(el).backgroundColor,color:getComputedStyle(el.querySelector('p') || el).color,position:getComputedStyle(el).position})));
    assert.deepEqual(cards, [
        {background:'rgb(34, 34, 34)',color:'rgb(255, 255, 255)',position:'sticky'},
        {background:'rgb(34, 34, 34)',color:'rgb(255, 255, 255)',position:'sticky'},
        {background:'rgb(221, 238, 255)',color:'rgb(17, 34, 51)',position:'sticky'}
    ]);
    await dynamic.emulateMedia({reducedMotion:'reduce'});
    assert.equal(await dynamic.locator('.sticky-stack > *').first().evaluate(el => getComputedStyle(el).position), 'static');
});
await preferenceCase('sticky cards stay static without a safe solid backing', async dynamic => {
    const markup = `<style>${variables}${read(root,'assets/motion/motion.css')}</style><section style="background-image:linear-gradient(#222,#333);color:white"><div class="sticky-stack"><div>First</div><div>Second</div></div></section>`;
    await dynamic.setContent(markup);
    assert.equal(await dynamic.locator('.sticky-stack > *').first().evaluate(el => getComputedStyle(el).position), 'static', 'no script preserves the original layout');
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    assert.equal(await dynamic.locator('.sticky-stack > *').first().evaluate(el => getComputedStyle(el).position), 'static');
    assert.equal(await dynamic.locator('.sticky-stack > *').first().evaluate(el => getComputedStyle(el).backgroundColor), 'rgba(0, 0, 0, 0)');
});
await preferenceCase('sticky cards stay static when authored paint prevents a solid backing', async dynamic => {
    await dynamic.setContent(`<style>${variables}${read(root,'assets/motion/motion.css')}</style><section style="background:#222;color:white"><div class="sticky-stack"><div>First</div><div style="background-color:transparent!important">Second</div></div></section>`);
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    assert.equal(await dynamic.locator('.sticky-stack--ready').count(), 0);
    assert.equal(await dynamic.locator('.motion-stack-backed').count(), 0);
    assert.equal(await dynamic.locator('.sticky-stack > *').first().evaluate(el => el.style.getPropertyValue('--motion-stack-background')), '');
    assert.equal(await dynamic.locator('.sticky-stack > *').first().evaluate(el => getComputedStyle(el).position), 'static');
});
await preferenceCase('motion screenshots wait for counting to restore authored markup', async dynamic => {
    await dynamic.setContent(`<style>${variables}${read(root,'assets/motion/motion.css')}${read(root,'assets/motion/profiles/dramatic.css')}</style><div style="height:1500px"></div><h2 class="count-up">98<span class="emph">%</span></h2><div style="height:1000px"></div>`);
    await dynamic.addScriptTag({content:read(root,'assets/motion/motion.js')});
    const {settleMotion} = require('../../bin/screenshot/screenshot.js');
    await settleMotion(dynamic, 4000);
    assert.equal(await dynamic.locator('.count-up').innerHTML(), '98<span class="emph">%</span>');
});

assert.deepEqual(preferenceFailures, []);


} finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
