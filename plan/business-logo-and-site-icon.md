# Business logo and site icon Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** For business-like sites, generate one square transparent mark, import it into the media library, and set it as `custom_logo` and `site_icon`, hiding the header site title only while that injected mark renders.

**Architecture:** Piggyback on collect-images / assemble-pages / generate-images / the content seeder. No new graph step. A `BusinessSite` matcher gates a synthetic `site-logo.png` spec and a `wp:site-logo` block tagged `site-logo-mark`. Title-hiding CSS keys on that class. A wiped-out key drops the role so the seeder never imports an opaque square.

**Tech Stack:** PHP 8.3, Imagick (`ImageTransparency`), existing unit harness (`php tests/run.php`) and integration suite (`php tests/run-integration.php`).

**Spec:** `docs/business-logo-and-site-icon.md` (BIGR-957). Plan lives under `plan/` because `docs/superpowers/` is gitignored.

---

## File map

| File | Responsibility |
|---|---|
| Create `src/BusinessSite.php` | Business-like matcher. Forwards `$prompt` to `PhotographySite`. |
| Create `tests/unit/business_site_test.php` | Matcher matrix. |
| Modify `src/ImageTransparency.php` | `padToSquare()`, `isKeyed()`. |
| Modify `tests/unit/image_transparency_test.php` | Pad and keyed-corner tests. |
| Modify `src/Steps/CollectImagesStep.php` | Append synthetic spec; collision warn. |
| Modify `tests/unit/collect_images_test.php` | Inject / skip / collision. |
| Modify `src/Steps/AssemblePagesStep.php` | Union `role: site-logo` into `plugin/images.json`. |
| Modify `tests/unit/assemble_pages_test.php` | Manifest title `Site logo` and role. |
| Modify `tests/integration/dp_slice3_section_mode_test.php:154` | Re-pin AssemblePagesStep sha256. |
| Modify `src/Steps/GenerateImagesStep.php` | Site-logo post-process; reconcile manifest; declare `plugin/images.json` write. |
| Modify `tests/unit/generate_images_test.php` | Pad, wipeout drop, ship when keyed. |
| Modify `src/Steps/HeaderHeroStep.php` | Insert `site-logo-mark` on business sites. |
| Modify `tests/unit/header_hero_test.php` | Insert / skip personal / keep authored lockup. |
| Modify `src/Steps/FinalizeThemeStep.php` | Inline `:has(.site-logo-mark img)` CSS. |
| Modify `tests/unit/finalize_theme_test.php` | CSS present; no BusinessSite gate. |
| Modify `src/Steps/ScaffoldPluginStep.php` | Theme-assets fallback, logo theme mods, owned restore. |
| Modify `tests/unit/scaffold_plugin_test.php` | Fallback, mods, ownership check. |

Do not edit `prompts/header.md` or `prompts/design-preview.md`. Do not add a pipeline step.

---

### Task 1: BusinessSite matcher

**Files:**
- Create: `src/BusinessSite.php`
- Create: `tests/unit/business_site_test.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

use Automattic\SiteBuild\BusinessSite;

test('BusinessSite matches a bakery storefront', function () {
    assert_true(BusinessSite::matches([
        'name'      => 'Hearth & Crumb',
        'site_type' => 'business storefront',
        'topic'     => 'artisan bread and pastries',
        'area'      => 'bakery',
    ]));
});

test('BusinessSite matches restaurant, cafe, café, salon, firm, hotel', function () {
    foreach (['restaurant', 'cafe', 'café', 'hair salon', 'consulting firm', 'hotel'] as $area) {
        assert_true(BusinessSite::matches(['area' => $area, 'site_type' => '']), "area={$area}");
    }
});

test('BusinessSite rejects a personal site with persona_name', function () {
    assert_true(!BusinessSite::matches([
        'name'         => 'Ada',
        'persona_name' => 'Ada Lovelace',
        'site_type'    => 'portfolio',
        'area'         => 'studio',
    ]));
});

test('BusinessSite rejects portfolio, blog, landing page', function () {
    assert_true(!BusinessSite::matches(['site_type' => 'portfolio', 'area' => 'art']));
    assert_true(!BusinessSite::matches(['site_type' => 'blog', 'topic' => 'essays']));
    assert_true(!BusinessSite::matches(['site_type' => 'landing page', 'topic' => 'product launch']));
});

test('BusinessSite rejects photography and gallery via PhotographySite', function () {
    assert_true(!BusinessSite::matches([
        'name'      => 'Stillrange',
        'site_type' => 'portfolio',
        'topic'     => 'fine-art landscape photography',
        'area'      => 'photography',
    ]));
    assert_true(!BusinessSite::matches([
        'name'      => 'Northlight',
        'site_type' => 'gallery',
        'area'      => 'art gallery',
    ]));
});

test('BusinessSite rejects a studio whose only photographer signal is the prompt', function () {
    assert_true(!BusinessSite::matches(
        ['name' => 'Ada', 'site_type' => 'studio', 'area' => 'studio'],
        'A minimalist photography portfolio for a fine-art landscape photographer',
    ));
});

test('BusinessSite does not match topic prose that only says services', function () {
    assert_true(!BusinessSite::matches([
        'site_type' => 'portfolio',
        'topic'     => 'design services for nonprofits',
        'area'      => 'advocacy',
    ]));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`

Expected: FAIL — `Class "Automattic\SiteBuild\BusinessSite" not found`

- [ ] **Step 3: Write the matcher**

`src/BusinessSite.php`:

