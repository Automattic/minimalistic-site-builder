(function () {
    'use strict';

    var root = document.documentElement;
    var ENHANCEMENT_CLASS = 'header-state-js';
    var SCROLLED_CLASS = 'header-is-scrolled';
    var HEADER_SELECTOR = '.site-header-shell--sticky-soft, .site-header-shell--overlay-to-solid';
    // Hysteresis: the scrolled state engages at the enter threshold but only
    // releases at the lower exit threshold, so jitter around a single boundary
    // cannot restart the surface transition on every frame.
    var SCROLL_ENTER_THRESHOLD = 24;
    var SCROLL_EXIT_THRESHOLD = 8;
    var header = null;
    var scrollFrame = 0;
    var measureFrame = 0;
    var resizeObserver = null;
    var listening = false;
    var adminBarEl = null;
    var adminBarQueried = false;
    var appliedAdminBarOffset = null;
    var adminBarBodySynced = false;

    if (!root
        || typeof window.requestAnimationFrame !== 'function'
        || typeof window.cancelAnimationFrame !== 'function') {
        return;
    }

    // An optimizer may both inline and enqueue this file; a second copy must
    // bail before touching any state, otherwise two drivers install duplicate
    // listeners and one failing open leaves a mixed half-driven state.
    if (window.__siteHeaderStateDriver) {
        return;
    }
    window.__siteHeaderStateDriver = true;

    function currentScrollTop() {
        return Math.max(window.pageYOffset || root.scrollTop || 0, 0);
    }

    function applyScrollState() {
        scrollFrame = 0;
        var top = currentScrollTop();
        if (top >= SCROLL_ENTER_THRESHOLD) {
            root.classList.add(SCROLLED_CLASS);
        } else if (top <= SCROLL_EXIT_THRESHOLD) {
            root.classList.remove(SCROLLED_CLASS);
        }
        applyAdminBarOffset();
    }

    function clearMeasuredHeight() {
        if (root.style && typeof root.style.removeProperty === 'function') {
            root.style.removeProperty('--site-header-height');
            root.style.removeProperty('--site-admin-bar-offset');
        }
        if (document.body
            && document.body.style
            && typeof document.body.style.removeProperty === 'function') {
            document.body.style.removeProperty('--site-admin-bar-offset');
        }
        adminBarEl = null;
        adminBarQueried = false;
        appliedAdminBarOffset = null;
        adminBarBodySynced = false;
    }

    function findAdminBar() {
        // Cache the element between frames. isConnected catches a bar removed
        // from the DOM; a missing bar is only re-queried after a measurement
        // event resets adminBarQueried, never on every scroll frame.
        if (adminBarEl && adminBarEl.isConnected !== false) {
            return adminBarEl;
        }
        if (adminBarEl || !adminBarQueried) {
            adminBarQueried = true;
            adminBarEl = document.getElementById('wpadminbar');
        }
        return adminBarEl;
    }

    function adminBarOffset() {
        var adminBar = findAdminBar();
        if (adminBar && typeof adminBar.getBoundingClientRect === 'function') {
            var rect = adminBar.getBoundingClientRect();
            var measured = Math.max(0, Math.ceil(rect.height || 0));
            if (typeof rect.bottom === 'number') {
                // At <=600px WordPress makes the admin bar absolute, so its
                // visible overlap shrinks as the page scrolls. Fixed desktop
                // bars keep bottom === height and retain their full offset.
                measured = Math.min(measured, Math.max(0, Math.ceil(rect.bottom)));
            }
            if (measured > 0) {
                return measured;
            }
            return 0;
        }
        if (document.body && document.body.classList.contains('admin-bar')) {
            var width = window.innerWidth || root.clientWidth || 0;
            if (width <= 600) {
                return 0;
            }
            return width <= 782 ? 46 : 32;
        }
        return 0;
    }

    function applyAdminBarOffset() {
        var value = adminBarOffset() + 'px';
        var bodyWritable = !!(document.body
            && document.body.style
            && typeof document.body.style.setProperty === 'function');
        // The rect above is still measured every frame (the <=600px absolute
        // bar scrolls away, so its overlap genuinely changes), but redundant
        // style writes are skipped when the applied value is already current.
        if (value === appliedAdminBarOffset && (adminBarBodySynced || !bodyWritable)) {
            return;
        }
        root.style.setProperty('--site-admin-bar-offset', value);
        if (bodyWritable) {
            // body.admin-bar owns the CSS-only fallback, so mirror the live
            // measurement here; otherwise that declaration would shadow the
            // value written on the document root for header descendants.
            document.body.style.setProperty('--site-admin-bar-offset', value);
            adminBarBodySynced = true;
        }
        appliedAdminBarOffset = value;
    }

    function stop() {
        if (scrollFrame) {
            window.cancelAnimationFrame(scrollFrame);
            scrollFrame = 0;
        }
        if (measureFrame) {
            window.cancelAnimationFrame(measureFrame);
            measureFrame = 0;
        }
        if (listening) {
            window.removeEventListener('scroll', scheduleScrollState);
            window.removeEventListener('resize', scheduleMeasurement);
            window.removeEventListener('load', scheduleMeasurement);
            window.removeEventListener('pageshow', refreshRestoredState);
            listening = false;
        }
        if (resizeObserver) {
            try {
                resizeObserver.disconnect();
            } catch (error) {
                // Cleanup must not interfere with the fail-open path.
            }
            resizeObserver = null;
        }
    }

    function failOpen() {
        stop();
        root.classList.remove(ENHANCEMENT_CLASS);
        root.classList.remove(SCROLLED_CLASS);
        clearMeasuredHeight();
    }

    function scheduleScrollState() {
        try {
            if (!scrollFrame) {
                scrollFrame = window.requestAnimationFrame(function () {
                    try {
                        applyScrollState();
                    } catch (error) {
                        failOpen();
                    }
                });
            }
        } catch (error) {
            failOpen();
        }
    }

    function measureHeader() {
        measureFrame = 0;
        // Resize/load/pageshow are the moments a late-rendered admin bar can
        // appear, so allow findAdminBar one fresh lookup here.
        adminBarQueried = false;
        var rect = header.getBoundingClientRect();
        var height = Math.max(0, Math.ceil(rect.height || header.offsetHeight || 0));
        root.style.setProperty('--site-header-height', height + 'px');
        applyAdminBarOffset();
    }

    function scheduleMeasurement() {
        try {
            if (!measureFrame) {
                measureFrame = window.requestAnimationFrame(function () {
                    try {
                        measureHeader();
                    } catch (error) {
                        failOpen();
                    }
                });
            }
        } catch (error) {
            failOpen();
        }
    }

    function refreshRestoredState() {
        scheduleScrollState();
        scheduleMeasurement();
    }

    function setup() {
        try {
            header = document.querySelector(HEADER_SELECTOR);
            if (!header) {
                failOpen();
                return;
            }

            // Synchronous setup handles a restored scroll position before the
            // first scheduled event and supplies an anchor offset immediately.
            applyScrollState();
            measureHeader();

            // Mark first so a partial listener-install failure removes every
            // callback that may already have been registered.
            listening = true;
            window.addEventListener('scroll', scheduleScrollState, { passive: true });
            window.addEventListener('resize', scheduleMeasurement, { passive: true });
            window.addEventListener('load', scheduleMeasurement, { passive: true });
            window.addEventListener('pageshow', refreshRestoredState, { passive: true });

            if (typeof window.ResizeObserver === 'function') {
                resizeObserver = new window.ResizeObserver(scheduleMeasurement);
                resizeObserver.observe(header);
            }
        } catch (error) {
            failOpen();
        }
    }

    try {
        // This head-loaded scope is the only selector allowed to make an
        // adaptive overlay fixed. It is removed on every setup/runtime error.
        root.classList.add(ENHANCEMENT_CLASS);
        applyScrollState();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setup, { once: true });
        } else {
            setup();
        }
    } catch (error) {
        failOpen();
    }
}());
