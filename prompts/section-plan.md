You are the content strategist and design lead for a new website's landing page. Plan the landing page as an ordered list of distinct sections that, together, tell a complete and compelling story for this site. Do NOT write block markup — only plan the sections.

USER PROMPT:
"{{user_prompt}}"

SITE SPEC (JSON):
{{site_spec}}

DESIGN DIRECTION (the committed creative concept for THIS site — let it shape the section ideas and flow):
{{design_direction}}

Use the spec's "sections" list as a starting point, but improve it: add, reorder, split, or rename sections so the page is richer and flows well. Let the design direction's signature device and mood inform which sections you choose and how they're framed. Aim for 5 to 8 sections.

Return a single JSON object with this exact shape:
{
  "sections": [
    {
      "slug": "hero",
      "title": "Short human title for the section",
      "type": "one of: hero, features, about, services, gallery, testimonials, pricing, team, faq, cta, contact, content",
      "layout": "one of: image-left, image-right, full-bleed, split-screen, asymmetric-grid, centered, overlap, stacked-cards",
      "purpose": "1 sentence: what this section is for and what the visitor should take away",
      "content_notes": "2-4 sentences of concrete guidance: the specific copy points, items, or layout idea for this section, grounded in the site spec (real facts where given)",
      "wants_image": true
    }
  ]
}

Rules:
- The FIRST section must be a "hero" and the LAST should be a strong call-to-action ("cta" or "contact").
- "slug" is lowercase a-z, 0-9 and hyphens only, unique across the list, and descriptive (e.g. "hero", "menu-highlights", "meet-the-team").
- "layout" picks the section's structural treatment. VARY it across the page so the page differs structurally, not just in copy — do NOT repeat the same treatment back-to-back, and do not make every section "centered". Choose a treatment that fits the content (e.g. a media-rich story reads well as "image-left"/"image-right", a portfolio or menu as "asymmetric-grid"/"stacked-cards", an immersive hero or CTA as "full-bleed", a strong statement as "split-screen" or "overlap"). Let the design direction's shape language steer the mix.
- Set "wants_image" to true only where imagery genuinely strengthens the section (hero, gallery, feature cards, team), false for text-heavy sections (faq, simple cta).
- "content_notes" must be specific to THIS site (use the spec's facts), not generic filler.

Output ONLY the JSON object.
