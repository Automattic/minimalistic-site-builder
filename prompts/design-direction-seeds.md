You are a senior design director at a top creative agency, brainstorming concept seeds for a website's visual direction. Generate exactly 3 candidate seeds. ONE of them will be picked and expanded into the site's full design direction, so every seed must be strong enough to carry the whole site on its own.

## Site Description
<user_brief>
{{user_prompt}}
</user_brief>

## Site Spec
Factual info about the site (JSON — no design guidance). Use it to ground every seed in the site's real topic, audience, and offering:
{{site_spec}}

## What a seed is

Each seed is a short text plus six labels (`ground`, `register`, `accent`, `tint`, `type_register`, `color_economy`) so we can tell the three seeds apart.

The text is a short evocative title (2-4 words), an em-dash, then ONE vivid sentence that commits the seed's visual world — its palette family, typography character, imagery treatment, and mood (including, when it matters to the concept, its motion mood) — and ties it to the site's specific topic and culture. The designer who expands the seed will treat that sentence as the creative core: concrete enough to steer every later choice, short enough to leave the exact hexes, font names, and layout to them.

Ground every seed in real-world visual traditions connected to the site's topic — the materials, spaces, cultural references, and design conventions of its industry. A seed should feel researched, not picked from a generic style menu: if you could swap the site topic and the seed still works unchanged, it's too generic.

## The labels

Answer these for the look you actually wrote. Use only the words listed. If a later seed gives the same `ground` + `register` + `accent` as an earlier one, we throw the later seed out.

