'use strict';

const fs = require('fs');
const vm = require('vm');

const sourcePath = process.argv[2];
if (!sourcePath) {
    throw new Error('Usage: node motion_driver_harness.js <motion.js>');
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

function fakeElement(classes, top, parent, left) {
    const properties = new Map();
    return {
        top,
        left: left || 0,
        width: 100,
        height: 100,
        parentElement: parent || null,
        classList: fakeClassList(classes),
        computedStyle: { overflowX: 'visible', overflowY: 'visible' },
        matches(selector) {
            if (selector === '.hero-entrance') {
                return this.classList.contains('hero-entrance');
            }
            return this.classList.contains('reveal')
                || this.classList.contains('reveal-up')
                || this.classList.contains('reveal-fade')
                || this.classList.contains('reveal-scale')
                || Boolean(this.parentElement
                    && this.parentElement.classList
                    && this.parentElement.classList.contains('stagger-children'));
        },
        style: {
            throwOnSet: false,
            setProperty(name, value) {
                if (this.throwOnSet) {
                    throw new Error('style mutation failed');
                }
                properties.set(name, String(value));
            },
            getPropertyValue(name) { return properties.get(name) || ''; },
        },
        getBoundingClientRect() {
            return {
                top: this.top,
                bottom: this.top + this.height,
                left: this.left,
                right: this.left + this.width,
            };
        },
    };
}

function environment(options) {
    const height = options.height || 800;
    const listeners = new Map();
    const documentListeners = new Map();
    const timers = new Map();
    const observers = [];
    const resizeObservers = [];
    let timerId = 0;

    class FakeIntersectionObserver {
        constructor(callback, observerOptions) {
            if (options.observerFailure === 'constructor') {
                throw new Error('observer constructor failed');
            }
            this.callback = callback;
            this.options = observerOptions;
            this.observed = new Set();
            this.disconnected = false;
            observers.push(this);
        }

        observe(target) {
            if (options.observerFailure === 'observe') {
                throw new Error('observer observe failed');
            }
            this.observed.add(target);
        }

        unobserve(target) {
            this.observed.delete(target);
        }

        disconnect() {
            this.disconnected = true;
            this.observed.clear();
        }

        emit(targets) {
            this.emitEntries(targets.map((target) => ({
                target,
                isIntersecting: true,
                intersectionRatio: 0.01,
            })));
        }

        emitEntries(entries) {
            this.callback(entries, this);
        }
    }

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

    const root = {
        classList: fakeClassList(),
        clientHeight: height,
        clientWidth: options.width || 1000,
        scrollHeight: options.scrollHeight || 2000,
        scrollTop: 0,
    };
    const body = { scrollHeight: root.scrollHeight };
    const document = {
        documentElement: root,
        body,
        readyState: 'complete',
        querySelectorAll() { return options.targets; },
        addEventListener(type, callback) {
            const callbacks = documentListeners.get(type) || [];
            callbacks.push(callback);
            documentListeners.set(type, callbacks);
        },
    };
    const context = {
        Array,
        Error,
        Math,
        console,
        document,
        innerHeight: height,
        innerWidth: options.width || 1000,
        pageYOffset: options.pageYOffset || 0,
        matchMedia() { return { matches: options.reducedMotion === true }; },
        getComputedStyle(element) {
            return element.computedStyle || { overflowX: 'visible', overflowY: 'visible' };
        },
        addEventListener(type, callback) {
            const callbacks = listeners.get(type) || [];
            callbacks.push(callback);
            listeners.set(type, callbacks);
        },
        removeEventListener(type, callback) {
            listeners.set(type, (listeners.get(type) || []).filter((item) => item !== callback));
        },
        setTimeout(callback) {
            timerId += 1;
            timers.set(timerId, callback);
            return timerId;
        },
        clearTimeout(id) { timers.delete(id); },
        IntersectionObserver: FakeIntersectionObserver,
    };
    if (options.resizeObserver === true) {
        context.ResizeObserver = FakeResizeObserver;
    }
    context.window = context;

    vm.runInNewContext(source, context, { filename: sourcePath });

    return {
        context,
        listeners,
        observers,
        resizeObservers,
        dispatch(type) {
            (listeners.get(type) || []).slice().forEach((callback) => callback());
        },
        dispatchDocument(type, event) {
            (documentListeners.get(type) || []).slice().forEach((callback) => callback(event));
        },
        flushTimers() {
            const callbacks = Array.from(timers.values());
            timers.clear();
            callbacks.forEach((callback) => callback());
        },
    };
}

function isVisible(target) {
    return target.classList.contains('is-visible');
}

function mainObservers(env) {
    return env.observers.filter((observer) => Object.prototype.hasOwnProperty.call(
        observer.options,
        'rootMargin'
    ));
}

function viewportObservers(env) {
    return env.observers.filter((observer) => !Object.prototype.hasOwnProperty.call(
        observer.options,
        'rootMargin'
    ));
}

function testGeometryAndStaggerBatches() {
    const firstGrid = { classList: fakeClassList(['stagger-children']) };
    const clippedGrid = fakeElement(['stagger-children'], 0, null, 100);
    clippedGrid.width = 300;
    clippedGrid.computedStyle.overflowX = 'auto';
    const aboveLine = fakeElement(['reveal'], 500);
    const horizontalOffscreen = fakeElement(['reveal'], 500, null, 1200);
    const clippedOffscreen = fakeElement([], 500, clippedGrid, 400);
    const outerClip = fakeElement([], 0, null, 0);
    outerClip.width = 100;
    outerClip.computedStyle.overflowX = 'hidden';
    const innerClip = fakeElement([], 0, outerClip, 100);
    innerClip.width = 100;
    innerClip.computedStyle.overflowX = 'hidden';
    const cumulativelyClipped = fakeElement(['reveal'], 500, innerClip, 50);
    const verticalClip = fakeElement([], 650);
    verticalClip.computedStyle.overflowY = 'hidden';
    const verticallyClipped = fakeElement(['reveal'], 500, verticalClip);
    verticallyClipped.height = 250;
    const belowLine = fakeElement(['reveal'], 700);
    const first = fakeElement([], 700, firstGrid);
    const second = fakeElement([], 700, firstGrid);
    const secondRow = fakeElement([], 900, firstGrid);
    const laterMobileChild = fakeElement([], 1200, firstGrid);
    const env = environment({
        height: 800,
        width: 1000,
        targets: [
            aboveLine,
            horizontalOffscreen,
            clippedOffscreen,
            cumulativelyClipped,
            verticallyClipped,
            belowLine,
            first,
            second,
            secondRow,
            laterMobileChild,
        ],
    });

    check(env.context.document.documentElement.classList.contains('motion-js'), 'motion scope was not enabled');
    check(env.context.document.documentElement.classList.contains('motion-ready'), 'registered-target scope was not enabled');
    check(mainObservers(env).length === 1, 'expected one main observer');
    check(viewportObservers(env).length === 1, 'expected one document-end viewport observer');
    const main = mainObservers(env)[0];
    const viewport = viewportObservers(env)[0];
    check(main.options.rootMargin === '0px 0px -200px 0px', '800px viewport did not use a 200px inset');
    check(main.options.threshold === 0.000001, 'main observer threshold must stay effectively zero');
    check(viewport.options.threshold === 0.000001, 'viewport observer threshold must stay effectively zero');
    check(isVisible(aboveLine), 'target above the 75% line was not classified synchronously');
    check(aboveLine.classList.contains('motion-skip'), 'initial target was not made static');
    check(!isVisible(belowLine), 'target below the 75% line revealed too early');
    check(main.observed.has(belowLine), 'below-line target was not observed by the main observer');
    check(viewport.observed.has(belowLine), 'below-line target was not observed by the viewport fallback');
    check(!isVisible(horizontalOffscreen), 'horizontal offscreen target was revealed synchronously');
    check(main.observed.has(horizontalOffscreen), 'horizontal offscreen target was not left to IO');
    check(!isVisible(clippedOffscreen), 'overflow-clipped target was revealed synchronously');
    check(main.observed.has(clippedOffscreen), 'overflow-clipped target was not left to IO');
    check(!isVisible(cumulativelyClipped), 'target outside the cumulative ancestor clip was revealed synchronously');
    check(main.observed.has(cumulativelyClipped), 'cumulatively clipped target was not left to IO');
    check(!isVisible(verticallyClipped), 'target clipped entirely below the 75% line was revealed synchronously');
    check(main.observed.has(verticallyClipped), 'vertically clipped target was not left to IO');

    viewport.emit([belowLine]);
    check(!isVisible(belowLine), 'document-end viewport observer revealed an ordinary target early');

    main.emitEntries([{
        target: belowLine,
        isIntersecting: true,
        intersectionRatio: 0,
        intersectionRect: { width: 100, height: 0 },
    }]);
    check(!isVisible(belowLine), 'zero-area edge contact counted as a visible intersection');

    belowLine.top = 500;
    first.top = 500;
    second.top = 500;
    secondRow.top = 560;
    main.emit([belowLine, first, second, secondRow]);
    check(isVisible(belowLine) && isVisible(first) && isVisible(second) && isVisible(secondRow), 'observer batch did not reveal its entries');
    check(first.style.getPropertyValue('--motion-stagger-order') === '0', 'first same-row child did not start the cascade');
    check(second.style.getPropertyValue('--motion-stagger-order') === '1', 'second same-row child did not follow the cascade');
    check(secondRow.style.getPropertyValue('--motion-stagger-order') === '0', 'a second visual row inherited the first row delay');

    laterMobileChild.top = 500;
    main.emit([laterMobileChild]);
    check(laterMobileChild.style.getPropertyValue('--motion-stagger-order') === '0', 'independent mobile child inherited an absolute index delay');
}

function testResizeRebuild() {
    const target = fakeElement(['reveal'], 900);
    const env = environment({ height: 800, targets: [target] });
    check(mainObservers(env)[0].options.rootMargin === '0px 0px -200px 0px', 'initial resize fixture margin is wrong');
    env.context.innerHeight = 1000;
    env.dispatch('resize');
    env.flushTimers();
    check(mainObservers(env).length === 2, 'resize did not rebuild the main observer');
    check(viewportObservers(env).length === 2, 'resize did not rebuild the viewport observer');
    check(mainObservers(env)[1].options.rootMargin === '0px 0px -250px 0px', 'resize did not recompute a height-relative inset');
}

function testDocumentEndFallback() {
    const target = fakeElement(['reveal'], 750);
    const env = environment({ height: 800, scrollHeight: 1040, targets: [target] });
    check(!isVisible(target), 'short final target revealed before document end');
    env.context.pageYOffset = 240;
    env.dispatch('scroll');
    check(isVisible(target), 'short final target was stranded below the 75% line');
    check(!target.classList.contains('motion-skip'), 'a target reached by scrolling was made static');
}

function testDocumentEndViewportObserver() {
    const initialTarget = fakeElement(['reveal'], 700);
    const initial = environment({ height: 800, scrollHeight: 800, targets: [initialTarget] });
    check(isVisible(initialTarget), 'initially visible short-page target remained hidden');
    check(initialTarget.classList.contains('motion-skip'), 'initially visible short-page target was animated');

    const carousel = fakeElement([], 0, null, 100);
    carousel.width = 300;
    carousel.computedStyle.overflowX = 'auto';
    const target = fakeElement(['reveal'], 700, carousel, 500);
    const env = environment({ height: 800, scrollHeight: 800, targets: [target] });
    check(!isVisible(target), 'clipped final target revealed before entering its carousel viewport');
    target.left = 200;
    viewportObservers(env)[0].emit([target]);
    check(isVisible(target), 'full-viewport observer stranded a final target exposed by an inner scroller');
}

function testStationaryLayoutFallbacks() {
    const resizedTarget = fakeElement(['reveal'], 700);
    const resized = environment({
        height: 800,
        scrollHeight: 1740,
        targets: [resizedTarget],
        resizeObserver: true,
    });
    check(resized.resizeObservers.length === 1, 'layout ResizeObserver was not installed');
    check(resized.resizeObservers[0].observed.has(resized.context.document.body), 'body layout was not observed');
    resized.context.document.documentElement.scrollHeight = 740;
    resized.context.document.body.scrollHeight = 740;
    resized.resizeObservers[0].emit();
    check(isVisible(resizedTarget), 'stationary ResizeObserver layout change stranded the final target');

    const loadedTarget = fakeElement(['reveal'], 700);
    const loaded = environment({ height: 800, scrollHeight: 1740, targets: [loadedTarget] });
    loaded.context.document.documentElement.scrollHeight = 740;
    loaded.context.document.body.scrollHeight = 740;
    loaded.dispatch('load');
    check(isVisible(loadedTarget), 'stationary load layout change stranded the final target');
}

function testFailOpen() {
    for (const failure of ['constructor', 'observe']) {
        const target = fakeElement(['reveal'], 700);
        const env = environment({ height: 800, targets: [target], observerFailure: failure });
        check(isVisible(target), `${failure} failure left a target hidden`);
        check(!env.context.document.documentElement.classList.contains('motion-js'), `${failure} failure left the hiding scope enabled`);
    }

    const parent = { classList: fakeClassList(['stagger-children']) };
    const broken = fakeElement([], 700, parent);
    const sibling = fakeElement([], 700, parent);
    const env = environment({ height: 800, targets: [broken, sibling] });
    broken.style.throwOnSet = true;
    mainObservers(env)[0].emit([broken, sibling]);
    check(isVisible(broken) && isVisible(sibling), 'async observer callback failure did not fail open');
    check(!env.context.document.documentElement.classList.contains('motion-js'), 'async failure left the hiding scope enabled');
}

function testPersistentFocusSkip() {
    const hero = fakeElement(['hero-entrance'], 0);
    const outerReveal = fakeElement(['reveal'], 700, hero);
    const innerReveal = fakeElement(['reveal-up'], 700, outerReveal);
    const env = environment({ height: 800, targets: [outerReveal, innerReveal] });
    const focused = {
        parentElement: innerReveal,
        matches() { return false; },
    };
    env.dispatchDocument('focusin', { target: focused });
    check(isVisible(innerReveal) && innerReveal.classList.contains('motion-skip'), 'inner focused reveal was not persistently skipped');
    check(isVisible(outerReveal) && outerReveal.classList.contains('motion-skip'), 'outer focused reveal was not persistently skipped');
    check(hero.classList.contains('motion-skip'), 'focused hero entrance was not persistently skipped');
    env.dispatchDocument('focusout', { target: focused });
    check(
        innerReveal.classList.contains('motion-skip')
            && outerReveal.classList.contains('motion-skip')
            && hero.classList.contains('motion-skip'),
        'focus-out removed a persistent ancestor skip'
    );
}

function testObserverWatchdog() {
    // A silent observer (occluded/automation window) must fail open instead of
    // leaving pending targets at opacity:0 forever.
    const stranded = fakeElement(['reveal'], 900);
    const silent = environment({ height: 800, targets: [stranded] });
    check(!isVisible(stranded), 'watchdog fixture target revealed too early');
    silent.flushTimers();
    check(isVisible(stranded), 'silent observer did not fail open via the watchdog');
    check(!silent.context.document.documentElement.classList.contains('motion-js'), 'watchdog fail-open left the hiding scope enabled');

    // A delivering observer proves itself healthy even with nothing
    // intersecting yet; the watchdog must not disturb normal reveal flow.
    const pending = fakeElement(['reveal'], 900);
    const healthy = environment({ height: 800, targets: [pending] });
    mainObservers(healthy)[0].emitEntries([{
        target: pending,
        isIntersecting: false,
        intersectionRatio: 0,
    }]);
    healthy.flushTimers();
    check(!isVisible(pending), 'watchdog revealed a pending target despite a healthy observer');
    check(healthy.context.document.documentElement.classList.contains('motion-js'), 'watchdog disabled a healthy motion scope');

    // A hidden page delivers nothing legitimately: the watchdog re-arms and
    // only fails open once the page has been visible for a full deadline.
    const backgrounded = fakeElement(['reveal'], 900);
    const hidden = environment({ height: 800, targets: [backgrounded] });
    hidden.context.document.visibilityState = 'hidden';
    hidden.flushTimers();
    check(!isVisible(backgrounded), 'hidden page triggered the watchdog fail-open');
    check(hidden.context.document.documentElement.classList.contains('motion-js'), 'hidden page disabled the motion scope');
    hidden.context.document.visibilityState = 'visible';
    hidden.flushTimers();
    check(isVisible(backgrounded), 'watchdog did not fail open after the page became visible');
}

function testReducedMotion() {
    const target = fakeElement(['reveal'], 500);
    const env = environment({ height: 800, targets: [target], reducedMotion: true });
    check(!env.context.document.documentElement.classList.contains('motion-js'), 'reduced motion enabled the hiding scope');
    check(env.observers.length === 0, 'reduced motion created an observer');
}

testGeometryAndStaggerBatches();
testResizeRebuild();
testDocumentEndFallback();
testDocumentEndViewportObserver();
testStationaryLayoutFallbacks();
testFailOpen();
testPersistentFocusSkip();
testObserverWatchdog();
testReducedMotion();

process.stdout.write('motion driver runtime harness passed\n');
