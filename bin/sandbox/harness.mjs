#!/usr/bin/env node
/**
 * Sandbox driver for the tree graph: one JSON command in, one JSON document out.
 *
 *   node bin/sandbox/harness.mjs <command-file.json>
 *
 * Two commands:
 *
 *   {"command": "compile", "harness_url": "...", "trees": {"<key>": [BlockNode, ...]}}
 *     Loads the companion's harness page (real wp.blocks in a real browser),
 *     reads the client-side block registry, and runs window.__compile() for
 *     each tree. Output: {"registry": [...], "results": {"<key>": {...}}} where
 *     each result is the harness's own {markup, all_valid, invalid,
 *     content_lost} (or {error}) passed through verbatim.
 *
 *   {"command": "verify", "url": "...", "wait"?, "nav_timeout_ms"?, "viewport"?,
 *    "block_names": [...]}
 *     Navigates the live page and measures it: a box tree over every
 *     block-classed element (geometry + computed styles), an accessibility
 *     outline, every element whose own text reads under 4.5:1 against its
 *     actual ground, and every <img> with its natural size and load state
 *     (lazy images are forced eager first). This is a faithful port of the
 *     x-pipeline oracle's extraction, so the PHP gates can consume the same
 *     shapes.
 *
 * Success prints the JSON on stdout and exits 0; any failure prints
 * {"error": "..."} on stdout and exits 1. Runs against the system Chrome via
 * playwright-core — see chrome.js.
 */

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright-core');
const { findChrome } = require('./chrome.js');

const DEFAULT_VIEWPORT = { width: 1440, height: 900 };

/**
 * The wrapper class WordPress emits for a block name:
 *   core/paragraph    -> wp-block-paragraph
 *   agent/testimonial -> wp-block-agent-testimonial
 */
function defaultClassName(blockName) {
    return 'wp-block-' + blockName.replace('/', '-').replace(/^core-/, '');
}

function classNameMap(blockNames) {
    const map = {};
    for (const name of blockNames) {
        const cls = defaultClassName(name);
        // A core block wins a collision: `core/x` and `other/core-x` would
        // collide, and core is the one whose class WordPress actually emits.
        if (!map[cls] || name.startsWith('core/')) map[cls] = name;
    }
    return map;
}

function round2(v) {
    return Math.round(v * 100) / 100;
}

/** Project the internal measurement onto the public box-tree shape. */
function toBoxTree(nodes) {
    return nodes.map((n) => {
        const out = {
            selector_path: n.selector_path,
            box: { x: round2(n.box.x), y: round2(n.box.y), w: round2(n.box.w), h: round2(n.box.h) },
            computed: n.computed,
        };
        if (n.block_name) out.block_name = n.block_name;
        return out;
    });
}

/** esbuild-style decorator shim some bundled pages expect during evaluate. */
async function installEvalShims(page) {
    await page
        .evaluate('globalThis.__name = globalThis.__name || function (f) { return f; };')
        .catch(() => {});
}

/** Fonts and web-font-driven reflow settle before anything is measured. */
async function settle(page, navTimeout) {
    await page.evaluate(() => (document.fonts ? document.fonts.ready : null)).catch(() => null);
    await page.waitForLoadState('networkidle', { timeout: Math.min(15000, navTimeout) }).catch(() => {});
    await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r()))));
}

/**
 * Measure the page: box tree, a11y outline, own-text contrast. A faithful port
 * of the x-pipeline oracle's extractLayout — the whole measurement runs in one
 * page.evaluate so every geometry read shares a single layout pass.
 */
