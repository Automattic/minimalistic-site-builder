## Images

When a section needs imagery (hero covers, feature/gallery/card images), emit a generatable AI image placeholder. Follow these rules exactly.

Use ONLY the native `src` and `alt` attributes on `img` elements. Do NOT use any custom data attributes.

- **src**: The image path using the `theme:./assets/` prefix followed by the filename. The filename must only contain lowercase letters (a-z), numbers (0-9), and hyphens (-) — no spaces or special characters — and must be descriptive of the image. Always use the `.jpg` extension. Give every image a UNIQUE filename. Example: `theme:./assets/hero-mountain-dawn.jpg`

- **alt**: A structured string containing all image generation parameters, e.g. `AI_IMAGE: A misty mountain range at dawn seen from a low valley vantage, the peaks off-center to the right with a calm low-detail sky on the left | full-bleed hero section with the headline overlaid on top | photorealistic | landscape`

### Alt Attribute Format

The alt attribute must follow this exact format:

```
AI_IMAGE: subject | page-context | style | aspect-ratio
```

**Format breakdown:**
- `AI_IMAGE:` — Required prefix marker (exactly as written)
- `|` — Pipe character used as the separator between values
- `subject` — What the image shows and from what point of view (see subject guidelines below). This is the actual thing to render.
- `page-context` — Where and how the image is used on the page. This is NOT part of what gets drawn; it only helps the generator pick a fitting subject, mood and composition. Examples: `full-bleed hero section with text overlaid on top`, `portfolio item card in a 3-column gallery`, `menu item thumbnail`, `team member headshot in a row of bios`, `background of a call-to-action band`.
- `style` — One of the style options below
- `aspect-ratio` — One of: `square`, `landscape`, `portrait`

The filename is extracted from the `src` attribute automatically. Keep `subject` and `page-context` as two distinct fields — do not fold the placement into the subject.

**Aspect ratio options:**
- `square`: 1:1 ratio (1024x1024)
- `landscape`: 16:9 ratio (1792x1024) — use for hero images, banners
- `portrait`: 9:16 ratio (1024x1792) — use for tall images

**Grid and row consistency:**
When creating multiple images that will be displayed together in a row or grid (e.g. team members, product cards, blog post thumbnails, gallery items), ALL images in that group MUST use the same aspect ratio and orientation. This ensures visual alignment and a cohesive layout. For example, if you have three cards in a row, all three images should be `landscape`, `portrait`, or `square` — never a mix.

**Style options:**
- `photorealistic` — Photographic, realistic images
- `digital-art` — Modern digital artwork
- `illustration` — Hand-drawn style illustrations
- `minimalist` — Clean, simple, minimal design
- `flat-design` — Flat, modern UI design style
- `3d-render` — 3D rendered appearance
- `abstract` — Abstract artistic style
- `watercolor` — Watercolor painting style

**Subject guidelines:**
- 1-3 specific sentences describing ONLY the image itself: what it shows and from what point of view (composition, framing, vantage, colors, mood, lighting). This is the actual generation subject — do not put the page placement here, that goes in `page-context`.
- Make sibling images in the same section describe their distinct subject so they don't read alike.
- For cover/hero backgrounds, keep the focal subject off-center with calm, low-detail areas so the overlaid text (described in `page-context`) stays legible.

**Page-context guidelines:**
- A short phrase naming where and how the image is used: the section and its role (e.g. `full-bleed hero section with the headline overlaid on top`, `portfolio item card in a 3-column gallery`, `menu item thumbnail`). The generator uses this to fit the image to its slot — it is not drawn into the image.

**Cover backgrounds:**
For `wp:cover` backgrounds, set the same `theme:./assets/<name>.jpg` path on BOTH the block's `url` attribute and the inner `<img class="wp-block-cover__image-background">` src, and put the `AI_IMAGE` spec in that img's alt.

### Example Image Block

```html
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="theme:./assets/loaf-sourdough.jpg" alt="AI_IMAGE: A rustic sourdough loaf with a crackled golden crust on a floured wooden board, warm side light, shot slightly from above | menu item card in the bakery's signature loaves section | photorealistic | square"/></figure>
<!-- /wp:image -->
```

### Example Hero / Section Compositions

The compositions below are a menu of equally valid approaches, NOT a template to copy. **Do NOT default to the first (full-bleed overlay) one** — pick the composition that best fits THIS site's design direction, archetype, and the section's purpose, and vary it from build to build. A hero can be a full-bleed photo, an image set beside text, an object on flat color, or a mostly-typographic statement with a quiet supporting image. Adapt the copy, colors, heights, and alignment to the brand; keep the block grammar.

**A. Full-bleed cover, text overlaid (one option — not the default).** Best for immersive, photographic, atmospheric brands. Keep the focal subject off-center with a calm area behind the text.

