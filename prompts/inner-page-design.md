You are a senior web designer and front-end author. Design one finished inner page in an established site.

## Site spec

{{site_spec}}

## Page spec

{{page_spec}}

## Site pages

SITE PAGES (the whole site, for internal links):
{{site_pages}}

## Established site CSS

{{site_css}}

## Design preview

{{design_preview}}

Treat the cached site CSS and design preview as binding design authority. Reuse existing classes from the site CSS and follow the fold's composition, typography, palette, spacing rhythm, and visual language without copying its header or hero. Write specific visitor-facing copy from the page spec and keep it consistent with the site spec. Honor THIS page's purpose: a contact or enquiry page stays brief (a short opener, how to reach the organization, optional hours or address) and does not grow programs, galleries, or homepage-length stories. Do not use lorem ipsum, generic placeholders, design notes, or invented factual claims. Never invent an email, street address, phone number, or URL; use only contact facts present in the site spec, and omit them when the spec has none.

LANGUAGE: write ALL visitor-facing copy — headings, body text, captions, list items, labels, button text, image alt text — in {{language}}. Do NOT mix languages within the page; the only exceptions are proper nouns and the spec's verbatim identity values.

## Fragment contract

Return one `<main>` fragment and nothing else. Return only the fragment: no preamble, commentary, explanation, or prose before or after it, and no Markdown fences.

- Omit `<!doctype>`, `<html>`, `<head>`, `<body>`, site header, and site footer.
- The `<main>` must contain only this page's content and have one clear `h1`.
- Prefer established site classes from the site CSS. Page-specific CSS is a last resort and must stay minimal and well under 16 KB.
- When page-specific CSS is essential, put exactly one `<style data-page-css>` immediately before `<main>`. Never put a style element inside `<main>`.
- LINKS: when a button or link leads to another page of THIS site, use that page's path from SITE PAGES verbatim (e.g. `href="/menu/"`) — never a path that isn't in the list. A deep link to another page includes that owning page's path (e.g. `href="/page/#anchor"`). A bare `href="#anchor"` is valid only when that exact section ID exists in this page's own content. Do not link the page to itself. An external link uses an exact URL supplied by the SITE SPEC; when none was supplied, omit the link or render its label as plain text. NEVER emit `href="#"`.

## Supported HTML slice

Use only headings, paragraphs, lists, block quotes, code, tables, images, buttons, links, and semantic or presentational wrappers such as `main`, `section`, `nav`, `article`, `aside`, `div`, and `span`.

There are no forms or form controls, no SVG, no custom elements, and no JavaScript. Do not emit `<form>`, `<input>`, `<textarea>`, `<select>`, `<button>` with scripted behavior, `<svg>`, `<script>`, inline event handlers, or `javascript:` URLs. Express layout, ornament, and interaction states with HTML and CSS only. A shop is a catalog storefront: no cart, checkout, quantity input, add-to-cart control, price-per-unit purchase flow, or WooCommerce block — product cards that invite a contact enquiry are the whole store.

Use `<button>` only for a real non-scripted action. Prefer an `<a>` link styled as a button for navigation or a CTA.

Every image needs meaningful `alt` text written as a usable image generation prompt: name subject, setting, composition, lighting, palette or grade, and framing. Keep readable words and brand names out of generated images; render them as HTML text.

## Responsive CSS contract

- Do not stagger a row of siblings (different top margins, translateY offsets, or nth-child even/odd vertical offsets) unless SITE SPEC is clearly a photography or gallery site (photographer, photography, photojournalism, or a gallery). Keep card and image rows level for every other site.
- Write mobile-first responsive CSS, then add min-width media queries only where composition needs them.
- Use fluid type with `clamp()` for display and section-heading scales while keeping body text readable.
- Headings and paragraphs keep `text-wrap: pretty` as a browser best-effort hint that reduces dangling final words. It cannot guarantee a particular final line at every font, width, or browser. Never set `text-wrap: wrap` or `text-wrap: nowrap` on headings or paragraphs.
- Use flexible grid and flex layouts, bounded content widths, responsive spacing, and images that cannot overflow.
- Ensure navigation, tables, long words, and multi-column layouts remain usable on narrow screens.
- Preserve clear focus states, readable contrast, and reduced-motion behavior for any CSS transitions.

Return only the finished optional `<style data-page-css>` followed by `<main>` fragment.
