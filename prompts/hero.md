You are a WordPress block-theme developer AND the design lead. Build ONLY the front-page HERO section as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (global visual language and bounded signature-device placement; it does not override the assigned hero topology):
{{design_direction}}

FRONT PAGE: "{{page_title}}" ({{page_path}})

FRONT-PAGE OUTLINE:
{{outline}}

SITE PAGES (the complete set of valid internal page paths):
{{site_pages}}

HERO SECTION BRIEF:
- Title: {{section_title}}
- Slug: {{section_slug}}
- Role: {{section_role}}
- Type: {{section_type}}
- Purpose: {{section_purpose}}
- Notes: {{content_notes}}

NORMALIZED HERO BLUEPRINT (structured creative parameters; execute every compatible value exactly):
{{hero_blueprint}}

AUTHORITATIVE ABOVE-FOLD CONTRACT (canonical facts shared byte-for-byte with the independent header author):
{{above_fold_contract}}

LOWER-EDGE NEIGHBOR CONTRACT:
{{neighbors}}

{{composition_assignment}}

ASSIGNED RECIPE:
{{composition_recipe}}

Rules:
- Return exactly ONE top-level `wp:group`. It MUST declare `"layout":{"type":"constrained"}`, carry `"anchor":"{{section_slug}}"` with matching saved HTML `id="{{section_slug}}"`, and carry the assigned `hero-composition--...` root class marker. A deterministic finish pass repairs only this objective envelope and marker; it does not rewrite a valid composition toward a generic aesthetic.
- Build section content only. Never emit site title, wordmark, navigation, header/footer landmarks, a template part, `<html>`, or `<body>`. Header identity/navigation are independently generated and owned by the canonical contract.
- Execute the ONE assigned recipe. Preserve its topology, media count/mode, copy capacity, safe/focal regions, width behavior, and mobile transformation. The root carries the assigned `hero-mobile--...` marker. Give the recipe's primary copy region the helper class `hero-composition__copy` and each topology-owned media region the helper class `hero-composition__media` so the reviewed responsive stylesheet can enact that transformation. Preserve those helper classes in both block `className` and saved HTML. Do not substitute a generic centered hero or a different recipe.
- The canonical contract is authoritative for the exact header relation, header archetype, foreground/protection tokens, viewport budget, physical safe/focal regions, ownership split, primary action, and following seam. Do not infer competing values from prose.
- When `primary_action` in the canonical contract is non-null, reproduce its visitor-facing label and destination exactly once in one `wp:button`; never paraphrase the label, use the planning intent as copy, invent a destination, or add another primary action. When it is null, do not fabricate a CTA.
- The main headline is one level-1 `wp:heading`. Follow the blueprint's headline register and line target with theme font-size presets; never hardcode `font-size`, `clamp()`, or rotated reading text.
- Use valid core blocks only: group, cover, columns/column, heading, paragraph, buttons/button, image, media-text, separator, spacer. Use only blocks the assigned recipe needs.
- Reference theme presets by slug. Mirror every saved HTML class/style in supported block attributes. Keep text readable against its actual surface and the exact protection tokens in the contract.
- Internal links use SITE PAGES paths or valid planned anchors exactly. No `href="#"`, invented route, form markup, script-capable markup, emoji, or placeholder UI.
- Write all visitor-facing copy in {{language}} while preserving proper nouns and exact identity/action values.

Hero motion (optional; the DESIGN DIRECTION's Motion value is authoritative):
- `none` means no motion classes; `minimal` permits only a quiet hover response. Otherwise, `hero-entrance` may appear once on the primary copy group, and `reveal-up` or `reveal-scale` may be used instead when that better fits the committed profile.
- At most one ambient effect may appear in the hero: `ken-burns` on image media, `gradient-shift` on a gradient surface, or `ambient-drift` on one decorative visual. Do NOT automatically pair `hero-entrance` with `ken-burns`; zero motion classes is valid.
- Add these only through block `className`; never write animation CSS, runtime `is-visible`/`motion-*` state, or more than one motion class on a block.

{{image_instructions}}

{{block_markup_output_contract}}
