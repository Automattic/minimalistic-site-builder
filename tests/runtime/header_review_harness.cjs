'use strict';

const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const headerCss = read('assets/header/header.css');
const navigationCss = read('node_modules/@wordpress/block-library/build-style/navigation/style.css');
const variables = `
:root {
    --wp--preset--color--base: #fff;
    --wp--preset--color--contrast: #111;
    --wp--preset--color--accent: #36f;
    --wp--preset--spacing--sm: 12px;
    --wp--preset--spacing--md: 24px;
    --wp--preset--spacing--lg: 48px;
}
body { margin: 0; font: 16px sans-serif; }
.site-header-shell > div { padding: 12px 20px; }
.wp-block-group.is-layout-flex { display: flex; align-items: center; gap: 24px; }
.wp-block-site-title { margin: 0; font-size: 24px; }
.wp-block-buttons { display: flex; }
.wp-block-button__link { display: block; padding: 10px 16px; }
`;
const style = `<style>${variables}${navigationCss}${headerCss}</style>`;
const computed = (page, selector, property, pseudo = null) => page.locator(selector)
    .evaluate((element, args) => getComputedStyle(element, args.pseudo)[args.property], { property, pseudo });
const navigation = `<nav class="wp-block-navigation">
    <button class="wp-block-navigation__responsive-container-open">Menu</button>
    <div class="wp-block-navigation__responsive-container">
        <div class="wp-block-navigation__responsive-container-content">
            <ul class="wp-block-navigation__container">${['Work', 'About', 'Services', 'Process', 'Contact'].map(label =>
                `<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="#${label}">${label}</a></li>`).join('')}
            </ul>
        </div>
    </div>
</nav>`;

async function checkSurfaces(page, cdp) {
    for (const behavior of ['sticky-soft', 'overlay-to-solid', 'overlay-transient']) {
        const overlay = behavior.startsWith('overlay');
        for (const treatment of overlay ? ['solid', 'transparent'] : ['transparent', 'glass']) {
            await page.setContent(`${style}<header class="site-header-shell site-header-shell--${behavior}">
                <div class="header-archetype--floating-pill header-behavior-${overlay ? 'overlay' : behavior}
                    header-top-${treatment} header-scrolled-glass header-start-base header-scrolled-contrast
                    header-foreground-base has-base-background-color">
                    <div class="wp-block-group header-pill">Studio</div>
                </div>
            </header>`);
            await page.evaluate(() => document.documentElement.className = 'header-state-js');
            const context = `${behavior}/${treatment}`;
            assert.equal(await computed(page, '.header-archetype--floating-pill', 'backgroundColor'), 'rgba(0, 0, 0, 0)', context);
            assert.notEqual(await computed(page, '.header-pill', 'backgroundColor'), 'rgba(0, 0, 0, 0)', context);
            assert.equal(await computed(page, '.header-archetype--floating-pill', 'content', '::before'), 'none', context);
            await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-transparency', value: 'reduce' }] });
            assert.equal(await computed(page, '.header-pill', 'backdropFilter', '::before'), 'none', context);
            assert.equal(await computed(page, '.header-pill', 'content', '::before'), 'none', context);
            await cdp.send('Emulation.setEmulatedMedia', { features: [] });
            await page.locator('.site-header-shell').evaluate(element => element.classList.add('site-header-shell--force-solid'));
            for (const scrolled of [false, true]) {
                await page.evaluate(value => document.documentElement.classList.toggle('header-is-scrolled', value), scrolled);
                // Disable the transition before the final surface check.
                await page.addStyleTag({ content: '.header-pill { transition: none !important; }' });
                assert.equal(await computed(page, '.header-pill', 'backgroundColor'), 'rgb(17, 17, 17)', context);
                assert.equal(await computed(page, '.header-pill', 'backdropFilter', '::before'), 'none', context);
            }
        }
    }
    console.log('PASS: pill surfaces, overlay modes, forced surfaces, and reduced transparency');
}