```php
<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

final class BusinessSite
{
    /**
     * @param array<mixed> $siteSpec
     */
    public static function matches(array $siteSpec, string $prompt = ''): bool
    {
        if (trim((string) ($siteSpec['persona_name'] ?? '')) !== '') {
            return false;
        }
        if (PhotographySite::matches($siteSpec, $prompt)) {
            return false;
        }

        $kind = strtolower(implode("\n", [
            (string) ($siteSpec['area'] ?? ''),
            (string) ($siteSpec['topic'] ?? ''),
            (string) ($siteSpec['site_type'] ?? ''),
            (string) ($siteSpec['title'] ?? ''),
            (string) ($siteSpec['name'] ?? ''),
        ]));

        return preg_match(
            '/\b(?:business(?:es)?|storefronts?|shops?|stores?|retail(?:ers?)?|restaurants?|cafés?|cafes?|baker(?:y|ies)|bars?|salons?|spas?|clinics?|gyms?|studios?|agenc(?:y|ies)|consultanc(?:y|ies)|firms?|saas|hotels?|boutiques?)\b/u',
            $kind
        ) === 1;
    }
}
```

Autoload is PSR-4 `Automattic\SiteBuild\` → `src/`. No composer dump needed.

- [ ] **Step 4: Run tests**

Run: `php tests/run.php`

Expected: PASS, including the new BusinessSite cases.

- [ ] **Step 5: Commit**

```bash
git add src/BusinessSite.php tests/unit/business_site_test.php
git commit -m "$(cat <<'EOF'
feat(site-logo): add BusinessSite matcher (BIGR-957)

Gate logo generation on business-like specs. Forward the prompt to
PhotographySite so a studio whose only photographer signal is the
prompt is not classified as a business.
EOF
)"
```

---

### Task 2: ImageTransparency pad and keyed check

**Files:**
- Modify: `src/ImageTransparency.php` (append public methods after `keyOutBackground`)
- Modify: `tests/unit/image_transparency_test.php`

- [ ] **Step 1: Write the failing tests** (inside the existing `if (!ImageTransparency::available()) { ... return; }` block, so they skip without Imagick)

```php
test('padToSquare centres a non-square bitmap on a transparent square at max(w, h, 512)', function () {
    $src = transparency_fixture('transparent', 'red', 40, 20);
    $out = ImageTransparency::padToSquare($src, 512);
    assert_eq([512, 512], png_size($out));
    assert_true(alpha_at($out, 0, 0) < 0.01, 'corner stays transparent');
    assert_true(alpha_at($out, 511, 511) < 0.01);
    // Native pixels are not resampled: the 40x20 mark sits centred.
    $cx = intdiv(512 - 40, 2) + 20;
    $cy = intdiv(512 - 20, 2) + 10;
    assert_true(alpha_at($out, $cx, $cy) > 0.5, 'subject still opaque at centre');
});

test('padToSquare uses the longer side when it already exceeds minSide', function () {
    $src = transparency_fixture('transparent', 'red', 80, 600);
    $out = ImageTransparency::padToSquare($src, 512);
    assert_eq([600, 600], png_size($out));
});

test('padToSquare returns its input unchanged on undecodable bytes', function () {
    assert_eq('NOT A PNG', ImageTransparency::padToSquare('NOT A PNG'));
});

test('isKeyed is true when every corner is fully transparent', function () {
    $keyed = ImageTransparency::keyOutBackground(transparency_fixture('white', 'red', 60, 60));
    assert_true(ImageTransparency::isKeyed($keyed));
});

test('isKeyed is false for a fully opaque PNG', function () {
    assert_true(!ImageTransparency::isKeyed(transparency_fixture('white', 'red', 60, 60)));
});
```

`transparency_fixture('transparent', ...)` needs Imagick to accept `'transparent'` as a pixel. If the existing helper's `newImage` rejects it, draw on a canvas created with `new ImagickPixel('transparent')` in the test instead of changing the helper's default.

- [ ] **Step 2: Run tests — expect FAIL** on missing `padToSquare` / `isKeyed`.

- [ ] **Step 3: Implement**

Add to `ImageTransparency` after `keyOutBackground()`:

```php
public static function padToSquare(string $pngBytes, int $minSide = 512): string
{
    if (!self::available()) {
        return $pngBytes;
    }
    try {
        $im = new \Imagick();
        $im->readImageBlob($pngBytes);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $side = max($w, $h, $minSide);
        if ($w === $side && $h === $side) {
            return $pngBytes;
        }
        $canvas = new \Imagick();
        $canvas->newImage($side, $side, new \ImagickPixel('transparent'));
        $canvas->setImageFormat('png');
        $canvas->compositeImage(
            $im,
            \Imagick::COMPOSITE_OVER,
            intdiv($side - $w, 2),
            intdiv($side - $h, 2),
        );
        return $canvas->getImageBlob();
    } catch (\Throwable) {
        return $pngBytes;
    }
}

