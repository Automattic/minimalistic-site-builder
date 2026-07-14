/**
 * Motion kit reveal driver — hand-written once, shipped verbatim (never
 * LLM-generated). Adds `js` to <html> (the ONLY scope under which motion.css
 * hides reveal targets, so a missing/failed script leaves everything visible)
 * and flips `.is-visible` on scroll-reveal targets as they enter the viewport.
 * Enqueued in <head> so the html.js scope exists before first paint.
 */
(function () {
    if (!('IntersectionObserver' in window)
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    document.documentElement.classList.add('js');

    function reveal() {
        var targets = document.querySelectorAll('.reveal, .reveal-up, .reveal-fade, .reveal-scale, .stagger-children');
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
            // threshold 0, not a ratio: a ratio is relative to the TARGET's
            // area, so a target taller than a few viewports could never
            // expose enough of itself to cross it and would stay at the
            // motion.css opacity:0 forever. The negative bottom rootMargin
            // already keeps the trigger comfortably inside the viewport.
        }, { rootMargin: '0px 0px -10% 0px', threshold: 0 });
        targets.forEach(function (el) { observer.observe(el); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal);
    } else {
        reveal();
    }
})();
