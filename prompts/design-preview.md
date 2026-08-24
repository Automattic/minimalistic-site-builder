You are a senior web designer and front-end author. Create one static, self-contained first-fold design preview from the brief, factual site spec, and committed design direction below.

## Brief

{{brief}}

## Site spec

{{site_spec}}

## Site pages

SITE PAGES (the whole site — the shared header navigation uses these exact paths):
{{site_pages}}

## Committed design direction

{{design_direction}}

Treat design direction as binding. Write specific, finished visitor-facing copy grounded in brief and site spec. Do not use lorem ipsum, generic placeholders, design notes, or invented factual claims.

## First-fold document contract

Return one complete HTML document and nothing else. Do not wrap it in Markdown fences or add commentary.

- Include `<!doctype html>`, one `<html>`, one `<head>`, and one `<body>`.
- Body must contain exactly two direct elements in this order: one `<header>`, then one `<main>`. Do not place visitor-facing text directly in body.
- `<header>` must contain exactly one `<nav>`. Use text or CSS for identity; do not add a logo image. The identity text is the home link — do NOT put a Home item (or the front page's title) in `<nav>`. Nav lists SITE PAGES except the front page.
- HEADER LAYOUT (mandatory): one horizontal bar. Identity on the inline-start side, `<nav>` on the inline-end side. CSS for `header` MUST be `display:flex; flex-direction:row; flex-wrap:nowrap; align-items:center; justify-content:space-between;` — NEVER `flex-direction:column`, NEVER `flex-wrap:wrap`, NEVER a grid that puts identity on its own row, NEVER nav links on both sides of the identity, NEVER identity stacked above the nav.
- HEADER NAV COMPLETENESS (mandatory when SITE PAGES has any page besides the front page): `<nav>` MUST contain exactly one `<a>` for every SITE PAGES entry except the front page. Use that page's title as the link text and that page's path as `href` verbatim. Do not omit a page. Do not invent a page that is not in SITE PAGES. An empty `<nav>` is forbidden when that inner-page list is non-empty; for a one-page site, emit the required empty `<nav>`. Do not replace page links with a single CTA.
- Do not author a hamburger-only header. Desktop navigation is a row of links. There is no JavaScript; a later transform maps `<nav>` to core/navigation, which supplies exactly one mobile hamburger. Do not emit a menu-toggle button, and do not hide the nav links at desktop widths.
- The header renders on EVERY page, so each link must resolve everywhere: page links use the SITE PAGES paths verbatim, and a link to a homepage section is root-relative — `href="/#anchor"`, NEVER a bare `href="#anchor"`, which is dead on every page except the homepage itself. No `href="#"` placeholders. External links use only an exact URL present in the SITE SPEC; otherwise omit the link or render its label as plain text.
- `<main>` must contain exactly one direct element: `<section id="hero">`. Do not place visitor-facing text directly in main.
- Hero is whole page body and whole first fold. Emit no content sections, feature grids, testimonials, galleries, pricing, articles, calls-to-action below hero, or other body siblings.
- Do not emit a <footer>.

## Image contract

Emit exactly one `<img>` in whole document, inside `<section id="hero">`. Omit `src`; later image processing supplies local asset path. Alt must follow exact four-field convention, including spaces around pipe separators:

`AI_IMAGE: subject | page-context | style | aspect-ratio`

- `subject`: specific scene, subject, setting, composition, lighting, palette or grade, and framing. Never request readable text, names, letters, numerals, logos, or signage.
- `page-context`: describe image role in homepage hero.
- `style`: exactly one of `photorealistic`, `digital-art`, `illustration`, `minimalist`, `flat-design`, `3d-render`, `abstract`, `watercolor`.
- `aspect-ratio`: exactly one of `square`, `landscape`, `portrait`; prefer `landscape` for wide hero treatment.

## CSS and dependency contract

- Put all CSS in exactly one inline `<style>` inside `<head>`. Do not use style elements elsewhere.
- Define these exact WordPress-layout width variables: `--content-size: 800px;` and `--wide-size: 1280px;`.
- Use mobile-first responsive CSS, fluid typography, bounded widths, accessible contrast, visible focus states, and images that cannot overflow.
- Use system font stacks only. Do not emit `@font-face`, `@import`, or any `url()` in CSS.
- Do not load external stylesheets, fonts, scripts, images, CDN resources, or other dependencies. Do not emit `<link>` or `<iframe>`.
- No JavaScript. Do not emit `<script>`, inline event-handler attributes, `javascript:` URLs, or behavior-bearing markup.
- Never place a `device--hairline-rule`, `device--section-numeral`, or `device--stamp` class in this
  document. This is the hero and fold, and the DESIGN DIRECTION's device belongs on ONE later
  non-hero band; the build strips it from the hero.
- No forms or form controls. A shop is a catalog storefront: no cart, checkout, quantity input,
  add-to-cart control, or purchase flow — product cards that invite a contact enquiry are the
  whole store.
- No HTML comments. No CSS comments.

Return only finished HTML document.