async function extractLayout(page, nameByClass) {
    await installEvalShims(page);
    return page.evaluate((lookup) => {
        /* ---------------------------------------------------------- utilities */
        const parsePx = (v) => {
            const n = Number.parseFloat(v);
            return Number.isFinite(n) ? n : 0;
        };
        const parseRgb = (v) => {
            const m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,/\s]+([\d.%]+))?\s*\)/i.exec(v || '');
            if (!m) return null;
            let a = 1;
            if (m[4] !== undefined) a = m[4].endsWith('%') ? Number.parseFloat(m[4]) / 100 : Number.parseFloat(m[4]);
            return [Number(m[1]), Number(m[2]), Number(m[3]), Number.isFinite(a) ? a : 1];
        };
        const selectorPath = (el) => {
            const parts = [];
            let cur = el;
            while (cur && cur !== document.documentElement) {
                const parent = cur.parentElement;
                let idx = 1;
                if (parent) {
                    let n = 0;
                    for (const sib of Array.from(parent.children)) {
                        n += 1;
                        if (sib === cur) {
                            idx = n;
                            break;
                        }
                    }
                }
                const wpClass = Array.from(cur.classList).find((c) => /^wp-block-[a-z0-9-]+$/.test(c));
                parts.unshift(`${cur.tagName.toLowerCase()}${wpClass ? '.' + wpClass : ''}:nth-child(${idx})`);
                cur = parent;
            }
            return parts.join(' > ');
        };
        const textOf = (el) => (el.textContent ?? '').replace(/\s+/g, ' ').trim();
        const ariaName = (el) => {
            const label = el.getAttribute('aria-label');
            if (label) return label.trim();
            const by = el.getAttribute('aria-labelledby');
            if (by) {
                const ref = document.getElementById(by.split(/\s+/)[0] ?? '');
                if (ref) return textOf(ref);
            }
            const alt = el.getAttribute('alt');
            if (alt) return alt.trim();
            const title = el.getAttribute('title');
            if (title) return title.trim();
            return '';
        };

        /* -------------------------------------------------- the box tree */
        const nodes = [];
        let candidates = 0;
        let named = 0;
        const all = Array.from(document.querySelectorAll('*'));
        let index = 0;
        for (const el of all) {
            const classes = Array.from(el.classList);
            const wpish = classes.filter((c) => c.indexOf('wp-block-') === 0);
            const dataType = el.getAttribute('data-type');
            const dataBlock = el.getAttribute('data-block');
            if (wpish.length === 0 && !dataType && !dataBlock) continue;
            candidates += 1;

            let blockName;
            if (dataType && dataType.indexOf('/') > 0) blockName = dataType;
            if (!blockName) {
                for (const c of wpish) {
                    if (Object.prototype.hasOwnProperty.call(lookup, c)) {
                        blockName = lookup[c];
                        break;
                    }
                }
            }
            if (blockName) named += 1;

            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);

            // Largest font size anywhere in the subtree: the region's headline size.
            let maxFont = parsePx(cs.fontSize);
            const desc = el.querySelectorAll('*');
            for (const d of Array.from(desc)) {
                if (!(d.textContent ?? '').trim()) continue;
                const f = parsePx(getComputedStyle(d).fontSize);
                if (f > maxFont) maxFont = f;
            }

            // Real vertical rhythm between element children. WordPress implements
            // blockGap as a margin on children for flow/constrained layouts, so the
            // computed `gap` is `normal` there and only this measurement is truthful.
            let childGap = null;
            const kids = Array.from(el.children).filter((k) => {
                const kr = k.getBoundingClientRect();
                return kr.width > 0 && kr.height > 0;
            });
            if (kids.length >= 2) {
                const gaps = [];
                for (let i = 1; i < kids.length; i += 1) {
                    const a = kids[i - 1].getBoundingClientRect();
                    const b = kids[i].getBoundingClientRect();
                    const vertical = b.top >= a.bottom - 1;
                    gaps.push(Math.max(0, vertical ? b.top - a.bottom : b.left - a.right));
                }
                gaps.sort((p, q) => p - q);
                childGap = gaps[Math.floor(gaps.length / 2)] ?? null;
            }

            const bg = parseRgb(cs.backgroundColor);
            const bgIsTransparent = !bg || bg[3] === 0;
            nodes.push({
                selector_path: selectorPath(el),
                block_name: blockName,
                box: {
                    x: r.x + window.scrollX,
                    y: r.y + window.scrollY,
                    w: r.width,
                    h: r.height,
                },
                computed: {
                    display: cs.display,
                    gap: cs.gap && cs.gap !== 'normal' ? cs.gap : cs.rowGap || 'normal',
                    fontSize: cs.fontSize,
                    color: cs.color,
                    background: bgIsTransparent && cs.backgroundImage !== 'none' ? cs.backgroundImage : cs.backgroundColor,
                },
                tag: el.tagName.toLowerCase(),
                text: textOf(el).slice(0, 240),
                aria_name: ariaName(el),
                depth: selectorPath(el).split(' > ').length,
                index: index++,
                max_font_px: maxFont,
                row_gap_px: cs.rowGap && cs.rowGap !== 'normal' ? parsePx(cs.rowGap) : null,
                child_gap_px: childGap,
                bg_rgba: bg,
                fg_rgba: parseRgb(cs.color),
            });
        }

        /* ------------------------------------------------- the a11y outline */
        const outline = [];
        const landmark = {
            header: 'banner',
            footer: 'contentinfo',
            main: 'main',
            nav: 'navigation',
            aside: 'complementary',
            form: 'form',
            section: 'region',
            article: 'article',
        };
        const walk = (el) => {
            const tag = el.tagName.toLowerCase();
            const explicit = el.getAttribute('role');
            let role = '';
            let level;
            let name = ariaName(el);

            if (explicit) {
                role = explicit;
            } else if (/^h[1-6]$/.test(tag)) {
                role = 'heading';
                level = Number(tag.slice(1));
            } else if (tag === 'a' && el.hasAttribute('href')) {
                role = 'link';
            } else if (tag === 'button' || (tag === 'input' && ['button', 'submit', 'reset'].includes(el.type))) {
                role = 'button';
            } else if (tag === 'img') {
                const alt = el.getAttribute('alt');
                if (alt !== '') role = 'img';
            } else if (tag === 'ul' || tag === 'ol') {
                role = 'list';
            } else if (tag === 'li') {
                role = 'listitem';
            } else if (tag === 'table') {
                role = 'table';
            } else if (tag === 'input' || tag === 'textarea') {
                role = 'textbox';
            } else if (tag === 'select') {
                role = 'combobox';
            } else if (landmark[tag]) {
                // A bare <section> is only a landmark when it is named.
                if (tag !== 'section' || name) role = landmark[tag];
            }

            if (role) {
                if (!name && (role === 'heading' || role === 'link' || role === 'button' || role === 'listitem')) {
                    name = textOf(el).slice(0, 120);
                }
                const entry = { role, name };
                if (level !== undefined) entry.level = level;
                outline.push(entry);
            }
            for (const child of Array.from(el.children)) walk(child);
        };
        if (document.body) for (const child of Array.from(document.body.children)) walk(child);

        /* ---------------------------------------- ink vs ground, every text leaf */
        // Every element carrying its OWN text, rated against the nearest painted
        // ancestor background. Measured here — not inferred from markup — because
        // authoring layers can each produce readable-looking inputs that render
        // unreadable together. Pairs under 4.5:1 are reported; policy (what fails
        // a run) belongs to the caller. Text over images is skipped: there is no
        // single ground to rate against.
        const lumOf = (c) => {
            const f = (v) => {
                const s = v / 255;
                return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
            };
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        const contrastOf = (a, b) => {
            const la = lumOf(a);
            const lb = lumOf(b);
            const hi = Math.max(la, lb);
            const lo = Math.min(la, lb);
            return (hi + 0.05) / (lo + 0.05);
        };
        const textContrast = [];
        for (const el of Array.from(document.querySelectorAll('body *'))) {
            if (textContrast.length >= 100) break;
            const own = Array.from(el.childNodes)
                .filter((n) => n.nodeType === 3)
                .map((n) => n.textContent ?? '')
                .join('')
                .trim();
            if (!own) continue;
            const cs = getComputedStyle(el);
            const rect = el.getBoundingClientRect();
            if (rect.width < 2 || rect.height < 2 || cs.visibility === 'hidden' || cs.display === 'none' || Number.parseFloat(cs.opacity) < 0.05) continue;
            const ink = parseRgb(cs.color);
            if (!ink || ink[3] === 0) continue;
            let cur = el;
            let ground = null;
            let overImage = false;
            while (cur && cur !== document.documentElement) {
                const cc = getComputedStyle(cur);
                if (cc.backgroundImage && cc.backgroundImage !== 'none') {
                    overImage = true;
                    break;
                }
                const b = parseRgb(cc.backgroundColor);
                if (b && b[3] > 0.1) {
                    ground = b;
                    break;
                }
                cur = cur.parentElement;
            }
            if (overImage || !ground) continue;
            const ratio = contrastOf(ink, ground);
            if (ratio < 4.5) {
                textContrast.push({
                    selector_path: selectorPath(el),
                    ratio: Math.round(ratio * 100) / 100,
                    color: cs.color,
                    background: `rgb(${ground[0]}, ${ground[1]}, ${ground[2]})`,
                    sample: own.slice(0, 80),
                });
            }
        }

        return {
            nodes,
            a11y_outline: outline,
            stats: { candidates, named, named_ratio: candidates === 0 ? 1 : named / candidates },
            text_contrast: textContrast,
        };
    }, nameByClass);
}