public static function isKeyed(string $pngBytes): bool
{
    if (!self::available()) {
        return false;
    }
    try {
        $im = new \Imagick();
        $im->readImageBlob($pngBytes);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w < 1 || $h < 1) {
            return false;
        }
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
            if ($im->getImagePixelColor($x, $y)->getColorValue(\Imagick::COLOR_ALPHA) > 0.0) {
                return false;
            }
        }
        return true;
    } catch (\Throwable) {
        return false;
    }
}
```

No resampling. Padding only.

- [ ] **Step 4: Run `php tests/run.php` — PASS.**

- [ ] **Step 5: Commit** `feat(site-logo): pad keyed marks to a square canvas (BIGR-957)`

---

### Task 3: collect-images synthetic spec

**Files:**
- Modify: `src/Steps/CollectImagesStep.php`
- Modify: `tests/unit/collect_images_test.php`

- [ ] **Step 1: Write the failing tests** in `tests/unit/collect_images_test.php`

```php
test('collect-images appends a site-logo spec for a business site', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name'        => 'Hearth & Crumb',
        'site_type'   => 'business storefront',
        'area'        => 'bakery',
        'topic'       => 'artisan bread',
        'visual_vibe' => 'warm and rustic',
        'persona_name'=> '',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery in Portland']);
    $project->writeText('theme/parts/page-home--hero.html',
        '<!-- wp:image --><img src="theme:./assets/hero.jpg" alt="AI_IMAGE: loaves | hero | photo | landscape"><!-- /wp:image -->'
    );

    (new CollectImagesStep())->run($project);

    $byName = [];
    foreach ($project->readJson('images.json') as $row) {
        $byName[$row['filename']] = $row;
    }
    assert_true(isset($byName['hero.jpg']));
    $logo = $byName['site-logo.png'];
    assert_eq('theme:./assets/site-logo.png', $logo['src']);
    assert_eq('square', $logo['aspectRatio']);
    assert_eq('flat', $logo['style']);
    assert_eq('pending', $logo['status']);
    assert_eq('site-logo', $logo['role']);
    assert_eq([], $logo['sources']);
    assert_contains('bakery', $logo['subject']);
    assert_contains('no letters', $logo['subject']);
    assert_true(!str_contains($logo['subject'], 'Hearth'), 'site name stays out of the subject');
    assert_eq('site logo and site icon, small square mark in the header', $logo['pageContext']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images does not append a site-logo for a personal site', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name' => 'Ada', 'persona_name' => 'Ada Lovelace',
        'site_type' => 'portfolio', 'area' => 'studio',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'My paintings']);
    $project->writeText('theme/parts/page-home--hero.html', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

    (new CollectImagesStep())->run($project);

    foreach ($project->readJson('images.json') as $row) {
        assert_true(($row['filename'] ?? '') !== 'site-logo.png');
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

test('collect-images skips the synthetic mark when site-logo.png was already collected', function () {
    [$project, $tmp] = collect_fixture();
    $project->writeJson('siteSpec.json', [
        'name' => 'Hearth & Crumb', 'site_type' => 'business storefront',
        'area' => 'bakery', 'persona_name' => '',
    ]);
    $project->writeJson('meta.json', ['prompt' => 'bakery']);
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<img src="theme:./assets/site-logo.png" alt="AI_IMAGE: a sourdough loaf | hero | photo | square">'
    );

    (new CollectImagesStep())->run($project);

    $rows = $project->readJson('images.json');
    assert_eq(1, count($rows));
    assert_eq('site-logo.png', $rows[0]['filename']);
    assert_true(($rows[0]['role'] ?? null) !== 'site-logo');
    $warnings = implode("\n", $project->readJson('warnings.json')['collect-images']);
    assert_contains("file='images.json'", $warnings);
    assert_contains("asset='site-logo.png'", $warnings);
    assert_contains('the reserved site-logo filename was already collected from page markup', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});
```

- [ ] **Step 2: Run — FAIL** (no synthetic row).

- [ ] **Step 3: Implement**

`declaration()->reads`: add `'siteSpec.json'`, `'meta.json'`.

At the end of `run()`, **before** `$project->writeJson('images.json', array_values($byFilename));`:

```php
$this->maybeAppendSiteLogo($project, $byFilename, $warnings);
```

Private methods on `CollectImagesStep`:

```php
/**
 * @param array<string,array<string,mixed>> $byFilename
 * @param list<string> $warnings
 */
private function maybeAppendSiteLogo(Project $project, array &$byFilename, array &$warnings): void
{
    if (!$project->exists('siteSpec.json')) {
        return;
    }
    $siteSpec = $project->readJson('siteSpec.json');
    $prompt = '';
    if ($project->exists('meta.json')) {
        $prompt = (string) ($project->readJson('meta.json')['prompt'] ?? '');
    }
    if (!\Automattic\SiteBuild\BusinessSite::matches($siteSpec, $prompt)) {
        return;
    }
    if (isset($byFilename['site-logo.png'])) {
        $warnings[] = "file='images.json'; asset='site-logo.png'; delivered no synthetic mark; "
            . 'disposition=the reserved site-logo filename was already collected from page markup';
        return;
    }
    $byFilename['site-logo.png'] = self::siteLogoSpec($siteSpec);
}

/** @param array<mixed> $siteSpec @return array<string,mixed> */
private static function siteLogoSpec(array $siteSpec): array
{
    $area = trim((string) ($siteSpec['area'] ?? ''));
    $topic = trim((string) ($siteSpec['topic'] ?? ''));
    $vibe = trim((string) ($siteSpec['visual_vibe'] ?? ''));
    $about = $area !== '' ? $area : ($topic !== '' ? $topic : 'a small business');
    $mood = $vibe !== '' ? ", {$vibe} mood" : '';
    return [
        'filename'    => 'site-logo.png',
        'src'         => 'theme:./assets/site-logo.png',
        'subject'     => "simple geometric brand mark for {$about}{$mood}, single ink, no letters, no numerals, no wordmark, no signage",
        'pageContext' => 'site logo and site icon, small square mark in the header',
        'style'       => 'flat',
        'aspectRatio' => 'square',
        'status'      => 'pending',
        'sources'     => [],
        'role'        => 'site-logo',
    ];
}
```

Do not put `name` or `title` in the subject.

- [ ] **Step 4: `php tests/run.php` — PASS.**

- [ ] **Step 5: Commit** `feat(site-logo): collect a synthetic site-logo.png spec (BIGR-957)`

---

### Task 4: assemble-pages union into plugin/images.json

**Files:**
- Modify: `src/Steps/AssemblePagesStep.php` (`contentImages()`, around line 183)
- Modify: `tests/unit/assemble_pages_test.php`
- Modify: `tests/integration/dp_slice3_section_mode_test.php` (sha256 at line 154)

- [ ] **Step 1: Failing test** in `assemble_pages_test.php`

```php
test('assemble-pages unions a site-logo role into the plugin image manifest', function () {
    [$project, $tmp] = assemble_fixture();
    $project->writeText(
        'theme/parts/page-home--hero.html',
        '<!-- wp:cover {"url":"theme:./assets/hero-loaves.jpg"} --><div></div><!-- /wp:cover -->' . "\n"
    );
    $project->writeJson('images.json', [
        ['filename' => 'hero-loaves.jpg', 'src' => 'theme:./assets/hero-loaves.jpg', 'subject' => 'Golden sourdough loaves on a rack'],
        [
            'filename' => 'site-logo.png',
            'src' => 'theme:./assets/site-logo.png',
            'subject' => 'simple geometric brand mark for bakery, no letters',
            'role' => 'site-logo',
        ],
        ['filename' => 'wordmark.png', 'src' => 'theme:./assets/wordmark.png', 'subject' => 'Bakery wordmark'],
    ]);

    (new AssemblePagesStep())->run($project);

    assert_eq([
        ['filename' => 'hero-loaves.jpg', 'title' => 'Golden sourdough loaves on a rack'],
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ], $project->readJson('plugin/images.json')['images']);

    exec('rm -rf ' . escapeshellarg($tmp));
});
```

Existing test `'assemble-pages writes the plugin image manifest for content-referenced assets'` must still omit chrome `wordmark.png`.

- [ ] **Step 2: Run — FAIL** (manifest missing site-logo row).

- [ ] **Step 3: Extend `contentImages()`**

After the page-markup loop, before `return array_values($images);`:

```php
foreach ($specs as $spec) {
    if (!is_array($spec) || ($spec['role'] ?? '') !== 'site-logo') {
        continue;
    }
    $filename = (string) ($spec['filename'] ?? '');
    if ($filename === '') {
        continue;
    }
    $images[$filename] = [
        'filename' => $filename,
        'title'    => 'Site logo',
        'role'     => 'site-logo',
    ];
}
```

Update the `@return` docblock to allow an optional `role` key.

- [ ] **Step 4: Re-pin the frozen hash**

Run: `php tests/run-integration.php` (the dp_slice3 pin is in that suite).

The assertion at `tests/integration/dp_slice3_section_mode_test.php:154` fails with the new AssemblePagesStep hash. Replace the expected hex with `hash_file('sha256', repo_path('src/Steps/AssemblePagesStep.php'))` from a one-liner:

```bash
php -r "require 'tests/lib.php'; echo hash_file('sha256', repo_path('src/Steps/AssemblePagesStep.php')), PHP_EOL;"
```

If `repo_path` is not available that way, `shasum -a 256 src/Steps/AssemblePagesStep.php`.

Paste the new digest into the assertion. Do not change TransformSiteStep's pin.

- [ ] **Step 5: `php tests/run.php` and `php tests/run-integration.php` — PASS.**

- [ ] **Step 6: Commit** `feat(site-logo): ship site-logo.png on the plugin image manifest (BIGR-957)`

---

### Task 5: generate-images post-process and manifest reconciliation

**Files:**
- Modify: `src/Steps/GenerateImagesStep.php` (`declaration` writes, `finish()`, `shipPluginImages()`)
- Modify: `tests/unit/generate_images_test.php`

Site-logo post-process runs only when `ImageTransparency::available()` so a host without Imagick does not drop every mark (`isKeyed` is false without the extension).

- [ ] **Step 1: Failing tests**

```php
test('generate-images square-pads a keyed site-logo and ships it to the plugin', function () {
    if (!\Automattic\SiteBuild\ImageTransparency::available()) {
        skip_test('imagick not loaded');
    }
    [$project, $tmp] = generate_fixture();
    $mark = \Automattic\SiteBuild\ImageTransparency::keyOutBackground(
        transparency_fixture('white', 'red', 40, 20)
    );
    $project->writeJson('images.json', array_merge($project->readJson('images.json'), [[
        'filename' => 'site-logo.png',
        'src' => 'theme:./assets/site-logo.png',
        'subject' => 'simple geometric brand mark for bakery, no letters',
        'pageContext' => 'site logo',
        'style' => 'flat',
        'aspectRatio' => 'square',
        'status' => 'pending',
        'sources' => [],
        'role' => 'site-logo',
    ]]));
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'hero.jpg', 'title' => 'A bakery at dawn'],
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ]]);
    $images = new FakeImageClient();
    $images->bytesByPromptSubstring['brand mark'] = $mark;

    (new GenerateImagesStep($images))->run($project);

    assert_true($project->exists('plugin/images/site-logo.png'));
    assert_eq([512, 512], png_size($project->readText('theme/assets/site-logo.png')));
    $specs = $project->readJson('images.json');
    $logo = null;
    foreach ($specs as $row) {
        if (($row['filename'] ?? '') === 'site-logo.png') {
            $logo = $row;
        }
    }
    assert_eq('site-logo', $logo['role']);
    assert_eq('completed', $logo['status']);

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('generate-images drops the site-logo role when keying wipes out', function () {
    if (!\Automattic\SiteBuild\ImageTransparency::available()) {
        skip_test('imagick not loaded');
    }
    [$project, $tmp] = generate_fixture();
    $white = transparency_fixture('white', 'white', 32, 32);
    $project->writeJson('images.json', [[
        'filename' => 'site-logo.png',
        'src' => 'theme:./assets/site-logo.png',
        'subject' => 'simple geometric brand mark for bakery, no letters',
        'pageContext' => 'site logo',
        'style' => 'flat',
        'aspectRatio' => 'square',
        'status' => 'pending',
        'sources' => [],
        'role' => 'site-logo',
    ]]);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ]]);
    $images = new FakeImageClient($white);

    (new GenerateImagesStep($images))->run($project);

    assert_true($project->exists('theme/assets/site-logo.png'), 'completed generation still writes the theme copy');
    assert_true(!$project->exists('plugin/images/site-logo.png'), 'unkeyed mark is not shipped');
    $logo = $project->readJson('images.json')[0];
    assert_true(!isset($logo['role']));
    assert_eq('completed', $logo['status']);
    assert_eq(['images' => []], $project->readJson('plugin/images.json'));
    $warnings = implode("\n", $project->readJson('warnings.json')['generate-images']);
    assert_contains('site-logo.png', $warnings);
    assert_contains('unkeyed', $warnings);

    exec('rm -rf ' . escapeshellarg($tmp));
});
```

`transparency_fixture` / `png_size` live in `image_transparency_test.php`. Either `require_once` that file's helpers (they are functions, safe to load twice if wrapped) or duplicate the two tiny helpers in `generate_images_test.php`. Prefer requiring:

```php
require_once __DIR__ . '/image_transparency_test.php';
```

only if that file does not register tests on include — it does (`test(...)` at load). Do **not** require it. Copy `transparency_fixture`, `alpha_at`, `png_size` into `generate_images_test.php` under distinct names if they would collide, or extract them later. For this task, copy the three helpers into `generate_images_test.php`.

Check `FakeImageClient::bytesByPromptSubstring` matching: the composed prompt contains the subject, so `'brand mark'` matches. Confirm in `FakeImageClient.php` that generate() uses that map.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

`declaration()->writes`: add `'plugin/images.json'`.

In `finish()`, after `$bytes = ImageTransparency::keyOutBackground($bytes);` and still inside the png branch, before `assertDeliveryMime`:

```php
if (($specs[$i]['role'] ?? '') === 'site-logo' && ImageTransparency::available()) {
    if (!ImageTransparency::isKeyed($bytes)) {
        unset($specs[$i]['role']);
        $project->addWarnings($this->id(), [
            "file='theme/assets/{$filename}'; asset='site-logo.png'; authored role=site-logo; "
            . 'delivered unkeyed opaque PNG kept as a theme asset only; '
            . 'disposition=the white-background key wiped out or never ran, so the mark is not a usable logo; '
            . 'role dropped, plugin manifest row will be removed, title stays visible',
        ]);
    } else {
        $bytes = ImageTransparency::padToSquare($bytes);
    }
}
```

Replace `shipPluginImages()`:

```php
private function shipPluginImages(Project $project): void
{
    if (!$project->exists('plugin/images.json')) {
        return;
    }
    $roles = [];
    if ($project->exists('images.json')) {
        foreach ((array) $project->readJson('images.json') as $spec) {
            if (is_array($spec) && isset($spec['filename'])) {
                $roles[(string) $spec['filename']] = (string) ($spec['role'] ?? '');
            }
        }
    }
    $manifest = $project->readJson('plugin/images.json');
    $kept = [];
    foreach ((array) ($manifest['images'] ?? []) as $image) {
        if (!is_array($image)) {
            continue;
        }
        $filename = (string) ($image['filename'] ?? '');
        if ($filename === '') {
            continue;
        }
        if (($image['role'] ?? '') === 'site-logo' && ($roles[$filename] ?? '') !== 'site-logo') {
            continue;
        }
        if (!$project->exists('theme/assets/' . $filename)) {
            continue;
        }
        $project->writeText('plugin/images/' . $filename, $project->readText('theme/assets/' . $filename));
        $kept[] = $image;
    }
    $project->writeJson('plugin/images.json', ['images' => $kept]);
}
```

`writeJsonAtomic('images.json')` already runs immediately before `shipPluginImages()` (`GenerateImagesStep.php:247`).

- [ ] **Step 4: `php tests/run.php` — PASS.** Also assert the declaration writes include `plugin/images.json` in the existing declaration test, or add one line there.

- [ ] **Step 5: Commit** `feat(site-logo): pad keyed marks and drop wiped-out logos (BIGR-957)`

---

### Task 6: Header injects site-logo-mark

**Files:**
- Modify: `src/Steps/HeaderHeroStep.php`
- Modify: `tests/unit/header_hero_test.php`

- [ ] **Step 1: Failing tests**

```php
test('ensureSiteLogoMark inserts a tagged site-logo before the site title', function () {
    $in = hh_header('{"layout":{"type":"flex"}}', '<!-- wp:site-title /-->');
    $out = HeaderHeroStep::ensureSiteLogoMark($in);
    assert_contains('<!-- wp:site-logo {"width":48,"shouldSyncIcon":true,"className":"site-logo-mark"} /-->', $out);
    $logoAt = strpos($out, 'wp:site-logo');
    $titleAt = strpos($out, 'wp:site-title');
    assert_true($logoAt !== false && $titleAt !== false && $logoAt < $titleAt);
    assert_eq($out, HeaderHeroStep::ensureSiteLogoMark($out), 'idempotent when a logo already exists');
});

