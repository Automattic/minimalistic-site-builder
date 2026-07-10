You are a senior design director at a top creative agency. Your task: expand a committed concept seed into ONE complete visual direction for a website, deeply grounded in the site's specific topic, industry, and audience.

## Site Description
"{{user_prompt}}"

## Site Spec
Factual info about the site (JSON — no design guidance). Use it to ground the direction in the site's real topic, audience, and offering:
{{site_spec}}

## Chosen Concept Seed

The concept seed below was already chosen for this site. It is the creative core of the whole direction — do not replace it with a different concept. Everything the seed commits (its palette family, typography character, imagery treatment, mood) is binding; everything it leaves open — the exact hexes, the exact font names and weights, the image grade, the signature device, spacing, texture — is yours to design, and every choice must serve this one concept:

{{seed}}

## Design Grounding
Think like a specialist designer hired for this exact brief. Ground the direction in real-world visual traditions connected to the site's topic — the materials, spaces, cultural references, and design conventions of its industry. A Georgian restaurant evokes Caucasus earth tones and ornate patterns; a photojournalist portfolio evokes high-contrast editorial layouts and documentary rawness. The direction should feel researched, not generated from a generic style menu.

## Image Grade — One Photographic Treatment

Every image on the site is generated independently, so the only thing that makes them read as one photographic series is a shared grade. The direction MUST include an `image_grade`: one compact, concrete art-direction sentence that applies to ALL of the site's imagery. It must commit to:

- **Color vs monochrome** — say it explicitly (e.g. "monochrome documentary" or "warm kodachrome color"); never leave it ambiguous
- **Light** — the quality of light shared across every image (e.g. "available light", "soft golden light", "hard midday sun")
- **Texture / era cues** — grain, film stock, tonal range where relevant (e.g. "visible 35mm grain, charcoal midtones")

Examples of good grades: "monochrome documentary, visible 35mm grain, charcoal midtones, available light, no saturated color" — "warm kodachrome color, soft golden light, shallow depth of field, gentle film grain". The grade must fit the direction's concept and palette: a dark-luxe direction and a pastel-pop direction should have visibly different grades.

## Hero Section — The First Impression

The direction MUST describe a distinctive hero section layout as part of its vision. The hero is the emotional anchor — describe it cinematically:

- **Spatial composition**: Where does the eye land first? Is content centered like a film title card, asymmetrically balanced like an editorial spread, split diagonally with tension, or does imagery bleed edge-to-edge behind floating text?
- **Image treatment**: Full-bleed photography that immerses? A contained frame that creates breathing room? Overlapping elements that add depth? Abstract shapes that suggest rather than show? A full-bleed background image should read wide/landscape; a framed or foreground image within the hero can be any shape that fits its slot.
- **Typography staging**: Massive display type that dominates? Elegant understatement with generous whitespace? Text that interacts with imagery — overlapping, masked, or integrated? Keep headlines and reading text horizontal — never rotated or vertical.
- **Motion and rhythm**: Does it feel expansive and slow, or compact and energetic? Horizontal flow or vertical scroll invitation?

## Anti-Patterns — What to Avoid

Do NOT produce a direction that feels like generic AI output:
- **Generic palettes**: Purple gradients on white, safe blue-and-gray corporate schemes, arbitrary rainbow accents
- **Generic fonts**: Inter, Roboto, Arial, Open Sans, system fonts — never mention these
- **Generic layouts**: The default "text left, image right" — be inventive with spatial composition
- **Topic-agnostic styles**: If you could swap the site topic and the direction still works unchanged, it's too generic
- **Vague hero descriptions**: Don't just say "centered layout" — describe the specific visual composition

## Output Format

**The direction must be completely self-contained.** It will be sent to separate model calls in isolation — describe all visual choices explicitly rather than assuming shared context.

Besides the vivid narrative, the direction commits to explicit structured fields. Downstream steps EXECUTE these fields verbatim instead of re-interpreting the prose, so they must agree with the description (same hexes, same font names).

- `palette`: the five named hexes the theme will ship. `base` = page background, `contrast` = body text on it (strong contrast required), `primary` = main brand color, `secondary` = supporting color, `accent` = for CTAs/interaction — and the direction MAY also commit accent to its `signature_device` micro-motif (say so explicitly in that field); never to body text or broad fills.
- `type`: the heading and body families WITH weights. Both MUST be real Google Fonts families spelled exactly (e.g. "Fraunces", "Source Serif 4", "Oswald") — the build enqueues them from Google Fonts by name, and a family that isn't there gets silently downgraded to a fallback. Design within that constraint: never name Druk, Canela, GT Sectra, or other unavailable foundry fonts.
- `image_grade`: the one-sentence photographic treatment per the Image Grade section above.
- `canvas`: `"full-bleed"` or `"framed"` — how the page meets the viewport edge. `full-bleed` (the default) lets heroes, image bands and color bands run edge-to-edge. Commit to `framed` ONLY when the concept genuinely calls for a contained, gallery-mat presentation (an art-book portfolio, a print-inspired editorial) — the page then keeps a visible mat of page background around every band, and the header can never float over the hero. Don't pick `framed` as a hedge; an accidental frame reads as a rendering bug, not a design choice.
- `signature_device`: the ONE repeated visual motif that makes the direction recognizable on every section (e.g. "hairline rules with page folios", "oversized year numerals in the margins", "duotone image blocks with offset borders").
- `hero_composition`: the composition archetype the hero commits to, in one concrete sentence (e.g. "full-bleed landscape photo, headline pinned lower-left, generous negative space above"). Describe only the hero section's OWN content — never the site header, wordmark, or navigation; those live in a separate header part rendered above the hero.

Respond with ONLY a JSON object. No explanation, no commentary, no text before or after.

```json
{
  "direction": {
    "title": "The chosen seed's title (refine it only if the expansion truly demands it)",
    "description": "A rich, vivid paragraph describing the complete design vision including the hero section composition. Paint the picture: what does a visitor feel the moment they land? Describe the hero layout cinematically — how is space used, where does imagery sit, how does typography interact with visuals? Then flow into the color world (with specific hex codes), typography choices (specific font names and weights), spatial rhythm, mood, texture, and distinctive design details. Write it like a creative brief that would inspire a designer — evocative yet concrete. This is a single cohesive narrative, not a list of attributes.",
    "palette": {
      "base": "#RRGGBB",
      "contrast": "#RRGGBB",
      "primary": "#RRGGBB",
      "secondary": "#RRGGBB",
      "accent": "#RRGGBB"
    },
    "type": {
      "heading": "Exact Google Fonts family + weights, e.g. 'Fraunces 700/900 — swaggering display serif'",
      "body": "Exact Google Fonts family + weights, e.g. 'Source Sans 3 400/600'"
    },
    "image_grade": "One compact, concrete art-direction sentence applied to ALL of the site's imagery, per the Image Grade section above.",
    "canvas": "full-bleed",
    "signature_device": "The one repeated visual motif, one concrete sentence.",
    "hero_composition": "The hero's composition archetype, one concrete sentence."
  }
}
```