/**
 * Force every image on the page to load before capture. WordPress adds
 * loading="lazy" below the fold; flip lazy images to eager, walk the scroll
 * positions to nudge scroll-keyed loaders, and wait (bounded) for every image
 * to report complete.
 */
async function eagerLoadImages(page, timeoutMs = 10000) {
    await page
        .evaluate(async () => {
            document.querySelectorAll('img[loading="lazy"]').forEach((el) => {
                el.loading = 'eager';
            });
            const step = Math.max(200, window.innerHeight);
            for (let y = 0; y <= document.body.scrollHeight; y += step) {
                window.scrollTo(0, y);
                await new Promise((r) => setTimeout(r, 30));
            }
            window.scrollTo(0, 0);
        })
        .catch(() => {});
    await page
        .waitForFunction(() => Array.from(document.images).every((i) => i.complete), undefined, { timeout: timeoutMs })
        .catch(() => {});
}

/**
 * Every <img> on the page, including inside composite blocks. loaded:false, or
 * a tiny natural size under a large rendered box, is a finding for the caller.
 */
async function collectImages(page) {
    return page.evaluate(() => {
        const pathOf = (el) => {
            const parts = [];
            let cur = el;
            while (cur && cur.tagName !== 'HTML' && parts.length < 6) {
                const tag = cur.tagName.toLowerCase();
                const parent = cur.parentElement;
                let nth = 1;
                if (parent) {
                    const same = Array.from(parent.children).filter((c) => c.tagName === cur.tagName);
                    nth = same.indexOf(cur) + 1;
                }
                parts.unshift(`${tag}:nth-of-type(${nth})`);
                cur = parent;
            }
            return parts.join(' > ');
        };
        return Array.from(document.images).map((img) => {
            const r = img.getBoundingClientRect();
            return {
                selector_path: pathOf(img),
                box: {
                    x: Math.round(r.x * 100) / 100,
                    y: Math.round(r.y * 100) / 100,
                    w: Math.round(r.width * 100) / 100,
                    h: Math.round(r.height * 100) / 100,
                },
                natural_w: img.naturalWidth,
                natural_h: img.naturalHeight,
                loaded: img.complete && img.naturalWidth > 0,
                lazy: img.loading === 'lazy',
                src: img.currentSrc || img.src,
            };
        });
    });
}

