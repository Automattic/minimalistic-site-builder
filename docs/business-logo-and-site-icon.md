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
    → business sites: ensure wp:site-logo immediately before wp:site-title
assemble-pages
    → copy role:site-logo into plugin/images.json
generate-images
    → write theme/assets/site-logo.png, copy to plugin/images/
plugin activation
    → import (plugin/images/, else theme/assets/)
    → custom_logo + site_icon = that attachment
    → CSS hides header site-title while the logo renders an img
```

## BusinessSite

New helper `src/BusinessSite.php`, same call shape as `PhotographySite`.

```php
public static function matches(array $siteSpec, string $prompt = ''): bool
```

`$prompt` is unused by the matcher (kept so the signature matches `PhotographySite`). Decision uses only `siteSpec` fields.

**No** when:

- `persona_name` is a non-empty string (personal portfolio, CV, personal blog).
- `PhotographySite::matches($siteSpec)` is true (photography / gallery).

**Yes** when the concatenated lowercase text of `site_type`, `area`, `topic`, `title`, and `name` matches:

```
/\b(?:business(?:es)?|storefronts?|shops?|stores?|retail(?:ers?)?|restaurants?|cafés?|cafes?|baker(?:y|ies)|bars?|salons?|spas?|clinics?|gyms?|studios?|agenc(?:y|ies)|consultanc(?:y|ies)|firms?|services?|saas|hotels?|boutiques?)\b/u
```

Pinned cases:

| Spec | Result |
|---|---|
| `site_type: business storefront`, bakery area | true |
| restaurant / cafe | true |
| `persona_name` set | false |
| portfolio, blog | false |
| photography / gallery (`PhotographySite`) | false |

Allowlist is conservative. Widening it is a later change.

## Image spec

Reserved filename: `site-logo.png`. Content filenames are subject-derived; this name is reserved for the synthetic mark.

`collect-images` appends the spec when `BusinessSite::matches()`. It reads `siteSpec.json` (add to `StepDeclaration::reads`). If `site-logo.png` is already in `images.json` from markup, do not overwrite that row and do not tag it `site-logo` — skip the synthetic mark and warn (`disposition=skipped synthetic site-logo because the reserved filename was already collected`). A content image must not become the site logo.

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

## plugin/images.json

`AssemblePagesStep::contentImages()` keeps scanning page markup, then unions any `images.json` entry whose `role` is `site-logo`.

Each manifest row:

```json
{ "filename": "site-logo.png", "title": "<subject or 'Site logo'>", "role": "site-logo" }
```

`role` is optional. Existing content rows omit it. `generate-images` already copies every manifest filename from `theme/assets/` to `plugin/images/` after a successful generate; no change there.

## Header

`HeaderHeroStep`, business sites only:

- If the header has no `wp:site-logo`, insert `<!-- wp:site-logo {"width":48,"shouldSyncIcon":true} /-->` immediately before the first `wp:site-title` (sibling, same parent). If there is no site-title, insert at the start of the identity cluster (`site-title` / `site-logo` / `site-tagline` group that `HeaderNav` already treats as chrome).
- Do not remove `wp:site-title`.
- Non-business headers: do not add an empty logo slot.

HTML-first: do not author a decorative logo `<img>` in the design document. The WordPress logo slot is this injected `core/site-logo` block, after transform. `design-preview.md` keeps the ban on logo images in the design HTML.

`header.md` stays allowed to mention `wp:site-logo` for archetypes that already use it. The fixer is the source of truth for business sites.

Header width estimates already add logo width when `site-logo` is present. Leave that as-is (counting title plus logo is conservative).

## Title visibility

Header keeps both blocks. The title is hidden only while the logo actually renders an image.

`FinalizeThemeStep` adds a fixed inline stylesheet on the already-enqueued `style.css` handle (not the adaptive-header kit: static headers prune that kit). Selector:

```css
.site-header-shell:has(.wp-block-site-logo img) .wp-block-site-title,
header:has(.wp-block-site-logo img) .wp-block-site-title {
  display: none;
}
```

Footer uses a `footer` landmark and is unaffected. Tagline is not hidden. Removing the custom logo in the editor removes the `img`; the title returns. The same happens when generation or import never set `custom_logo`.

## Seeder (gallery + site settings)

`ScaffoldPluginStep` content plugin, on activation:

1. Import as today (`wp_upload_bits`, `wp_insert_attachment`, `_wpcom_ai_generated_post`, attachment metadata).
2. File path: `plugin/images/{basename}` if present, else `get_stylesheet_directory() . '/assets/' . {basename}`. Same basename / charset guards as today. Theme is activated before this plugin on wpcom (`wp theme activate` then `wp plugin activate`).
3. After a successful import of a row with `role === 'site-logo'`:
   - Store previous `custom_logo` theme mod and `site_icon` option on the plugin state.
   - `set_theme_mod( 'custom_logo', $attachment_id )`.
   - `update_option( 'site_icon', $attachment_id )`.
4. Same attachment id for both.

On deactivation: restore the previous `custom_logo` and `site_icon` (remove the mod / delete the option when there was no previous value), then delete seeded attachments and pages as today.

This fallback also imports ordinary content images on Dotcom, where `big_sky_build_wow_plugin_files_for_delivery()` drops `plugin/images/*` binaries. That is intended: content images were already supposed to land in the media library.

wpcom does not set `custom_logo` or `site_icon`. Those need an attachment id that exists only after this import. `blogname` stays host-applied.

## Failures

Generated-content ladder: never abort the build for a missing or bad mark.

| Situation | Delivery |
|---|---|
| Not a business site | no spec, no block, no theme mods |
| `--with-images` skipped | spec may sit pending; seeder finds no file; no mods; title visible |
| `site-logo.png` generate failed | existing generate-images warning; no file; no mods; title visible |
| Import failed | no mods; title visible; no extra abort |
| Header missing | warn; continue |

`warnings.json` rows stay actionable (file, authored, delivered, disposition) when this path changes delivered output (failed generate, skipped import after a completed generate).

## Tests

- `BusinessSite` matrix (table above).
- `collect-images` appends `site-logo.png` + `role` only for a business spec; personal and photography fixtures do not gain the row; a pre-existing `site-logo.png` placeholder is left untagged and warned.
- `assemble-pages` puts the role-tagged row in `plugin/images.json` even when page HTML does not reference it; content-only images stay without `role`.
- `generate-images` ships `site-logo.png` to `plugin/images/` when the manifest lists it (same as `hero.jpg` today).
- Scaffolded plugin PHP contains the theme-assets fallback, `custom_logo` / `site_icon` writes, and deactivate restore.
- `header-hero` inserts `wp:site-logo` before `wp:site-title` for business sites and leaves a personal-site header unchanged.
- `finalize-theme` emits the `:has(.wp-block-site-logo img)` rule.

## Out of scope

- Easy Editor empty-logo placeholder UI.
- wpcom delivery allowlist for `plugin/images/*`.
- Wordmarks or letter-bearing logos.
- Hiding footer `wp:site-title`.
- New pipeline step or `postImages()` change.
