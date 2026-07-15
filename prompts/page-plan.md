You are the content strategist and design lead for ONE page of a new multi-page website. Plan THIS page as an ordered list of distinct sections that, together, do this page's job completely. Do NOT write block markup — only plan the sections.

USER PROMPT:
"{{user_prompt}}"

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

Tailor the section choice to the site's `site_type` / `area` and THIS page's purpose, for example:
- portfolio → project or photo gallery, selected work, about the maker
- SaaS / product → feature grids, how-it-works, pricing tiers
- restaurant → menu highlights, about/story, reservations or visit
- agency → case-study cards, services grid, client logos
- blog / news → latest posts, categories, featured story
Pick what genuinely fits THIS page rather than a rigid template.

You are also the page's art director: each section will be built independently and concurrently by a different author who sees only its own brief, so YOU own the page-level visual rhythm. Assign every section a layout archetype and a background treatment, and describe its seams, so adjacent sections never repeat compositions and the background bands pace the page deliberately.

Return a single JSON object with this exact shape:
{
  "sections": [
    {
      "slug": "hero",
      "title": "Short human title for the section",
      "type": "one of: hero, features, about, services, gallery, testimonials, pricing, team, faq, cta, contact, content",
      "purpose": "1 sentence: what this section is for and what the visitor should take away",
      "content_notes": "2-4 sentences of concrete guidance: the specific copy points, items, or layout idea for this section, grounded in the site spec (real facts where given)",
      "layout_archetype": "one of: full-bleed-cover, asymmetric-split, centered-stack, offset-grid, mixed-width-editorial, equal-card-grid, list-with-thumbnails",
      "background": "one of: base, tinted, contrast, image",
      "vertical_density": "one of: compact, standard, spacious",
      "handoff": "1 line: what visually sits immediately above and below this section (each neighbor's background + archetype), so the transitions are designed rather than accidental"
    }
  ]
}

Layout archetypes (pick the one that best serves each section's content):
- full-bleed-cover — a full-width cover image or gradient with overlaid text
- asymmetric-split — two unequal columns (e.g. 34/66 or 40/60), never 50/50
- centered-stack — a single constrained, centered column carried by type and whitespace
- offset-grid — a staggered grid whose items deliberately don't line up in neat rows
- mixed-width-editorial — a magazine-like row mixing wide and narrow items
- equal-card-grid — the classic equal-height card row
- list-with-thumbnails — stacked rows, each a small image beside text

Background treatments:
- base — the default page background
- tinted — a subtle tint (secondary color or soft gradient preset)
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

Rules:
- LANGUAGE: every "title" and every copy point inside "content_notes" is written in {{language}} — section titles become on-page headings and the notes seed each section's copy, so a plan in the wrong language leaks into the page. "slug" stays lowercase a-z ASCII regardless (transliterate).
- IDENTITY: where the plan names the brand or the person, use the spec's `name` / `persona_name` exactly, and any planned email uses the spec's `email_domain` — never invent alternates.
- THIS PAGE ONLY: plan only content that belongs here per this page's purpose and the SITE PAGES list. Content that lives on a sibling page gets, at most, a teaser that links onward — "content_notes" may reference another page by its path (e.g. "closes with a link to /menu/").
- The FIRST section must be a "hero" and the LAST should be a strong call-to-action ("cta" or "contact").
- "slug" is lowercase a-z, 0-9 and hyphens only, unique across the list, and descriptive (e.g. "hero", "menu-highlights", "meet-the-team").
- "content_notes" must be specific to THIS site (use the spec's facts), not generic filler.
- "content_notes" must never include site chrome — no wordmark, site title lockup, navigation, or menu links, even if the design direction's hero composition mentions them. The site header is a separate template part that renders above (or overlaid on) the hero; planning nav into a section produces a doubled header.
- Decorative ornaments (drawn flourishes, glyphs, motif marks) are rationed at the page level: if the design direction's signature device calls for one, plan at most ONE such motif for the whole page and describe it identically wherever it recurs, so every section reuses the same asset. Plain rules, hairlines and underlines are never planned as imagery — they are borders/separators the section authors build with styles. Likewise, never plan words, names or calligraphic lettering as imagery (generated images garble glyphs): anything meant to be read is planned as real text, styled by the theme's typography.
- Variety is mandatory: NO layout_archetype may be used by two ADJACENT sections, and "equal-card-grid" may appear at most TWICE on the whole page.
- Plan the background rhythm deliberately: mostly "base" with 1-2 "contrast" or "image" bands placed for pacing (e.g. under the hero's fold and before the closing CTA) — never alternating stripes, and let the design direction's mood decide how heavy the dark/image bands feel.
- Plan the width rhythm the same way: centered-stack sections give the page its content-width beats; grid and cover archetypes carry wide or full bands. A page where every band renders at the same width reads flat — alternate deliberately.
- Plan vertical density across the WHOLE page. Use `standard` by default, `compact` for tall/image-dense sections, and `spacious` only for short sections where the design direction explicitly needs a pause. Never make a long gallery or information-heavy section spacious, never assign spacious to adjacent sections, and use it at most twice on the page.
- Adjacent sections on the same guaranteed-continuous solid surface (`base` or `contrast`) share one seam budget: the deterministic rhythm pass removes the upper section's bottom padding and lets the lower section's top padding own that gap. Tinted gradients and image assets keep separate edges because two independently authored instances may differ. Write handoff prose with that distinction in mind rather than budgeting whitespace twice.
- An "image" background wraps whatever archetype the section uses inside a full-bleed cover band; pairing it with "full-bleed-cover" is the classic image-led band (a natural hero choice, not a redundancy). Reserve "image" for the 1-2 sections where imagery should carry the band.
- When the DESIGN DIRECTION's **Canvas** is `framed`, "full-bleed" archetypes and bands still apply but render inside the page's mat (capped at wide width) rather than touching the viewport edge — plan the rhythm knowing every band keeps a visible margin of page background around it.
- "handoff" must name the actual neighbors' assignments (e.g. "Sits between the full-bleed image hero above and the base-background menu grid below; this contrast band gives the eye a rest between two image-heavy blocks."). For the first section the neighbor above is the site header; for the last it is the footer.
- Before returning, re-check the finished list top-to-bottom: if any two ADJACENT sections share a layout_archetype, or "equal-card-grid" appears more than twice, change one of them — the plan is rejected otherwise.

Output ONLY the JSON object.
