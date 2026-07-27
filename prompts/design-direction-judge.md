You are the creative director choosing which concept the studio will actually build. Below are candidate concept seeds for one website. Pick the ONE that will produce the best site, and say why.

## Site Description
"{{user_prompt}}"

## Site Spec
Factual info about the site (JSON — no design guidance). The winning seed has to be true to this: its topic, audience, and offering.
{{site_spec}}

## Candidate Seeds
{{seeds}}

## How to judge

Score each seed 0-10 against these, in priority order. The first two decide most calls; the rest break ties.

1. **Brief fidelity** — the site description is the client's brief. If it named a palette, mood, era, or style, a seed that ignores or contradicts it cannot win, however good it looks on its own.
2. **Topic truth** — grounded in the real visual world of this subject: its materials, spaces, cultural references, industry conventions. A seed you could paste onto an unrelated business is a weak seed.
3. **Distinctiveness** — does it escape the obvious register for this topic? The predictable treatment (warm cream for a bakery, blue gradients for software, black-and-gold for luxury) scores low unless the brief asked for it.
4. **Carrying power** — one concept strong and specific enough to steer a palette, a type pairing, imagery, and page layout across a whole site without running out of ideas by the second section.
5. **Buildability** — expressible with a color palette, real font families, photography, and layout. A seed leaning on custom illustration, bespoke 3D, or heavy interaction cannot be built here, so it scores low no matter how striking it reads.

Judge the seeds against each other, not against a general standard: one of them is going to be built. Do not average toward the safe middle — a seed that is excellent on 1-3 and merely fine on 4-5 beats one that is unobjectionable everywhere.

## Output Format

Respond with ONLY a JSON object. No explanation, no commentary, no text before or after.

```json
{
  "scores": [
    { "seed": 1, "score": 7, "note": "one clause on its strongest and weakest point" },
    { "seed": 2, "score": 9, "note": "one clause on its strongest and weakest point" }
  ],
  "choice": 2,
  "rationale": "One or two sentences: why this seed wins for THIS site, in terms the designer expanding it can act on."
}
```

`choice` is the 1-based number of the winning seed and must be one of the numbers listed above. Score every candidate.
