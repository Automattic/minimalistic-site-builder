<?php
declare(strict_types=1);

/**
 * Step (deterministic): the "frame" — bake the design system's CSS scaffolding
 * into the theme's style.css. This is the global-CSS half of the spec's Frame
 * step (header + footer are generated as block parts by SectionsStep).
 *
 * Two things land here, both referencing theme.json tokens via CSS variables so
 * token discipline holds:
 *   1. One orchestrated page-load reveal — top-level sections fade/slide up with
 *      a staggered animation-delay (the "one well-orchestrated page load beats
 *      scattered micro-interactions" rule). Disabled under prefers-reduced-motion.
 *   2. The CSS pattern catalog the section prompts hook into by className
 *      (marquee, scroll-row, sticky-rail, stacked-cards, sticker, equal-cards).
 *
 * Deterministic on purpose: these utilities are a fixed, token-referencing
 * vocabulary, so a curated block is more reliable than an extra LLM call — and
 * it guarantees the classes the sections reference actually exist.
 */
final class FrameCssStep implements Step
{
    /** Marker so the block is appended exactly once per style.css. */
    private const MARKER = '/* --- builder: design frame --- */';

    public function id(): string
    {
        return 'frame-css';
    }

    public function label(): string
    {
        return 'Bake design frame into style.css';
    }

    public function run(Project $project): void
    {
        $css = $project->exists('theme/style.css') ? $project->readText('theme/style.css') : '';
        if (str_contains($css, self::MARKER)) {
            return; // already framed (idempotent)
        }
        $project->writeText('theme/style.css', rtrim($css) . "\n\n" . self::FRAME_CSS . "\n");
    }

    private const FRAME_CSS = <<<'CSS'
/* --- builder: design frame --- */

/* One orchestrated page-load reveal: top-level sections fade + rise in with a
   staggered delay. Honors reduced-motion (no animation, full opacity). */
@media (prefers-reduced-motion: no-preference) {
  .wp-site-blocks > * {
    animation: builder-reveal 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
  }
  .wp-site-blocks > :nth-child(1) { animation-delay: 0.00s; }
  .wp-site-blocks > :nth-child(2) { animation-delay: 0.08s; }
  .wp-site-blocks > :nth-child(3) { animation-delay: 0.16s; }
  .wp-site-blocks > :nth-child(4) { animation-delay: 0.24s; }
  .wp-site-blocks > :nth-child(5) { animation-delay: 0.32s; }
  .wp-site-blocks > :nth-child(6) { animation-delay: 0.40s; }
  .wp-site-blocks > :nth-child(7) { animation-delay: 0.48s; }
  .wp-site-blocks > :nth-child(n+8) { animation-delay: 0.56s; }
}
@keyframes builder-reveal {
  from { opacity: 0; transform: translateY(1.5rem); }
  to   { opacity: 1; transform: none; }
}

/* Marquee / ticker strip — horizontally scrolling text band. */
.marquee { overflow: hidden; }
.marquee > * {
  display: inline-block;
  white-space: nowrap;
  animation: builder-marquee 28s linear infinite;
}
@keyframes builder-marquee {
  from { transform: translateX(100%); }
  to   { transform: translateX(-100%); }
}
@media (prefers-reduced-motion: reduce) {
  .marquee > * { animation: none; white-space: normal; }
}

/* Horizontal scroll row — sideways scroll-snap carousel of cards. */
.scroll-row { overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
.scroll-row > .wp-block-columns { flex-wrap: nowrap; }
.scroll-row .wp-block-column { scroll-snap-align: start; min-width: 280px; flex: 0 0 auto; }

/* Sticky rail — a column that stays pinned while its neighbor scrolls. */
.sticky-rail { position: sticky; top: var(--wp--preset--spacing--40, 2rem); align-self: start; }

/* Layered / rotated card stack — hand-pinned, collaged feel. */
.stacked-cards > .wp-block-group { transform: rotate(-2deg); transition: transform 0.3s ease; }
.stacked-cards > .wp-block-group:nth-child(even) { transform: rotate(2deg); }
.stacked-cards > .wp-block-group:hover { transform: rotate(0deg) scale(1.02); position: relative; z-index: 2; }

/* Sticker / pill overlay — a small rotated accent badge over imagery. */
.sticker {
  display: inline-block;
  transform: rotate(-6deg);
  background-color: var(--wp--preset--color--accent);
  color: var(--wp--preset--color--base);
  padding: 0.4em 0.9em;
  border-radius: 999px;
  font-family: var(--wp--preset--font-family--heading);
  line-height: 1.1;
}

/* Equal-height cards in a row — stretch columns, bottom-align optional CTAs. */
.equal-cards > .wp-block-column { display: flex; flex-direction: column; flex-grow: 0; flex-shrink: 0; }
.equal-cards > .wp-block-column > .wp-block-group { display: flex; flex-direction: column; flex-grow: 1; }
.equal-cards .cta-bottom { margin-top: auto; }
CSS;
}