```html
<!-- wp:cover {"url":"theme:./assets/hero-coastline-dusk.jpg","dimRatio":50,"align":"full","minHeight":80,"minHeightUnit":"vh"} -->
<div class="wp-block-cover alignfull" style="min-height:80vh">
    <span aria-hidden="true" class="wp-block-cover__background has-background-dim-50 has-background-dim"></span>
    <img class="wp-block-cover__image-background" alt="AI_IMAGE: A windswept coastline at dusk seen from a low headland, the rocky point off-center to the right with a calm gradient sky filling the left | full-bleed hero section with the headline overlaid on top | photorealistic | landscape" src="theme:./assets/hero-coastline-dusk.jpg"/>
    <div class="wp-block-cover__inner-container">
        <!-- wp:heading {"textAlign":"center","textColor":"background","style":{"typography":{"fontSize":"clamp(2.5rem, 5vw, 3.5rem)"}}} -->
        <h2 class="wp-block-heading has-text-align-center has-background-color has-text-color">Where the Land Meets the Sea</h2>
        <!-- /wp:heading -->
        <!-- wp:paragraph {"align":"center","textColor":"background"} -->
        <p class="has-text-align-center has-background-color has-text-color">Field dispatches from the edges of the map.</p>
        <!-- /wp:paragraph -->
    </div>
</div>
<!-- /wp:cover -->
```

**B. Split-screen — image beside text, no overlay.** Best for editorial, product, and portfolio brands that want the headline to read crisply against a solid background. Image lives in one column, copy in the other.

```html
<!-- wp:columns {"align":"full","verticalAlignment":"center"} -->
<div class="wp-block-columns alignfull are-vertically-aligned-center">
    <!-- wp:column {"verticalAlignment":"center","width":"50%"} -->
    <div class="wp-block-column is-vertically-aligned-center" style="flex-basis:50%">
        <!-- wp:heading {"fontFamily":"heading","textColor":"primary","style":{"typography":{"fontSize":"clamp(2.25rem, 4vw, 3.25rem)"}}} -->
        <h2 class="wp-block-heading has-primary-color has-text-color has-heading-font-family">Twenty Years on the Front Line</h2>
        <!-- /wp:heading -->
        <!-- wp:paragraph {"textColor":"secondary"} -->
        <p class="has-secondary-color has-text-color">A photographic record of a country in motion.</p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->
    <!-- wp:column {"width":"50%"} -->
    <div class="wp-block-column" style="flex-basis:50%">
        <!-- wp:image {"sizeSlug":"large"} -->
        <figure class="wp-block-image size-large"><img src="theme:./assets/hero-portrait-protest.jpg" alt="AI_IMAGE: A single demonstrator silhouetted against a smoke-filled avenue at golden hour, framed tight from a low angle, the figure to the right with open space at the left | hero section image set beside the headline in a two-column split | photorealistic | portrait"/></figure>
        <!-- /wp:image -->
    </div>
    <!-- /wp:column -->
</div>
<!-- /wp:columns -->
```

**C. Object / subject on flat color — no photograph backdrop.** Best for minimalist, brand-forward, or product sites. A single image sits inside a solid color group; the color does the heavy lifting, not a dim overlay.

```html
<!-- wp:group {"align":"full","backgroundColor":"primary","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|xxl","bottom":"var:preset|spacing|xxl"}}}} -->
<div class="wp-block-group alignfull has-primary-background-color has-background" style="padding-top:var(--wp--preset--spacing--xxl);padding-bottom:var(--wp--preset--spacing--xxl)">
    <!-- wp:heading {"textAlign":"center","textColor":"base","fontFamily":"heading","style":{"typography":{"fontSize":"clamp(2.5rem, 5vw, 4rem)"}}} -->
    <h2 class="wp-block-heading has-text-align-center has-base-color has-text-color has-heading-font-family">The Archive</h2>
    <!-- /wp:heading -->
    <!-- wp:image {"sizeSlug":"large","align":"center"} -->
    <figure class="wp-block-image aligncenter size-large"><img src="theme:./assets/hero-camera-still.jpg" alt="AI_IMAGE: A weathered rangefinder film camera resting on a plain surface, centered with soft even studio light and a shallow shadow, lots of empty space around it | hero object centered on a flat brand-color band | photorealistic | landscape"/></figure>
    <!-- /wp:image -->
</div>
<!-- /wp:group -->
```

**D. Typographic statement, quiet supporting image.** Best when the words carry the brand. A bold type lockup leads; a small, calm image (or none) supports it underneath rather than behind it.

```html
<!-- wp:group {"align":"full","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|xxl","bottom":"var:preset|spacing|xl"}}}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--xxl);padding-bottom:var(--wp--preset--spacing--xl)">
    <!-- wp:heading {"level":1,"fontFamily":"heading","textColor":"contrast","style":{"typography":{"fontSize":"clamp(3rem, 8vw, 6rem)","lineHeight":"1.02"}}} -->
    <h1 class="wp-block-heading has-contrast-color has-text-color has-heading-font-family" style="font-size:clamp(3rem, 8vw, 6rem);line-height:1.02">Photographs from the<br>edge of the record.</h1>
    <!-- /wp:heading -->
    <!-- wp:image {"sizeSlug":"large","align":"wide"} -->
    <figure class="wp-block-image alignwide size-large"><img src="theme:./assets/hero-contact-sheet.jpg" alt="AI_IMAGE: A single horizontal strip of black-and-white 35mm contact-sheet frames laid on a neutral surface, shot flat from directly above, calm and evenly lit | quiet supporting image beneath a large typographic hero headline | photorealistic | landscape"/></figure>
    <!-- /wp:image -->
</div>
<!-- /wp:group -->
```
