You are a senior design director at a top creative agency, brainstorming concept seeds for a website's visual direction. Generate exactly 3 candidate seeds. ONE of them will be picked and expanded into the site's full design direction, so every seed must be strong enough to carry the whole site on its own.

## Site Description
"{{user_prompt}}"

## Site Spec
Factual info about the site (JSON — no design guidance). Use it to ground every seed in the site's real topic, audience, and offering:
{{site_spec}}

## What a seed is

Each seed is a short text plus five labels (`ground`, `register`, `accent`, `tint`, `type_register`) so we can tell the three seeds apart.

The text is a short evocative title (2-4 words), an em-dash, then ONE vivid sentence that commits the seed's visual world — its palette family, typography character, imagery treatment, and mood (including, when it matters to the concept, its motion mood: stately and slow, quick and springy, cinematic, or completely still) — and ties it to the site's specific topic and culture. The designer who expands the seed will treat that sentence as the creative core: concrete enough to steer every later choice, short enough to leave the exact hexes, font names, and layout to them.

Ground every seed in real-world visual traditions connected to the site's topic — the materials, spaces, cultural references, and design conventions of its industry. A seed should feel researched, not picked from a generic style menu: if you could swap the site topic and the seed still works unchanged, it's too generic.

## The labels

Answer these for the look you actually wrote. Use only the words listed. If a later seed gives the same `ground` + `register` + `accent` as an earlier one, we throw the later seed out.

- `ground`: `"light"` or `"dark"` — is the page mostly pale, or mostly dark?
- `register`: the design tradition the seed speaks in — one of `"heritage"` (traditional craft), `"modernist"` (clean and spare), `"editorial"` (magazine-like), `"expressive"` (decorative and bold), `"art-deco"` (geometric luxury, symmetry, metallics), `"brutalist"` (raw structure, exposed grid, blunt type), `"poster"` (Swiss/Cassandré graphic punch, one big idea), `"noir"` (high-contrast cinematic shadow), `"archival"` (catalog, index, specimen sheet), `"craft"` (hand-made, imperfect, tactile), `"retro-futurist"` (a past era's idea of the future), `"pop"` (flat bright graphic optimism), `"organic"` (botanical, flowing, natural form), or `"technical"` (schematic, blueprint, measured)
- `accent`: `"warm"`, `"cool"`, `"earth"`, `"jewel"`, or `"neutral"` — the color of the highlights: amber/red, blue/green, clay/olive, gemstone, or grey/black/white
- `tint`: `"warm"`, `"cool"`, `"violet"`, `"green"`, `"blush"`, or `"neutral"` — which way the PAGE ITSELF is tinted, not the highlights. `ground` already said light or dark; this says which family that ground belongs to: cream/sand, blue/slate, purple/aubergine, sage/olive, pink/clay, or a true grey. It applies to dark pages too — a warm charcoal and a cool ink are different worlds. The build reads this label and moves the page background onto the family you name, so name the one you actually mean.
- `type_register`: the letterform tradition the seed sets its lettering in — one of `"grotesque"`, `"didone"`, `"slab"`, `"humanist"`, `"geometric"`, `"transitional"`, `"condensed"`, `"mono"`, `"script"`, or `"display-serif"`. This is a SEPARATE choice from `register`: a calm concept can be set in a Didone and a bold one in a humanist. Do not let the mood pick the lettering for you — that reflex is why generated sites keep arriving in the same handful of faces. The three seeds should not all name the same tradition — and every tradition you name must be one a designer would defend for THIS brief. Ask of each seed: what in this site's world is set in this lettering? A century-old tavern can carry a calligraphic humanist, a robust transitional, or a Didone with wine-label gravity; a techy web slab on that brief has no argument behind it, and a tradition chosen only to differ from the other two seeds is as much a reflex as the famous default. Differentiate among the defensible traditions, never past them.

Pick three different combinations of those answers first — each one a combination you could defend for this brief — then write the sentence that fits each one.

Every family is equally available. Warm cream is the answer this brief will pull you toward hardest and it is right only when the concept genuinely asks for it; a blue, violet, or green ground is not a riskier choice, just a less reflexive one. If all three seeds come back `warm`, you have written one world three times. On an open brief the three seeds must not share one `tint`: name at least two families across the round, and prefer three. Only a brief that fixes the palette earns a repeated tint — honor it in all three seeds and let them match. The build records a round that leans on one family either way.
{{locked_labels}}

## Differentiation — gauge the brief first

Read the site description and site spec to determine how much design direction has already been provided.

- **Vague brief** (e.g. "a bakery website" with no stated style): the 3 seeds should read like proposals from 3 different designers — three different palettes, moods, eras, and imagery treatments.
- **Specific brief** (the user describes a palette, style, mood, or era): honor those choices in ALL 3 seeds and vary only what the user left open. If that means two seeds end up with the same three answers, leave them that way. Don't invent a difference the user ruled out.
- **In between**: scale accordingly. Lock in what the user specified, explore what they didn't.

A common failure: three seeds that all orbit the topic's OBVIOUS mood — for a bakery, three variations of warm-cream-and-amber coziness. Unless the user fixed the mood, pull the three seeds apart: at least one light page and one dark page, different highlight colors, different design languages. If two seeds would lead a designer to roughly the same palette and atmosphere, replace one. Every seed must still be true to the topic. Different looks, not random ones.

## Output Format

Respond with ONLY a JSON object. No explanation, no commentary, no text before or after.

```json
{
  "seeds": [
    {
      "seed": "First Title — one vivid sentence committing palette, typography, imagery, and mood.",
      "ground": "light",
      "register": "heritage",
      "accent": "warm",
      "tint": "green",
      "type_register": "transitional"
    },
    {
      "seed": "Second Title — one vivid sentence committing palette, typography, imagery, and mood.",
      "ground": "dark",
      "register": "noir",
      "accent": "jewel",
      "tint": "violet",
      "type_register": "didone"
    },
    {
      "seed": "Third Title — one vivid sentence committing palette, typography, imagery, and mood.",
      "ground": "light",
      "register": "poster",
      "accent": "cool",
      "tint": "neutral",
      "type_register": "condensed"
    }
  ]
}
```