test('ensureSiteLogoMark does not retag an authored branded-lockup logo', function () {
    $in = hh_header(
        '{"layout":{"type":"flex"}}',
        '<!-- wp:site-logo {"width":48,"shouldSyncIcon":true} /-->' . "\n" . '<!-- wp:site-title /-->'
    );
    $out = HeaderHeroStep::ensureSiteLogoMark($in);
    assert_eq($in, $out);
    assert_true(!str_contains($out, 'site-logo-mark'));
});

test('header-hero injects the mark on a business site and not on a personal site', function () {
    with_project('builder_hh_logo_', function ($project) {
        $project->writeJson('siteSpec.json', [
            'name' => 'Hearth & Crumb', 'site_type' => 'business storefront',
            'area' => 'bakery', 'persona_name' => '',
        ]);
        $project->writeJson('meta.json', ['prompt' => 'A neighborhood bakery']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'none']);
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]];
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'foreground-split');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('80') . "\n");

        putenv(\Automattic\SiteBuild\AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        $header = $project->readText('theme/parts/header.html');
        assert_contains('site-logo-mark', $header);
        assert_contains('wp:site-title', $header);
    });

    with_project('builder_hh_nologo_', function ($project) {
        $project->writeJson('siteSpec.json', [
            'name' => 'Ada', 'persona_name' => 'Ada Lovelace',
            'site_type' => 'portfolio', 'area' => 'studio',
        ]);
        $project->writeJson('meta.json', ['prompt' => 'My paintings']);
        $project->writeJson('theme/theme.json', hh_theme_json());
        $project->writeJson('designDirection.json', ['canvas' => 'full-bleed', 'motion' => 'none']);
        $pages = [[
            'slug' => 'home', 'title' => 'Home', 'front' => true,
            'sections' => [['slug' => 'hero', 'role' => 'hero', 'layout_archetype' => 'centered-stack', 'background' => 'base']],
        ]];
        $project->writeJson('pages.json', ['pages' => $pages]);
        hh_above_fold($project, $pages, 'foreground-split');
        $project->writeText('theme/parts/header.html', hh_header('{"layout":{"type":"constrained"}}') . "\n");
        $project->writeText('theme/parts/page-home--hero.html', hh_cover('80') . "\n");

        putenv(\Automattic\SiteBuild\AboveFoldContract::HEADER_ARCHETYPE_ENV);
        (new HeaderHeroStep())->run($project);

        assert_true(!str_contains($project->readText('theme/parts/header.html'), 'wp:site-logo'));
    });
});
```

- [ ] **Step 2: Run — FAIL** (`ensureSiteLogoMark` missing).

- [ ] **Step 3: Implement**

Add `'meta.json'` to `declaration()->reads`.

Public static on `HeaderHeroStep`:

```php
public static function ensureSiteLogoMark(string $markup): string
{
    if (preg_match('/<!--\s+wp:site-logo\b/', $markup) === 1) {
        return $markup;
    }
    $comment = BlockMarkup::serializeComment('site-logo', [
        'width'          => 48,
        'shouldSyncIcon' => true,
        'className'      => 'site-logo-mark',
    ], true);
    if (preg_match('/<!--\s+wp:site-title\b/', $markup) === 1) {
        return (string) preg_replace('/(<!--\s+wp:site-title\b)/', $comment . "\n\$1", $markup, 1);
    }
    return $comment . "\n" . $markup;
}
```

In `run()`, after `$header = $project->readText(...)` (line 250) and after `$siteSpec` / `$siteName` are known (line 214–215). Read prompt from `meta.json` the same way as collect-images. If `BusinessSite::matches($siteSpec, $prompt)`, `$header = self::ensureSiteLogoMark($header);`.

Missing `header.html` stays fatal (existing contract). No site-title → prepend the comment to the document.

- [ ] **Step 4: `php tests/run.php` — PASS.** Confirm serialized attribute order is `width`, `shouldSyncIcon`, `className` (PHP array insertion order + `json_encode`).

- [ ] **Step 5: Commit** `feat(site-logo): inject site-logo-mark into business headers (BIGR-957)`

---

### Task 7: Title-hiding CSS

**Files:**
- Modify: `src/Steps/FinalizeThemeStep.php` (`functionsPhp()`, ~line 526)
- Modify: `tests/unit/finalize_theme_test.php`

- [ ] **Step 1: Failing test**

```php
test('finalize-theme inlines header title-hiding keyed on site-logo-mark', function () {
    $tmp = sys_get_temp_dir() . '/builder_fin_logo_' . uniqid();
    $project = (new ProjectStore($tmp))->create('Forno Vero');
    $project->writeText('theme/style.css', "/*\nTheme Name: Forno Vero\n*/\n");
    finalize_static_header($project);

    quietly(fn () => (new FinalizeThemeStep())->run($project));

    $php = $project->readText('theme/functions.php');
    assert_contains("wp_add_inline_style('forno-vero-style'", $php);
    assert_contains('.wp-block-site-logo.site-logo-mark img', $php);
    assert_contains('.site-header-shell:has(', $php);
    assert_contains('header:has(', $php);
    assert_true(!str_contains($php, 'BusinessSite'), 'rule is inert without the class; no matcher in functions.php');

    exec('rm -rf ' . escapeshellarg($tmp));
});
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement**

