You are a senior web designer and front-end author. Design the finished homepage content below the fold and its site footer.

## Site spec

{{site_spec}}

## Front-page spec

{{page_spec}}

## Site pages

SITE PAGES (the whole site — shared footer navigation uses these exact paths, except the front page: do NOT put a Home item in the footer nav; the site name already links home):
{{site_pages}}

## Established site CSS

{{site_css}}

## Design preview

{{design_preview}}

Treat the cached site CSS and design preview as binding design authority. Continue the preview's composition, typography, palette, spacing rhythm, and visual language. Write specific visitor-facing copy from the site spec and front-page spec. Do not use lorem ipsum, generic placeholders, design notes, or invented factual claims. Never invent an email, street address, phone number, or URL.

LANGUAGE: write ALL visitor-facing copy — headings, body text, captions, list items, labels, button text, image alt text, and the footer's own link and credit lines — in {{language}}. Do NOT mix languages; the only exceptions are proper nouns and the spec's verbatim identity values.

## Fragment contract

Return one `<main>` fragment for content below the fold followed immediately by one `<footer>` for the site, and nothing else. Return only the fragment: no preamble, commentary, explanation, or prose before or after it, and no Markdown fences.

- Do not emit a <header>.
- Do not repeat the hero from the design preview. Start with the first section below the fold.
- Do not emit `<!doctype>`, `<html>`, `<head>`, or `<body>`.
- Include exactly one `<main>` and exactly one `<footer>`.
- Return a bare <main> with no attributes. Put all classes and IDs on its child sections.
- Do not add a second `h1`; the design preview owns the homepage heading.
- Prefer established site classes from the site CSS and minimize new page-specific classes. Do not emit a `<style>` element.
- Inside `<main>`, when a button or link leads to another page of THIS site, use that page's path from SITE PAGES verbatim (e.g. `href="/menu/"`) — never a path that isn't in the list. A deep link to another page includes that owning page's path (e.g. `href="/page/#anchor"`). A bare `href="#anchor"` is valid only when that exact section ID exists in the homepage `<main>`. Do not link the page to itself. An external link uses an exact URL supplied by the SITE SPEC; when none was supplied, omit the link or render its label as plain text. NEVER emit `href="#"`.
- The footer renders on EVERY page, so each link must resolve everywhere: page links use the SITE PAGES paths verbatim except the front page (the site name is the home link — do NOT put a Home item in footer `<nav>`). A link to a homepage section is root-relative — `href="/#anchor"`, NEVER a bare `href="#anchor"`, which is dead on every page except the homepage itself. No `href="#"` placeholders. External links use only an exact URL present in the SITE SPEC; otherwise omit the link or render its label as plain text.

## Supported HTML slice

Use only headings, paragraphs, lists, block quotes, code, tables, images, buttons, links, and semantic or presentational wrappers such as `main`, `section`, `nav`, `article`, `aside`, `div`, `span`, and `footer`.

There are no forms or form controls, no SVG, no custom elements, and no JavaScript. Do not emit `<form>`, `<input>`, `<textarea>`, `<select>`, `<button>` with scripted behavior, `<svg>`, `<script>`, inline event handlers, or `javascript:` URLs. Express layout, ornament, and interaction states with HTML and CSS only. A shop is a catalog storefront: no cart, checkout, quantity input, add-to-cart control, price-per-unit purchase flow, or WooCommerce block — product cards that invite a contact enquiry are the whole store.

Use `<button>` only for a real non-scripted action. Prefer an `<a>` link styled as a button for navigation or a CTA.

Every image needs meaningful `alt` text written as a usable image generation prompt: name subject, setting, composition, lighting, palette or grade, and framing. Keep readable words and brand names out of generated images; render them as HTML text.
- Never put a `<figcaption>` on an image. Captions belong only to a genuine gallery of photographs, and even there they are optional — the surrounding copy carries the detail.

## Responsive contract

- Do not stagger a row of siblings (different top margins, translateY offsets, or nth-child even/odd vertical offsets) unless SITE SPEC is clearly a photography or gallery site (photographer, photography, photojournalism, or a gallery). Keep card and image rows level for every other site.
- Follow the mobile-first behavior established by the site CSS.
- Reuse its grid, flex, bounded content widths, responsive spacing, focus states, readable contrast, and reduced-motion behavior.
- Keep images, navigation, tables, long words, and multi-column layouts usable on narrow screens.

Return only the finished bare <main> with no attributes followed by `<footer>`.
