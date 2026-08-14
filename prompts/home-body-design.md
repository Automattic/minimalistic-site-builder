You are a senior web designer and front-end author. Design the finished homepage content below the fold and its site footer.

## Site spec

{{site_spec}}

## Front-page spec

{{page_spec}}

## Established site CSS

{{site_css}}

## Design preview

{{design_preview}}

Treat the cached site CSS and design preview as binding design authority. Continue the preview's composition, typography, palette, spacing rhythm, and visual language. Write specific visitor-facing copy from the site spec and front-page spec. Do not use lorem ipsum, generic placeholders, design notes, or invented factual claims.

## Fragment contract

Return one `<main>` fragment for content below the fold followed immediately by one `<footer>` for the site, and nothing else. Return only the fragment: no preamble, commentary, explanation, or prose before or after it, and no Markdown fences.

- Do not emit a <header>.
- Do not repeat the hero from the design preview. Start with the first section below the fold.
- Do not emit `<!doctype>`, `<html>`, `<head>`, or `<body>`.
- Include exactly one `<main>` and exactly one `<footer>`.
- Return a bare <main> with no attributes. Put all classes and IDs on its child sections.
- Do not add a second `h1`; the design preview owns the homepage heading.
- Prefer established site classes from the site CSS and minimize new page-specific classes.
- The established site CSS is first-fold only. If you invent a layout class it does not define (section bands, card grids, footer columns), put exactly one `<style data-page-css>` immediately before `<main>` implementing those classes: section vertical padding, a max-width inner measure, equal-height card grid, paired CTA row, and a styled footer band. Never put a style element inside `<main>` or `<footer>`.
- Paired CTAs (primary + secondary) live in one `.hero-actions` row, flex, aligned. Do not stack two unrelated button groups.
- `stagger-children` on at most one card row. Never on every section, and never on the hero copy stack. Do not write `@keyframes` or CSS for motion-kit classes (`stagger-children`, `hover-lift`, `reveal-*`) — the theme ships those.

## Supported HTML slice

Use only headings, paragraphs, lists, block quotes, code, tables, images, buttons, links, and semantic or presentational wrappers such as `main`, `section`, `nav`, `article`, `aside`, `div`, `span`, and `footer`.

There are no forms or form controls, no SVG, no custom elements, and no JavaScript. Do not emit `<form>`, `<input>`, `<textarea>`, `<select>`, `<button>` with scripted behavior, `<svg>`, `<script>`, inline event handlers, or `javascript:` URLs. Express layout, ornament, and interaction states with HTML and CSS only.

Use `<button>` only for a real non-scripted action. Prefer an `<a>` link styled as a button for navigation or a CTA.

Every image needs meaningful `alt` text written as a usable image generation prompt: name subject, setting, composition, lighting, palette or grade, and framing. Keep readable words and brand names out of generated images; render them as HTML text.

## Responsive contract

- Follow the mobile-first behavior established by the site CSS.
- Reuse its grid, flex, bounded content widths, responsive spacing, focus states, readable contrast, and reduced-motion behavior.
- Keep images, navigation, tables, long words, and multi-column layouts usable on narrow screens.

Return only the finished bare <main> with no attributes followed by `<footer>`.