In `functionsPhp()`, immediately after the `wp_enqueue_style('{$slug}-style', ...)` line inside the `wp_enqueue_scripts` callback, append:

```php
                wp_add_inline_style('{$slug}-style', '.site-header-shell:has(.wp-block-site-logo.site-logo-mark img) .wp-block-site-title,header:has(.wp-block-site-logo.site-logo-mark img) .wp-block-site-title{display:none}');
```

Keep it a single-quoted CSS string inside the generated PHP. Do not append to `theme/style.css` (CssScrub / contrast parse that file).

- [ ] **Step 4: `php tests/run.php` — PASS.** `php -l` on functions.php already covered by the existing finalize test.

- [ ] **Step 5: Commit** `feat(site-logo): hide header title while the injected mark renders (BIGR-957)`

---

### Task 8: Seeder fallback, theme mods, owned restore

**Files:**
- Modify: `src/Steps/ScaffoldPluginStep.php` (`PLUGIN_PHP` heredoc)
- Modify: `tests/unit/scaffold_plugin_test.php`

- [ ] **Step 1: Extend WP stubs** in `scaffold_plugin_test.php` (inside the `if (!function_exists('get_option'))` block and `wp_stub_reset`):

```php
// wp_stub_reset():
$GLOBALS['wp_theme_mods'] = [];
$GLOBALS['wp_stylesheet_directory'] = sys_get_temp_dir() . '/wp-stub-theme';

function get_stylesheet_directory(): string
{
    return $GLOBALS['wp_stylesheet_directory'];
}
function get_theme_mod(string $key, $default = false)
{
    return $GLOBALS['wp_theme_mods'][$key] ?? $default;
}
function set_theme_mod(string $key, $value): bool
{
    $GLOBALS['wp_theme_mods'][$key] = $value;
    return true;
}
function remove_theme_mod(string $key): void
{
    unset($GLOBALS['wp_theme_mods'][$key]);
}
```

