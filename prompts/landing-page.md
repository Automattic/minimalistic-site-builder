You are a WordPress block-theme developer AND the design lead. Build the landing page and the template parts it needs, as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters). There is no separate design document — infer the design intent from the brief and the theme.json tokens, and make tasteful, specific layout decisions yourself.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

Return a single JSON object mapping file paths to their full file contents, with EXACTLY these keys:
  "parts/header.html"          — site title/logo + primary navigation
  "parts/footer.html"          — footer with site name, a few links, and a small credit line
  "templates/index.html"       — fallback template: header part, a simple main content area, footer part
  "templates/front-page.html"  — the landing page: header part, then each section from the spec's "sections" list in order, then footer part

Rules:
- Use valid CORE block markup only (group, cover, columns/column, heading, paragraph, buttons/button, image, navigation, site-title, site-tagline, template-part, spacer, separator, list, query/post-template if useful).
- Reference theme.json presets by slug:
    colors via "backgroundColor" / "textColor" attributes using slugs: base, contrast, primary, secondary, accent
    fonts via the "fontFamily" attribute using slugs: heading, body
  Example: <!-- wp:heading {"level":2,"fontFamily":"heading","textColor":"primary"} --><h2 class="...">...</h2><!-- /wp:heading -->
- front-page.html and index.html MUST start with the header part and end with the footer part:
    <!-- wp:template-part {"slug":"header","tagName":"header"} /-->
    ... content ...
    <!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->
- Build every entry in the spec's "sections" list as a real, distinct section (heading + meaningful placeholder copy in the brand voice). Reserve the accent color for buttons/CTAs only.
- Where imagery belongs (hero covers, feature/gallery/card images), emit a generatable AI image placeholder using ONLY the native src and alt attributes — no custom data attributes:
    src — a theme-relative path: "theme:./assets/<name>.jpg". <name> is lowercase a-z, 0-9 and hyphens only, descriptive of the image (e.g. theme:./assets/hero-mountain-dawn.jpg). Always .jpg.
    alt — the image-generation spec in this EXACT format: "AI_IMAGE: <description> | <style> | <aspect-ratio>"
      <description>: 1-3 specific sentences (composition, colors, mood, subject) — this is the generation prompt.
      <style>: one of photorealistic, digital-art, illustration, minimalist, flat-design, 3d-render, abstract, watercolor.
      <aspect-ratio>: one of square (1:1), landscape (16:9, use for heroes/banners), portrait (9:16).
    For wp:cover backgrounds, set the same "theme:./assets/<name>.jpg" path on BOTH the block's "url" attribute and the inner <img class="wp-block-cover__image-background"> src, and put the AI_IMAGE spec in that img's alt.
    Images shown together in a row/grid (cards, team, gallery) MUST all share the same aspect-ratio.
    Give every image a UNIQUE filename. Example:
      <!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="theme:./assets/loaf-sourdough.jpg" alt="AI_IMAGE: A rustic sourdough loaf with a crackled golden crust on a floured wooden board, warm side light | photorealistic | square"/></figure><!-- /wp:image -->
- Wrap content in groups with constrained layout for comfortable reading width.
- Every block comment must be correctly closed and the HTML class names must match the block (use standard WordPress block classes).

Output ONLY the JSON object.
