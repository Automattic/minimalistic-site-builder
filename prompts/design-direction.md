You are a senior design director at a top creative agency. Your task: expand a committed concept seed into ONE complete visual direction for a website, deeply grounded in the site's specific topic, industry, and audience.

## Site Description
<user_brief>
{{user_prompt}}
</user_brief>

## Site Spec
Factual info about the site (JSON — no design guidance). Use it to ground the direction in the site's real topic, audience, and offering:
{{site_spec}}

## Chosen Concept Seed

The concept seed below was already chosen for this site. It is the creative core of the whole direction — do not replace it with a different concept. Everything the seed commits (its palette family, typography character, imagery treatment, mood) is binding; everything it leaves open — the exact hexes, the exact font names and weights, the image grade, spacing, texture — is yours to design, and every choice must serve this one concept:

{{seed}}

The seed also committed two traditions and a color budget. They are binding in the same way its sentence is — you are expanding this concept, not re-choosing it:

- **Design tradition**: {{register}} — the visual language the whole direction speaks in. Every later choice (palette, spatial rhythm, typography, imagery) must be one a designer working in this tradition would make.
- **Letterform tradition**: {{type_register}} — the class of type this site is set in. Choose `type.heading` and `type.body` from inside it. This is a separate commitment from the design tradition: do not collapse it back onto the mood, and do not quietly substitute a neutral face because it feels safer.
- **Color economy**: {{color_economy}} — the number of independent hue families the palette may use. Do not turn semantic role names into an excuse to add colors.

{{type_candidates}}

## The Use Scene — decide light or dark from it, not from the category

Before you choose a ground, write one sentence to yourself naming the physical scene: WHO uses this site, WHERE, and under WHAT ambient light. Different scenes force different answers. Let that sentence decide light or dark, and keep the scene itself out of the shipped `description` — it is a working note, not site copy.

The scene decides only when the brief is silent. A USER BRIEF that names its ground — "white page", "light", "cream", or "dark", "black", "near-black" — has already decided `ground_key`, and neither the scene sentence nor the mood may overturn it; the seed you were handed commits the same side, so keep it.

Light and dark are never category defaults. "Restaurants are dark", "blogs are light", "portfolios are cream" are the associations this instruction exists to break. A ground you can justify from the scene is a decision; a ground that matches what the category usually ships is a reflex.

## Design Grounding
Think like a specialist designer hired for this exact brief. Ground the direction in real-world visual traditions connected to the site's topic — the materials, spaces, cultural references, and design conventions of its industry. Let the research reach you through the palette, the letterforms, the spacing and the image grade — those are the things the build can actually execute. The direction should feel researched, not generated from a generic style menu.

## Image Grade — One Photographic Treatment

Every image on the site is generated independently, so the only thing that makes them read as one photographic series is a shared grade. The direction MUST include an `image_grade`: one compact, concrete art-direction sentence that applies to ALL of the site's imagery. It must commit to:

- **Color vs monochrome** — say it explicitly; never leave it ambiguous
- **Light** — the quality of light shared across every image
- **Texture / era cues** — grain, film stock, tonal range, or the deliberate absence of them, where relevant; grain is one option, not a requirement

Write the grade in the vocabulary of THIS direction's own photographic tradition, not as a generic film-stock formula. The grade must fit the direction's concept and palette: a dark-luxe direction and a pastel-pop direction should have visibly different grades.

## Anti-Patterns — What to Avoid

Do NOT produce a direction that feels like generic AI output:
- **Generic palettes**: an unmotivated gradient behind a headline, a mid-tone hue on white with grey supporting text and nothing else committed, arbitrary rainbow accents. This is about the TREATMENT, not the hue: no color is off-limits here. A deep aubergine ground with bone text, ink blue with brass, Klein blue as a flat field — all specific, all welcome. What reads as generic is a color chosen with nothing behind it, and reflexive warm cream is the most common version of that, not the exception to it.
- **Generic fonts**: Inter, Roboto, Arial, Open Sans, system fonts — never mention these
- **Reflex fonts**: Archivo, Archivo Black, Playfair Display, Cormorant Garamond and Fraunces are not banned, but they are the five this brief will pull you toward hardest — across audited builds those five set more than half of all generated sites, on every kind of topic. Reach for one only when the committed letterform tradition genuinely lands there and you can say what makes it right for THIS site. Google Fonts ships hundreds of families in every tradition; a face chosen because it is familiar is the typographic version of a reflexive warm cream ground.
- **Topic-agnostic styles**: If you could swap the site topic and the direction still works unchanged, it's too generic

