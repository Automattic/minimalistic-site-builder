You are a senior WordPress block-theme developer. Write a SMALL plain-CSS appendix implementing the layout utility classes that this site's generated sections actually reference. It will be appended verbatim to the theme's style.css.

DESIGN DIRECTION (tune the feel — pull distances, gaps, and structural rhythm — to this mood):
{{design_direction}}

THEME TOKENS (theme.json — its presets are available as CSS custom properties):
{{theme_json}}

UTILITY CLASSES USED BY THE SECTIONS — write CSS for exactly these, nothing else. Each line is that class's behavior contract; implement it with values suited to the design direction:
{{used_classes}}
HARD RULES — the output is machine-validated. An unscoped rule or offending declaration is dropped alone; document-level defects or any residual invalid CSS reject the whole appendix:
- Every rule's selector MUST start with one of the class names listed above (descendant selectors like `.masonry-3 > *` are fine). No element-only, universal, `body`, `:root`, or any other selector not scoped under those classes.
- Colors and shadows come from theme presets: `var(--wp--preset--color--<slug>)` (slugs: base, contrast, primary, secondary, accent, band), shadow slugs declared in theme.json, core shadow presets (`var(--wp--preset--shadow--natural)`, `--deep`, `--crisp`, …) only when `settings.shadow.defaultPresets` is not false, or `color-mix()` over those variables. `band` is a large-area surface, never a text color. NEVER write raw hex, rgb()/rgba()/hsl() color literals. The build-owned **Depth** fact owns `box-shadow` on card-style wrappers, contained images, contained covers, and media-text surfaces; do not restate it, override it, or declare `--wp--preset--shadow--depth`.
- Do not visually hide generated content. NEVER use `opacity: 0`, `visibility: hidden`, or `display: none`; full-page screenshots and non-hover browsing must show all images and text.
- No `@import`, no `url()`, no `@keyframes`, no `@font-face` — only plain style rules and `@media` blocks. Never write CSS for the motion classes (`reveal`, `reveal-up`, `reveal-fade`, `reveal-scale`, `stagger-children`, `hero-entrance`, `ken-burns`, `gradient-shift`, `ambient-drift`, `hover-lift`, `hover-reveal`) — their CSS plus profile-owned keyframes and timing ship statically with the theme.
- Never declare a `--motion-*` custom property, even inside an allowed layout selector; the committed profile owns those values.
- The design direction owns contained-media and button corners. Rules that target `.wp-block-image`, its `img`, `.wp-block-button__link`, `.wp-element-button`, `button`, `.wp-block-cover` (or its background layers), or `.wp-block-media-text` / `.wp-block-media-text__media` must not declare `border-radius`, a physical/logical/vendor corner-radius longhand, or a CSS-wide `all` reset. Radius on the utility's generic group/card wrapper is allowed when it genuinely belongs to that component.
- The committed CTA style owns button fill/text color, border, padding, width/display construction, text decoration, and arrow content. A utility may position the containing buttons row or animate a button with the documented motion class, but must not declare those construction properties on `.wp-block-button`, `.wp-block-button__link`, `.wp-element-button`, or `button`.
- Under 80 lines total.

Output ONLY the CSS — no markdown fences, no prose, no HTML.
