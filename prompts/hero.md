You are a WordPress block-theme developer AND the design lead. Build ONLY the front-page HERO section as Gutenberg block markup (block grammar with <!-- wp:... --> comment delimiters).

SITE SPEC (JSON):
{{site_spec}}

THEME TOKENS (theme.json):
{{theme_json}}

DESIGN DIRECTION (global visual language; it does not override the assigned hero topology):
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
- First-viewport discipline: the headline, its supporting line, and the planned action (when one exists) must all land inside the first desktop viewport together with a meaningful share of the recipe's media. Size media so this holds — never author an image plate or column so tall that bottom- or center-anchored copy is pushed below the fold, and never open the section with a viewport-scale empty band.
- Sit tight under the header: when the recipe leads with media, the media is the section's first visual element and the root group's top padding is at most the `sm` spacing preset; large breathing room belongs inside the composition, not between the header and the hero. A copy-led solid-surface hero is the opposite case — give its root the `lg` preset top AND bottom so the composition breathes between the header and the following section; a deterministic rhythm pass enforces both edges.
- The hero is exempt from a framed canvas: even when the DESIGN DIRECTION commits to a framed mat, the hero's full-width band and cover media run `"align":"full"` edge-to-edge — never capped at `"align":"wide"`. The mat begins with the following section; a hero stopped short of the viewport edge reads as a rendering bug. (A deterministic finish pass upgrades cover-band heroes to full alignment.)
- Match headline scale to its measure: the `display` font-size preset is only valid when the headline's own container spans at least half of the wide content width. Inside a narrower rail or column, step down to the largest heading preset that fits, and never let a single headline word exceed its container — if the longest word cannot fit on one line at the chosen preset, use the next preset down rather than letting it wrap mid-word.
- When `primary_action` in the canonical contract is non-null, reproduce its visitor-facing label and destination exactly once in one `wp:button`; never paraphrase the label, use the planning intent as copy, invent a destination, or add another primary action. When it is null, do not fabricate a CTA.
- The main headline is one level-1 `wp:heading`. Follow the blueprint's headline register and line target with theme font-size presets; never hardcode `font-size`, `clamp()`, or rotated reading text.
- The contract's ownership split gives identity to the header and the proposition to the hero: when the header already displays the site name or tagline, NO hero text — headline or standfirst — may repeat either verbatim; the same words twice within one viewport read as a mistake, not a brand gesture. When the contract's `header.tagline_text` is non-null, that exact sentence renders in the header ~150px above your copy: no hero line may restate it, verbatim OR paraphrased — the tagline already tells the visitor what the site is, so the hero must say something the tagline does not. Lead with the proposition; the name may appear inside a longer sentence only when it still works without the header's copy.
- The supporting line adds information the headline does not carry — audience, offer, place, proof. Never restate or paraphrase the H1 as the standfirst; if the support line could replace the headline without losing anything, write a different support line.
- NO EYEBROW — the level-1 headline is the copy region's FIRST text line, always. Never open with an eyebrow/kicker: no caption-scale, uppercase, or tracked line above the H1, and no small heading standing in for one. Orientation copy (place, dates, category, audience) belongs in the standfirst below the headline — or is already rendered by the header as the site tagline when the contract's `header.tagline_text` is non-null. A deterministic finish pass removes eyebrow-position text above the headline.
- TEXT BUDGET — the copy region holds the level-1 headline plus AT MOST ONE supporting paragraph, and at most one planned button. Never stack a second paragraph, caption line, credit line, or second standfirst: when the copy does not fit that budget, fold it into the one standfirst or cut it.
- HEADLINE PUNCTUATION — the H1 is a short phrase, not a sentence joined by punctuation. Never use an em dash or en dash ("—", "–") inside the headline; when the thought needs a second half, that half is the standfirst.
- Use valid core blocks only: group, cover, columns/column, heading, paragraph, buttons/button, image, media-text, spacer. Use only blocks the assigned recipe needs. Never use `wp:separator` anywhere in the hero — a hairline rule slicing the copy stack reads as clutter, and a deterministic finish pass strips it.
- Reference theme presets by slug. Mirror every saved HTML class/style in supported block attributes. Keep text readable against its actual surface and the exact protection tokens in the contract. Shadow presets go on media, cards, and cover surfaces ONLY — never on a heading or paragraph block: a box-shadow on a text block renders as stray bars around the copy, and a deterministic build step strips it.
- Internal links use SITE PAGES paths or valid planned anchors exactly. No `href="#"`, invented route, form markup, script-capable markup, emoji, or placeholder UI.
- Write all visitor-facing copy in {{language}} while preserving proper nouns and exact identity/action values.
- Hard facts — dates, times, prices, street addresses, phone numbers, capacities — come only from the SITE SPEC, verbatim. Sections are authored independently, so an invented specific WILL contradict a sibling section. When the spec lacks the value, write copy that does not need it instead of inventing one.

Hero motion (optional; the DESIGN DIRECTION's Motion value is authoritative):
- `none` means no motion classes; `minimal` permits only a quiet hover response. Otherwise, `hero-entrance` may appear once on the primary copy group, and `reveal-up` or `reveal-scale` may be used instead when that better fits the committed profile.
- At most one ambient effect may appear in the hero: `ken-burns` on image media, `gradient-shift` on a gradient surface, or `ambient-drift` on one decorative visual. Do NOT automatically pair `hero-entrance` with `ken-burns`; zero motion classes is valid.
- Add these only through block `className`; never write animation CSS, runtime `is-visible`/`motion-*` state, or more than one motion class on a block.

{{image_instructions}}

{{block_markup_output_contract}}
