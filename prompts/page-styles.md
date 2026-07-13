You are a senior WordPress block-theme developer. Write a SMALL plain-CSS appendix implementing the layout utility classes that this site's generated sections actually reference. It will be appended verbatim to the theme's style.css.

DESIGN DIRECTION (tune the feel — pull distances, hover travel, easing, shadow strength — to this mood):
{{design_direction}}

THEME TOKENS (theme.json — its presets are available as CSS custom properties):
{{theme_json}}

UTILITY CLASSES USED BY THE SECTIONS — write CSS for exactly these, nothing else. Each line is that class's behavior contract: implement the behavior; the specific values (pull distances, hover travel, easing, shadow choice) are your design call:
{{used_classes}}
{{motion_tuning}}
HARD RULES — the output is machine-validated and the whole appendix is rejected if any rule breaks them:
- Every rule's selector MUST start with one of the class names listed above (descendant selectors like `.hover-reveal img` or `.masonry-3 > *` are fine). No element-only, universal, `body`, `:root`, or any other selector not scoped under those classes (the ONLY exception is the optional motion-tuning `:root` block described above, when offered).
- Colors and shadows come from theme presets: `var(--wp--preset--color--<slug>)` (slugs: base, contrast, primary, secondary, accent), the core shadow presets (`var(--wp--preset--shadow--natural)`, `--deep`, `--crisp`, …), or `color-mix()` over those variables. NEVER write raw hex, rgb()/rgba()/hsl() color literals.
- Do not visually hide generated content. NEVER use `opacity: 0`, `visibility: hidden`, or `display: none`; full-page screenshots and non-hover browsing must show all images and text.
- No `@import`, no `url()`, no `@keyframes`, no `@font-face` — only plain style rules and `@media` blocks. Never write CSS for the motion classes (`reveal`, `reveal-up`, `reveal-fade`, `reveal-scale`, `stagger-children`, `hero-entrance`, `ken-burns`, `gradient-shift`, `ambient-drift`) — their CSS ships statically with the theme.
- Under 80 lines total.

Output ONLY the CSS — no markdown fences, no prose, no HTML.