- `ground`: `"light"` or `"dark"` — is the page mostly pale, or mostly dark? When the USER BRIEF itself names the page ground — "white page", "light", "pale", "cream" on one side; "dark", "black", "near-black", "midnight" on the other — that word is the ground for ALL three seeds. A stated ground is a client decision, not a category reflex: never answer "white page" with a dark seed because the scene or the mood argued for one. Only a brief that says nothing about its ground leaves the choice to the seeds.
- `register`: the design tradition the seed speaks in — one of `"heritage"` (traditional craft), `"modernist"` (clean and spare), `"editorial"` (magazine-like), `"expressive"` (decorative and bold), `"art-deco"` (geometric luxury, symmetry, metallics), `"brutalist"` (raw structure, exposed grid, blunt type), `"poster"` (Swiss/Cassandré graphic punch, one big idea), `"noir"` (high-contrast cinematic shadow), `"archival"` (catalog, index, specimen sheet), `"craft"` (hand-made, imperfect, tactile), `"retro-futurist"` (a past era's idea of the future), `"pop"` (flat bright graphic optimism), `"organic"` (botanical, flowing, natural form), or `"technical"` (schematic, blueprint, measured)
- `accent`: `"warm"`, `"cool"`, `"earth"`, `"jewel"`, or `"neutral"` — the hue family of the highlights, not of the page
- `tint`: `"warm"`, `"cool"`, `"violet"`, `"green"`, `"blush"`, or `"neutral"` — which way the PAGE ITSELF is tinted, not the highlights. `ground` already said light or dark; this says which hue family that ground belongs to. Each family spans many specific hues; name the family here and let the direction pick the exact one. It applies to dark pages too — a warm dark and a cool dark are different worlds. The build reads this label and moves the page background onto the family you name, so name the one you actually mean.
- `type_register`: the letterform tradition the seed sets its lettering in — one of `"grotesque"`, `"didone"`, `"slab"`, `"humanist"`, `"geometric"`, `"transitional"`, `"condensed"`, `"mono"`, `"script"`, or `"display-serif"`. This is a SEPARATE choice from `register`: a calm concept can be set in a Didone and a bold one in a humanist. Do not let the mood pick the lettering for you — that reflex is why generated sites keep arriving in the same handful of faces. The three seeds should not all name the same tradition — and every tradition you name must be one a designer would defend for THIS brief. Ask of each seed: what in this site's world is set in this lettering? Most briefs can defend several traditions and rule out others; a tradition with no argument in the brief's world is wrong even when it looks fresh, and a tradition chosen only to differ from the other two seeds is as much a reflex as the famous default. Differentiate among the defensible traditions, never past them.
- `color_economy`: how many independent hue families the concept needs — one of `"monochrome"`, `"single-accent"`, or `"multicolor"`. `monochrome` means one hue family or a neutral scale, with semantic roles separated by tone; `single-accent` means a neutral or tonal foundation with one independent interaction hue; `multicolor` means several purposeful hue families with a defined role for each. Palette role names do not require different hues. Honor an explicit color budget from the brief in all three seeds; when the brief leaves it open, vary the economy when that produces defensibly different concepts.

Pick three different combinations of those answers first — each one a combination you could defend for this brief — then write the sentence that fits each one.

Every family is equally available. Warm cream is the answer this brief will pull you toward hardest and it is right only when the concept genuinely asks for it; a blue, violet, or green ground is not a riskier choice, just a less reflexive one. If all three seeds come back `warm`, you have written one world three times. On an open brief the three seeds must not share one `tint`: name at least two families across the round, and prefer three. Only a brief that fixes the palette earns a repeated tint — honor it in all three seeds and let them match. The build records a round that leans on one family either way.
{{locked_labels}}

## Differentiation — gauge the brief first

Read the site description and site spec to determine how much design direction has already been provided.

- **Vague brief** (a topic with no stated style): the 3 seeds should read like proposals from 3 different designers — three different palettes, moods, eras, and imagery treatments.
- **Specific brief** (the user describes a palette, style, mood, or era): honor those choices in ALL 3 seeds and vary only what the user left open. If that means two seeds end up with the same three answers, leave them that way. Don't invent a difference the user ruled out.
- **In between**: scale accordingly. Lock in what the user specified, explore what they didn't.

A common failure: three seeds that all orbit the topic's OBVIOUS mood — three variations of the palette and atmosphere the category is known for. Unless the user fixed the mood, pull the three seeds apart: at least one light page and one dark page, different highlight colors, different design languages. If two seeds would lead a designer to roughly the same palette and atmosphere, replace one. Every seed must still be true to the topic. Different looks, not random ones.

## Output Format

Respond with ONLY a JSON object. No explanation, no commentary, no text before or after.

The angle-bracket strings below demonstrate the JSON shape only. They are not
legal output values and they do not recommend any label. Replace every one with
content grounded in the brief and one exact value from the allowed vocabulary
above. Array position has no aesthetic meaning: candidate 1 is not the light,
traditional, warm, or otherwise "safe" candidate, and candidates 2 and 3 have
no preset character either.

```json
{
  "seeds": [
    {
      "seed": "<candidate 1 title> — <candidate 1 sentence>",
      "ground": "<allowed ground label chosen for candidate 1>",
      "register": "<allowed register label chosen for candidate 1>",
      "accent": "<allowed accent label chosen for candidate 1>",
      "tint": "<allowed tint label chosen for candidate 1>",
      "type_register": "<allowed type_register label chosen for candidate 1>",
      "color_economy": "<allowed color_economy label chosen for candidate 1>"
    },
    {
      "seed": "<candidate 2 title> — <candidate 2 sentence>",
      "ground": "<allowed ground label chosen for candidate 2>",
      "register": "<allowed register label chosen for candidate 2>",
      "accent": "<allowed accent label chosen for candidate 2>",
      "tint": "<allowed tint label chosen for candidate 2>",
      "type_register": "<allowed type_register label chosen for candidate 2>",
      "color_economy": "<allowed color_economy label chosen for candidate 2>"
    },
    {
      "seed": "<candidate 3 title> — <candidate 3 sentence>",
      "ground": "<allowed ground label chosen for candidate 3>",
      "register": "<allowed register label chosen for candidate 3>",
      "accent": "<allowed accent label chosen for candidate 3>",
      "tint": "<allowed tint label chosen for candidate 3>",
      "type_register": "<allowed type_register label chosen for candidate 3>",
      "color_economy": "<allowed color_economy label chosen for candidate 3>"
    }
  ]
}
```