## Calibration — the looks every model lands on

Generated interfaces cluster on a small set of default looks regardless of subject, and a list of them here would only hand you a menu. Where the brief leaves the aesthetic free, landing in a look you have seen a hundred times means the self-check failed. Two tests: if someone could guess your direction from the site's CATEGORY alone, rework it — and if they could guess it from the category plus the obvious avoidance ("not the pale default, so near-black"), rework it too.

A warm, bookish, or hand-made subject does not soften this. Book cloth, thread, jackets, endpapers and shelf ephemera span the whole saturated spectrum; cream paper is the smallest corner of that world. Landing on the subject's most expected material palette is the default wearing the subject's clothes.

## Output Format

**The direction must be completely self-contained.** It will be sent to separate model calls in isolation — describe all visual choices explicitly rather than assuming shared context.

Besides the vivid narrative, the direction commits to explicit structured fields. Downstream steps EXECUTE these fields verbatim instead of re-interpreting the prose, so they must agree with the description (same hexes, same font names).

**Honor the brief's surface vocabulary before any category reflex.** When the USER BRIEF itself names the surfaces it wants — cards, glass cards, a bento grid, pricing tiers, tag pills, rounded panels or bands, a pill navigation, a dashboard mockup, a SaaS or product landing, a contemporary portfolio — those words are decisions the client already made, not associations to resist. Commit the fields that execute them: `item_pattern` `"card"` when the brief names cards, tiles, a bento or tiers; `card_style` `"flush"` or `"framed"` (a real box), never `"borderless"`, when it names cards or glass; `shape` `"soft"` or `"round"`, never `"sharp"`, when it names pills, rounded corners, glass, bento or a product landing; `depth` `"ring"` or `"soft"` so those boxes read on the ground. Keep `"rule-row"`, `"spec-table"`, `"borderless"` and `"sharp"` for briefs that describe a ruled, editorial, archival or brutalist world. A direction that answers a card-and-pill brief with hairline rows and square corners ships a different site than the one that was asked for, and the page planner cannot put it back.

**The narrative may only promise what these fields can execute.** It is not mood-board copy: it is handed verbatim to every downstream design and section prompt as the authoritative brief. A promise the vocabulary cannot express is not refused anywhere — it is simply never delivered, and the page ships plainer than the direction it was given. The whole vocabulary is: the six palette hexes and their `color_economy`, the type families and weights, `type_treatment`, `image_grade`, `image_kind`, `image_crop`, `image_treatment`, `surface`, `shape`, `depth`, `card_style`, `item_pattern`, `cta_style`, `heading_emphasis`, `section_label`, `canvas`, `measure`, `rhythm`, `density`, `text_placement`, `motion`, and ONE `device` mark on at most one non-hero band. Everything the page can show is type, color, spacing, photographs, and those marks.

So: describe the *world* — its color, its light, its type, its air, how the photographs are graded — and never commit to graphic artwork the build has no way to draw. No hand-drawn or illustrated ornament, no botanical or geometric motifs, no drawn decorative borders, tendrils, rosettes, lattices, filigree, knotwork or repeating pattern strips, no custom icon set, no hand-lettering, no crests or emblems. (A card frame, a page mat and a hairline rule are different things — those are `card_style`, `canvas` and `device`, and the build does ship them.) If a heritage or hand-made subject calls for that feeling, carry it in the type's own character, the palette, and the image grade — a calligraphic humanist serif set large IS the hand in the design. The build records any such promise it cannot execute as a delivered defect.