async function compile(page, command) {
    if (typeof command.harness_url !== 'string' || command.harness_url === '') {
        throw new Error('compile needs a harness_url');
    }
    await page.goto(command.harness_url, { waitUntil: 'domcontentloaded', timeout: 120000 });
    // harness.js loads last on the page, so __ready + wp.blocks together mean
    // the compiler is actually live (not merely the document parsed).
    await page.waitForFunction(() => window.__ready && window.wp && window.wp.blocks, undefined, { timeout: 120000 });
    await page.evaluate(() => window.__ready.then(() => true));
    const registry = await page.evaluate(() => window.__registry());
    const results = {};
    for (const [key, blocks] of Object.entries(command.trees ?? {})) {
        results[key] = await page.evaluate((b) => window.__compile(b), blocks);
    }
    return { registry, results };
}

async function verify(page, command) {
    if (typeof command.url !== 'string' || command.url === '') {
        throw new Error('verify needs a url');
    }
    const wait = command.wait === 'domcontentloaded' ? 'domcontentloaded' : 'load';
    const navTimeout = Number.isInteger(command.nav_timeout_ms) && command.nav_timeout_ms > 0
        ? command.nav_timeout_ms
        : 60000;
    await page.goto(command.url, { waitUntil: wait, timeout: navTimeout });
    await settle(page, navTimeout);
    const lookup = classNameMap(Array.isArray(command.block_names) ? command.block_names : []);
    const extracted = await extractLayout(page, lookup);
    // Measure images only after the lazy ones have actually been asked for —
    // otherwise everything below the fold reports natural 0x0.
    await eagerLoadImages(page);
    const images = await collectImages(page);
    return {
        box_tree: toBoxTree(extracted.nodes),
        a11y_outline: extracted.a11y_outline,
        text_contrast: extracted.text_contrast,
        images,
        measured: { viewport: page.viewportSize(), loaded_url: page.url() },
    };
}

async function main() {
    const file = process.argv[2];
    if (!file) {
        throw new Error('usage: node bin/sandbox/harness.mjs <command-file.json>');
    }
    const command = JSON.parse(readFileSync(file, 'utf8'));

    const executablePath = findChrome(process.env.CHROME || process.env.CHROME_BIN);
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const viewport = command.viewport && command.viewport.width > 0 && command.viewport.height > 0
            ? { width: command.viewport.width, height: command.viewport.height }
            : DEFAULT_VIEWPORT;
        const context = await browser.newContext({ viewport });
        const page = await context.newPage();
        if (command.command === 'compile') return await compile(page, command);
        if (command.command === 'verify') return await verify(page, command);
        throw new Error(`unknown command: ${String(command.command)}`);
    } finally {
        await browser.close();
    }
}

main().then(
    (result) => {
        process.stdout.write(JSON.stringify(result) + '\n');
    },
    (error) => {
        process.stdout.write(JSON.stringify({ error: error && error.message ? String(error.message) : String(error) }) + '\n');
        process.exitCode = 1;
    },
);
