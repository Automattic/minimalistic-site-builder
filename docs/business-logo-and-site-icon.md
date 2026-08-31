# Business logo and site icon during theme generation

**Linear:** [BIGR-957](https://linear.app/a8c/issue/BIGR-957/generate-business-logo-and-site-icon-during-theme-generation)
**Branch:** `bigr-957-generate-business-logo-and-site-icon-during-theme-generation`

## Goal

For business-like sites, generate one small square brand mark during image generation, import it into the media library, and set it as both the WordPress custom logo and the site icon. In the header, the mark replaces the site title while it is set; if the mark is missing or later removed, the title shows again. The footer still shows the site title.

## Scope

In this repository only. Easy Editor placeholder work is out of scope. wpcom apply (`site_builder_apply_site_identity`, `big_sky_build_wow_plugin_files_for_delivery`) is not changed; Dotcom gets the attachment because the seeder falls back to the already-delivered `theme/assets/` copy.

## Approach

Piggyback on the existing image pipeline. No new graph step. wpcom's finish span already runs `generate-images`.

```
site-spec
    → BusinessSite::matches()?
collect-images
    → if yes, append images.json spec: site-logo.png
header-hero
    → business sites: ensure wp:site-logo immediately before wp:site-title,
      tagged className=site-logo-mark
assemble-pages
    → copy role:site-logo into plugin/images.json
generate-images
    → key out white, square-pad the mark, write theme/assets/site-logo.png
    → opaque (keying wiped out)? drop the role row, ship nothing
    → copy surviving manifest files to plugin/images/
plugin activation
    → import (plugin/images/, else theme/assets/)
    → custom_logo + site_icon = that attachment
    → CSS hides header site-title while the injected mark renders an img
```

## BusinessSite

New helper `src/BusinessSite.php`, same call shape as `PhotographySite`.

```php
public static function matches(array $siteSpec, string $prompt = ''): bool
```

`$prompt` is not part of the business decision, but it **is** forwarded to the photography check: `PhotographySite::matches($siteSpec, $prompt)`. Both existing callers pass the prompt (`SectionComposition.php:372`, `FixBlocksStep.php:645`), and a photographer whose spec says `site_type: studio` carries the only "photographer" signal in the prompt. Dropping it there would classify them as a business.

**No** when:

- `persona_name` is a non-empty string (personal portfolio, CV, personal blog).
- `PhotographySite::matches($siteSpec, $prompt)` is true (photography / gallery).

**Yes** when the concatenated lowercase text of `site_type`, `area`, `topic`, `title`, and `name` matches:

```
/\b(?:business(?:es)?|storefronts?|shops?|stores?|retail(?:ers?)?|restaurants?|cafés?|cafes?|baker(?:y|ies)|bars?|salons?|spas?|clinics?|gyms?|studios?|agenc(?:y|ies)|consultanc(?:y|ies)|firms?|saas|hotels?|boutiques?)\b/u
```

Every token is a noun for a kind of business. `services?` was considered and dropped: it is a noun for a kind of sentence, and turns up in `topic` prose on sites that are not businesses.

Pinned cases:

| Spec | Result |
|---|---|
| `site_type: business storefront`, bakery area | true |
| restaurant / cafe / café | true |
| hair salon, consulting firm, hotel | true |
| `persona_name` set | false |
| portfolio, blog, landing page | false |
| photography / gallery (`PhotographySite`) | false |
| `site_type: studio` + "photographer" in the prompt only | false |

Allowlist is conservative. Widening it is a later change.

## Image spec

Reserved filename: `site-logo.png`. Content filenames are subject-derived; this name is reserved for the synthetic mark.

`collect-images` appends the spec when `BusinessSite::matches()`. It reads `siteSpec.json` (add to `StepDeclaration::reads`). If `site-logo.png` is already in `images.json` from markup, do not overwrite that row and do not tag it `site-logo` — skip the synthetic mark and warn. A content image must not become the site logo.

```
file='images.json'; asset='site-logo.png'; delivered no synthetic mark; disposition=the reserved site-logo filename was already collected from page markup
```

Shape (same keys as a collected placeholder, plus `role`):

| Field | Value |
|---|---|
| `filename` | `site-logo.png` |
| `src` | `theme:./assets/site-logo.png` |
| `subject` | composed from `area`, `topic`, and `visual_vibe` only — never `name` or `title` |
| `pageContext` | `site logo and site icon, small square mark in the header` |
| `style` | `flat` |
| `aspectRatio` | `square` (Gemini `1:1`) |
| `status` | `pending` |
| `sources` | `[]` |
| `role` | `site-logo` |

`.png` selects the existing transparent pipeline (`ImagePromptComposer` white isolation, `ImageTransparency` keying, 1K). The subject must forbid letters, numerals, wordmarks, signage, and the site name (BIGR-768). Example subject:

> simple geometric brand mark for a neighborhood bakery, warm rustic mood, single ink, no letters, no numerals, no wordmark, no signage

`GenerateImagesStep::siteContext()` already omits the site name. Do not pass the name into the logo subject.

## Site-logo post-process

The transparent pipeline is tuned for decorative flourishes, and two of its behaviours are wrong for an asset that also has to be a favicon. Both are handled in `GenerateImagesStep::finish()`, in the existing `image/png` branch immediately after `ImageTransparency::keyOutBackground()` (`GenerateImagesStep.php:571-576`), gated on `($specs[$i]['role'] ?? '') === 'site-logo'`.

### Square canvas

`ImageTransparency::trimToInk()` (`ImageTransparency.php:227`) crops the keyed mark to its ink bounding box plus a ~2% pad, so the delivered PNG has whatever aspect the mark happens to have and can be well under 512px on a side. WordPress's site icon wants a square at least 512×512, and `update_option('site_icon', $id)` set directly — no customizer crop, so no `site_icon-*` intermediate sizes — leaves `get_site_icon_url()` soft-resizing the source. A non-square mark comes out squashed.

New: `ImageTransparency::padToSquare(string $pngBytes, int $minSide = 512): string`.

- Side `S = max(width, height, $minSide)`.
- Centre the existing bitmap on a transparent `S × S` canvas. **No resampling** — padding only, so the mark ships at native resolution and the transform is lossless and deterministic.
- Fails soft like the rest of the class: any error returns the input unchanged.

### Keying wipeout

`keyOutBackground()` abandons the key and returns its input when the alpha mean falls under 0.05% of quantum (`ImageTransparency.php:142-144`) — a true wipeout keyed everything away. For a flourish that degrades quietly. For a mark that replaces the site title it delivers an **opaque white square in the header**, with the title hidden behind it.

New: `ImageTransparency::isKeyed(string $pngBytes): bool` — true when all four corner pixels are fully transparent. A successful key always ends in `trimToInk()`, which guarantees a transparent pad of at least 2px on every side, so opaque corners mean the key was abandoned (or the ink runs edge to edge, which is equally unusable as a mark).

When `isKeyed()` is false for the site-logo role: warn, `unset($specs[$i]['role'])` so `images.json` stops claiming it, and let the rest of `finish()` run as normal. The file still lands in `theme/assets/` — it is a completed generation, just not a usable mark.

### Reconciliation

`shipPluginImages()` (`GenerateImagesStep.php:264`) currently copies every manifest filename that exists. It gains one rule: a `plugin/images.json` row tagged `role: site-logo` survives only while the matching `images.json` spec still carries `role: site-logo`. Otherwise the row is dropped from the manifest and the file is not copied, so the seeder never sees it. `plugin/images.json` joins the step's `writes`.

`images.json` is written (`writeJsonAtomic`, `GenerateImagesStep.php:247`) immediately before `shipPluginImages()` runs, so the role is already current by then.

### Known limitation

The single attachment stays transparent, which is right for the header mark and fine for a browser tab. iOS composites a transparent Apple touch icon onto black. Shipping a second, flattened `site-icon.png` would mean a second attachment and a second manifest role; not worth it for this change.

## plugin/images.json

`AssemblePagesStep::contentImages()` keeps scanning page markup, then unions any `images.json` entry whose `role` is `site-logo`. The header is a template part, not page content, so the mark is never referenced by the markup this function scans — the union is the only way it reaches the manifest.

Each manifest row:

```json
{ "filename": "site-logo.png", "title": "Site logo", "role": "site-logo" }
```

The title is fixed, not the subject. `title` becomes the attachment's `post_title` at import (`ScaffoldPluginStep.php:331`), and the subject is prompt prose — "simple geometric brand mark for a neighborhood bakery, warm rustic mood, single ink, no letters…" is a bad media-library title.

`role` is optional. Existing content rows omit it.

## Header

`HeaderHeroStep`, business sites only:

- If the header has no `wp:site-logo`, insert `<!-- wp:site-logo {"width":48,"shouldSyncIcon":true,"className":"site-logo-mark"} /-->` immediately before the first `wp:site-title` (sibling, same parent). If there is no site-title, insert at the start of the identity cluster (`site-title` / `site-logo` / `site-tagline` group that `HeaderNav` already treats as chrome).
- Do not remove `wp:site-title`.
- Non-business headers: do not add an empty logo slot.

`className` is a supported `core/site-logo` attribute and core renders it on the block wrapper. The serializer's `attributeOrder` for the block is `width, isLink, linkTarget, shouldSyncIcon, align, lock, anchor, className, …`, so the three attributes serialize in the order written above.

The class is what scopes the title-hiding rule below to marks **this step injected**. An `is-style-rounded` or `custom-motion` class an archetype already carries is additive; `site-logo-mark` is a marker, not a style hook, and ships no CSS of its own beyond that rule.

HTML-first: do not author a decorative logo `<img>` in the design document. The WordPress logo slot is this injected `core/site-logo` block, after transform. `design-preview.md` keeps the ban on logo images in the design HTML.

`header.md` stays allowed to mention `wp:site-logo` for archetypes that already use it. The fixer is the source of truth for business sites.

Header width estimates already add logo width when `site-logo` is present (`HeaderHeroStep::estimatedRowWidth()`). Leave that as-is (counting title plus logo is conservative). The injected 48px mark does change header geometry on a design authored around a text wordmark, so the plan carries a visual check on a real business build, not unit tests alone.

## Title visibility

Header keeps both blocks. The title is hidden only while an **injected** mark actually renders an image.

`FinalizeThemeStep` adds a fixed inline stylesheet on the already-enqueued `style.css` handle — `wp_add_inline_style("{$slug}-style", …)`, the handle registered at `FinalizeThemeStep.php:657`. Not the adaptive-header kit: static headers prune that kit. Inline rather than appending to `theme/style.css` so the `:has()` selector never reaches `CssScrub` or the contrast checks, which parse that file.

```css
.site-header-shell:has(.wp-block-site-logo.site-logo-mark img) .wp-block-site-title,
header:has(.wp-block-site-logo.site-logo-mark img) .wp-block-site-title {
  display: none;
}
```

Both selectors are needed: `shellClassName()` only emits `site-header-shell` for non-static header behaviours (`AssemblePagesStep.php:307-322`).

The rule is emitted for every theme. It is inert without the class, so no gate on `BusinessSite` is required in this step.

The `.site-logo-mark` qualifier is what keeps this from being a site-wide behaviour change. Without it the rule fires on any logo a site owner later uploads — including the `branded-lockup` archetype (`prompts/header.md:29`), which deliberately pairs `wp:site-logo` **beside** `wp:site-title` as one unit. A designed lockup keeps its wordmark; only a mark this pipeline injected replaces the title. `header.md:43` ("the wp:site-title always carries the identity") stays true for every header this step did not touch.

Footer uses a `footer` landmark and is unaffected. Tagline is not hidden. Removing the custom logo in the editor removes the `img`; the title returns. The same happens when generation or import never set `custom_logo`.

## Seeder (gallery + site settings)

`ScaffoldPluginStep` content plugin, on activation:

1. Import as today (`wp_upload_bits`, `wp_insert_attachment`, `_wpcom_ai_generated_post`, attachment metadata).
2. File path: `plugin/images/{basename}` if present, else `get_stylesheet_directory() . '/assets/' . {basename}`. Both roots get the **same** guards the single root has today (`ScaffoldPluginStep.php:305-321`): basename-only, `/^[a-z0-9-]+\.(?:jpe?g|png)$/i`, and a `realpath()` that must still sit inside the root it was resolved against. The theme fallback is a second allowed root, not a relaxation. Theme is activated before this plugin on wpcom (`wp theme activate` then `wp plugin activate`).
3. After a successful import of a row with `role === 'site-logo'`, record on the plugin state before writing anything:
   - `changed_logo => true`
   - `logo_attachment_id => $attachment_id`
   - `custom_logo => get_theme_mod('custom_logo')` (null when unset)
   - `site_icon => get_option('site_icon')`

   then `set_theme_mod('custom_logo', $attachment_id)` and `update_option('site_icon', $attachment_id)`.
4. Same attachment id for both.

On deactivation, **before** the `wp_delete_attachment()` loop (`ScaffoldPluginStep.php:653-656`), so no mod ever points at a deleted id:

- Only when `changed_logo` is set **and** the live value still equals `logo_attachment_id`. A site owner who picked their own logo after activation keeps it. This is the shape `changed_front` already uses for the front-page restore (`ScaffoldPluginStep.php:684`), extended with an ownership check because a logo, unlike the front page, is a thing owners routinely change.
- Restore the recorded `custom_logo` (`remove_theme_mod('custom_logo')` when there was none) and `site_icon` (`delete_option('site_icon')` when there was none).

Then delete seeded attachments and pages as today.

This fallback also imports ordinary content images on Dotcom, where `big_sky_build_wow_plugin_files_for_delivery()` drops `plugin/images/*` binaries. That is intended: content images were already supposed to land in the media library.

wpcom does not set `custom_logo` or `site_icon`. Those need an attachment id that exists only after this import. `blogname` stays host-applied.

## Failures

Generated-content ladder: never abort the build for a missing or bad mark.

| Situation | Delivery |
|---|---|
| Not a business site | no spec, no block, no theme mods |
| `--with-images` skipped | spec sits pending; manifest row has no file; seeder finds nothing; no mods; title visible |
| `site-logo.png` generate failed | existing generate-images warning; no file; no mods; title visible |
| Keying wiped out (mark came back opaque) | warn; `role` dropped; manifest row removed; nothing shipped or imported; no mods; title visible |
| Import failed | no mods; title visible; no extra abort |
| Header missing | warn; continue |

`warnings.json` rows stay actionable (file, authored, delivered, disposition) when this path changes delivered output (failed generate, wiped-out key, skipped import after a completed generate).

## Tests

Suites: `tests/run.php` (unit) and `tests/run-integration.php` (integration). Both must pass.

- `BusinessSite` matrix (table above), including the prompt-only photographer case.
- `collect-images` appends `site-logo.png` + `role` only for a business spec; personal and photography fixtures do not gain the row; a pre-existing `site-logo.png` placeholder is left untagged and warned.
- `ImageTransparency::padToSquare` centres a non-square bitmap on a transparent square at `max(w, h, 512)` without resampling, and returns its input unchanged on failure.
- `ImageTransparency::isKeyed` is false for a fully opaque PNG and true for one with transparent corners.
- `generate-images` square-pads only the `site-logo` role, and on an unkeyed mark drops the role, removes the manifest row, and does not copy the file.
- `assemble-pages` puts the role-tagged row in `plugin/images.json` even when page HTML does not reference it; content-only images stay without `role`; the manifest title is `Site logo`.
- `generate-images` ships `site-logo.png` to `plugin/images/` when the manifest lists it and the mark is keyed (same as `hero.jpg` today).
- Scaffolded plugin PHP contains the theme-assets fallback with its own containment guard, the `custom_logo` / `site_icon` writes, and a deactivate restore that is skipped when the live value no longer matches `logo_attachment_id`.
- `header-hero` inserts `wp:site-logo` with `className: site-logo-mark` before `wp:site-title` for business sites and leaves a personal-site header unchanged.
- `finalize-theme` emits the `:has(.wp-block-site-logo.site-logo-mark img)` rule, and an authored lockup without the class keeps its title.
- Re-pin the `src/Steps/AssemblePagesStep.php` sha256 at `tests/integration/dp_slice3_section_mode_test.php:154`. `contentImages()` changes in this work, and that assertion fails until the hash is updated.

## Out of scope

- Easy Editor empty-logo placeholder UI.
- wpcom delivery allowlist for `plugin/images/*`.
- Wordmarks or letter-bearing logos.
- A second, flattened `site-icon.png` attachment for iOS home screens.
- Hiding footer `wp:site-title`.
- New pipeline step or `postImages()` change.
