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

You are also the page's art director: each section will be built independently and concurrently by a different author who sees only its own brief, so YOU own the page-level visual rhythm. Assign every section a layout archetype and a background treatment, and describe its seams, so adjacent sections never repeat compositions and the background bands pace the page deliberately.

Return a single JSON object with this exact shape:
{
  "sections": [
    {
      "slug": "hero",
      "title": "Short human title for the section",
      "type": "a short, specific semantic label; examples: menu, timeline, case-studies, process, services, gallery, testimonials, pricing, team, faq, contact, story",
      "purpose": "1 sentence: what this section is for and what the visitor should take away",
      "content_notes": "2-4 sentences of concrete guidance: the specific copy points, items, or layout idea for this section, grounded in the site spec (real facts where given)",
      "layout_archetype": "one of: full-bleed-cover, asymmetric-split, centered-stack, offset-grid, equal-card-grid, list-with-thumbnails, bento-grid, faq-split",
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
- faq-split — a two-region split: the leading region holds the heading, one lead line and at most one action; the trailing region is a native accordion of three to seven questions, each a collapsible item with its answer. For FAQ, "questions", objections and policy sections. Never plan the questions as paragraphs or a list.
- bento-grid — two card rows of unequal count (2 cards then 3, or 3 then 2) with exactly ONE card inverted as the highlight; for a set of four to six capabilities, features, proof points or awards that the brief wants scanned as tiles. Never for a flat set of equals (that is equal-card-grid) and never for long copy.

Section structure and types:
- List sections in their intended page order. The builder derives each section's structural role from that order after generation, so do not return a `role` field.
- `type` is an open-ended semantic label, always in English. Choose or invent the most specific short label for what the section actually contains; do not collapse a menu, timeline, case-study index, process, event calendar, or location guide into a generic bucket.
- The site's committed repeated-item idiom is `{{item_pattern}}`. Set `item_pattern` to exactly `"{{item_pattern}}"` on every genuinely list-like section: menus, catalogs, schedules, programs, archives, pricing/features/services sets, technical facts, skills/genres/amenities, directories, teams, timelines, FAQs, and similar repeated collections. Set it to null on heroes, stories, single CTAs, quotes, galleries without textual items, and other non-list sections. Treat testimonials and reviews as quote sections, not data: set their `item_pattern` to null unless the committed idiom is `card` — a testimonial repeats voices with attributions, not label/value facts, and a tabular idiom would force its portraits and quotes into ledger rows. Never choose a different idiom per section. Choose a layout archetype that can house the assigned idiom: `rule-row`, `spec-table`, and `tag-cluster` generally need a centered stack or an asymmetric split rather than an equal-card grid; `card` may use the card/grid archetypes.

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
- spacious — a deliberately slow, short text-led beat; ration this to the few
  moments where whitespace itself carries the composition

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
- The FIRST section is the page-opening hero. In a plan with two or more sections, the LAST provides a strong closing next step, while every section between them carries the page's content. A one-section plan is necessarily the opening hero.
- "slug" is lowercase a-z, 0-9 and hyphens only, unique across the list, and descriptive (the opening section's slug is "hero"; every other slug names its own content).
- "content_notes" must be specific to THIS site (use the spec's facts), not generic filler.
- "content_notes" must never include site chrome — no wordmark, site title lockup, navigation, or menu links, even if the design direction mentions them. The site header is a separate template part that renders above (or overlaid on) the hero; planning nav into a section produces a doubled header.
- "content_notes" must never propose decorative sequence or index numbers — no numbered section labels ("01 Collection"), no numbered eyebrows, no "01, 02, …" identifier columns on items, and no instruction to number cards or steps. A number is valid in the notes ONLY when the SITE SPEC itself asks for visible numbering, or when the number is real content from the spec (a price, a year, an address). Word the notes so ordered steps read as ordered through copy ("first", "then"), not through painted numerals.
- Never plan a footer or site-chrome section. The theme generates exactly one separate footer template part and appends it after this page's LAST section, so a section whose slug, title, or type is `footer`, `footer-info`, `site-footer`, or equivalent would produce a duplicate ending. Make the LAST section a page-owned next step that follows from THIS page's purpose and the spec's facts — not global navigation, legal links, or footer contact columns.
- Decorative ornaments (drawn flourishes, motif marks, illustrated icons) are NEVER planned as generated imagery — AI-generated ornaments come out off-palette and wobbly. Glyph marks are not a planning tool either: never plan decorative glyph marks — not as a list bullet, metadata separator, or a mark repeated before every heading. Plain rules, hairlines and underlines are never planned as imagery — they are borders/separators the section authors build with styles. Likewise, never plan words, names or calligraphic lettering as imagery (generated images garble glyphs): anything meant to be read is planned as real text, styled by the theme's typography. Planned imagery is content imagery only: hero covers, feature/gallery/card images, photographic bands.
- Variety is mandatory: NO layout_archetype may be used by two ADJACENT sections, and "equal-card-grid" may appear at most TWICE on the whole page.
- Variety is counted across the WHOLE page, not just between neighbors: no single layout_archetype may be used more than TWICE on a page of up to 8 sections (a third of the sections on longer pages). Alternating two archetypes down the page satisfies the adjacency rule and still produces a uniform page — it is rejected. A 6-section page needs at least three different compositions.
- The DESIGN DIRECTION's **Rhythm** governs every archetype and background choice below — it is the site-level intent these per-section picks express, so read it FIRST and plan the page against it rather than assigning each section in isolation. `stacked` leans on centered-stack and lets type scale carry the page; `alternating` gives consecutive sections visibly different compositions (archetypes, not backgrounds — the background rule below still governs those); `offset` favours asymmetric-split, and offset-grid where this site is eligible for it, while avoiding centered-stack; `interrupted` places at least one "image" or "contrast" full-bleed band per page against an otherwise steady stack; `banded` spends the page's allowance of contrast/tinted bands rather than carrying the page on archetype changes; `gallery` favours grid and image-led bands over text-led ones. One archetype used for most of a page is a failed plan under every rhythm except `stacked`.
- The DESIGN DIRECTION's **Density** biases vertical_density across the page. `expansive` — spend both spacious pauses, keep everything else `standard`, and never assign `compact`. `airy` — spend both spacious pauses, and prefer `standard` over `compact` for everything else. `measured` — `standard` throughout, with a spacious pause only where the composition genuinely needs one. `dense` — prefer `compact` wherever the content supports it, and use at most one spacious pause. `packed` — `compact` everywhere the content permits, and no spacious pauses. This is a bias, not an override: spacious stays an accent under every density, and the per-page caps below still hold.
- The DESIGN DIRECTION's **Text placement** governs every per-section `text_placement` assignment below the page-opening hero. Read it as a site-level intent, the same way Rhythm governs per-section archetypes: `left-column` keeps most copy on the leading grid edge; `centered` centers most short/text-led stacks; `split` favors two-zone bands and alternates the occupied side where semantics allow; `asymmetric-thirds` uses the second and third zones of wide bands so successive text masses do not repeat one axis. A section may take a deliberate exception when its content or archetype demands it, but repeating `left-column` on every below-fold section fails every commitment except `left-column`. The section author still enforces readable measure; this field moves the column, never widens it.
- `text_placement` remains REQUIRED on the first section because every section shares one schema. Echo the site-level commitment there, but do not use it to redesign the front-page hero: `hero_blueprint.text_anchor` and the locked hero projection remain that opening's authority. The placement becomes executable on the sections below it.
- Plan the background rhythm deliberately: `base` is the resting surface, with 1-2 purposeful `tinted`, `contrast`, or `image` beats placed for pacing where THIS page's content genuinely changes register. On every page of 5 or more sections, use AT MOST TWO non-base backgrounds across the whole page — never alternating stripes. More content does not earn more colors, and even a `multicolor` palette does not mean every section needs a different surface. The build demotes excess bands to `base`, so spend this budget on the two transitions that matter most. If the build later moves your closing section off the footer's surface, that one correction sits outside this budget.
- "Mostly base" is not "all base". A page of 5 or more sections MUST carry at least one "contrast", "tinted" or "image" band — a plan where every section sits on the page background is rejected. This matters MORE on longer pages, not less: a 7-section page with no band is one unbroken scroll of the same colour, which is exactly where a reader loses their place.
{{footer_surface_rule}}
- Plan the width rhythm the same way: centered-stack sections give the page its content-width beats; grid and cover archetypes carry wide or full bands. A page where every band renders at the same width reads flat — alternate deliberately.
- Plan horizontal copy rhythm independently from width rhythm. On a wide/full band, assign where its headline/intro column starts with `text_placement`; do not use full-width paragraphs as a shortcut. For `split` and `asymmetric-thirds`, state the occupied side/zone in `content_notes`, preserve reading order on mobile, and never invent filler copy or decorative empty text merely to balance the opposite zone.
- Plan vertical density across the WHOLE page. Start from the resting value the **Density** commitment sets above, then use `compact` for tall/image-dense sections and `spacious` only for short sections where the design direction explicitly needs a pause. Never make a long gallery or information-heavy section spacious, never assign spacious to adjacent sections, and use it at most twice on the page.
- Adjacent sections on the same guaranteed-continuous solid surface (`base` or `contrast`) share one seam budget: the deterministic rhythm pass removes the upper section's bottom padding and lets the lower section's top padding own that gap. Tinted gradients and image assets keep separate edges because two independently authored instances may differ. Write handoff prose with that distinction in mind rather than budgeting whitespace twice.
- An "image" background wraps whatever archetype the section uses inside a full-bleed cover band; pairing it with "full-bleed-cover" is the classic image-led band (a natural hero choice, not a redundancy), and it is the ONLY background a "full-bleed-cover" section may carry. Reserve "image" for the 1-2 sections where imagery should carry the band.
- When the DESIGN DIRECTION's **Canvas** is `framed`, "full-bleed" archetypes and bands still apply but render inside the page's mat (capped at wide width) rather than touching the viewport edge — plan the rhythm knowing every band below the hero keeps a visible margin of page background around it. The page-opening hero is exempt: it runs edge-to-edge on every canvas, and the mat begins with the second section.
- When the DESIGN DIRECTION's **Device** is not `none`, assign that device to at most ONE non-hero section by naming the class in that section's `content_notes` (e.g. "this band carries device--stamp"). Never the hero. Never two bands.
- "handoff" must name the actual neighbors' assignments — the archetype and background of the section above, the archetype and background of the section below, and why this section's own assignment makes that transition work — in your own words each time. For the first section the neighbor above is the site header; for the last it is the footer.
- Before returning, re-check the finished list top-to-bottom: if any two ADJACENT sections share a layout_archetype, if any archetype is used more than twice on the page, if "equal-card-grid" appears more than twice, or if a page of 5+ sections has every section on "base" or more than two non-base backgrounds, change one of them — the plan is rejected or deterministically repaired otherwise.

Output ONLY the JSON object.
