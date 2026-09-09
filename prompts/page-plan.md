You are the content strategist and design lead for ONE page of a new multi-page website. Plan THIS page as an ordered list of distinct sections that, together, do this page's job completely. Do NOT write block markup — only plan the sections.

USER PROMPT:
<user_brief>
{{user_prompt}}
</user_brief>

SITE SPEC (JSON):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it shape the section ideas and flow):
{{design_direction}}

SITE PAGES (every page of the site; plan ONLY the one marked below):
{{site_pages}}

THIS PAGE:
  Title:   {{page_title}}
  Slug:    {{page_slug}}
  Purpose: {{page_purpose}}

{{page_emphasis}}

{{front_hero_context}}

Tailor the section choice to the site's `site_type` / `area` and THIS page's purpose: derive the sections from what this site offers, who it serves, and what the visitor must be able to do, starting from the spec's `sections` list. Pick what genuinely fits THIS page rather than a rigid template.

You are also the page's art director: each section will be built independently and concurrently by a different author who sees only its own brief, so YOU own the page-level visual rhythm. Assign every section a layout archetype and a background treatment, and describe its seams, so repetition, contrast and transitions serve the page's concept and content.

The user's requested aesthetic takes precedence over industry conventions. Plan the page's overall silhouette before its individual sections: hierarchy, proportions, density, where imagery earns its place, and how content groups across the page. A different font and palette on the same sequence of split rows and cards is not a new composition. Use the supported layouts as building blocks for this concept, not as a checklist to rotate through. Repetition and a concise sequence are valid when intentional.

Return a single JSON object with this exact shape:
{
  "sections": [
    {
      "slug": "hero",
      "title": "Short human title for the section",
      "type": "a short, specific semantic label; examples: menu, timeline, case-studies, process, services, gallery, testimonials, pricing, team, faq, contact, story",
      "purpose": "1 sentence: what this section is for and what the visitor should take away",
      "content_notes": "2-4 sentences of concrete guidance: the specific copy points, items, or layout idea for this section, grounded in the site spec (real facts where given)",
      "layout_archetype": "one of: full-bleed-cover, asymmetric-split, centered-stack, offset-grid, equal-card-grid, list-with-thumbnails",
      "background": "one of: base, tinted, contrast, image",
      "vertical_density": "one of: compact, standard, spacious",
      "item_pattern": null,
      "text_placement": "one of: left-column, centered, split, asymmetric-thirds",
      "handoff": "1 line: what visually sits immediately above and below this section (each neighbor's background + archetype), so the transitions are designed rather than accidental",
      "primary_action": null
    }
  ]
}

`primary_action` is REQUIRED on every section and is either null or exactly:
{
  "label": "Short visitor-facing action copy in the site's language",
  "intent": "One planning sentence explaining what the action helps the visitor do",
  "destination": "/an-exact-page-path/ or #an-exact-planned-section-anchor"
}
{{primary_action_rule}}
When present, keep `label` to 1-80 Unicode grapheme clusters of plain text with no
markup or control characters. Use that exact visitor-facing label; `intent` is
non-empty plain-text planning context and must never become button copy;
`destination` is also plain text. Never invent or guess a
route, placeholder `#`, phone number, or external URL. A contact mailto or
tel: is valid only when that exact address or number appears in SITE SPEC.
Never invent an email, street address, phone number, or URL.

