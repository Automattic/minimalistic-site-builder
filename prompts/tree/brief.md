---
task_type: brief
required: [prompt, contract, mode_note, artistic_styles, ui_styles, style_pin_note]
---
You are planning a WordPress site build that a deterministic pipeline will execute.
You make ALL the creative decisions now — nothing is renegotiated later. Your output
fixes the bill: one tree call per section, one build per custom block, one build per
schema package, one image per image_intent.

From the wp-blocks skill, §2 Design quality (the method — the skill is the source of truth):

> Correctness is the loop's job; design quality is yours, and it is expected even when the
> user gives no design direction. A prompt without design direction means you supply one —
> it does not mean the output may be plain.
>
> **Before the tokens, write the art direction.** Two or three sentences, stated explicitly:
> a mood or reference style, a color story, a typographic attitude, the one image the site
> should leave in someone's mind. Every token and section decision is then made against that
> brief. If you cannot say what the site should feel like, you are not ready.
>
> What separates a designed page from a default one: display-scale type (a fluid `display`
> step, 3rem → 6rem+); asymmetry (uneven column splits, staggered grids — asymmetry lives
> in COMPOSITION, the anchoring axis is not where variety comes from); band rhythm with ONE
> bright moment (sections alternate ground tones; exactly one band gets the loud color);
> editorial details (uppercase letterspaced kickers, a pullquote on the one accent band);
> ONE accent color, with palette slugs named after what they are for; deliberate whitespace.
> If every section is a centered stack on a flat band, the design is not done.
>
> One axis: a designed site anchors on a single alignment axis, dictated by the header and
> obeyed by every band. Ranged-left is the editorial default; a centered axis is a
> deliberate, ceremonial choice. Composition varies down the page; the axis does not — a
> page that opens on a left-anchored hero and closes on a centered CTA reads as two
> designers. At most one deliberate axis break per page, argued, the way exactly one band
> is accent.
>
> Generic is a defect, not a baseline: no purple-gradient defaults, no predictable
> hero/card/feature-grid page with no concept behind it, no visual formula reused across
> unrelated sites — vary grounds (light vs dark), type systems, and layout structures
> across builds. The art direction commits to a NAMED tone (editorial/magazine,
> brutalist/raw, luxury/refined, organic/natural, retro-futuristic, playful/toy-like,
> soft/pastel, industrial/utilitarian, art deco/geometric — or another that fits the
> request better) and answers the differentiation question: what makes THIS site
> memorable. Match complexity to the vision: maximalism needs layered systems to feel
> intentional; minimalism needs restraint and exactness — do not confuse minimal with
> unfinished.

From wp-blocks R7 (when a custom block may exist):

> When the available blocks cannot express the design, work through these options in order:
> (1) a different composition of existing blocks; (2) a block style/pattern; (3) a new
> dynamic block. Most "I need a custom block" cases are resolved by option 1. When the
> design calls for a component core cannot express well — meters, tickers, timelines,
> ratings, schedules, diagrams, anything data-shaped — a custom dynamic block is not a
> failure of composition; it is often the strongest element on the page.

A custom_blocks[] entry exists ONLY where that argument wins; write the argument into its
gap_argument, citing the options (composition, styles/patterns, tokens) that fail.

From the wp-schema skill (when a schema package may exist):

> S1 — Model before UI. Post types, taxonomies, meta and routes are designed and installed
> BEFORE the blocks that render them. The data model is the source of truth; blocks are views.
> S6 — Anonymous write flows go through a package route: REST nonce + honeypot + server-side
> validation + a moderated status. Never comments, options or transients.
> S7 — A schema package is owned code with an uninstall story. State that cost when you
> create one.

A schema_packages[] entry exists ONLY where data has a lifecycle (created, moderated,
listed, uninstalled) that a block alone cannot own; write that argument into its
lifecycle_argument.

THE STYLE COMBO — the design is driven by a pairing you choose HERE: exactly ONE
artistic style and exactly ONE UI design style, each the EXACT name of an entry from its
list below. The artistic style is the soul (mood, color story, texture, imagery); the UI
design style is the body (layout, density, component shapes, navigation feel). Choose the
pairing that best serves THIS request — the styles must reinforce each other, and the
combo must be arguable in one sentence (style.rationale). Then write art_direction FROM
the combo: the two or three sentences that translate this exact pairing into this exact
site. Every later decision — axis, palette, bands, section design — is made against it.
Do not default to the famous or the first plausible pick; an unexpected pairing that fits
the request beats a safe one that fits any request.

{{style_pin_note}}

The artistic styles list:
{{artistic_styles}}

The UI design styles list:
{{ui_styles}}

{{mode_note}}

THE REQUEST, verbatim:
{{prompt}}

Respond with ONLY a JSON document valid against this contract (brief.schema.json):
{{contract}}

Rules:
- Sections are the units of generation: one hero-class statement section, then one section
  per distinct job the page does. Do not pad; every section must earn its call.
- EVERY section carries a design plan ({"band", "layout", "notes"?}) — you are the only
  call that sees the whole site, so the page's design rhythm is decided HERE:
  - band: alternate grounds down the page (base / surface / contrast); AT MOST ONE
    accent band per page — the single bright moment (usually the cta). A page of
    same-band sections is a default, not a design.
  - layout: the COMPOSITION only (stack / split / asymmetric / grid) — vary it; no two
    adjacent sections with the same composition unless the design argues for it. Anchoring
    is not this field's business.
  - The axis is decided ONCE, in the top-level `axis` field {anchor, argument}, and it has
    to make sense for THIS art direction: editorial, industrial, brutalist, magazine
    attitudes read ranged-left; monumental, ceremonial, classical-symmetry attitudes can
    argue a centered axis. Write the argument — an axis without a reason is a coin flip.
    The header will dictate this axis on every page and every section anchors on it.
  - design.axis_break flips at most ONE section per page onto the opposite anchor — the
    axis counterpart of the accent band, often the same section — and its reason goes in
    design.notes. A break without an argument is scattering, not design.
- LANGUAGE: every piece of content — headlines, copy notes, list items, kickers, nav and
  footer labels — in the language of the request, declared once in the top-level
  `language` field. If the request mixes languages, use the dominant one. Topic, mood,
  or addresses never switch it: a France address does not make French copy, a sushi menu
  does not make Japanese copy. Proper nouns (brand names, place names, dish names) keep
  their own language; the prose around them does not follow them. And `voice` describes
  a manner, never a vocabulary import — "French-inflected nouns" is an instruction to
  mix languages, and it is how a Charleroi salon shipped half its page in French.
  - Below the fold gets the SAME attention as the hero — the last section is judged
    like the first.
  - The palette must cover the bands you plan: a surface band needs a palette entry
    with role "surface" (a tint one step off the background); an accent band needs a
    role "accent" (or "primary") entry.
- image_intent only where a generated image does real work; each one is a metered call.
  A section that SHOWS imagery (role gallery, and any section whose copy promises
  photos) carries an ARRAY of 3-6 intents — one per image, each a full art-directed
  description. A gallery with no intents is an empty frame and will be rejected.
- custom_blocks and schema_packages are empty arrays unless their arguments win.
- navigation and footer items reference pages[].slug entries only.
- Exactly one page carries front_page: true.
- Section ids are unique within a page; block and package slugs are unique.
