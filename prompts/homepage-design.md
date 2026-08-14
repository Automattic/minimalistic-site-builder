You are a senior web designer and front-end author. Design one complete homepage from the brief, factual site spec, committed design direction, and candidate seed below.

## Brief

{{brief}}

## Site spec

{{site_spec}}

## Committed design direction

{{design_direction}}

## Candidate seed

{{seed}}

Treat the design direction as binding. Execute its structured fields: use the named type slots (including `font-family` for an accent face on flavor names, prices, folio, or numerals — never body copy), honor `shape` on contained media and buttons, and put a committed `device--*` class on at most one non-hero `<section>` if the direction names one. Do not invent textures, extra fonts, or motifs — the theme ships the `surface` overlay. Use the seed to create a distinct candidate angle without replacing or contradicting that direction. Write specific, finished visitor-facing copy from the brief and site spec; do not use lorem ipsum, generic placeholders, design notes, or invented factual claims.

Execute the committed construction on the page, not only in prose:

- Image cards carry `card-style--` plus the assigned `card_style` value (`card-style--flush`, `card-style--framed`, `card-style--overlap`, or `card-style--borderless`). Flush and overlap also carry `card-flush`. Do not invent a 1px frame when the direction says flush.
- If `motion_note` names kit classes (`hover-lift`, `stagger-children`, …), put those classes on the matching blocks — cards get `hover-lift`, at most ONE card row gets `stagger-children`. Do not put stagger on every section or on the hero copy stack. Do not write `@keyframes` or CSS for those kit classes — the theme ships them.
- The header is a start-aligned row: wordmark at the start, links, then the CTA. The hamburger (when the nav collapses) sits at the inline end, never in the center.
- Two CTAs in one flex row (`.hero-actions`), aligned. Do not leave a primary button and a ghost link as unrelated stacked blocks.
- Below-fold sections need CSS the first-fold preview does not have: `.section` / `.section-inner` vertical padding and a max-width measure, `.card-grid` as an equal-height grid, and a styled `.footer-inner`. Invent those rules in the document stylesheet.
- The wordmark is a home link: `<a class="brand" href="/">…</a>`. Never a bare `<span class="brand">`.
- Keep the hero's primary and secondary actions as real `<a href>` to site-spec page paths. An empty first viewport is a failed page.
- Every nav and CTA href is a real page path from the site spec (`/`, `/visit/`, …). Never `#hero` or `#`.
- Do not hardcode `is-current` on a nav item. WordPress marks the current page at render time with `current-menu-item`.
- BINDING FACTS: hours, loan terms, addresses, prices, and inboxes are site-spec truth. Repeat them verbatim. If the spec has `loan_days` or `contact_email`, that is the only allowed value. Do not invent a second number or a second general inbox on this page.
- BINDING DESTINATIONS: a control's label and href must name the same action. A request / reserve / pedir-obra control goes to a request path or `mailto:` for that action (for example `mailto:acervo@` plus the site spec's `email_domain`). A subscribe control goes to a newsletter mailbox. Never send a distinct action to the generic visit page because that page exists.
- Eyebrows and kickers are rationed. Default is heading-first. Add a kicker only for genuine metadata the heading does not carry (a date, venue, or category).

## Document contract

Return one self-contained HTML document and nothing else. Do not wrap it in Markdown fences or add commentary.

- Include `<!doctype html>`, `<html>`, `<head>`, and `<body>`.
- In `<head>`, include character encoding, a viewport meta tag, and exactly one inline `<style>` containing all CSS. Do not use external stylesheets.
- Use a semantic `<header>`, a `<main>` containing ordered homepage `<section>` landmarks, and a `<footer>`. Give every section a unique, stable `id` suitable for a CSS selector and future patch revision.
- Keep site chrome in `<header>` and `<footer>`. Keep homepage content in the sections inside `<main>`.
- Honor the site spec's requested homepage sections, content, language, identity, and facts.

## Supported HTML slice

Use only headings, paragraphs, lists, block quotes, code, tables, images, buttons, links, and semantic or presentational wrappers such as `header`, `main`, `section`, `footer`, `nav`, `article`, `aside`, `div`, and `span`.

There are no forms or form controls, no SVG, no custom elements, and no JavaScript. Do not emit `<form>`, `<input>`, `<textarea>`, `<select>`, `<button>` with scripted behavior, `<svg>`, `<script>`, inline event handlers, or `javascript:` URLs. Express layout, ornament, and interaction states with HTML and CSS only.

Use `<button>` only for a real non-scripted action. Prefer an `<a>` link styled as a button for navigation or a CTA.

## Image contract

Use `<img>` only where imagery materially improves the page. Every image needs meaningful `alt` text written as a usable image generation prompt: name subject, setting, composition, lighting, palette or grade, and framing. Do not use vague alt text such as "hero image", file names, or empty alt text for content imagery. Keep any readable words or brand names out of generated images; render them as HTML text.

## Responsive CSS contract

- Write mobile-first responsive CSS, then add min-width media queries only where composition needs them.
- Use fluid type with `clamp()` for display and section-heading scales while keeping body text readable.
- Keep running copy in a 45–75ch measure. Do not let a paragraph span the viewport.
- No gradient text. No thick colored `border-left` / `border-right` accent stripe on cards or callouts.
- Use flexible grid/flex layouts, bounded content widths, responsive spacing, and images that cannot overflow.
- Ensure navigation, tables, long words, and multi-column layouts remain usable on narrow screens.
- Preserve clear focus states, readable contrast, and reduced-motion behavior for any CSS transitions.

Make hierarchy, spacing rhythm, composition variety, typographic scale, palette discipline, and header/hero/footer cohesion deliberate. Avoid repetitive card grids, default text-left/image-right sections, timid type, and decorative repetition. Return only the finished HTML document.
