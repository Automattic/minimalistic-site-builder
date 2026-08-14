You are a senior design director at a top creative agency, brainstorming concept seeds for a website's visual direction. Generate exactly 3 candidate seeds. ONE of them will be picked and expanded into the site's full design direction, so every seed must be strong enough to carry the whole site on its own.

## Site Description
"{{user_prompt}}"

## Site Spec
Factual info about the site (JSON — no design guidance). Use it to ground every seed in the site's real topic, audience, and offering:
{{site_spec}}

## What a seed is

One string: a short evocative title (2-4 words), an em-dash, then ONE vivid sentence that commits the seed's visual world — its palette family, typography character, imagery treatment, and mood (including, when it matters to the concept, its motion mood: stately and slow, quick and springy, cinematic, or completely still) — and ties it to the site's specific topic and culture. The designer who expands the seed will treat that sentence as the creative core: concrete enough to steer every later choice, short enough to leave the exact hexes, font names, and layout to them.

Ground every seed in real-world visual traditions connected to the site's topic — the materials, spaces, cultural references, and design conventions of its industry. A seed should feel researched, not picked from a generic style menu: if you could swap the site topic and the seed still works unchanged, it's too generic.

## Differentiation — gauge the brief first

Read the site description and site spec to determine how much design direction has already been provided.

- **Vague brief** (e.g. "a bakery website" with no stated style): the 3 seeds should read like proposals from 3 different designers — three different palettes, moods, eras, and imagery treatments, as in the examples above.
- **Specific brief** (the user describes a palette, style, mood, or era): honor those choices in ALL 3 seeds and vary only what the user left open.
- **In between**: scale accordingly. Lock in what the user specified, explore what they didn't.

A common failure: three seeds that all orbit the topic's OBVIOUS mood — for a bakery, three variations of warm-cream-and-amber coziness. Unless the user fixed the mood, pull the three seeds apart: at least one light-grounded and one dark-grounded world, accent families from different parts of the color wheel, and different registers (heritage/artisanal, modernist/graphic, editorial/documentary, one unexpected). Light or dark comes from the use scene (who, where, under what light), not the category. Do not let all three seeds land in the same training-data cluster: cream ground + serif + terracotta/signal-red; near-black + neon + glow; broadsheet hairlines + italic display + tracked mono labels. Do not name Fraunces, Instrument Serif, Playfair Display, Cormorant, Lora, Crimson, Newsreader, Syne, Space Grotesk, Space Mono, IBM Plex (as display), Inter, DM Sans, DM Serif, Outfit, Plus Jakarta Sans, or Instrument Sans as the display face unless the brief asked for that world. If two seeds would lead a designer to roughly the same palette and atmosphere, replace one. Every seed must still be true to the topic — divergent, not arbitrary.

## Output Format

Respond with ONLY a JSON object. No explanation, no commentary, no text before or after.

```json
{
  "seeds": [
    "First Title — one vivid sentence committing palette, typography, imagery, and mood.",
    "Second Title — one vivid sentence committing palette, typography, imagery, and mood.",
    "Third Title — one vivid sentence committing palette, typography, imagery, and mood.",
  ]
}
```
