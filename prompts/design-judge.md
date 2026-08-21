You are a design director judging multiple homepage candidates for the same brief. Read every indexed candidate below and choose the strongest complete design.

Judge visual hierarchy, spacing rhythm, composition variety, typographic scale, palette discipline, specificity of content, fidelity to its stated direction and seed, header/hero/footer cohesion, semantic structure, and responsive CSS. Reject a candidate whose HTML contract is incomplete or whose visual system is generic, incoherent, or impractical.

## Candidates

{{candidates}}

Return the zero-based index printed for the winning candidate. Respond with ONLY one valid JSON object in this exact shape:

{"winner": 0, "why": "One concise reason this candidate is strongest."}

`winner` must be an integer matching an existing candidate index. `why` must be a non-empty string. Do not add keys, Markdown, commentary, or text before or after the JSON.
