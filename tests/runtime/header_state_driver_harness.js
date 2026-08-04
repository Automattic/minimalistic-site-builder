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
    return {
        setProperty(name, value) { values.set(name, String(value)); },
        removeProperty(name) { values.delete(name); },
        getPropertyValue(name) { return values.get(name) || ''; },
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

    const document = {
        documentElement: root,
        body,
        readyState: options.readyState || 'complete',
        querySelector() {
            if (options.queryFailure) {
                throw new Error('query failed');
            }
            return header;
        },
        getElementById(id) { return id === 'wpadminbar' ? adminBar : null; },
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
        console,
        document,
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
        windowListeners,
        documentListeners,
        resizeObservers,
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

    env.context.pageYOffset = 25;
    env.dispatch('scroll');
    env.flushFrames();
    check(env.root.classList.contains('header-is-scrolled'), 'small threshold did not activate at 25px');
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
testFailOpen();

process.stdout.write('header state driver runtime harness passed\n');
