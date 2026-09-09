/**
 * Motion kit reveal driver — hand-written once, shipped verbatim (never
 * LLM-generated). Adds a motion-owned bootstrap scope before first paint,
 * then flips `.is-visible` once registered targets reach the main viewport.
 * Stagger containers expose direct children individually, so cards stacked
 * on narrow screens do not finish animating while still offscreen.
 */
(function () {
    var root = document.documentElement;
    var ENTRANCE_SELECTOR = '.reveal, .reveal-up, .reveal-fade, .reveal-scale, .reveal-blur, '
        + '.reveal-wipe, .reveal-wipe-up, .reveal-aperture, .reveal-zoom, .stagger-children > *, .count-up';
    var WORD_CLASS = 'word-reveal__word';
    var WORD_INLINE = /^(?:A|ABBR|B|EM|I|SPAN|STRONG|SUB|SUP|U|MARK|SMALL)$/;

    // Wrap each word of a `.word-reveal` heading in an indexed span so the
    // kit can stagger them on load (a word reveal is a hero-entrance, not a
    // scroll reveal: the observer would mark a first-viewport target static).
    // Inline emphasis (a `.emph` span, <em>, <a>) is walked into, not
    // flattened; any other child element makes the heading unsplittable and
    // it stays whole and visible. Runs before motion-ready so the split
    // words start their staggered entrance from the first painted frame.
    function splitWords() {
        var headings = document.querySelectorAll('.word-reveal:not(.word-reveal--split)');
        Array.prototype.forEach.call(headings, function (heading) {
            var index = 0;
            var splittable = true;
            var walk = function (node) {
                var children = Array.prototype.slice.call(node.childNodes);
                children.forEach(function (child) {
                    if (child.nodeType === 3) {
                        var parts = child.nodeValue.split(/(\s+)/);
                        if (parts.length === 1 && !/\S/.test(parts[0])) {
                            return;
                        }
                        var fragment = document.createDocumentFragment();
                        parts.forEach(function (part) {
                            if (part === '') {
                                return;
                            }
                            if (!/\S/.test(part)) {
                                fragment.appendChild(document.createTextNode(part));
                                return;
                            }
                            var span = document.createElement('span');
                            span.className = WORD_CLASS;
                            span.style.setProperty('--word-index', String(index));
                            span.textContent = part;
                            index += 1;
                            fragment.appendChild(span);
                        });
                        node.replaceChild(fragment, child);
                    } else if (child.nodeType === 1) {
                        if (child.tagName === 'BR') {
                            return;
                        }
                        if (!WORD_INLINE.test(child.tagName)) {
                            splittable = false;
                            return;
                        }
                        walk(child);
                    }
                });
            };
            var original = heading.innerHTML;
            try {
                walk(heading);
                if (!splittable || index === 0) {
                    heading.innerHTML = original;
                    return;
                }
                heading.classList.add('word-reveal--split');
            } catch (error) {
                heading.innerHTML = original;
            }
        });
    }

    // This callback is replaced after setup so keyboard focus can also
    // unobserve a pending target. Before DOMContentLoaded, the default still
    // makes focused content persistently static and visible.
    var revealFocused = function (target) {
        target.classList.add('motion-skip');
        target.classList.add('is-visible');
    };

    document.addEventListener('focusin', function (event) {
        // Persist the escape on EVERY owning entrance. `closest()` alone would
        // skip only an inner reveal and let a hidden outer reveal/card return
        // to opacity:0 as soon as focus moved away.
        var target = event.target;
        while (target && target !== root) {
            if (typeof target.matches === 'function'
                && (target.matches(ENTRANCE_SELECTOR) || target.matches('.hero-entrance'))) {
                revealFocused(target);
            }
            target = target.parentElement;
        }
    }, true);

    if (!('IntersectionObserver' in window)
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    root.classList.add('motion-js');

    // Build each `.marquee` paragraph into a track of two identical groups
    // of repeated items (frm W8c): the CSS loop translates the track by
    // half, so the clone makes the seam invisible. The first item keeps the
    // readable text; every repeat is hidden from assistive tech. Runs before
    // motion-ready and only under motion-js, so reduced motion and no-JS
    // keep the plain static line.
    function buildMarquees() {
        var marquees = document.querySelectorAll('.marquee:not(.marquee--built)');
        Array.prototype.forEach.call(marquees, function (marquee) {
            var text = (marquee.textContent || '').replace(/\s+/g, ' ').trim();
            if (text === '') {
                return;
            }
            var group = document.createElement('span');
            group.className = 'marquee__group';
            var makeItem = function (hidden) {
                var item = document.createElement('span');
                item.className = 'marquee__item';
                item.textContent = text;
                if (hidden) {
                    item.setAttribute('aria-hidden', 'true');
                }
                return item;
            };
            group.appendChild(makeItem(false));
            marquee.textContent = '';
            marquee.appendChild(group);
            var guard = 0;
            while (group.scrollWidth < marquee.clientWidth && guard < 24) {
                group.appendChild(makeItem(true));
                guard++;
            }
            var clone = group.cloneNode(true);
            clone.setAttribute('aria-hidden', 'true');
            Array.prototype.forEach.call(clone.querySelectorAll('.marquee__item'), function (item) {
                item.setAttribute('aria-hidden', 'true');
            });
            var track = document.createElement('span');
            track.className = 'marquee__track';
            track.appendChild(group);
            track.appendChild(clone);
            marquee.appendChild(track);
            marquee.classList.add('marquee--built');
        });
    }

    function reveal() {
        var targets;
        try {
            splitWords();
            buildMarquees();
            targets = Array.prototype.slice.call(document.querySelectorAll(ENTRANCE_SELECTOR));
            targets.forEach(function (target) {
                target.classList.add('motion-target');
            });
            // From this point CSS hides only registered targets. A block added
            // later stays visible instead of being stranded outside this
            // one-time observer snapshot.
            root.classList.add('motion-ready');
        } catch (error) {
            root.classList.remove('motion-js');
            root.classList.remove('motion-ready');
            return;
        }

        if (targets.length === 0) {
            return;
        }

        var VIEWPORT_INSET = 0.25;
        var ROW_TOLERANCE = 4;
        var WATCHDOG_MS = 4000;
        var observer = null;
        var viewportObserver = null;
        var layoutObserver = null;
        var resizeTimer = null;
        var watchdogTimer = null;
        var observerDelivered = false;

        function viewportHeight() {
            // innerHeight remains the viewport in both standards and quirks
            // mode; documentElement can grow to document height in the latter.
            return window.innerHeight || root.clientHeight;
        }

        function viewportWidth() {
            return window.innerWidth || root.clientWidth;
        }

        function clipsOverflow(value) {
            return /^(?:auto|scroll|hidden|clip|overlay)$/.test(value);
        }

        function intersectsVisibleArea(target, rect, width, height) {
            var clipLeft = 0;
            var clipRight = width;
            var clipTop = 0;
            var clipBottom = height;
            if (rect.right <= clipLeft || rect.left >= clipRight
                || rect.bottom <= clipTop || rect.top >= clipBottom) {
                return false;
            }

            // Manual initial/end classification must honor clipping just like
            // IntersectionObserver. Otherwise offscreen cards inside an
            // overflow carousel would be marked visible before they slide in.
            var ancestor = target.parentElement;
            while (ancestor && ancestor !== root) {
                var style = window.getComputedStyle(ancestor);
                var clipX = clipsOverflow(style.overflowX);
                var clipY = clipsOverflow(style.overflowY);
                if (clipX || clipY) {
                    var ancestorRect = ancestor.getBoundingClientRect();
                    if (clipX) {
                        clipLeft = Math.max(clipLeft, ancestorRect.left);
                        clipRight = Math.min(clipRight, ancestorRect.right);
                    }
                    if (clipY) {
                        clipTop = Math.max(clipTop, ancestorRect.top);
                        clipBottom = Math.min(clipBottom, ancestorRect.bottom);
                    }
                    if (clipLeft >= clipRight || clipTop >= clipBottom) {
                        return false;
                    }
                }
                ancestor = ancestor.parentElement;
            }
            return rect.right > clipLeft && rect.left < clipRight
                && rect.bottom > clipTop && rect.top < clipBottom;
        }

        function pendingTargets() {
            return targets.filter(function (target) {
                return !target.classList.contains('is-visible');
            });
        }

        // Count a figure up from zero when its block enters (frm W8b). The
        // authored text stays the source of truth: prefix, thousands
        // separators, decimals and suffix are preserved, only the digits
        // move. Static paths (reduced motion, motion-skip, no JS) never call
        // this, so the final figure is what they show.
        function startCountUp(target) {
            if (!target.classList.contains('count-up') || target.getAttribute('data-count-started') === 'true') {
                return;
            }
            target.setAttribute('data-count-started', 'true');
            var original = (target.textContent || '').trim();
            var match = /^([^0-9]*)([0-9][0-9,.\u00a0 ]*[0-9]|[0-9])(.*)$/.exec(original);
            if (!match) {
                return;
            }
            var prefix = match[1];
            var digits = match[2];
            var suffix = match[3];
            var separator = /,/.test(digits) ? ',' : '';
            var decimalMatch = /\.([0-9]+)$/.exec(digits);
            var decimals = decimalMatch ? decimalMatch[1].length : 0;
            var finalValue = parseFloat(digits.replace(/[,\u00a0 ]/g, ''));
            if (isNaN(finalValue)) {
                return;
            }
            var durationText = getComputedStyle(root).getPropertyValue('--motion-count-duration');
            var duration = parseFloat(durationText) || 1400;
            if (/s\s*$/.test(durationText) && !/ms\s*$/.test(durationText)) {
                duration = duration * 1000;
            }
            var format = function (value) {
                var fixed = value.toFixed(decimals);
                if (separator !== '') {
                    var parts = fixed.split('.');
                    parts[0] = parts[0].replace(/\B(?=([0-9]{3})+(?![0-9]))/g, separator);
                    fixed = parts.join('.');
                }
                return prefix + fixed + suffix;
            };
            var start = null;
            var step = function (now) {
                if (start === null) {
                    start = now;
                }
                var progress = Math.min(1, (now - start) / duration);
                var eased = 1 - Math.pow(1 - progress, 3);
                target.textContent = progress >= 1 ? original : format(finalValue * eased);
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            target.textContent = format(0);
            window.requestAnimationFrame(step);
        }

        function show(target) {
            target.classList.add('is-visible');
            startCountUp(target);
            if (observer) {
                try {
                    observer.unobserve(target);
                } catch (error) {
                    // The target is visible already; cleanup cannot re-hide it.
                }
            }
            if (viewportObserver) {
                try {
                    viewportObserver.unobserve(target);
                } catch (error) {
                    // Same fail-open cleanup contract as the main observer.
                }
            }
        }

        function showStatic(target) {
            target.classList.add('motion-skip');
            show(target);
        }

        function showBatch(batch) {
            var rows = [];
            batch.slice().sort(function (a, b) {
                return targets.indexOf(a) - targets.indexOf(b);
            }).forEach(function (target) {
                var parent = target.parentElement;
                if (parent && parent.classList.contains('stagger-children')) {
                    var top = target.getBoundingClientRect().top;
                    var row = null;
                    rows.some(function (candidate) {
                        if (candidate.parent === parent
                            && Math.abs(candidate.top - top) <= ROW_TOLERANCE) {
                            row = candidate;
                            return true;
                        }
                        return false;
                    });
                    if (!row) {
                        row = { parent: parent, top: top, count: 0 };
                        rows.push(row);
                    }
                    target.style.setProperty('--motion-stagger-order', Math.min(row.count, 8));
                    row.count += 1;
                }
                show(target);
            });
        }

        revealFocused = function (target) {
            target.classList.add('motion-skip');
            if (targets.indexOf(target) !== -1) {
                show(target);
                stopWhenDone();
            } else {
                target.classList.add('is-visible');
            }
        };

        function failOpen() {
            targets.forEach(function (target) {
                target.classList.add('is-visible');
            });
            // This class belongs only to this driver, so removing it safely
            // disables every reveal-hiding rule if an async/polyfill path fails.
            root.classList.remove('motion-js');
            root.classList.remove('motion-ready');
            stop();
        }

        function stop() {
            window.clearTimeout(resizeTimer);
            window.clearTimeout(watchdogTimer);
            window.removeEventListener('resize', scheduleObserver);
            window.removeEventListener('scroll', revealAtDocumentEnd);
            window.removeEventListener('load', revealAtDocumentEnd);
            if (observer) {
                try {
                    observer.disconnect();
                } catch (error) {
                    // All targets are visible (or setup has failed open).
                }
            }
            if (viewportObserver) {
                try {
                    viewportObserver.disconnect();
                } catch (error) {
                    // All targets are visible (or setup has failed open).
                }
            }
            if (layoutObserver) {
                try {
                    layoutObserver.disconnect();
                } catch (error) {
                    // Optional layout watching must not affect visibility.
                }
            }
        }

        function stopWhenDone() {
            if (pendingTargets().length === 0) {
                stop();
                return true;
            }
            return false;
        }

        function intersectingTargets(entries) {
            return entries.filter(function (entry) {
                return entry.intersectionRatio > 0
                    || Boolean(entry.isIntersecting
                        && entry.intersectionRect
                        && entry.intersectionRect.width > 0
                        && entry.intersectionRect.height > 0);
            }).map(function (entry) {
                return entry.target;
            });
        }

        function atDocumentEnd(height) {
            var scrollTop = window.pageYOffset || root.scrollTop || 0;
            var scrollHeight = Math.max(
                root.scrollHeight,
                document.body ? document.body.scrollHeight : 0
            );
            return Math.ceil(scrollTop + height) >= scrollHeight - 1;
        }

        function buildObserver() {
            try {
                var height = viewportHeight();
                var width = viewportWidth();
                var inset = Math.max(1, Math.round(height * VIEWPORT_INSET));
                var triggerLine = height - inset;
                if (observer) {
                    observer.disconnect();
                }
                if (viewportObserver) {
                    viewportObserver.disconnect();
                }
                observer = new IntersectionObserver(function (entries) {
                    observerDelivered = true;
                    try {
                        showBatch(intersectingTargets(entries));
                        stopWhenDone();
                    } catch (error) {
                        failOpen();
                    }
                }, {
                    // IntersectionObserver percentages are width-relative. A
                    // height-derived pixel inset gives every aspect ratio the
                    // intended 75% vertical trigger line.
                    rootMargin: '0px 0px -' + inset + 'px 0px',
                    // Effectively zero, but positive: threshold:0 treats mere
                    // edge contact as intersecting and never fires again when
                    // a clipped carousel item gains real visible area.
                    threshold: 0.000001
                });
                // The main observer deliberately ignores the bottom 25%.
                // This full-viewport companion only acts at document end, so
                // an inner-scroller or position-only change can still expose a
                // final target without requiring a window scroll/resize event.
                viewportObserver = new IntersectionObserver(function (entries) {
                    observerDelivered = true;
                    try {
                        if (atDocumentEnd(viewportHeight())) {
                            showBatch(intersectingTargets(entries));
                            stopWhenDone();
                        }
                    } catch (error) {
                        failOpen();
                    }
                }, { threshold: 0.000001 });

                var initial = [];
                var passed = [];
                pendingTargets().forEach(function (target) {
                    var rect = target.getBoundingClientRect();
                    if (rect.bottom <= 0) {
                        passed.push(target);
                    } else if (rect.top <= triggerLine
                        && intersectsVisibleArea(target, rect, width, triggerLine)) {
                        initial.push(target);
                    } else {
                        observer.observe(target);
                        viewportObserver.observe(target);
                    }
                });
                // Content already visible/restored above the activation line
                // is immediately usable; the hero owns intentional load motion.
                passed.forEach(showStatic);
                initial.forEach(showStatic);
                if (!stopWhenDone()) {
                    revealAtDocumentEnd(true);
                }
            } catch (error) {
                failOpen();
            }
        }

        function scheduleObserver() {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(buildObserver, 100);
        }

        function revealAtDocumentEnd(staticEntrance) {
            try {
                var height = viewportHeight();
                var width = viewportWidth();
                if (!atDocumentEnd(height)) {
                    return;
                }

                // A short final target may never cross the 75% line. Passed
                // targets become static; only physically visible targets join
                // the entrance batch, so hidden carousel items stay pending.
                var passed = [];
                var finalTargets = [];
                pendingTargets().forEach(function (target) {
                    var rect = target.getBoundingClientRect();
                    if (rect.bottom <= 0) {
                        passed.push(target);
                    } else if (rect.top < height
                        && intersectsVisibleArea(target, rect, width, height)) {
                        finalTargets.push(target);
                    }
                });
                passed.forEach(showStatic);
                if (staticEntrance === true) {
                    finalTargets.forEach(showStatic);
                } else {
                    showBatch(finalTargets);
                }
                stopWhenDone();
            } catch (error) {
                failOpen();
            }
        }

        // A healthy IntersectionObserver always delivers an initial batch for
        // its observed targets, so silence past this deadline means the
        // observer is broken (occluded/automation window, extension
        // interference) and pending content would stay at opacity:0 forever.
        // A hidden page legitimately delivers nothing — re-arm and only fail
        // open once the page has been visible for a full deadline.
        function armWatchdog() {
            watchdogTimer = window.setTimeout(function () {
                if (observerDelivered) {
                    return;
                }
                if (document.visibilityState === 'hidden') {
                    armWatchdog();
                    return;
                }
                failOpen();
            }, WATCHDOG_MS);
        }

        buildObserver();
        if (!stopWhenDone()) {
            armWatchdog();
            window.addEventListener('resize', scheduleObserver);
            window.addEventListener('scroll', revealAtDocumentEnd, { passive: true });
            window.addEventListener('load', revealAtDocumentEnd, { once: true });
            if ('ResizeObserver' in window) {
                try {
                    layoutObserver = new ResizeObserver(revealAtDocumentEnd);
                    layoutObserver.observe(root);
                    if (document.body) {
                        layoutObserver.observe(document.body);
                    }
                } catch (error) {
                    layoutObserver = null;
                }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal, { once: true });
    } else {
        reveal();
    }
})();
