You are a senior web designer and front-end author. Design one complete homepage from the brief, factual site spec, committed design direction, and candidate seed below.

## Brief

{{brief}}

## Site spec

{{site_spec}}

## Committed design direction

{{design_direction}}

## Candidate seed

{{seed}}

Treat the design direction as binding. Use the seed to create a distinct candidate angle without replacing or contradicting that direction. Write specific, finished visitor-facing copy from the brief and site spec; do not use lorem ipsum, generic placeholders, design notes, or invented factual claims.

LANGUAGE: write ALL visitor-facing copy — headings, body text, captions, list items, labels, button text, image alt text — in {{language}}. Do NOT mix languages; the only exceptions are proper nouns and the spec's verbatim identity values.

## Document contract

Return one self-contained HTML document and nothing else. Do not wrap it in Markdown fences or add commentary.

- Include `<!doctype html>`, `<html>`, `<head>`, and `<body>`.
- In `<head>`, include character encoding, a viewport meta tag, and exactly one inline `<style>` containing all CSS. Do not use external stylesheets.
- Use a semantic `<header>`, a `<main>` containing ordered homepage `<section>` landmarks, and a `<footer>`. Give every section a unique, stable `id` suitable for a CSS selector and future patch revision.
- Keep site chrome in `<header>` and `<footer>`. Keep homepage content in the sections inside `<main>`.
- Honor the site spec's requested homepage sections, content, language, identity, and facts.

## Supported HTML slice

Use only headings, paragraphs, lists, block quotes, code, tables, images, buttons, links, and semantic or presentational wrappers such as `header`, `main`, `section`, `footer`, `nav`, `article`, `aside`, `div`, and `span`.

- Device: when the DESIGN DIRECTION carries a **Device** fact naming a class, put that class on the root element of exactly ONE band, and never the hero — the build ships the CSS for it and strips the class from any extra band or from the hero. When there is no Device fact, never invent one.

There are no forms or form controls, no SVG, no custom elements, and no JavaScript. Do not emit `<form>`, `<input>`, `<textarea>`, `<select>`, `<button>` with scripted behavior, `<svg>`, `<script>`, inline event handlers, or `javascript:` URLs. Express layout, ornament, and interaction states with HTML and CSS only. A form the brief or plan genuinely asks for (contact, booking, RSVP, signup) must NOT silently disappear because of this rule: reserve its place with a `<div class="html-form-placeholder">` containing the heading the form would have and one short line naming what it will collect (e.g. "Booking form: name, email, message") — a later build step replaces that placeholder with the working form.

Use `<button>` only for a real non-scripted action. Prefer an `<a>` link styled as a button for navigation or a CTA.

## Image contract

Use `<img>` only where imagery materially improves the page. Every image needs meaningful `alt` text written as a usable image generation prompt: name subject, setting, composition, lighting, palette or grade, and framing. Do not use vague alt text such as "hero image", file names, or empty alt text for content imagery. Keep any readable words or brand names out of generated images; render them as HTML text.

## Responsive CSS contract

- Write mobile-first responsive CSS, then add min-width media queries only where composition needs them.
- Use fluid type with `clamp()` for display and section-heading scales while keeping body text readable.
- Use flexible grid/flex layouts, bounded content widths, responsive spacing, and images that cannot overflow.
- Ensure navigation, tables, long words, and multi-column layouts remain usable on narrow screens.
- Preserve clear focus states, readable contrast, and reduced-motion behavior for any CSS transitions.

Make hierarchy, spacing rhythm, composition variety, typographic scale, palette discipline, and header/hero/footer cohesion deliberate. Avoid repetitive card grids, default text-left/image-right sections, timid type, and decorative repetition. Return only the finished HTML document.