The plugin state option key is `{{FN_PREFIX}}_content_state` (`ScaffoldPluginStep.php` define). For slug `hearth-crumb` that is `hearth_crumb_content_state`.

Failing tests:

```php
test('seeder imports from theme assets when plugin/images is empty and sets logo mods', function () {
    $slug = 'hearth-crumb';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'hero.jpg', 'title' => 'Loaves'],
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ]]);
    $themeDir = sys_get_temp_dir() . '/wp-stub-theme-' . uniqid();
    @mkdir($themeDir . '/assets', 0777, true);
    file_put_contents($themeDir . '/assets/hero.jpg', 'JPEG');
    file_put_contents($themeDir . '/assets/site-logo.png', 'PNG');

    wp_stub_reset();
    $GLOBALS['wp_stylesheet_directory'] = $themeDir;
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();

    assert_eq(2, count($GLOBALS['wp_attachments']));
    $logoId = (int) get_theme_mod('custom_logo');
    assert_true($logoId > 0, 'custom_logo set');
    assert_eq($logoId, (int) get_option('site_icon'));
    $state = get_option('hearth_crumb_content_state');
    assert_true($state['changed_logo']);
    assert_eq($logoId, $state['logo_attachment_id']);

    exec('rm -rf ' . escapeshellarg($tmp));
    exec('rm -rf ' . escapeshellarg($themeDir));
});

test('seeder restore skips logo mods the owner replaced', function () {
    $slug = 'hearth-crumb';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ]]);
    @mkdir($project->pluginPath('images'), 0777, true);
    file_put_contents($project->pluginPath('images/site-logo.png'), 'PNG');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();
    $seeded = (int) get_theme_mod('custom_logo');
    set_theme_mod('custom_logo', 999);
    update_option('site_icon', 999);

    (content_fn($slug, 'deactivate'))();

    assert_eq(999, (int) get_theme_mod('custom_logo'));
    assert_eq(999, (int) get_option('site_icon'));
    assert_true(!isset($GLOBALS['wp_attachments'][$seeded]));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('seeder restore puts back previous logo mods when still owned', function () {
    $slug = 'hearth-crumb';
    [$project, $tmp] = scaffold_plugin_fixture($slug);
    $project->writeJson('plugin/images.json', ['images' => [
        ['filename' => 'site-logo.png', 'title' => 'Site logo', 'role' => 'site-logo'],
    ]]);
    @mkdir($project->pluginPath('images'), 0777, true);
    file_put_contents($project->pluginPath('images/site-logo.png'), 'PNG');

    wp_stub_reset();
    require_once $project->pluginPath('site-content.php');
    (content_fn($slug, 'activate'))();
    $seeded = (int) get_theme_mod('custom_logo');
    assert_true($seeded > 0);

    (content_fn($slug, 'deactivate'))();

    assert_eq(false, get_theme_mod('custom_logo', false));
    assert_eq(false, get_option('site_icon', false));

    exec('rm -rf ' . escapeshellarg($tmp));
});

test('import_images applies the same containment guard to the theme assets root', function () {
    [$project, $tmp] = scaffold_plugin_fixture();
    $php = $project->readText(ScaffoldPluginStep::MAIN_FILE);
    assert_contains('get_stylesheet_directory', $php);
    assert_contains('DIRECTORY_SEPARATOR) !== 0', $php);
    exec('rm -rf ' . escapeshellarg($tmp));
});
```

