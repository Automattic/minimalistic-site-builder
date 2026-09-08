# Five-style eval for PR #625

Built from source commit `02f7ce633cd48657db8ceb093a0a4f471b56d60d` using the ordinary PHP CLI's default Anthropic API models: `claude-haiku-4-5` and `claude-opus-5`. Blocks-first, single-page builds, followed by `php bin/images.php <slug>` for WPCOM image generation and the full post-image phase. All five builds and image phases exited successfully; generated-content warnings remain.

Screenshots are full-page Chrome captures from the actual WordPress sites running in Studio at 1440px and 390px viewport widths (900px viewport height), with motion enabled, lazy images loaded and entrances settled. No manual design corrections were applied. [Rendered checks](rendered-checks.json) include image loading, document width, element bounds and dead links.

| Style | Generated site | Screenshots | Playground example | Generated assets | Final validation warnings |
| --- | --- | --- | --- | --- | --- |
| Whimsical | Hearth & Habit | [Desktop](whimsical-desktop.png) / [Mobile](whimsical-mobile.png) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FAutomattic%2Fminimalistic-site-builder%2Fplayground-artifacts%2Fpr-625%2F02f7ce63%2Fpr625-climate-whimsical.zip) | 13 | 6 |
| Brutalist | Terrain & Light | [Desktop](brutalist-desktop.png) / [Mobile](brutalist-mobile.png) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FAutomattic%2Fminimalistic-site-builder%2Fplayground-artifacts%2Fpr-625%2F02f7ce63%2Fpr625-photo-brutalist.zip) | 10 | 3 |
| Art Deco | Fuoco | [Desktop](art-deco-desktop.png) / [Mobile](art-deco-mobile.png) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FAutomattic%2Fminimalistic-site-builder%2Fplayground-artifacts%2Fpr-625%2F02f7ce63%2Fpr625-pizza-art-deco.zip) | 5 | 1 |
| Bauhaus | Kiln & Grain | [Desktop](bauhaus-desktop.png) / [Mobile](bauhaus-mobile.png) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FAutomattic%2Fminimalistic-site-builder%2Fplayground-artifacts%2Fpr-625%2F02f7ce63%2Fpr625-bakery-bauhaus.zip) | 8 | 3 |
| Retro-Futurist | Velocity | [Desktop](retro-futurist-desktop.png) / [Mobile](retro-futurist-mobile.png) | [Open](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2FAutomattic%2Fminimalistic-site-builder%2Fplayground-artifacts%2Fpr-625%2F02f7ce63%2Fpr625-bicycle-retro-futurist.zip) | 16 | 4 |

## Assessment

These are five different briefs, one run per style, not a controlled before/after experiment or evidence of within-style diversity. They exercise Claude, not GLM.

- **Whimsical:** the most visibly distinct treatment here: dark forest palette, handwritten headings and coherent illustrated home-maintenance imagery. The page reads whimsical without repeated ornamental CSS shapes.
- **Brutalist:** forceful slab headings, hard edges, rules and an irregular gallery carry some of the direction. The large photographic cover is still conventional, and mobile hero text is visibly clipped at the right edge. The hero content box reaches about 438px in a 390px viewport even though the page itself reports no horizontal scroll overflow.
- **Art Deco:** coherent nocturnal photography, centered capitals and warm rules, but the result reads more like an elegant restaurant site than a strongly Art Deco composition. The mobile document is 394px wide in a 390px viewport; the Specialties heading is visibly tight at the right edge.
- **Bauhaus:** functional sans typography, asymmetric product scales and technical copy offer some direction, but the restrained bakery catalog does not establish a strong Bauhaus visual identity. Its gray/teal treatment and photographic cover remain conventional.
- **Retro-Futurist:** cream, orange and teal studio imagery with slab headings suggests a vintage product catalog. The futuristic side of the request is weak; its cover-plus-product-grids structure is familiar.

Hero diversity remains limited: four directions chose `layered-poster` with a cover image; only Whimsical chose `foreground-split`. The captures show different palettes, imagery and section arrangements, but this is not a blanket aesthetic pass.

## Delivered defects

- Visible dead buttons: Whimsical **Sign up**, Brutalist **Inquire**, and Retro-Futurist **Start an Inquiry** lack destinations. They were not silently patched for screenshots.
- All 47 rendered content images loaded on desktop and mobile, and no uncaught page errors were observed in these checks. This is a bounded check, not full functional or accessibility QA.
- The five generated logo PNGs were not usable as transparent logos. The pipeline retained them as opaque assets and fell back to visible text site titles, with warnings; the 52 generated assets therefore comprise 47 displayed content images and five logo fallbacks.
- Final validation still reports 6 / 3 / 1 / 3 / 4 problems for Whimsical / Brutalist / Art Deco / Bauhaus / Retro-Futurist respectively. The project ZIPs include each build's actionable warnings and logs.
- PHP 8.5 deprecation notices occurred in the existing HTTP/font/image helpers but did not abort the builds.

## Exact briefs

1. A climate care blog about practical, everyday home sustainability. Art direction: Whimsical.
2. A photography portfolio for a fine-art landscape photographer. Art direction: Brutalist.
3. A single-page menu site for a wood-fired Neapolitan pizzeria. Art direction: Art Deco.
4. A product catalog for an artisan sourdough bakery. Art direction: Bauhaus.
5. An online store for a premium urban and commuter bicycle brand. Art direction: Retro-Futurist.

These are the five original `bin/eval.php` briefs with explicit art direction. The photography brief's original `minimalist` adjective was removed to avoid contradicting Brutalist. The earlier Codex subscription attempt failed before successful model output; all five projects were restarted from prompt refinement with the correct PHP CLI default. No source code or existing eval projects were changed.

The bundles were made with the existing `bin/publish-playground.php --dry-run` packager. Each includes a Blueprint and the full generated project. No configured API credential values were found in those project files before packaging. Screenshots and bundles belong only to `playground-artifacts`, as explicitly requested; they are not part of the feature branch.
