## Images

When a generated unit needs content imagery (covers, feature/gallery/card images), emit a generatable AI image placeholder. Follow these rules exactly.

Use ONLY the native `src` and `alt` attributes on `img` elements. Do NOT use any custom data attributes.

- **src**: The image path using the `theme:./assets/` prefix followed by the filename. The filename must only contain lowercase letters (a-z), numbers (0-9), and hyphens (-) — no spaces or special characters — and must be descriptive of the image. ALWAYS use the `.jpg` extension — every generated image is an opaque content image; never use `.png` (see "No decorative or transparent images" below). Give every image a UNIQUE filename. Example: `theme:./assets/hero-mountain-dawn.jpg`

- **alt**: A structured string containing all image generation parameters, e.g. `AI_IMAGE: A misty mountain range at dawn seen from a low valley vantage, the peaks off-center to the right with a calm low-detail sky on the left | wide destination feature with the left side kept as open, low-detail negative space | photorealistic | landscape`

### Alt Attribute Format

The alt attribute must follow this exact format:

```
AI_IMAGE: subject | page-context | style | aspect-ratio
```

**Format breakdown:**
- `AI_IMAGE:` — Required prefix marker (exactly as written)
- `|` — Pipe character used as the separator between values
- `subject` — What the image shows and from what point of view (see subject guidelines below). This is the actual thing to render.
- `page-context` — Where and how the image is used on the page. This is NOT part of what gets drawn; it only helps the generator pick a fitting subject, mood and composition. Examples: `wide feature band with a quiet low-detail area kept clear on top`, `portfolio item card in a 3-column gallery`, `menu item thumbnail`, `team member headshot in a row of bios`, `background of a call-to-action band`.
- `style` — One of the style options below
- `aspect-ratio` — One of: `square`, `landscape`, `ultrawide`, `portrait`, `card-landscape`, `card-portrait`

The filename is extracted from the `src` attribute automatically. Keep `subject` and `page-context` as two distinct fields — do not fold the placement into the subject.

**Aspect ratio options:**
- `square`: 1:1 ratio — only when the layout slot is genuinely 1:1
- `landscape`: 16:9 ratio — the default for hero and banner images and for wide feature/gallery rows
- `ultrawide`: 21:9 ratio — ONLY for full-bleed hero/cover/banner backgrounds that span the viewport edge to edge; matches the wide desktop banner shape so less of the composition is cropped away. Never use it for contained images, cards, or columns.
- `portrait`: 9:16 ratio — dramatic tall images: a full-height editorial shot, a tall side-by-side hero panel
- `card-landscape`: 4:3 ratio — contained landscape slots: product cards, blog thumbnails, feature images in columns
- `card-portrait`: 3:4 ratio — the natural portrait-card shape: team headshots, tall product cards, framed insets. Prefer this over `portrait` for anything rendered as a card or inside a column — 9:16 is usually too tall for those slots.

When the DESIGN DIRECTION includes an **Image crop** fact, make the structured aspect-ratio agree with it: `landscape` uses `card-landscape` for contained cards and `landscape` for feature media; `portrait` uses `card-portrait`; `square` uses `square`; `panoramic` uses `landscape` for contained cards and `ultrawide` for feature bands; `mixed` keeps the role-specific choices above. A full-bleed hero/background remains `landscape` or `ultrawide` under every site-wide crop, because a viewport banner cannot use a vertical source safely.

A full-bleed hero/cover BACKGROUND image MUST be `landscape` or `ultrawide` — never `square`, `portrait`, or a card ratio — so it fills the wide banner without being cropped. This applies only to the background: a `framed` or foreground image inside the hero (e.g. a portrait shot in a contained frame, or a second image layered over the background) picks whatever aspect ratio fits its own slot. Generally, match each image's aspect ratio to the shape of the slot it fills so it is not cropped toward an unintended shape.

