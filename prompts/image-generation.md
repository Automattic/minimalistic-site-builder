## Images

When a generated unit needs content imagery (covers, feature/gallery/card images), emit a generatable AI image placeholder. Follow these rules exactly.

Use ONLY the native `src` and `alt` attributes on `img` elements. Do NOT use any custom data attributes.

- **src**: The image path using the `theme:./assets/` prefix followed by the filename. The filename must only contain lowercase letters (a-z), numbers (0-9), and hyphens (-) — no spaces or special characters — and must be descriptive of the image. Use `.jpg` for these opaque content-image slots, including illustrations; do not request a transparent `.png` asset through this content-image contract. Give every image a UNIQUE filename.

- **alt**: A structured string containing all image generation parameters, in the exact `AI_IMAGE: subject | page-context | style | aspect-ratio` form described below

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

**Style options:** Use the **Image kind** keyword for content. Use `photorealistic` for portraits and `flat-design` for logos. The build adds render instructions; describe only the subject and composition.
- `photorealistic` — Photographic, realistic images
- `digital-art` — Modern digital artwork
- `illustration` — Hand-drawn style illustrations
- `minimalist` — Clean, simple, minimal design
- `ui-screenshot` — Application screen only; no frame, title bar, browser, device, or desk
- `flat-design` — Flat, modern UI design style
- `3d-render` — 3D rendered appearance
- `abstract` — Abstract artistic style
- `watercolor` — Watercolor painting style

**Image choice and visual direction:**
Use the **Style signature** to choose the subject and composition, not just to restate a style name. Photography, figurative illustration, hand-drawn filigree, painterly artwork, crests and other pictorial treatments can all be generated when they suit the concept. Choose meaningful images and a scale at which their detail reads. Do not replace every image with themed props or add the same decorative mark to every section. The image must still belong to the site's subject and this section's purpose.

The shared `image_grade` supplies medium, light, color treatment and texture. Choose the supported style option that agrees with it; do not impose photography on an illustrated direction. Exact palette matching and crisp detail at tiny sizes are quality checks, not grounds to declare whole kinds of artwork impossible. Functional separators, simple borders and controls should remain native blocks/HTML; generated artwork belongs in image slots, not repeated UI decorations. These slots deliver opaque JPEGs, so compose a complete background rather than requesting transparency.

**Subject guidelines:**
- 1-3 specific sentences describing ONLY the image itself: what it shows and from what point of view (composition, framing, vantage, mood). This is the actual generation subject — do not put the page placement here, that goes in `page-context`.
- NEVER ask the image to render text. No words, names, letters, numerals, wordmarks, monograms, mottos, signage copy, labels, or "calligraphy/hand-lettering of <words>" — in any language or script. Image models can render lettering, but exact brand wording is not guaranteed, and raster text cannot be read by assistive tech, translated, or restyled. This is a delivery and accessibility rule, not a claim that the model cannot draw text. Everything meant to be read is real HTML typography styled by the theme. If a plan or design note asks for lettered imagery (a hand-lettered name, a calligraphic line), express it as styled heading/paragraph text instead and keep imagery purely pictorial. Prefer scenes whose focal subject carries no lettering at all: the image model may complete a prominent sign, storefront fascia, menu board, screen, placard or label with garbled fake text — at worst a wrong brand name painted over the site's own storefront. When a text-bearing surface is unavoidable in the scene, the subject must describe it as bare — clear glass, an unmarked awning, a blank board — or keep it cropped by the frame, far in the background, or softly out of focus. Never write words for lettering or signage into the subject, even to negate or aim them away: naming lettering plants it, and "lettering turned away" comes back as mirrored glyphs.
- Describe content and composition, not a competing medium or grade. A single site-wide grade (the shared medium, color treatment, light and texture) is applied to every image automatically at generation time. Keep each subject specific while following that shared art direction; put a photographic or illustrated treatment in the shared grade and matching style option rather than inventing a conflicting treatment per image.
- Make sibling images in the same section describe their distinct subject so they don't read alike.
- For cover backgrounds with overlaid copy, keep the focal subject off-center with calm, low-detail areas so the overlaid HTML text stays legible.
- Match the reservation to the scene's natural axis. A left or right reservation needs a subject whose weight is naturally lateral: a figure standing to one side, a wall or doorway, a shoreline or horizon with a clear open side. A raised-vantage crowd, a receding avenue, a valley or a big sky is organized top to bottom; asked to push it sideways, the image model turns the scene 90° inside the canvas. For such a scene keep the copy reservation at the top or bottom instead. Good: `A lone figure at the right edge of a long concrete pier, the left two-thirds open water and haze` with the left reserved. Bad: `A dense crowd on a wide avenue seen from above, the mass of figures weighted to the right` with the left reserved — reserve the upper third and let the crowd fill the lower half.
- State the vantage so that up is up: name the horizon, the sky, the ground or the ceiling when the scene has one. The prompt adds an upright-view anchor automatically; a subject that wants a deliberately tilted or rotated view must say so explicitly.

**Page-context guidelines:**
- A short phrase naming where and how the image is used (e.g. `wide feature band in a portfolio grid`, `portfolio item card in a 3-column gallery`, `menu item thumbnail`). The generator uses this to fit the image to its slot — it is not drawn into the image.
- Write this machine-guidance field in English even when the site's visitor-facing copy uses another language. It is normalized through a fixed pictorial vocabulary before image generation; it is not visible site copy.
- Treat the structured `aspectRatio` field as authoritative for canvas orientation. Page-context prose may describe placement but must not contradict that field.
- Describe copy-overlay placement as reserved empty space in photographic terms, never as text: name the slot as a photograph and describe the region the recipe reserves for copy as empty, low-detail space (which region, and how much of the frame, follows from the recipe's text anchor — do not default to one side) — NOT as the place where a headline or subtitle will sit. Naming a headline, subtitle, caption or menu in the page-context is the audited trigger for the model painting ghost text and fake UI into that exact region of the image — and so is design-comp vocabulary like `hero cover background`: a typography-capable image model reads a design brief as an invitation to typeset the missing title block, so prefer photographic slot language (`editorial photograph`, `full-frame backdrop`) over web-layout language (`hero`, `banner`, `cover background`).

**Cover backgrounds:**
For `wp:cover` backgrounds, set the same generated theme asset path on BOTH the block's `url` attribute and the inner `<img>` src, and put the `AI_IMAGE` spec in that img's alt. The `url` and `src` are asset PATHS only — never write the `AI_IMAGE:` spec into a `url` or `src`; it belongs solely in the `alt`. A cover whose `url` is an `AI_IMAGE:` string ships the raw prompt text as the image and renders no picture.
- NO CAPTIONS — never write a `<figcaption>` on a `wp:image`, and never set a `caption` attribute on one. A caption under a card image or a standalone image reads as clutter; the orienting detail belongs in the surrounding copy, or nowhere. Images inside a `wp:gallery` MAY carry a caption where the collection genuinely needs one, but a gallery does not need captions and is complete without them — never add one just because the block allows it. A deterministic finish pass removes captions from images outside a gallery.