- [ ] **Step 2: Run — FAIL** (no theme fallback / no theme mods).

- [ ] **Step 3: Implement in `PLUGIN_PHP`**

Replace the body of `{{FN_PREFIX}}_content_import_images` so each image:

1. Validates basename / regex as today.
2. Tries `$pluginRoot = realpath(__DIR__ . '/images')` then `$path = $pluginRoot . DIRECTORY_SEPARATOR . $filename` if `is_file`.
3. Else `$themeRoot = realpath(get_stylesheet_directory() . '/assets')` (only if `function_exists('get_stylesheet_directory')`) and the same `is_file` join.
4. If neither file exists, `continue`.
5. `$real = realpath($path)`; require `$real` starts with `$root . DIRECTORY_SEPARATOR` for the root that supplied the file.
6. Upload / insert as today.
7. Map value includes `'role' => isset($image['role']) ? (string) $image['role'] : ''`.

Do not `continue` the whole loop when `plugin/images` is missing.

In `{{FN_PREFIX}}_content_activate`, after `$image_map = ...import_images(...)`:

```php
foreach ($image_map as $imported) {
    if (!is_array($imported) || ($imported['role'] ?? '') !== 'site-logo') {
        continue;
    }
    $id = (int) ($imported['id'] ?? 0);
    if ($id < 1) {
        continue;
    }
    $state['changed_logo'] = true;
    $state['logo_attachment_id'] = $id;
    $state['custom_logo'] = get_theme_mod('custom_logo');
    $state['site_icon'] = get_option('site_icon');
    set_theme_mod('custom_logo', $id);
    update_option('site_icon', $id);
    break;
}
```

