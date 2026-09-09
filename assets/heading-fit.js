/**
 * Last-mile word fit for authored heroes. The build's estimate cannot know the
 * rendered font or a CSS-authored column width. Measure the real text without
 * introducing size containment (which collapses intrinsic-width copy regions).
 * Only overflowing headings shrink; source text, <br> and rich text stay intact.
 */
(function () {
    'use strict';

    function start() {
        const headings = Array.from(document.querySelectorAll('.hero-composition--authored .wp-block-heading'));
        if (!headings.length) return;
        const states = new WeakMap();
        headings.forEach(function (heading) {
            states.set(heading, {
                value: heading.style.getPropertyValue('font-size'),
                priority: heading.style.getPropertyPriority('font-size'),
                hadStyle: heading.hasAttribute('style'),
                fitted: false,
            });
        });

        function restore(heading, state) {
            if (!state.fitted) return;
            if (state.value) heading.style.setProperty('font-size', state.value, state.priority);
            else heading.style.removeProperty('font-size');
            if (!state.hadStyle && !heading.style.length) heading.removeAttribute('style');
            state.fitted = false;
        }

        function fits(heading) {
            const style = getComputedStyle(heading);
            const box = heading.getBoundingClientRect();
            const scale = heading.offsetWidth ? box.width / heading.offsetWidth : 1;
            const left = box.left + (parseFloat(style.borderLeftWidth) + parseFloat(style.paddingLeft)) * scale;
            const right = box.right - (parseFloat(style.borderRightWidth) + parseFloat(style.paddingRight)) * scale;
            const walker = document.createTreeWalker(heading, NodeFilter.SHOW_TEXT);
            const range = document.createRange();
            while (walker.nextNode()) {
                const node = walker.currentNode;
                if (!node.data.trim()) continue;
                range.selectNodeContents(node);
                for (const rect of range.getClientRects()) {
                    if (rect.width && (rect.left < left - 0.5 || rect.right > right + 0.5)) return false;
                }
            }
            return true;
        }

        function fit(heading) {
            const state = states.get(heading);
            restore(heading, state);
            const style = getComputedStyle(heading);
            const size = parseFloat(style.fontSize);
            if (!heading.clientWidth || !Number.isFinite(size) || size <= 1 || style.writingMode !== 'horizontal-tb' || fits(heading)) return;

            // Bounded search: no line-count target, no promotion and no layout
            // rearrangement. !important beats Core's preset font-size classes.
            let low = 1;
            let high = size;
            state.fitted = true;
            heading.style.setProperty('font-size', low + 'px', 'important');
            if (!fits(heading)) {
                // A fixed-size inline child or non-text constraint cannot be
                // repaired by shrinking its parent. Keep the authored size.
                restore(heading, state);
                return;
            }
            for (let i = 0; i < 10; i++) {
                const candidate = (low + high) / 2;
                heading.style.setProperty('font-size', candidate + 'px', 'important');
                if (fits(heading)) low = candidate;
                else high = candidate;
            }
            heading.style.setProperty('font-size', low + 'px', 'important');
        }

        let pending = false;
        function schedule() {
            if (pending) return;
            pending = true;
            requestAnimationFrame(function () {
                pending = false;
                headings.forEach(fit);
            });
        }

        headings.forEach(fit);
        window.addEventListener('resize', schedule, { passive: true });
        if (typeof ResizeObserver === 'function') {
            const widths = new WeakMap();
            const observer = new ResizeObserver(function (entries) {
                entries.forEach(function (entry) {
                    const width = entry.contentRect.width;
                    if (widths.get(entry.target) !== width) {
                        widths.set(entry.target, width);
                        schedule();
                    }
                });
            });
            headings.forEach(function (heading) {
                observer.observe(heading);
                if (heading.parentElement) observer.observe(heading.parentElement);
            });
        }
        if (document.fonts) {
            document.fonts.ready.then(schedule);
            document.fonts.addEventListener('loadingdone', schedule);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}());
