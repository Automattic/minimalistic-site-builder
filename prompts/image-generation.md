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
- `square`: 1:1 ratio (1024x1024) — only when the layout slot is genuinely 1:1
- `landscape`: 16:9 ratio (1792x1024) — usually for hero and banner images; also the default for wide feature/gallery rows
- `portrait`: 9:16 ratio (1024x1792) — usually for tall images

A full-bleed hero/cover BACKGROUND image MUST be `landscape` — never `square` or `portrait` — so it fills the wide banner without being cropped. This applies only to the background: a `framed` or foreground image inside the hero (e.g. a portrait shot in a contained frame, or a second image layered over the background) picks whatever aspect ratio fits its own slot. Generally, match each image's aspect ratio to the shape of the slot it fills so it is not cropped toward an unintended shape.

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

### Example: Complete Hero Section

```html
<!-- wp:cover {"url":"theme:./assets/hero-mountain-dawn.jpg","dimRatio":50,"align":"full","minHeight":80,"minHeightUnit":"vh"} -->
<div class="wp-block-cover alignfull" style="min-height:80vh">
    <span aria-hidden="true" class="wp-block-cover__background has-background-dim-50 has-background-dim"></span>
    <img class="wp-block-cover__image-background" alt="AI_IMAGE: A misty mountain range at dawn seen from a low valley vantage, the peaks off-center to the right with a calm low-detail sky on the left | full-bleed hero section with the headline overlaid on top | photorealistic | landscape" src="theme:./assets/hero-mountain-dawn.jpg"/>
    <div class="wp-block-cover__inner-container">
        <!-- wp:heading {"textAlign":"center","textColor":"background","style":{"typography":{"fontSize":"clamp(2.5rem, 5vw, 3.5rem)"}}} -->
        <h2 class="wp-block-heading has-text-align-center has-background-color has-text-color">Into the High Country</h2>
        <!-- /wp:heading -->
        <!-- wp:paragraph {"align":"center","textColor":"background"} -->
        <p class="has-text-align-center has-background-color has-text-color">Guided treks through the alpine wilderness.</p>
        <!-- /wp:paragraph -->
    </div>
</div>
<!-- /wp:cover -->
```