Initialize in the `$state` array: `'changed_logo' => false`.

In `{{FN_PREFIX}}_content_deactivate`, **before** the `wp_delete_attachment` loop:

```php
if (!empty($state['changed_logo'])) {
    $owned = isset($state['logo_attachment_id'])
        && (int) get_theme_mod('custom_logo') === (int) $state['logo_attachment_id']
        && (int) get_option('site_icon') === (int) $state['logo_attachment_id'];
    if ($owned) {
        if (empty($state['custom_logo'])) {
            remove_theme_mod('custom_logo');
        } else {
            set_theme_mod('custom_logo', $state['custom_logo']);
        }
        if (empty($state['site_icon'])) {
            delete_option('site_icon');
        } else {
            update_option('site_icon', $state['site_icon']);
        }
    }
}
```

Then delete attachments as today.

Ownership: restore `custom_logo` and `site_icon` independently. Each is restored only while its live value still equals `logo_attachment_id`. Changing one must not strand the other pointing at an attachment the deactivate loop is about to delete.

- [ ] **Step 4: `php tests/run.php` — PASS.** `php -l` on a generated `plugin/site-content.php`.

- [ ] **Step 5: Commit** `feat(site-logo): import logo into media and set site icon (BIGR-957)`

---

### Task 9: Full suite + visual note

- [ ] **Step 1: Run `php tests/run.php`** — Expected: passed count is baseline (3600 on trunk) plus the tests this work added; failed is still exactly the 9 pre-existing names on trunk. A tenth failure is ours. Do not chase the trunk failures.

- [ ] **Step 2: Run `php tests/run-integration.php`** — Expected: 33 passed / 1 failed (G4), including the re-pinned AssemblePagesStep hash.

- [ ] **Step 3: Visual check (not a unit test).** On a real business build: `php bin/build.php "a neighborhood bakery in Portland" --slug=logo-bakery --with-images --no-serve`. Confirm: header shows the mark not the title; footer still has the title; Media Library has "Site logo"; Site Icon is set. If the mark shoves the nav, that is the accepted geometry cost in the spec — record it in the PR, do not add a new heuristic in this change. If the title and tagline sit in an inner `blockGap: 0` group, the 48px mark stacks above them inside that group (the spec's sibling-of-title rule) — if that looks wrong, revisit the insertion rule, not a one-off CSS patch.

- [ ] **Step 4: No extra commit unless a test fix was needed.**

---

## Spec coverage

| Spec section | Task |
|---|---|
| BusinessSite matcher, prompt forwarded, no `services?` | 1 |
| `padToSquare` / `isKeyed` | 2 |
| collect-images spec, collision warning string | 3 |
| plugin/images.json union, title `Site logo` | 4 |
| AssemblePagesStep sha256 re-pin | 4 |
| finish() pad + wipeout drop role | 5 |
| shipPluginImages reconciliation + writes | 5 |
| Header inject `site-logo-mark` | 6 |
| Authored lockup not retagged | 6 |
| Inline CSS on style handle, both selectors | 7 |
| Seeder fallback + containment | 8 |
| `changed_logo` / ownership restore before delete | 8 |
| Failures never abort | 3–8 (no throws on missing mark) |
| Out of scope (Easy Editor, flattened icon, header.md) | none — do not implement |
| iOS-black limitation | spec only |
| Visual geometry check | 9 |

## Type consistency

- Role string is always `site-logo`.
- Class name is always `site-logo-mark`.
- Filename is always `site-logo.png`.
- Manifest title is always `Site logo`.
- `BusinessSite::matches(array $siteSpec, string $prompt = ''): bool`
- `ImageTransparency::padToSquare(string $pngBytes, int $minSide = 512): string`
- `ImageTransparency::isKeyed(string $pngBytes): bool`
- `HeaderHeroStep::ensureSiteLogoMark(string $markup): string`
- State keys: `changed_logo`, `logo_attachment_id`, `custom_logo`, `site_icon`
