<!-- cache-layer:site -->
{{site_context}}

<!-- cache-layer:unit -->
You are the design lead and a WordPress block-theme developer. Compose ONLY this page's opening hero as Gutenberg block markup. The site's concept and requested visual language determine this opening, not a catalog layout. Inner pages belong to the same visual family but need not repeat the home page's arrangement or headline.

THIS PAGE: "{{page_title}}" ({{page_path}})
OUTLINE:
{{outline}}

VALID SITE PAGES:
{{site_pages}}

HERO BRIEF:
- Title: {{section_title}}
- Slug: {{section_slug}}
- Purpose: {{section_purpose}}
- Content: {{content_notes}}

CONCEPT-LED COMPOSITION:
{{hero_blueprint}}

SHARED HEADER AND ACTION CONTRACT:
{{above_fold_contract}}

FOLLOWING SECTION:
{{neighbors}}

Design decisions:
- The requested style must be recognizable in composition, image choice, type and color together. Do not substitute a generic business-site treatment or repeat decorative ::before/::after shapes. Photography, illustration and generated artwork are all available; choose a medium that carries this visual language.
- Execute the blueprint's composition and rationale in the site's typography, palette, surfaces and image language. Refine the arrangement where needed to make the concept work in supported blocks. Do not default to a centered slogan and photograph when the concept calls for something else.
- Include at least one image that meaningfully supports the site's concept. Choose its placement, scale and relationship to the copy; it need not sit beside the text or fill the background. Multiple images may have different supported aspect ratios. Compose each requested image for its actual slot.
- Choose alignment, equal or unequal columns, framing, spacing and the relationship between text and media deliberately. A framed hero is valid. Typography, an ordered image sequence, or several coordinated content groups can carry the opening. These are possibilities, not a menu or a requirement to be unusual.
- Let useful content determine the text arrangement. Labels, credits, a secondary heading, several short paragraphs, or a meaningful divider are valid when grounded in the brief. Avoid filler, redundant claims and invented proof. Use one clear H1; choose its theme font-size preset for the composition rather than always forcing display scale.
- Readability and responsive behavior are requirements, not a universal first-screen height. Establish the main idea promptly; a deliberately longer opening is valid. Keep meaningful reading order in the DOM, use wrapping/stacking core layouts on narrow screens, and avoid clipped words, accidental overflow or fixed-height containers that hide content. Do not rely on essential absolute positioning or rotated reading text.

Delivery requirements:
- Return one root wp:group with matching anchor/id "{{section_slug}}", a layout suited to the composition, and className "hero-composition--authored hero-mobile--authored" in both block attributes and saved HTML. Add semantic design-* classes where the shared CSS step should realize custom composition. These identify ownership, not a prescribed shape. Nested groups may use supported flex, grid, flow or constrained layouts. Use supported block attributes and theme presets; mirror attributes into the saved markup.
- Use valid core blocks: group, cover, columns/column, heading, paragraph, buttons/button, image, media-text, spacer, separator, list/list-item, quote. Do not emit navigation, header/footer landmarks, template parts, html/body, forms, scripts or event handlers. The header is authored separately.
- Coordinate with the header contract's actual mode, foreground/protection tokens and safe top region. An overlay header requires an uninterrupted, verifiably protected top surface; never place essential text underneath it. Use the assigned token as overlayColor with dimRatio at least 40 in ten-point increments when that protection is required. Otherwise choose image protection for readability against the actual pixels.
- The contract owns the primary action's exact label and destination: include it once when non-null, and do not invent an action when null. Use the site's CTA treatment and accessible link text. CTA construction is global: do not add local fill, ink, border or padding. A committed block-style CTA may span its hero container when intentional; other CTA styles keep their intrinsic construction. Ordinary content links may reference valid site pages. No dead href="#" links or invented routes.
- Use the supplied brand identity intentionally. It may be the hero statement when that is the concept; avoid accidental repetition of the header's copy. Supporting text should add information.
- Facts, dates, prices, addresses, contact details, URLs and claims must come from the supplied SITE SPEC. Never invent an email, street address, phone number, or URL. Do not invent credibility or business facts. Write all visitor-facing copy in {{language}}.
- Keep readable foreground/background contrast, keyboard access and the site's motion commitment. Optional bundled motion classes may support the composition; none means no motion, minimal means hover only. Do not author animation CSS or script. Motion must never be necessary to access content.
- Optional `hero-entrance` belongs on one primary group. Do NOT automatically pair `hero-entrance` with `ken-burns`. At most two entrances and one ambient effect; choose only the bundled classes named in the design direction. Never combine effects that compete for the same transform.

{{image_instructions}}

{{block_markup_output_contract}}
