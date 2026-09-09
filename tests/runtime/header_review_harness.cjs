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
const headerCss=read(root,'assets/header/header.css');
await page.setContent(`<style>${variables}${headerCss}</style><header class="site-header-shell site-header-shell--sticky-soft"><div class="header-archetype--floating-pill header-behavior-sticky-soft header-top-transparent header-start-base header-scrolled-base header-foreground-contrast has-base-background-color"><div class="wp-block-group header-pill">Studio <nav>Work About Contact</nav></div></div></header>`);
await page.evaluate(()=>document.documentElement.className='header-state-js');
assert.notEqual(await page.locator('.header-pill').evaluate(e=>getComputedStyle(e).backgroundColor),'rgba(0, 0, 0, 0)');
await page.locator('.header-archetype--floating-pill').evaluate(e=>{e.classList.remove('header-top-transparent');e.classList.add('header-top-glass');});
const cdp=await page.context().newCDPSession(page);
await cdp.send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-transparency',value:'reduce'}]});
assert.equal(await page.locator('.header-pill').evaluate(e=>getComputedStyle(e,'::before').backdropFilter),'none');
assert.equal(await page.locator('.header-pill').evaluate(e=>getComputedStyle(e,'::before').content),'none');
console.log('PASS: sticky pill surface and reduced transparency');
await cdp.send('Emulation.setEmulatedMedia',{features:[]});
for(const width of [390,600,700,782,1280]){
 await page.setViewportSize({width,height:800});
 await page.setContent(`<style>${variables}.wp-block-navigation__responsive-container-open{display:none}.wp-block-navigation__responsive-container{display:block}${headerCss}</style><header class="site-header-shell"><div class="header-archetype--bar-center-cta"><div class="header-bar-center"><span>Studio</span><nav class="wp-block-navigation"><button class="wp-block-navigation__responsive-container-open">Menu</button><div class="wp-block-navigation__responsive-container">Work About Services Process Contact</div></nav><button>Contact</button></div></div></header>`);
 assert.equal(await page.locator('.wp-block-navigation__responsive-container-open').evaluate(e=>getComputedStyle(e).display),width<=782?'flex':'none');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
}
console.log('PASS: header menu at 390, 600, 700, 782, and 1280 pixels');

} finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
