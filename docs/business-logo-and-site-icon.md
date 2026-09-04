# Business logo and site icon during theme generation

**Linear:** [BIGR-957](https://linear.app/a8c/issue/BIGR-957/generate-business-logo-and-site-icon-during-theme-generation)
**Branch:** `bigr-957-generate-business-logo-and-site-icon-during-theme-generation`

## Goal

For business-like sites, generate one small square brand mark during image generation, import it into the media library, and set it as the WordPress custom logo. A flattened, opaque copy of the same mark becomes the site icon. In the header, the mark replaces the site title while it is set; if the mark is missing or later removed, the title shows again. The footer still shows the site title.

## Scope

In this repository only. Easy Editor placeholder work is out of scope. wpcom apply (`site_builder_apply_site_identity`, `big_sky_build_wow_plugin_files_for_delivery`) is not changed; Dotcom gets the attachment because the seeder falls back to the already-delivered `theme/assets/` copy.

## Approach

Piggyback on the existing image pipeline. No new graph step. wpcom's finish span already runs `generate-images`.

```
site-spec
    → SiteSpecStep::isPersonal()? (persona_name set)
collect-images
    → if yes, append images.json spec: site-logo.png
header-hero
    → non-personal sites: ensure wp:site-logo immediately before wp:site-title,
      tagged className=site-logo-mark
assemble-pages
    → copy role:site-logo into plugin/images.json
generate-images
    → key out white, square-pad the mark
    → recolor the ink to the header site-title color (the same token the name uses)
    → write theme/assets/site-logo.png (transparent, for custom_logo)
    → flatten a copy onto the header background
    → write theme/assets/site-icon.png (opaque, for site_icon)
    → opaque (keying wiped out)? drop the role row, ship nothing
    → copy surviving manifest files to plugin/images/
plugin activation
    → import (plugin/images/, else theme/assets/)
    → custom_logo = the site-logo attachment
    → site_icon   = the site-icon attachment
    → CSS hides header site-title while the injected mark renders an img
```

## Who gets a mark

**Changed in BIGR-986 (2026-09-04).** The first version gated the mark on a
`BusinessSite` keyword matcher. That matcher was a list of about twenty
business nouns (shop, salon, agency, hotel, ...). It excluded photography and
gallery sites and personal sites. The list was an allowlist: a product landing
page, a nonprofit, a school, or a photographer studio got no mark. The matcher
is gone, together with `PhotographySite`.

The gate is now the one identity decision the site-spec step already makes:

```php
SiteSpecStep::wantsLogoMark(array $siteSpec): bool   // spec present and persona_name empty
```

- **Personal site** (`persona_name` set: portfolio, CV, personal blog) keeps
  the plain site title. It gets no mark and no header injection.
- **Every other site** gets the generated mark and the header injection.
- **No `siteSpec.json`** (a partial run) gets no mark and no injection.

No step reads the prompt for this decision now. `meta.json` left the `reads`
of `collect-images` and `header-hero`.

## Image spec

Reserved filename: `site-logo.png`. Content filenames are subject-derived; this name is reserved for the synthetic mark.

`collect-images` appends the spec unless `SiteSpecStep::isPersonal()`. It reads `siteSpec.json`, declared in `StepDeclaration::reads`. If `site-logo.png` is already in `images.json` from markup, do not overwrite that row and do not tag it `site-logo` — skip the synthetic mark and warn. A content image must not become the site logo.

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

`GenerateImagesStep::siteContext()` already omits the site name. Do not pass the name into the logo subject. `topic`, `area`, and `visual_vibe` are free prose and can still carry the identity ("Hearth & Crumb's sourdough programme"); compose the subject through `GenerateImagesStep::safeSubjectMatter()` and fall back to a safe `site_type`, then to `an organization` (and drop the vibe suffix) when a candidate repeats `name`, `persona_name`, or `email_domain`.

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

New: `ImageTransparency::isKeyed(string $pngBytes): bool` — true when all four corner pixels have alpha `<= 0.01`. PNG quantisation does not guarantee exact-zero; the same epsilon the rest of `ImageTransparency` tests use. A successful key always ends in `trimToInk()`, which guarantees a transparent pad of at least 2px on every side, so opaque corners mean the key was abandoned (or the ink runs edge to edge, which is equally unusable as a mark). Without Imagick, `isKeyed()` returns false.

When `isKeyed()` is false for the site-logo role: warn, `unset($specs[$i]['role'])` so `images.json` stops claiming it, and let the rest of `finish()` run as normal. The file still lands in `theme/assets/` — it is a completed generation, just not a usable mark.

### Reconciliation

`shipPluginImages()` (`GenerateImagesStep.php:264`) currently copies every manifest filename that exists. It gains one rule: a `plugin/images.json` row tagged `role: site-logo` survives only while the matching `images.json` spec still carries `role: site-logo`. Otherwise the row is dropped from the manifest and the file is not copied, so the seeder never sees it. Rewrite the artifact only when a site-logo row was actually dropped — a missing ordinary content file still skips the copy and leaves its manifest row. `plugin/images.json` joins the step's `writes`.

`images.json` is written (`writeJsonAtomic`, `GenerateImagesStep.php:247`) immediately before `shipPluginImages()` runs, so the role is already current by then.

### Site icon: a second, opaque file

`custom_logo` and `site_icon` want opposite things, and the recolor is what forces the issue. The header composites the mark over its own bar, so the logo must stay transparent and must carry the title's ink — a dark mark on a dark header is the bug the recolor fixed. A browser tab has no bar behind it: that same light mark on transparency is invisible in light mode, and iOS composites a transparent touch icon onto black.

So the icon is its own file. After the recolor, `ImageTransparency::flattenOver()` composites the square mark onto the header's **background** colour (`GenerateImagesStep::headerBackgroundHex()`, the `backgroundColor` sibling of the ink walk, falling back to `base`) and drops the alpha channel. The result is written to `theme/assets/site-icon.png`.

The icon reads exactly as the header reads — same ink, same ground — wherever the tab paints it. One generation, one recolor, two files.

No icon is produced when the mark was dropped, or when the header background cannot be resolved. In that case `site_icon` is left untouched: an unset icon is better than an invisible one, and the transparent mark is never borrowed for it.

## plugin/images.json

`AssemblePagesStep::contentImages()` keeps scanning page markup, then unions any `images.json` entry whose `role` is `site-logo`. The header is a template part, not page content, so the mark is never referenced by the markup this function scans — the union is the only way it reaches the manifest.

Each manifest row:

```json
{ "filename": "site-logo.png", "title": "Site logo", "role": "site-logo" }
{ "filename": "site-icon.png", "title": "Site icon", "role": "site-icon" }
```

`assemble-pages` unions only the `site-logo` row. The icon has no `images.json` spec — it is derived in `generate-images` after the mark survives keying — so `shipPluginImages()` appends its row, and only alongside a surviving logo row.

The title is fixed, not the subject. `title` becomes the attachment's `post_title` at import (`ScaffoldPluginStep.php:331`), and the subject is prompt prose — "simple geometric brand mark for a neighborhood bakery, warm rustic mood, single ink, no letters…" is a bad media-library title.

`role` is optional. Existing content rows omit it.

## Header

`HeaderHeroStep`, every non-personal site (see "Who gets a mark"):

- If the header has no `wp:site-logo`, insert `<!-- wp:site-logo {"width":48,"shouldSyncIcon":true,"className":"site-logo-mark"} /-->` immediately before the first `wp:site-title` (sibling, same parent). If there is no site-title, insert at the start of the root group wrapper (after the opening `<div>`), not before the group comment — a prepend there fails the HTML-first safe-wrapper check. This branch only runs when there is no identity cluster to find.
- Do not remove `wp:site-title`.
- Personal-site headers, and a build without `siteSpec.json`: keep the header free of an empty logo slot.

`className` is a supported `core/site-logo` attribute and core renders it on the block wrapper. The serializer's `attributeOrder` for the block is `width, isLink, linkTarget, shouldSyncIcon, align, lock, anchor, className, …`, so the three attributes serialize in the order written above.

The class is what scopes the title-hiding rule below to marks **this step injected**. An `is-style-rounded` or `custom-motion` class an archetype already carries is additive; `site-logo-mark` is a marker, not a style hook, and ships no CSS of its own beyond that rule.

HTML-first: do not author a decorative logo `<img>` in the design document. The WordPress logo slot is this injected `core/site-logo` block, after transform. `design-preview.md` keeps the ban on logo images in the design HTML.

`header.md` stays allowed to mention `wp:site-logo` for archetypes that already use it. The fixer is the source of truth for the injected mark.

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

The rule is emitted for every theme. It is inert without the class, so no personal-site gate is required in this step.

The editor deliberately does **not** get this rule. `add_editor_style` still mirrors `style.css`; the inline hide stays on the front-end `wp_enqueue_scripts` callback so a business header in the site editor keeps the title visible and editable. Removing the custom logo there must still reveal the title the visitor will see.

The `.site-logo-mark` qualifier is what keeps this from being a site-wide behaviour change. Without it the rule fires on any logo a site owner later uploads — including the `branded-lockup` archetype (`prompts/header.md:29`), which deliberately pairs `wp:site-logo` **beside** `wp:site-title` as one unit. A designed lockup keeps its wordmark; only a mark this pipeline injected replaces the title. `header.md:43` ("the wp:site-title always carries the identity") stays true for every header this step did not touch.

Footer uses a `footer` landmark and is unaffected. Tagline is not hidden. Removing the custom logo in the editor removes the `img`; the title returns. The same happens when generation or import never set `custom_logo`.

## Seeder (gallery + site settings)

`ScaffoldPluginStep` content plugin, on activation:

1. Import as today (`wp_upload_bits`, `wp_insert_attachment`, `_wpcom_ai_generated_post`, attachment metadata).
2. File path: `plugin/images/{basename}` if present, else `get_stylesheet_directory() . '/assets/' . {basename}`. Both roots get the **same** guards the single root has today (`ScaffoldPluginStep.php:305-321`): basename-only, `/^[a-z0-9-]+\.(?:jpe?g|png)$/i`, and a `realpath()` that must still sit inside the root it was resolved against. The theme fallback is a second allowed root, not a relaxation. Theme is activated before this plugin on wpcom (`wp theme activate` then `wp plugin activate`).
3. After a successful import, walk the map once and act per role, recording the previous value before writing:
   - `role === 'site-logo'` → `changed_logo => true`, `logo_attachment_id`, `custom_logo => get_theme_mod('custom_logo')`, then `set_theme_mod('custom_logo', $id)`.
   - `role === 'site-icon'` → `changed_logo => true`, `icon_attachment_id`, `site_icon => get_option('site_icon')`, then `update_option('site_icon', $id)`.
4. Two attachment ids, never shared. With no `site-icon` row, `site_icon` is left untouched — the transparent mark is not borrowed for it.

On deactivation, **before** the `wp_delete_attachment()` loop (`ScaffoldPluginStep.php:653-656`), so no mod ever points at a deleted id:

- When `changed_logo` is set, restore `custom_logo` and `site_icon` independently: each is restored only while its live value still equals **its own** recorded id (`logo_attachment_id` / `icon_attachment_id`). An owner who changed only the site icon keeps that icon; the still-owned logo is cleared so the upcoming attachment delete cannot leave a dangling theme mod. This is the shape `changed_front` already uses for the front-page restore (`ScaffoldPluginStep.php:684`), extended with a per-setting ownership check because a logo, unlike the front page, is a thing owners routinely change.
- Restore the recorded `custom_logo` (`remove_theme_mod('custom_logo')` when there was none) and `site_icon` (`delete_option('site_icon')` when there was none).

Then delete seeded attachments and pages as today.

This fallback also imports ordinary content images on Dotcom, where `big_sky_build_wow_plugin_files_for_delivery()` drops `plugin/images/*` binaries. That is intended: content images were already supposed to land in the media library.

wpcom does not set `custom_logo` or `site_icon`. Those need an attachment id that exists only after this import. `blogname` stays host-applied.

## Failures

Generated-content ladder: never abort the build for a missing or bad mark.

| Situation | Delivery |
|---|---|
| Personal site (`persona_name` set), or no `siteSpec.json` | no spec, no block, no theme mods |
| `--with-images` skipped | spec sits pending; manifest row has no file; seeder finds nothing; no mods; title visible |
| `site-logo.png` generate failed | existing generate-images warning; no file; no mods; title visible |
| Keying wiped out (mark came back opaque) | warn; `role` dropped; manifest row removed; nothing shipped or imported; no mods; title visible |
| Imagick unavailable | warn; `role` dropped; no mark shipped or imported; no mods; title visible |
| Header background unresolvable | logo ships as usual; no `site-icon.png`; `site_icon` left untouched |
| Import failed | no mods; title visible; no extra abort |
| Header missing | warn; continue |

`warnings.json` rows stay actionable (file, authored, delivered, disposition) when this path changes delivered output (failed generate, wiped-out key, skipped import after a completed generate).

## Tests

Suites: `tests/run.php` (unit) and `tests/run-integration.php` (integration). Both must pass.

- `collect-images` appends the mark for photography, gallery, nonprofit, and product-page specs with no `persona_name`.
- `collect-images` appends `site-logo.png` + `role` for a business spec; a personal fixture does not gain the row; a pre-existing `site-logo.png` placeholder is left untagged and warned; identity-bearing `area`/`topic`/`visual_vibe` fall back rather than entering the subject.
- `ImageTransparency::padToSquare` centres a non-square bitmap on a transparent square at `max(w, h, 512)` without resampling, and returns its input unchanged on failure.
- `ImageTransparency::isKeyed` is false for a fully opaque PNG, true for one with transparent corners, and true when those corners quantise to a small non-zero alpha (`< 0.01`).
- `generate-images` square-pads only the `site-logo` role, and on an unkeyed mark drops the role, removes the manifest row, and does not copy the file.
- `ImageTransparency::flattenOver` paints the mark onto an opaque ground and returns its input on a bad hex; `headerBackgroundHex` follows `backgroundColor` and falls back to `base`.
- `generate-images` writes an opaque `site-icon.png` beside the transparent logo, ships it, and adds the `site-icon` manifest row; no icon is produced when the mark was dropped.
- The seeder sets `custom_logo` and `site_icon` from **different** attachments, and leaves `site_icon` untouched when no `site-icon` row shipped.
- `assemble-pages` puts the role-tagged row in `plugin/images.json` even when page HTML does not reference it; content-only images stay without `role`; the manifest title is `Site logo`; a content row whose filename is also tagged `site-logo` keeps its subject title and is not retagged.
- `generate-images` ships `site-logo.png` to `plugin/images/` when the manifest lists it and the mark is keyed (same as `hero.jpg` today); drops the role and warns when Imagick is missing or the key never ran; leaves missing ordinary content rows in the manifest.
- Scaffolded plugin PHP contains the theme-assets fallback with its own containment guard, the `custom_logo` / `site_icon` writes, and a deactivate restore that is skipped per setting when the live value no longer matches `logo_attachment_id` (changing only `site_icon` still restores `custom_logo`).
- `header-hero` inserts `wp:site-logo` with `className: site-logo-mark` before `wp:site-title` for a business spec and for a photography spec, and leaves a personal-site header unchanged.
- `finalize-theme` emits the `:has(.wp-block-site-logo.site-logo-mark img)` rule, and an authored lockup without the class keeps its title.
- Re-pin the `src/Steps/AssemblePagesStep.php` sha256 at `tests/integration/dp_slice3_section_mode_test.php:154`. `contentImages()` changes in this work, and that assertion fails until the hash is updated.

## Out of scope

- Easy Editor empty-logo placeholder UI.
- wpcom delivery allowlist for `plugin/images/*`.
- Wordmarks or letter-bearing logos.
- Hiding footer `wp:site-title`.
- New pipeline step or `postImages()` change.