async function checkMenus(page) {
    for (const [archetype, row] of [['bar-center-cta', 'header-bar-center'], ['spread-nav', 'header-spread']]) {
        for (const nested of [false, true]) {
            for (const width of [390, 600, 700, 782, 783, 1280]) {
                await page.setViewportSize({ width, height: 800 });
                const nav = nested ? `<div class="wp-block-group">${navigation}</div>` : navigation;
                const cta = archetype === 'bar-center-cta'
                    ? '<div class="wp-block-buttons"><a class="wp-block-button__link">Contact</a></div>' : '';
                await page.setContent(`${style}<header class="site-header-shell"><div class="header-archetype--${archetype}">
                    <div class="wp-block-group is-layout-flex ${row}"><h2 class="wp-block-site-title">Northlight Studio</h2>${nav}${cta}</div>
                </div></header>`);
                const context = `${archetype}/${nested}/${width}`;
                assert.equal(await computed(page, '.wp-block-navigation__responsive-container-open', 'display'), width <= 782 ? 'flex' : 'none', context);
                assert.equal(await computed(page, '.wp-block-navigation__responsive-container', 'display'), width <= 782 ? 'none' : 'block', context);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, context);
                if (width <= 782) {
                    // Apply Core classes to test the modal CSS.
                    await page.locator('.wp-block-navigation__responsive-container').evaluate(element => element.classList.add('is-menu-open'));
                    await page.waitForFunction(() => document.querySelector('.is-menu-open').getBoundingClientRect().top === 0);
                    const rect = await page.locator('.wp-block-navigation__responsive-container').boundingBox();
                    assert.deepEqual(rect, { x: 0, y: 0, width, height: 800 }, context);
                }
            }
        }
    }
    console.log('PASS: Core menu CSS at six widths, with direct and nested navigation');
}

async function checkSections(page) {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.setContent(`${style}<header class="site-header-shell site-header-shell--sticky-soft">
        <div class="header-archetype--spread-nav"><div class="header-spread"><nav class="wp-block-navigation"><ul>
            ${[['last', 'Last'], ['caf%C3%A9', 'Cafe'], ['first', 'First'], ['page', 'Page'], ['%ZZ', 'Invalid']].map(([id, label]) =>
                `<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="#${id}"${id === 'page' ? ' aria-current="page"' : ''}>${label}</a></li>`).join('')}
        </ul></nav></div></div>
    </header><main><div style="height:500px"></div>
        <section id="first" style="height:300px">First</section>
        <section id="café" style="height:20px">Cafe</section>
        <section id="last" style="height:1000px">Last</section>
        <section id="page" style="height:1000px">Page</section>
    </main>`);
    await page.addScriptTag({ content: read('assets/header/header.js') });
    const scrollToSection = async (id, offset = 0) => {
        await page.evaluate(({ id, offset }) => {
            const top = document.getElementById(id).getBoundingClientRect().top + scrollY;
            scrollTo(0, top - innerHeight * 0.35 + offset);
        }, { id, offset });
        await page.waitForFunction(id => {
            const link = document.querySelector('.is-current-section a');
            return link && decodeURIComponent(link.getAttribute('href').slice(1)) === id;
        }, id);
    };
    await scrollToSection('first', 5);
    assert.equal(await computed(page, '.is-current-section a', 'backgroundColor'), 'rgba(0, 0, 0, 0)');
    await scrollToSection('café', 5);
    await scrollToSection('last', 5);
    await scrollToSection('first', 5);
    assert.equal(await page.locator('a[href="#page"]').getAttribute('aria-current'), 'page');
    await page.evaluate(() => scrollTo(0, 0));
    await page.waitForFunction(() => !document.querySelector('.is-current-section'));
    console.log('PASS: section order, encoded IDs, short sections, scroll jumps, and current-page state');
}

(async () => {
    const browser = await chromium.launch({ executablePath: process.argv[2], headless: true, args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const cdp = await page.context().newCDPSession(page);
        await checkSurfaces(page, cdp);
        await checkMenus(page);
        await checkSections(page);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
