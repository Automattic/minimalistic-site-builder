'use strict';

const fs = require('fs');
const vm = require('vm');

const sourcePath = process.argv[2];
if (!sourcePath) {
    throw new Error('Usage: node header_state_driver_harness.js <header.js>');
}
const source = fs.readFileSync(sourcePath, 'utf8');

function check(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function fakeClassList(initial) {
    const values = new Set(initial || []);
    return {
        add(value) { values.add(value); },
        remove(value) { values.delete(value); },
        contains(value) { return values.has(value); },
    };
}

function fakeStyle() {
    const values = new Map();
    const writes = new Map();
    return {
        setProperty(name, value) {
            values.set(name, String(value));
            writes.set(name, (writes.get(name) || 0) + 1);
        },
        removeProperty(name) { values.delete(name); },
        getPropertyValue(name) { return values.get(name) || ''; },
        writeCount(name) { return writes.get(name) || 0; },
    };
}

function environment(options) {
    const windowListeners = new Map();
    const documentListeners = new Map();
    const animationFrames = new Map();
    const resizeObservers = [];
    let frameId = 0;

    const header = options.noHeader ? null : {
        height: options.headerHeight || 72,
        offsetHeight: options.headerHeight || 72,
        throwOnMeasure: false,
        getBoundingClientRect() {
            if (this.throwOnMeasure) {
                throw new Error('header measurement failed');
            }
            return { height: this.height };
        },
    };
    const root = {
        classList: fakeClassList(),
        style: fakeStyle(),
        scrollTop: options.rootScrollTop || 0,
        clientWidth: options.width || 1200,
    };
    const body = {
        classList: fakeClassList(options.adminBar ? ['admin-bar'] : []),
        style: fakeStyle(),
    };
    const adminBar = options.adminBarElement ? {
        getBoundingClientRect() {
            const height = options.adminBarHeight || 34;
            const bottom = options.adminBarScrolls
                ? height - (context.pageYOffset || 0)
                : height;
            return { height, bottom };
        },
    } : null;
    const navigationBlockDefinitions = options.navigationBlocks
        || [options.navigationLinks || []];
    const navigationBlocks = navigationBlockDefinitions.map((definitions) => {
        const links = definitions.map((definition) => {
            const item = {
                classList: fakeClassList(definition.itemClasses || []),
            };
            const attributes = new Map([['href', definition.href]]);
            if (definition.ariaCurrent) {
                attributes.set('aria-current', definition.ariaCurrent);
            }
            const anchor = {
                parentElement: item,
                getAttribute(name) { return attributes.get(name) || null; },
                setAttribute(name, value) { attributes.set(name, String(value)); },
                closest(selector) {
                    return selector === '.wp-block-navigation-item' ? item : null;
                },
            };
            return { anchor, item };
        });
        return {
            links,
            querySelectorAll(selector) {
                return selector === 'a[href]' ? links.map((entry) => entry.anchor) : [];
            },
        };
    });
    const navigationLinks = navigationBlocks.flatMap((block) => block.links);

    class FakeResizeObserver {
        constructor(callback) {
            this.callback = callback;
            this.observed = new Set();
            this.disconnected = false;
            resizeObservers.push(this);
        }
        observe(target) { this.observed.add(target); }
        disconnect() {
            this.disconnected = true;
            this.observed.clear();
        }
        emit() { this.callback([]); }
    }

    let adminBarLookups = 0;

    const document = {
        documentElement: root,
        // noBody models head-time execution ($in_footer=false): the script
        // runs before <body> exists and the test attaches it later.
        body: options.noBody ? null : body,
        readyState: options.readyState || 'complete',
        querySelector() {
            if (options.queryFailure) {
                throw new Error('query failed');
            }
            return header;
        },
        querySelectorAll(selector) {
            if (selector === '.wp-block-navigation') {
                return navigationBlocks;
            }
            return selector === '.wp-block-navigation a[href]'
                ? navigationLinks.map((entry) => entry.anchor)
                : [];
        },
        getElementById(id) {
            if (id !== 'wpadminbar') {
                return null;
            }
            adminBarLookups += 1;
            return adminBar;
        },
        addEventListener(type, callback, listenerOptions) {
            const callbacks = documentListeners.get(type) || [];
            callbacks.push({ callback, options: listenerOptions });
            documentListeners.set(type, callbacks);
        },
    };

    const context = {
        Array,
        Error,
        Math,
        URL,
        console,
        document,
        location: new URL(options.locationHref || 'https://example.test/'),
        pageYOffset: options.pageYOffset || 0,
        innerWidth: options.width || 1200,
        addEventListener(type, callback, listenerOptions) {
            const callbacks = windowListeners.get(type) || [];
            callbacks.push({ callback, options: listenerOptions });
            windowListeners.set(type, callbacks);
        },
        removeEventListener(type, callback) {
            windowListeners.set(type, (windowListeners.get(type) || []).filter(
                (entry) => entry.callback !== callback
            ));
        },
        requestAnimationFrame(callback) {
            frameId += 1;
            animationFrames.set(frameId, callback);
            return frameId;
        },
        cancelAnimationFrame(id) { animationFrames.delete(id); },
        ResizeObserver: FakeResizeObserver,
    };
    context.window = context;

    vm.runInNewContext(source, context, { filename: sourcePath });

    return {
        context,
        document,
        root,
        header,
        detachedBody: body,
        windowListeners,
        documentListeners,
        resizeObservers,
        navigationBlocks,
        navigationLinks,
        adminBarLookups() { return adminBarLookups; },
        rerun() {
            vm.runInNewContext(source, context, { filename: sourcePath });
        },
        dispatch(type) {
            (windowListeners.get(type) || []).slice().forEach((entry) => entry.callback());
        },
        dispatchDocument(type) {
            (documentListeners.get(type) || []).slice().forEach((entry) => entry.callback());
        },
        flushFrames() {
            const callbacks = Array.from(animationFrames.values());
            animationFrames.clear();
            callbacks.forEach((callback) => callback());
        },
        frameCount() { return animationFrames.size; },
    };
}

function testCurrentNavigationFallback() {
    const root = environment({
        noHeader: true,
        locationHref: 'https://example.test/?preview=1',
        navigationLinks: [
            { href: '/' },
            { href: '/about/' },
            { href: '#hero' },
            { href: 'https://outside.test/' },
        ],
    });
    check(
        root.navigationLinks[0].anchor.getAttribute('aria-current') === 'page',
        'root navigation link did not receive runtime current state'
    );
    check(
        root.navigationLinks[0].item.classList.contains('current-menu-item'),
        'root navigation item did not receive WordPress current class'
    );
    for (const entry of root.navigationLinks.slice(1)) {
        check(!entry.anchor.getAttribute('aria-current'), 'non-current navigation link received aria-current');
        check(!entry.item.classList.contains('current-menu-item'), 'non-current navigation item received current class');
    }

    const inner = environment({
        locationHref: 'https://example.test/about/?preview=1#section',
        navigationLinks: [
            { href: '/' },
            { href: '/about' },
        ],
    });
    check(
        inner.navigationLinks[1].anchor.getAttribute('aria-current') === 'page',
        'trailing-slash normalization missed current inner-page link'
    );
    check(
        inner.navigationLinks[1].item.classList.contains('current-menu-item'),
        'current inner-page item did not receive WordPress current class'
    );

    const serverOwned = environment({
        locationHref: 'https://example.test/about/',
        navigationLinks: [
            { href: '/', ariaCurrent: 'page', itemClasses: ['current-menu-item'] },
            { href: '/about/' },
        ],
    });
    check(
        !serverOwned.navigationLinks[1].anchor.getAttribute('aria-current'),
        'fallback overrode server-owned current navigation state'
    );
    check(
        !serverOwned.navigationLinks[1].item.classList.contains('current-menu-item'),
        'fallback added a second current navigation item'
    );
}

function testCurrentNavigationFallbackIsScopedPerBlock() {
    const env = environment({
        noHeader: true,
        locationHref: 'https://example.test/about/',
        navigationBlocks: [
            [
                { href: '/' },
                { href: '/about/' },
            ],
            [
                { href: '/', ariaCurrent: 'page', itemClasses: ['current-menu-item'] },
                { href: '/about/' },
            ],
        ],
    });

    check(
        env.navigationBlocks[0].links[1].anchor.getAttribute('aria-current') === 'page',
        'testCurrentNavigationFallbackIsScopedPerBlock: unmarked header navigation did not receive runtime current state'
    );
    check(
        env.navigationBlocks[0].links[1].item.classList.contains('current-menu-item'),
        'testCurrentNavigationFallbackIsScopedPerBlock: unmarked header navigation item did not receive WordPress current class'
    );
    check(
        env.navigationBlocks[1].links[0].anchor.getAttribute('aria-current') === 'page'
            && env.navigationBlocks[1].links[0].item.classList.contains('current-menu-item'),
        'testCurrentNavigationFallbackIsScopedPerBlock: WordPress-owned footer navigation state was changed'
    );
    check(
        !env.navigationBlocks[1].links[1].anchor.getAttribute('aria-current')
            && !env.navigationBlocks[1].links[1].item.classList.contains('current-menu-item'),
        'testCurrentNavigationFallbackIsScopedPerBlock: fallback added a second current footer navigation item'
    );
}

function testInitialAndScrollState() {
    const env = environment({ pageYOffset: 80, headerHeight: 74 });
    check(env.root.classList.contains('header-state-js'), 'enhancement scope was not enabled');
    check(env.root.classList.contains('header-is-scrolled'), 'restored scroll was not applied synchronously');
    check(env.root.style.getPropertyValue('--site-header-height') === '74px', 'header height was not measured');
    check(env.root.style.getPropertyValue('--site-admin-bar-offset') === '0px', 'logged-out admin offset was not zero');
    check(env.document.body.style.getPropertyValue('--site-admin-bar-offset') === '0px', 'body offset did not mirror root');
    check(env.resizeObservers.length === 1, 'ResizeObserver was not installed');
    check(env.resizeObservers[0].observed.has(env.header), 'header was not resize-observed');

    for (const event of ['scroll', 'resize', 'load', 'pageshow']) {
        const registration = (env.windowListeners.get(event) || [])[0];
        check(registration && registration.options.passive === true, `${event} listener was not passive`);
    }

    env.context.pageYOffset = 0;
    env.dispatch('scroll');
    env.dispatch('scroll');
    check(env.frameCount() === 1, 'scroll events were not coalesced into one animation frame');
    check(env.root.classList.contains('header-is-scrolled'), 'state changed outside the animation frame');
    env.flushFrames();
    check(!env.root.classList.contains('header-is-scrolled'), 'top state was not restored after scrolling up');

    env.context.pageYOffset = 24;
    env.dispatch('scroll');
    env.flushFrames();
    check(env.root.classList.contains('header-is-scrolled'), 'enter threshold did not activate at 24px');

    // Hysteresis dead band: between the exit and enter thresholds the state
    // must hold whatever it already was, in both directions.
    env.context.pageYOffset = 12;
    env.dispatch('scroll');
    env.flushFrames();
    check(env.root.classList.contains('header-is-scrolled'), 'dead band released the scrolled state on the way down');

    env.context.pageYOffset = 8;
    env.dispatch('scroll');
    env.flushFrames();
    check(!env.root.classList.contains('header-is-scrolled'), 'exit threshold did not release at 8px');

    env.context.pageYOffset = 23;
    env.dispatch('scroll');
    env.flushFrames();
    check(!env.root.classList.contains('header-is-scrolled'), 'dead band re-entered the scrolled state on the way up');
}

function testMeasurementAndAdminBar() {
    const desktop = environment({ adminBar: true, width: 1200, headerHeight: 68 });
    check(desktop.root.style.getPropertyValue('--site-admin-bar-offset') === '32px', 'desktop admin offset was not 32px');
    check(desktop.document.body.style.getPropertyValue('--site-admin-bar-offset') === '32px', 'desktop body offset did not mirror root');
    desktop.header.height = 81;
    desktop.resizeObservers[0].emit();
    desktop.resizeObservers[0].emit();
    check(desktop.frameCount() === 1, 'resize measurements were not coalesced');
    desktop.flushFrames();
    check(desktop.root.style.getPropertyValue('--site-header-height') === '81px', 'responsive height was not refreshed');

    const mobile = environment({ adminBar: true, width: 700 });
    check(mobile.root.style.getPropertyValue('--site-admin-bar-offset') === '46px', 'mobile admin offset was not 46px');

    const measured = environment({ adminBar: true, adminBarElement: true, adminBarHeight: 35 });
    check(measured.root.style.getPropertyValue('--site-admin-bar-offset') === '35px', 'rendered admin bar was not measured');
    check(measured.document.body.style.getPropertyValue('--site-admin-bar-offset') === '35px', 'measured body offset did not mirror root');

    const narrowFallback = environment({ adminBar: true, width: 600 });
    check(narrowFallback.root.style.getPropertyValue('--site-admin-bar-offset') === '0px', '<=600 fallback retained a gap for the non-fixed admin bar');

    const scrollingBar = environment({
        adminBar: true,
        adminBarElement: true,
        adminBarHeight: 46,
        adminBarScrolls: true,
        width: 500,
    });
    check(scrollingBar.root.style.getPropertyValue('--site-admin-bar-offset') === '46px', 'visible absolute admin bar was not cleared initially');
    scrollingBar.context.pageYOffset = 20;
    scrollingBar.dispatch('scroll');
    scrollingBar.flushFrames();
    check(scrollingBar.root.style.getPropertyValue('--site-admin-bar-offset') === '26px', 'partly visible admin bar overlap was not tracked');
    check(scrollingBar.document.body.style.getPropertyValue('--site-admin-bar-offset') === '26px', 'scrolling body offset did not mirror root');
    scrollingBar.context.pageYOffset = 60;
    scrollingBar.dispatch('scroll');
    scrollingBar.flushFrames();
    check(scrollingBar.root.style.getPropertyValue('--site-admin-bar-offset') === '0px', 'scrolled-away admin bar left a blank fixed-header gap');

    // A fixed desktop bar has a constant offset: scroll frames must neither
    // re-query the element nor rewrite an unchanged custom property.
    const cachedBar = environment({ adminBar: true, adminBarElement: true, adminBarHeight: 32 });
    const lookupsAfterInit = cachedBar.adminBarLookups();
    const rootWritesAfterInit = cachedBar.root.style.writeCount('--site-admin-bar-offset');
    const bodyWritesAfterInit = cachedBar.document.body.style.writeCount('--site-admin-bar-offset');
    for (const offset of [200, 400]) {
        cachedBar.context.pageYOffset = offset;
        cachedBar.dispatch('scroll');
        cachedBar.flushFrames();
    }
    check(cachedBar.adminBarLookups() === lookupsAfterInit, 'scroll frames re-queried the admin bar element');
    check(cachedBar.root.style.writeCount('--site-admin-bar-offset') === rootWritesAfterInit, 'unchanged root admin offset was rewritten during scroll');
    check(cachedBar.document.body.style.writeCount('--site-admin-bar-offset') === bodyWritesAfterInit, 'unchanged body admin offset was rewritten during scroll');
    check(cachedBar.root.style.getPropertyValue('--site-admin-bar-offset') === '32px', 'cached fixed-bar offset lost its value');
}

function testLoadingAndRestoredState() {
    const env = environment({ readyState: 'loading', pageYOffset: 60, headerHeight: 70 });
    check(env.root.classList.contains('header-state-js'), 'head scope was not enabled while DOM loaded');
    check(env.root.classList.contains('header-is-scrolled'), 'head pass missed restored scroll');
    check(env.root.style.getPropertyValue('--site-header-height') === '', 'header measured before DOMContentLoaded');
    env.dispatchDocument('DOMContentLoaded');
    check(env.root.style.getPropertyValue('--site-header-height') === '70px', 'DOMContentLoaded did not finish setup');

    env.context.pageYOffset = 0;
    env.header.height = 76;
    env.dispatch('pageshow');
    env.flushFrames();
    check(!env.root.classList.contains('header-is-scrolled'), 'pageshow did not refresh restored top state');
    check(env.root.style.getPropertyValue('--site-header-height') === '76px', 'pageshow did not refresh header height');
}

function testHeadTimeExecution() {
    // PHP enqueues the driver with $in_footer=false, so the real script runs
    // in <head> where document.body is still null.
    const env = environment({
        readyState: 'loading',
        noBody: true,
        adminBar: true,
        pageYOffset: 80,
        headerHeight: 70,
    });
    check(env.root.classList.contains('header-state-js'), 'head-time run without a body failed open');
    check(env.root.classList.contains('header-is-scrolled'), 'head-time run missed the restored scroll position');
    check(env.root.style.getPropertyValue('--site-header-height') === '', 'header measured before a body existed');

    env.document.body = env.detachedBody;
    env.dispatchDocument('DOMContentLoaded');
    check(env.root.classList.contains('header-state-js'), 'setup after the body arrived failed open');
    check(env.root.style.getPropertyValue('--site-header-height') === '70px', 'setup did not finish after the body arrived');
    check(env.root.style.getPropertyValue('--site-admin-bar-offset') === '32px', 'admin fallback offset was not applied after the body arrived');
    check(env.document.body.style.getPropertyValue('--site-admin-bar-offset') === '32px', 'late-arriving body did not receive the mirrored admin offset');
}

function testDoubleInitGuard() {
    // An optimizer can inline and also enqueue the same file; the second copy
    // must bail instead of installing a duplicate driver.
    const env = environment({ pageYOffset: 80, headerHeight: 72 });
    env.rerun();
    check((env.windowListeners.get('scroll') || []).length === 1, 'second script copy installed a duplicate scroll listener');
    check((env.windowListeners.get('pageshow') || []).length === 1, 'second script copy installed a duplicate pageshow listener');
    check(env.resizeObservers.length === 1, 'second script copy installed a duplicate ResizeObserver');
    check(env.root.classList.contains('header-state-js'), 'guarded rerun disturbed the enhancement scope');
}

function testFailOpen() {
    for (const options of [{ noHeader: true }, { queryFailure: true }]) {
        const env = environment(options);
        check(!env.root.classList.contains('header-state-js'), 'setup failure left fixed-overlay scope enabled');
        check(!env.root.classList.contains('header-is-scrolled'), 'setup failure left stale scroll state');
    }

    const asyncFailure = environment({ headerHeight: 72 });
    asyncFailure.header.throwOnMeasure = true;
    asyncFailure.dispatch('resize');
    asyncFailure.flushFrames();
    check(!asyncFailure.root.classList.contains('header-state-js'), 'async failure left fixed-overlay scope enabled');
    check(!asyncFailure.root.classList.contains('header-is-scrolled'), 'async failure left scroll state enabled');
    check(asyncFailure.root.style.getPropertyValue('--site-header-height') === '', 'async failure left a stale anchor offset');
    check(asyncFailure.document.body.style.getPropertyValue('--site-admin-bar-offset') === '', 'async failure left a stale body admin offset');
    check((asyncFailure.windowListeners.get('scroll') || []).length === 0, 'async failure left listeners installed');
    check(asyncFailure.resizeObservers[0].disconnected, 'async failure left ResizeObserver connected');
}

testInitialAndScrollState();
testMeasurementAndAdminBar();
testLoadingAndRestoredState();
testHeadTimeExecution();
testDoubleInitGuard();
testFailOpen();
testCurrentNavigationFallback();
testCurrentNavigationFallbackIsScopedPerBlock();

process.stdout.write('header state driver runtime harness passed\n');