**Grid and row consistency:**
When creating multiple images that will be displayed together in a row or grid (e.g. team members, product cards, blog post thumbnails, gallery items), ALL images in that group MUST use the same aspect ratio and orientation. This ensures visual alignment and a cohesive layout. For example, if you have three cards in a row, all three images should be `card-landscape`, `card-portrait`, or `square` — never a mix.

**Style options:**
- `photorealistic` — Photographic, realistic images
- `digital-art` — Modern digital artwork
- `illustration` — Hand-drawn style illustrations
- `minimalist` — Clean, simple, minimal design
- `flat-design` — Flat, modern UI design style
- `3d-render` — 3D rendered appearance
- `abstract` — Abstract artistic style
- `watercolor` — Watercolor painting style

**No decorative or transparent images:**
Generated imagery is for CONTENT — covers, feature/gallery/card images, photographic bands. Never emit a decorative image: no drawn ornaments, flourishes, motif marks, sprigs, crests, rosettes, stamps, emblems, icons, or tick/rule strips as AI imagery, and never a `.png` or any "transparent background" asset. Generated ornaments come out off-palette (the model cannot match the theme's hexes) and geometrically wobbly, and small raster icons turn to mush at display size — a mismatched ornament reads as a stain on the design, far worse than no ornament at all.

Decoration, when a section needs any at all, comes from theme primitives — they inherit the palette exactly and stay crisp at any size:
- Rules, hairlines, underlines, tick strips and dividers: `wp:separator`, border styles, or spacing — never imagery.
- Never add decorative glyph characters — no marks before headings or eyebrows, no glyph list bullets or metadata separators; whitespace, type scale and color already carry the hierarchy.
- Feature icons: use none — let type and layout carry the hierarchy; never an AI-generated icon image.

**Subject guidelines:**
- 1-3 specific sentences describing ONLY the image itself: what it shows and from what point of view (composition, framing, vantage, mood). This is the actual generation subject — do not put the page placement here, that goes in `page-context`.
- NEVER ask the image to render text. No words, names, letters, numerals, wordmarks, monograms, mottos, signage copy, labels, or "calligraphy/hand-lettering of <words>" — in any language or script. Image models garble glyphs and invent fake scripts, and raster text can't be read by assistive tech, translated, or restyled. Everything meant to be read is real HTML typography styled by the theme. If a plan or design note asks for lettered imagery (a hand-lettered name, a calligraphic line), express it as styled heading/paragraph text instead and keep imagery purely pictorial. Prefer scenes whose focal subject carries no lettering at all: the image model completes any prominent sign, storefront fascia, menu board, screen, placard or label with garbled fake text — at worst a wrong brand name painted over the site's own storefront. When a text-bearing surface is unavoidable in the scene, the subject must describe it as bare — clear glass, an unmarked awning, a blank board — or keep it cropped by the frame, far in the background, or softly out of focus. Never write words for lettering or signage into the subject, even to negate or aim them away: naming lettering plants it, and "lettering turned away" comes back as mirrored glyphs.
- Describe content and composition, NOT photographic grade or style treatment. A single site-wide grade (color vs black-and-white, film grain, light quality, color grading) is applied to every image automatically at generation time — do not restate or contradict it in the subject (no "black and white", "golden hour color", "muted grey tones", "35mm grain" and the like). Per-image grading would make adjacent images clash.
- Make sibling images in the same section describe their distinct subject so they don't read alike.
- For cover backgrounds with overlaid copy, keep the focal subject off-center with calm, low-detail areas so the overlaid HTML text stays legible.
- Match the reservation to the scene's natural axis. A left or right reservation needs a subject whose weight is naturally lateral: a figure standing to one side, a wall or doorway, a shoreline or horizon with a clear open side. A raised-vantage crowd, a receding avenue, a valley or a big sky is organized top to bottom; asked to push it sideways, the image model turns the scene 90° inside the canvas. For such a scene keep the copy reservation at the top or bottom instead. Good: `A lone figure at the right edge of a long concrete pier, the left two-thirds open water and haze` with the left reserved. Bad: `A dense crowd on a wide avenue seen from above, the mass of figures weighted to the right` with the left reserved — reserve the upper third and let the crowd fill the lower half.
- State the vantage so that up is up: name the horizon, the sky, the ground or the ceiling when the scene has one. The prompt adds an upright-view anchor automatically; a subject that wants a deliberately tilted or rotated view must say so explicitly.

**Page-context guidelines:**
- A short phrase naming where and how the image is used (e.g. `wide feature band in a portfolio grid`, `portfolio item card in a 3-column gallery`, `menu item thumbnail`). The generator uses this to fit the image to its slot — it is not drawn into the image.
- Write this machine-guidance field in English even when the site's visitor-facing copy uses another language. It is normalized through a fixed pictorial vocabulary before image generation; it is not visible site copy.
- Treat the structured `aspectRatio` field as authoritative for canvas orientation. Page-context prose may describe placement but must not contradict that field.
- Describe copy-overlay placement as reserved empty space in photographic terms, never as text: write `full-frame editorial photograph with the left third kept as open, low-detail negative space` — NOT `hero with the headline and subtitle overlaid on the left`. Naming a headline, subtitle, caption or menu in the page-context is the audited trigger for the model painting ghost text and fake UI into that exact region of the image — and so is design-comp vocabulary like `hero cover background`: a typography-capable image model reads a design brief as an invitation to typeset the missing title block, so prefer photographic slot language (`editorial photograph`, `full-frame backdrop`) over web-layout language (`hero`, `banner`, `cover background`).

**Cover backgrounds:**
For `wp:cover` backgrounds, set the same `theme:./assets/<name>.jpg` path on BOTH the block's `url` attribute and the inner `<img>` src, and put the `AI_IMAGE` spec in that img's alt. The `url` and `src` are asset PATHS only — never write the `AI_IMAGE:` spec into a `url` or `src`; it belongs solely in the `alt`. A cover whose `url` is an `AI_IMAGE:` string ships the raw prompt text as the image and renders no picture.
- NO CAPTIONS — never write a `<figcaption>` on a `wp:image`, and never set a `caption` attribute on one. A caption under a card image or a standalone image reads as clutter; the orienting detail belongs in the surrounding copy, or nowhere. Images inside a `wp:gallery` MAY carry a caption where the collection genuinely needs one, but a gallery does not need captions and is complete without them — never add one just because the block allows it. A deterministic finish pass removes captions from images outside a gallery.

### Example Image Block

```html
<!-- wp:image {"sizeSlug":"large"} -->
<figure><img src="theme:./assets/loaf-sourdough.jpg" alt="AI_IMAGE: A rustic sourdough loaf with a crackled golden crust on a floured wooden board, warm side light, shot slightly from above | menu item card in the bakery's signature loaves section | photorealistic | square"/></figure>
<!-- /wp:image -->
```

### Example: Complete Hero Section

```html
<!-- wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0"}}},"layout":{"type":"constrained"}} -->
<div>
    <!-- wp:cover {"url":"theme:./assets/hero-mountain-dawn.jpg","dimRatio":50,"align":"full","minHeight":80,"minHeightUnit":"vh"} -->
    <div>
        <img alt="AI_IMAGE: A misty mountain range at dawn seen from a low valley vantage, the peaks off-center to the right with a calm low-detail sky on the left | full-bleed hero section with the headline overlaid on top | photorealistic | landscape" src="theme:./assets/hero-mountain-dawn.jpg"/>
        <div>
            <!-- wp:heading {"level":1,"style":{"typography":{"textAlign":"center"}},"textColor":"base","fontFamily":"heading","fontSize":"display"} -->
            <h1>Into the High Country</h1>
            <!-- /wp:heading -->
            <!-- wp:paragraph {"style":{"typography":{"textAlign":"center"}},"textColor":"base","fontSize":"lead"} -->
            <p>Guided treks through the alpine wilderness.</p>
            <!-- /wp:paragraph -->
        </div>
    </div>
    <!-- /wp:cover -->
</div>
<!-- /wp:group -->
```