Layout archetypes (pick the one that best serves each section's content):
- full-bleed-cover — a full-width cover image or gradient with overlaid text. ALWAYS pair it with background "image": the section delivers one wp:cover band, and only the "image" treatment lets the builder run that band edge to edge (any other background frames the cover inside a padded solid band, and the builder forces the pairing to "image" anyway).
- asymmetric-split — one row of unequal regions, never equal. Two regions (e.g. 34/66 or 40/60) for a lead-and-support band about one thing; three (e.g. 50/25/25) for a magazine row mixing one wide feature with narrow notes. Pick two unless the content is genuinely several items.
- centered-stack — a single constrained, centered column carried by type and whitespace (the theme centers every element in the band, so plan short copy for it: a long centered rag is hard to read)
- offset-grid — a staggered grid whose items deliberately don't line up in neat rows. Use ONLY when the DESIGN DIRECTION's rhythm is `offset` or `gallery`. Under every other rhythm, pick a level row (equal-card-grid, asymmetric-split, or list-with-thumbnails) instead of offsetting sibling tops.
- equal-card-grid — the classic equal-height card row
- list-with-thumbnails — stacked rows, each a small image beside text

Section structure and types:
- List sections in their intended page order. The builder derives each section's structural role from that order after generation, so do not return a `role` field.
- `type` is an open-ended semantic label, always in English. Choose or invent the most specific short label for what the section actually contains; do not collapse a menu, timeline, case-study index, process, event calendar, or location guide into a generic bucket.
- The site's preferred repeated-item idiom is `{{item_pattern}}`. Use it when it suits the information. Choose `card`, `rule-row`, `spec-table`, or `tag-cluster` per section when a different idiom serves its content better: portraits for a team, aligned rows for prices, paired facts for specifications, short labels for a tag collection. Use null when no repeated-item recipe is needed, including prose and quotes. Keep typography, palette and spacing coherent across these choices; never force content into a shape that loses its meaning. Choose an archetype that can house the assigned idiom.

Background treatments:
- base — the default page background
- tinted — the committed `band` palette surface; never `secondary` and never a gradient
- contrast — a dark inverted band (contrast background, light text)
- image — a full-bleed image band

Vertical density controls the section's OUTER top/bottom breathing room. The
builder applies it deterministically after all independently authored sections
return, so it is a page-level rhythm decision rather than something each section
author improvises:
- compact — image-heavy galleries, long grids, practical information, or any
  section whose content already creates substantial height
- standard — the default for most heroes, stories, feature sections and CTAs
- spacious — generous outer breathing room when whitespace carries the
  composition, including sustained spacious sequences when the concept needs them

Text placement controls the horizontal position of the section's readable copy
stack, independently of the band's width and `layout_archetype`:
- left-column — put the readable column on the wide band's leading edge
- centered — center the copy column, but keep wrapping paragraphs start-aligned
- split — make copy one side of an intentional two-zone composition; use the
  section notes to name the occupied side and alternate sides when useful
- asymmetric-thirds — offset copy into the second or third zone of a wide band;
  name the exact zone in `content_notes` so the section author does not guess

Rules:
- LANGUAGE: every "title" and every copy point inside "content_notes" is written in {{language}} — section titles become on-page headings and the notes seed each section's copy, so a plan in the wrong language leaks into the page. "slug" and "type" are machine-facing identifiers and are ALWAYS plain English words in lowercase a-z ASCII, regardless of {{language}} — they are never rendered on the page.
- IDENTITY: where the plan names the brand or the person, use the spec's `name` / `persona_name` exactly. Any planned email, phone, address, or URL must be an exact SITE SPEC value — never invent alternates, and never construct an address at `email_domain`.
- A shop is a catalog: product cards, prices only when SITE SPEC supplies them, enquire. Never plan a cart, checkout, quantity field, or add-to-cart control. There is no cart backend.
- THIS PAGE ONLY: plan only content that belongs here per this page's purpose and the SITE PAGES list. The purpose is the contract — do not pad a narrow page (contact, enquiry, hours) with homepage-style bands. Content that lives on a sibling page gets, at most, a teaser that links onward — "content_notes" may reference another page by its path ONLY when that exact path appears in SITE PAGES (e.g. "closes with a link to /menu/"). Never invent paths for pages that are not listed; on a one-page site, keep CTAs on-page (section anchors or same-page actions) instead of dead routes like /menu/ or /about/. Follow {{page_emphasis}} for section count: a contact page is 2 to 4 sections.
- The FIRST section is the page-opening hero. The LAST section completes the page's content; it need not be a separate CTA band. Place a useful next step where the visitor needs it. A one-section plan is necessarily the opening hero and may be sufficient for a genuinely narrow brief.
- "slug" is lowercase a-z, 0-9 and hyphens only, unique across the list, and descriptive (the opening section's slug is "hero"; every other slug names its own content).
- "content_notes" must be specific to THIS site (use the spec's facts), not generic filler. For the opening, retain the blueprint's focal point, essential content and mobile hierarchy; plan deferred supporting details in a later appropriate section instead of duplicating them in the hero. For inner-page openings, name the page-specific primary impression, image role and mobile reading order in these notes. Supporting facts may lead when the page's purpose calls for them; no universal image-first order or content quota applies.
- "content_notes" must never include site chrome — no wordmark, site title lockup, navigation, or menu links, even if the design direction mentions them. The site header is a separate template part that renders above (or overlaid on) the hero; planning nav into a section produces a doubled header.
- **Eyebrows are banned.** Never put a kicker, small uppercase label, caption line or minor heading above a heading, including in heroes and repeated items. Put useful metadata below the heading or in body copy.
- **Decorative numbering is banned.** No invented "01 / 02 / 03" section, card or step labels, folio numbers or identifier columns. Preserve real numeric content (prices, dates, addresses) and explicitly requested visible numbering. A process alone is not permission to add painted numerals.
- **Lines and borders need a structural purpose.** Use whitespace, typography and grouping first. Keep functional control boundaries, table/index row divisions and required component frames. No rules under headings, between ordinary paragraphs or at section seams; no extra boxes around every content group. Matching a style is not sufficient justification.
- Never plan a footer or site-chrome section. The theme generates exactly one separate footer template part and appends it after this page's LAST section, so a section whose slug, title, or type is `footer`, `footer-info`, `site-footer`, or equivalent would produce a duplicate ending. Make the LAST section a page-owned next step that follows from THIS page's purpose and the spec's facts — not global navigation, legal links, or footer contact columns.
- Decorative ornaments (drawn flourishes, motif marks, illustrated icons) are NEVER planned as generated imagery — AI-generated ornaments come out off-palette and wobbly. Glyph marks are not a planning tool either: never plan decorative glyph marks — not as a list bullet, metadata separator, or a mark repeated before every heading. Plain rules, hairlines and underlines are never planned as imagery — they are borders/separators the section authors build with styles. Likewise, never plan words, names or calligraphic lettering as imagery (generated images garble glyphs): anything meant to be read is planned as real text, styled by the theme's typography. Planned imagery is content imagery only: hero covers, feature/gallery/card images, photographic bands.
- Choose composition from the concept and content. Intentional repetition is valid: a catalog, reading sequence or gallery may keep the same archetype. Change layout when it improves hierarchy or meaning, not to meet a quota.

- The DESIGN DIRECTION's **Rhythm** guides the sequence. `stacked` can sustain one reading column; `alternating` changes compositions where useful; `offset` favors unequal splits and staggered grids; `interrupted` introduces a purposeful break; `banded` uses color fields; `gallery` gives imagery the lead. Let the content justify exceptions.
- The DESIGN DIRECTION's **Density** guides vertical_density: `expansive` and `airy` favor spacious pauses, `measured` favors standard spacing, and `dense` or `packed` favor compact spacing. Account for the actual amount of content. There is no per-page quota or adjacency restriction on a valid density.
- The DESIGN DIRECTION's **Text placement** governs every per-section `text_placement` assignment below the page-opening hero. Read it as a site-level intent, the same way Rhythm governs per-section archetypes: `left-column` keeps most copy on the leading grid edge; `centered` centers most short/text-led stacks; `split` favors two-zone bands and alternates the occupied side where semantics allow; `asymmetric-thirds` uses the second and third zones of wide bands so successive text masses do not repeat one axis. A section may take a deliberate exception when its content or archetype demands it, but repeating `left-column` on every below-fold section fails every commitment except `left-column`. The section author still enforces readable measure; this field moves the column, never widens it.
- `text_placement` remains REQUIRED on the first section because every section shares one schema. Echo the site-level commitment there, but let the hero blueprint's composition own its text arrangement. With an authored hero, plan each inner-page opening as a related but distinct expression of that concept, suited to its own content, with at least one meaningful image. The catalog archetype is planning shorthand for these openings, not a mandatory recipe. Explain the actual arrangement in content_notes.
- Plan backgrounds as part of the concept. A continuous base surface, subtle tonal changes, sustained color fields and image-led sequences are all valid. Keep contrast readable and make transitions purposeful. There is no required number of non-base bands.

{{footer_surface_rule}}
- Choose widths for the content: constrained reading columns and wide grids/media. Vary width when it strengthens hierarchy; a continuous reading measure is valid.
- Plan horizontal copy rhythm independently from width rhythm. On a wide/full band, assign where its headline/intro column starts with `text_placement`; do not use full-width paragraphs as a shortcut. For `split` and `asymmetric-thirds`, state the occupied side/zone in `content_notes`, preserve reading order on mobile, and never invent filler copy or decorative empty text merely to balance the opposite zone.
- Plan vertical density across the whole page; use the amount of content and intended pace to choose breathing room. Do not add filler content or empty blocks to create balance.
- Adjacent sections on the same guaranteed-continuous solid surface (`base` or `contrast`) share one seam budget: the deterministic rhythm pass removes the upper section's bottom padding and lets the lower section's top padding own that gap. Tinted gradients and image assets keep separate edges because two independently authored instances may differ. Write handoff prose with that distinction in mind rather than budgeting whitespace twice.
- An "image" background wraps whatever archetype the section uses inside a full-bleed cover band; pairing it with "full-bleed-cover" is the classic image-led band (a natural hero choice, not a redundancy), and it is the ONLY background a "full-bleed-cover" section may carry. Reserve "image" for the 1-2 sections where imagery should carry the band.
- When the DESIGN DIRECTION's **Canvas** is `framed`, plan bands inside the page's mat at wide width. An authored opening may be framed too; follow its composition and actual header protection contract, not a universal full-bleed exemption.
- When the DESIGN DIRECTION's **Device** is not `none`, assign that device to at most ONE non-hero section by naming the class in that section's `content_notes` (e.g. "this band carries device--stamp"). Never the hero. Never two bands.
- "handoff" must name the actual neighbors' assignments — the archetype and background of the section above, the archetype and background of the section below, and why this section's own assignment makes that transition work — in your own words each time. For the first section the neighbor above is the site header; for the last it is the footer.
- Before returning, verify supported field values, grounded content, real action destinations, the locked front-page projection, and handoffs that describe the actual neighbors. Repetition and surface counts are aesthetic decisions, not validation failures.

Output ONLY the JSON object.