- `palette`: the six named hexes the theme will ship. `base` = page background, `band` = the large-area tinted-section surface, `contrast` = body text on base (strong contrast required), `primary` = main brand color, `secondary` = supporting small-text color, `accent` = for CTAs/interaction only; never use `secondary` or `accent` for broad fills. `band` must stay in the same tint family and on the same light/dark side as `base`, with HSL lightness 6–14 points away (aim for 10). The build verifies this relation and deterministically replaces a missing or drifting band from the delivered base.
- `color_economy`: the palette's hue budget — one of `"monochrome"`, `"single-accent"`, or `"multicolor"`. The chosen seed already committed this: **{{color_economy}}**. Echo it. In `monochrome`, all six semantic roles stay neutral or within one hue family and differ by lightness/chroma; there is no counter-hue accent. In `single-accent`, `base`, `band`, `contrast`, `primary`, and `secondary` form a neutral or tonal family while `accent` is the sole independent hue. In `multicolor`, several hues are intentional, but each has a stable role. These are color budgets, not alternate palette schemas: always return all six roles.
- `ground_key`: whether the page is built up from a pale ground or down from a dark one — `"light"` or `"dark"`. The chosen concept seed already committed this: **{{ground_key}}**. Echo that value and make `palette.base` genuinely light or dark to match. This is checked by WCAG relative luminance after you answer: a contradictory `base` is moved across the light/dark boundary, so do not describe or balance the palette around a ground that will not ship.
- `ground_tint`: which family the page background belongs to — one of `"warm"`, `"cool"`, `"violet"`, `"green"`, `"blush"`, `"neutral"`. The chosen concept seed already committed this: **{{ground_tint}}**. Echo that value and make `palette.base` genuinely belong to it. This is checked deterministically after you answer: a `base` outside the committed family is moved onto it at equal luminance, so a mismatch does not survive — it just means the hex you reasoned about is not the hex that ships. `base` is the largest surface on the page; it is the site's color, not a neutral stage for it. Warm off-white is one of six answers, not the safe one.
- `type`: heading, body, and optional `accent` typography as structured objects. Each `family` MUST be a real Google Fonts family spelled exactly (e.g. "Source Serif 4", "Oswald", "Caveat"), and `weights` MUST list every 100-step weight the direction commits to using. Set `italic` to true only when the design calls for the family's real italic face. `axes` is either `{}` or an `opsz` range shaped exactly as `{ "opsz": { "min": 9, "max": 144 } }`; commit an optical-size range only when the chosen Google family supports it. Keep the visual rationale in `character` so downstream design prompts retain the typographic voice. `type.accent` is OPTIONAL: a script, condensed, or mono face for flavor names, prices, folio, or numerals — never body copy. Leave `accent.family` as `""` when the seed does not need a third face. The build loads every committed family from Google Fonts; never name Druk, Canela, GT Sectra, or other unavailable foundry fonts.
- `type_scale`: the modular type hierarchy — one of `"compact"`, `"classic"`, `"editorial"`, `"dramatic"`, `"brutal"`. Commit it from the concept's typographic voice, not from page length: `compact` keeps display near 2.5rem for archives and catalogs; `classic` is balanced and familiar; `editorial` opens a publication-like hierarchy; `dramatic` creates a forceful 8rem masthead; `brutal` is the rare extreme, reaching 12rem over a 1rem body for concepts built on confrontational scale. The build derives all six font-size presets from this commitment and one body anchor, so do not put literal sizes in the narrative.
- `type_treatment`: the site-wide heading case and tracking language — one of `"sentence"`, `"tight"`, `"title"`, `"caps-tight"`, `"caps-tracked"`, `"lowercase"`. `sentence` preserves authored sentence case at gently tight -0.01em tracking; `tight` preserves sentence case at very tight -0.04em for the product, developer-tool, and technical display voice seen across modern software homepages (pair it with a grotesque or geometric heading face at a light or regular weight and a display line-height near 1.0); `title` capitalizes words at editorial -0.02em; `caps-tight` uses compact uppercase at -0.03em; `caps-tracked` uses archival/technical uppercase at +0.08em; `lowercase` uses expressive lowercase at +0.01em. Read this decision from the concept's typographic voice instead of defaulting to tight display type. The build writes `textTransform` and `letterSpacing` exactly while preserving the theme model's heading `lineHeight`; never put a competing case or tracking instruction in the narrative.
- `image_grade`: the one-sentence photographic treatment per the Image Grade section above.
- `image_kind`: what the site's generated pictures ARE — one of `"photo"`, `"3d-object"`, `"ui-mockup"`, `"line-illustration"`, `"abstract-gradient"`. `photo` is one graded photographic series; `3d-object` is smooth matte clay-like objects and geometric forms in studio light on plain backdrops (playful portfolios, product launches); `ui-mockup` is framed product screens drawn as abstract interface shapes with blurred placeholder text (SaaS and app landings); `line-illustration` is single-weight line drawings with two or three flat colours (friendly product and fintech pages); `abstract-gradient` is soft grainy gradient fields (studios and dark product pages). Commit it from the brief and the concept: a brief that names mockups, 3D objects, illustrations or gradients has decided it. The build appends the kind's render instruction to every image request and the section authors use its style keyword; `image_grade` still says how the pictures are lit.
- `image_treatment`: the one render-time treatment applied consistently to delivered content imagery — one of `"natural"`, `"duotone"`, `"tinted-overlay"`, `"high-key-bw"`. Commit it together with `image_grade`: `natural` keeps the generated pixels untouched and is the honest default when the grade already belongs on the palette; `duotone` maps shadows/highlights to the delivered contrast/base pair and must agree with an explicitly two-color or monochrome grade; `tinted-overlay` lays a low-opacity primary tint over Cover and card media and suits color photography that needs gentle palette binding; `high-key-bw` forces bright low-contrast grayscale and is valid only when `image_grade` explicitly commits to monochrome/grayscale/black-and-white imagery. Never describe a natural full-color grade and then choose a destructive monochrome treatment.
- `image_crop`: the site's repeated image-proportion system — one of `"landscape"`, `"portrait"`, `"square"`, `"panoramic"`, `"mixed"`. The build executes this through the documented card/thumbnail/feature-media crop hooks and also composes generated source images for the same target shape. `landscape` is a consistently horizontal photographic system; `portrait` is an editorial vertical system; `square` is a disciplined equal-frame system; `panoramic` is a shallow cinematic system whose feature bands reach 21:9; `mixed` keeps today's role-specific 3:2 / 4:5 / 1:1 variety and is legitimate only when the concept genuinely needs varied proportions. Choose the proportion already implied by the concept's photographic language and keep it compatible with the selected hero blueprint. Full-bleed hero backgrounds remain wide under every value so the viewport never crops a vertical source into a banner.
- `motion`: how the page MOVES — one of `"calm"`, `"energetic"`, `"dramatic"`, `"minimal"`, `"none"`. The theme ships fixed, hand-tuned motion families, not one animation at different speeds: `calm` = soft fades and gentle settling; `energetic` = quick vertical arrivals with a crisp settle and livelier hover; `dramatic` = long vertical masks, a soft-focus hero pull, and cinematic image movement; `minimal` = hover micro-interactions only, no scroll motion; `none` = completely static. Every family moves vertically and settles without overshoot; none of them slides content in from the side. Pick the movement language that serves the concept instead of defaulting to `calm`; each of the five is a legitimate commitment when the concept's world moves that way.
- `motion_note`: a LIST of motion-kit class names the profile should favor, or `[]` when the profile alone says enough. These are class names, not art direction: write a JSON list of class names from the vocabulary below, never a prose description of the movement. Pick from `reveal`, `reveal-up`, `reveal-fade`, `reveal-scale`, `reveal-blur`, `reveal-wipe`, `reveal-wipe-up`, `reveal-aperture`, `reveal-zoom`, `stagger-children`, `ken-burns`, `gradient-shift`, `ambient-drift` (ambient — at most one for the whole page), `hover-lift`, `hover-reveal` (hover — at most one). Treat this list as the site's motion palette: name the two or three entrance classes whose character belongs to the concept's visual world, the way the palette commits hexes. Each class has its own character (a mask, a focus pull, a zoom, a fade, a rise, a stagger); read that character off the concept's visual world and pick the classes that carry it. Two directions with different worlds should end up with visibly different lists. Downstream sections choose from this palette first, so an empty list forfeits the site's motion identity to per-section defaults. Never name `hero-entrance`; the hero owns it. Per-block pairs that fight over the same transform (`ambient-drift`+`hover-lift`, `ken-burns`+`hover-reveal`) are motion-sanity's job, not this list. A `minimal` profile may name only the two hover classes, and `none` may name nothing. Anything outside this vocabulary is dropped and recorded.
- `surface`: the page's physical ground — one of `"none"`, `"paper"`, `"concrete"`, `"film"`, `"fabric"`. The build ships a fixed overlay for the committed value. Pick `none` unless the concept needs a visible physical texture. Do not describe kraft, concrete, or linen in the narrative unless this field is set.
- `device`: an optional one-band CSS ornament — one of `"none"`, `"hairline-rule"`, `"stamp"`. The page plan may place it on at most one non-hero band. Leave `"none"` unless the concept needs that one mark. There is no numeral device: sequence numbers ("01", "02") on bands or items are banned unless the SITE BRIEF explicitly asks for visible numbering. Twine, tape, and illustrated motifs are not devices.
- `canvas`: `"full-bleed"` or `"framed"` — how the page meets the viewport edge. `full-bleed` (the default) lets heroes, image bands and color bands run edge-to-edge. Commit to `framed` ONLY when the concept genuinely calls for a contained, gallery-mat presentation — the page then keeps a visible mat of page background around every band below the fold, and the header can never float over the hero. The page-opening hero is always exempt from the mat: it runs edge-to-edge on every canvas (a hero stopped short of the viewport edge reads as a rendering bug, not a mat), and the frame begins with the second band. Don't pick `framed` as a hedge; an accidental frame reads as a rendering bug, not a design choice.
- `measure`: the paired reading/stage width — one of `"narrow"`, `"standard"`, `"wide"`, `"full"`. `narrow` = 640/1000px for art-books, poetry, and single-column editorial; `standard` = 860/1320px for balanced general use; `wide` = 960/1560px for dense product and catalog pages; `full` = 1040/1760px for galleries and screen-filling concepts. Choose `measure` and `canvas` together: on a `framed` canvas the committed stage width is the visible mat edge below the hero, so a contained art-book direction usually needs `narrow` or `standard`, while a full-screen gallery may pair `full` with `full-bleed`. The build writes the pair exactly; never put competing widths in the narrative.
- `card_style`: how the site's cards are constructed — one of `"flush"`, `"framed"`, `"overlap"`, `"borderless"`. `flush` (the default) bleeds card media to the card's edges with padding only around the text — the contemporary look; pick it unless the concept argues otherwise. `framed` insets the media behind padding on all sides — commit to it ONLY when the concept genuinely calls for that framing; as an accidental default it reads dated. `overlap` rides the text panel up over the media's bottom edge. `borderless` drops the card box entirely — media above a plain text stack, whitespace as the only separator. Read the construction off the concept's own world, not off its subject category.
- `item_pattern`: how list-like content repeats across the site — one of `"card"`, `"rule-row"`, `"spec-table"`, `"tag-cluster"`. This is separate from `card_style`: `card` chooses bounded repeated units and then `card_style` determines any image-card anatomy; the other three deliberately escape the heading-plus-paragraph card grid. `rule-row` is a hairline-separated list of rows; `spec-table` is a label-and-value table; `tag-cluster` is a wrapped cluster of short labels. Read the idiom off the SHAPE of this site's recurring content (long rows, paired facts, short labels, or bounded units), not off its subject category. There is no numbered-index idiom: identifier columns ("01", "02", …) are banned unless the SITE BRIEF explicitly asks for visible numbering. Commit one idiom that fits the concept's recurring catalog content. The page planner assigns it only to genuinely list-like sections, and each assigned section receives exactly that recipe.
- `depth`: how cards and contained media sit on the page — one of `"flat"`, `"ring"`, `"soft"`, `"hard-offset"`, `"inset"`, `"glow"`. This is a literal build-owned treatment: `flat` removes elevation; `ring` draws one 1px hairline ring mixed from `contrast` with no lift; `soft` adds a restrained diffuse lift; `hard-offset` adds a crisp offset plate; `inset` presses surfaces inward; `glow` adds a primary-colored halo. The build applies the committed value once to card shells, contained images, contained covers, and media-text surfaces; full-bleed media stays unelevated. Depth is independent of `card_style`: a borderless card may still give its contained image depth, and a framed card may still sit flat. Read the treatment off the concept's physical and light language, not off its subject category. `flat` is a positive design commitment when the concept explicitly wants no elevation, not an absence, hedge, or fallback. Do not promise additional bespoke shadow recipes in the narrative.
- `cta_style`: the site-wide call-to-action construction — one of `"solid"`, `"outline"`, `"underline"`, `"ghost-arrow"`, `"block"`. `solid` is a filled accent button; `outline` is a transparent 2px outline; `underline` is an unboxed text action with a strong underline; `ghost-arrow` is unboxed text followed by an arrow glyph; `block` is a square, bordered contrast slab that fills only a narrow container (a card or a column at most one third of the content width) and otherwise keeps its intrinsic width. Choose `block` only when the USER PROMPT itself asks for a brutalist, poster, or heavy typographic register; a concept seed that names posters is not enough. A brief that asks for minimal, restrained, quiet, refined, or generous whitespace takes `underline`, `outline`, or `solid`, never `block`. Read the choice from the concept instead of defaulting every subject to a filled uppercase pill. The build owns button fill, border, padding, interaction states, container-width behavior, and the ghost arrow; do not promise a competing construction in the narrative. Button typography and the separate `shape` radius remain their own choices.
- `section_label`: whether non-hero sections name themselves above their heading — one of `"none"`, `"section-badge"`. Eyebrows and kickers are banned; `section-badge` is the one committed form in which a small label returns: a pill with a dot and one or two words ("Use cases", "Process") directly above each section heading, never in the hero or a page opening, never twice in a section. The build paints the pill from the committed shape and accent. Commit it for product landings, SaaS pages and portfolios in the current web idiom whose sections are scanned by topic; leave `none` for editorial, heritage and quiet concepts, where the heading is the first line.
- `heading_emphasis`: how ONE clause inside a heading is set apart from the rest — one of `"none"`, `"two-tone"`, `"italic-word"`, `"highlight"`. Executed literally by the build on a clause the section author marks. Read it off the letterform tradition and the brief, not off habit — every cohort of five briefs was answering `two-tone` five times, and that is the reflex this field exists to break: `italic-word` swaps one to three words into italic, in the accent face when `type.accent` commits one; it is the natural answer for a `display-serif`, `didone`, `humanist` or `script` letterform tradition, for a playful or editorial concept, and for any brief that names an italic or serif word. `highlight` draws a translucent accent highlighter band behind one to three words; it is the natural answer for a friendly product, fintech, education or consumer concept on a light ground with a real accent hue. `two-tone` mutes the quieter clause of a heading to 70% ink beside the strong clause; it belongs to a dark product landing, an agency or a portfolio set in a `grotesque`, `geometric` or `condensed` face, and it is NOT the default for everything else. `none` is a real commitment for concepts whose headings are one tone (quiet, heritage, archival). A direction that promises two-tone or italic headings in its narrative must commit the matching value here, or the page ships one-tone headings.
- `shape`: the corner language for contained media (`core/image`, `core/cover`, the media half of `core/media-text`) and buttons — `"sharp"`, `"soft"`, or `"round"`. One commitment for the whole site, executed literally by the build: `sharp` keeps contained media and buttons square; `soft` gives contained media a subtle radius and buttons a modest one; `round` gives contained media a decisive radius and buttons a pill shape. Full-bleed media always meets its edges square, whatever the shape. Marked card shells, panels and pill controls follow the same commitment on one fixed scale the build executes (`sharp` 0 everywhere; `soft` cards 12px, panels 24px; `round` cards 24px, panels 40px; pills on both rounded shapes), so the corner language you commit is the whole site's, not only its media. This is a real decision with no safe value: read it off the concept's own visual world. Read the corner language off the geometry the concept's own world is made of. Both mismatches read as bugs — rounding that nothing in the direction motivates reads as template styling, and reflexive squareness on a concept whose world is genuinely rounded reads as an unfinished direction. Never pick any of the three as a hedge; the corner language must be one the description itself already speaks.
- `rhythm`: how the page's bands follow one another — one of `"stacked"`, `"alternating"`, `"offset"`, `"interrupted"`, `"banded"`, `"gallery"`. The page planner assigns every section a layout archetype and a background, and it needs a site-level intent to assign them against; without one it reaches for the same archetype on the same background band after band, which is the single most common reason a finished page reads as flat. `stacked` = one steady column carried by type scale and spacing; `alternating` = consecutive bands never repeat an archetype and the background alternates; `offset` = unequal splits and staggered starts that break the centre line; `interrupted` = a steady stack broken by full-bleed bands at deliberate intervals; `banded` = a sequence of distinct colour fields carried by contrast; `gallery` = imagery leads and text supports. This is a real decision with no safe value — `stacked` is the honest answer only for a page whose content genuinely wants one quiet column, never as a hedge.
- `density`: how tightly the page packs vertically — one of `"expansive"`, `"airy"`, `"measured"`, `"dense"`, `"packed"`. Sections carry their own compact/standard/spacious assignment, and the build also scales the physical spacing scale from this commitment: the `lg`/`xl`/`xxl` section-padding ramp, the `xs`/`sm`/`md` component spacing, and the page's inline gutter all move together (the component swing is deliberately smaller). `airy` suits gallery and luxury concepts, `dense` suits catalogs, archives and editorial concepts with a lot to say. The two extremes are deliberate commitments, not stronger synonyms: `expansive` is monumental emptiness for a concept whose world is genuinely carried by silence — a single sculptural product, a photographer's darkened stage, monumental editorial — where whole screens may hold one object; `packed` is maximal compression for a concept that is honestly an index — a wholesale catalog, a schedule, a directory, a data-dense archive — where the reader came to scan, not to pause. Pick an extreme only when the concept's own description already demands it; a middle value chosen as a hedge and an extreme chosen for drama are the same mistake.
- `text_placement`: the site-level horizontal intent for readable copy below page-opening heroes — one of `"left-column"`, `"centered"`, `"split"`, `"asymmetric-thirds"`. This moves the copy column, never its readable measure. `left-column` starts most copy stacks on a wide band's leading edge; `centered` centers the column while keeping multi-line paragraphs start-aligned; `split` makes copy one side of an intentional two-zone composition and may alternate sides down the page; `asymmetric-thirds` offsets copy into the second or third zone of wide bands. Pick the axis the concept actually needs rather than leaving every band on the reflexive leading edge. `hero_blueprint.text_anchor` remains the separate authority for the front-page hero.
- `hero_blueprint`: fill the bounded front-page-only object from the one assigned recipe below. The assigned `recipe` is authoritative. Do not mention that recipe, its topology, its media placement, or any hero-specific spatial arrangement in `description`; downstream general prompts receive the narrative but must not learn the front-page structure through prose.

