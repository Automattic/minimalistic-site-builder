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

} finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