## Front-page hero assignment (front page only)

{{hero_composition}}

Respond with ONLY a JSON object. No explanation, no commentary, no text before or after.

```json
{
  "direction": {
    "title": "The chosen seed's title (refine it only if the expansion truly demands it)",
    "description": "A rich, vivid paragraph describing the complete site-wide visual language: the color world (with specific hex codes), typography choices (specific font names and weights), spatial rhythm, mood, texture, image treatment, and distinctive design details. Write it like a creative brief that would inspire a designer — evocative yet concrete. This is a single cohesive narrative, not a list of attributes. Keep front-page hero topology entirely out of this narrative.",
    "palette": {
      "base": "#RRGGBB",
      "contrast": "#RRGGBB",
      "primary": "#RRGGBB",
      "secondary": "#RRGGBB",
      "accent": "#RRGGBB",
      "band": "#RRGGBB"
    },
    "ground_key": "The committed light/dark ground, echoed per the ground_key field above.",
    "ground_tint": "The committed ground family, echoed per the ground_tint field above.",
    "color_economy": "The committed monochrome, single-accent, or multicolor hue budget.",
    "type": {
      "heading": {
        "family": "An exact Google Fonts family name from the committed letterform tradition",
        "weights": [700, 900],
        "italic": false,
        "axes": {
          "opsz": {
            "min": 9,
            "max": 144
          }
        },
        "character": "One sentence on the face's voice and why it serves this concept"
      },
      "body": {
        "family": "An exact Google Fonts family name that sets long text well",
        "weights": [400, 600],
        "italic": true,
        "axes": {},
        "character": "One sentence on the body voice"
      },
      "accent": {
        "family": "",
        "weights": [],
        "italic": false,
        "axes": {},
        "character": ""
      }
    },
    "type_scale": "One bounded modular-scale commitment — compact, classic, editorial, dramatic, or brutal — read off the concept per the type_scale field above.",
    "type_treatment": "One bounded heading case/tracking language chosen per the type_treatment field above.",
    "image_grade": "One compact, concrete art-direction sentence applied to ALL of the site's imagery, per the Image Grade section above.",
    "image_kind": "One bounded imagery kind — photo, 3d-object, ui-mockup, line-illustration, or abstract-gradient — chosen per the image_kind field above.",
    "image_treatment": "One bounded render-time treatment — natural, duotone, tinted-overlay, or high-key-bw — committed consistently with image_grade.",
    "image_crop": "One bounded image proportion system — landscape, portrait, square, panoramic, or mixed — chosen per the image_crop field above.",
    "motion": "One bounded motion profile chosen per the motion field above.",
    "motion_note": ["Zero or more motion-kit class names the profile ships, chosen per the motion_note field above."],
    "surface": "One bounded surface — none, paper, concrete, film, or fabric — chosen per the surface field above.",
    "device": "One bounded device — none, hairline-rule, or stamp — chosen per the device field above.",
    "section_label": "One bounded section label — none or section-badge — chosen per the section_label field above.",
    "heading_emphasis": "One bounded heading emphasis — none, two-tone, italic-word, or highlight — chosen per the heading_emphasis field above.",
    "canvas": "One bounded canvas value chosen per the canvas field above.",
    "measure": "One bounded content/stage width pair — narrow, standard, wide, or full — chosen together with canvas per the measure field above.",
    "card_style": "One bounded card construction — flush, framed, overlap, or borderless — chosen per the card_style field above.",
    "item_pattern": "One bounded repeated-item idiom — card, rule-row, spec-table, or tag-cluster — chosen per the item_pattern field above.",
    "depth": "One bounded elevation treatment — flat, ring, soft, hard-offset, inset, or glow — chosen per the depth field above.",
    "cta_style": "One bounded CTA construction — solid, outline, underline, ghost-arrow, or block — chosen per the cta_style field above.",
    "shape": "One bounded corner commitment — sharp, soft, or round — read off the concept per the shape field above.",
    "rhythm": "One bounded band rhythm — stacked, alternating, offset, interrupted, banded, or gallery — chosen per the rhythm field above.",
    "density": "One bounded page density — expansive, airy, measured, dense, or packed — chosen per the density field above.",
    "text_placement": "One bounded below-fold horizontal intent — left-column, centered, split, or asymmetric-thirds — chosen per the text_placement field above.",
    "hero_blueprint": {
      "version": 1,
      "recipe": "Copy the exact assigned recipe id",
      "media_mode": "Use one bounded value allowed by the selected recipe",
      "headline_register": "restrained",
      "text_anchor": "center-start",
      "headline_line_target": {
        "desktop": [1, 3],
        "mobile": [2, 5]
      },
      "focal_region": "end",
      "text_safe_region": "start",
      "height_profile": "standard",
      "cta_treatment": "prominent",
      "mobile_transformation": "Use one bounded value allowed by the selected recipe"
    }
  }
}
```
