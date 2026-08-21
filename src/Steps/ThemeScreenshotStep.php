<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\GeminiImage;
use Automattic\SiteBuild\Narrator;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;
use Automattic\SiteBuild\ThemeScreenshot;

/**
 * Step (deterministic): write the theme's screenshot — the preview card
 * WordPress shows in Appearance > Themes and in every theme picker.
 *
 * Input:  plugin/pages.json + plugin/pages/*.html (to find the hero image),
 *         theme/assets/* (the generated pixels), theme/theme.json (the palette).
 * Output: the card at 1200x900 — theme/screenshot.jpg for a cropped photo,
 *         theme/screenshot.png for a palette poster. WordPress reads either;
 *         see ThemeScreenshot for why the two paths use different formats.
 *         Only ever one of them exists: WordPress prefers .png over .jpg, so
 *         a leftover poster from an earlier run would outrank the real
 *         screenshot this one just wrote. Writing either card deletes the
 *         other.
 *
 * The source is the front page's first photographic image, cover-cropped to the
 * card. That is usually the hero's image, because the hero is the front page's
 * first section — but it is a heuristic, not the hero contract: a hero whose
 * blueprint carries no image (designDirection.json's media_mode) hands the card
 * to the next section's photo instead. Reading the declared hero would be
 * better, and is blocked on knowing which part each image came from:
 * aboveFold.json names the hero part, but assemble-pages has already inlined
 * and deleted the parts by the time this step runs, and images.json does not
 * record the part a reference came from. BIGR-853 adds that provenance.
 *
 * When the build generated no images the card is composed from the theme's
 * palette instead (see ThemeScreenshot::poster).
 *
 * That fallback is the common case, not an edge case: image generation is
 * opt-in and runs outside the pipeline, so in-pipeline this step almost always
 * finds an empty assets directory. Hosts that generate images run this step
 * again afterwards — it is idempotent and re-reads the assets directory, so
 * the second run replaces the palette poster with the real hero. bin/build.php
 * does exactly that.
 *
 * Only photographic assets are considered, which GeminiImage::mimeForFilename
 * already decides for the whole pipeline: `.png` is the transparent-background
 * convention for decorative ornaments, which make a poor preview of a whole
 * site and would need flattening besides.
 *
 * A screenshot is a nicety, so nothing here is fatal. A host with neither
 * imagick nor gd loaded ships the theme without a preview card and says so on
 * the console; a source image that will not decode degrades to the poster and
 * records that in warnings.json.
 */
final class ThemeScreenshotStep implements Step
{
    /** The cropped photo. JPEG, because a lossless photo costs megabytes. */
    private const PHOTO_PATH = 'theme/screenshot.jpg';

    /** The palette poster. PNG, because flat rectangles compress to nothing. */
    private const POSTER_PATH = 'theme/screenshot.png';

    public function id(): string
    {
        return 'theme-screenshot';
    }

    public function label(): string
    {
        return 'Theme screenshot';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: [
                'images.json',
                'theme/theme.json',
                'theme/assets/*',
                'plugin/pages.json',
                'plugin/pages/*',
            ],
            writes: [
                self::PHOTO_PATH,
                self::POSTER_PATH,
                'warnings.json',
            ],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        // Not a warnings.json row: warnings.json is the repair pass's queue and
        // no repair can install a PHP extension. The console and the build
        // report are where a host capability belongs.
        if (!ThemeScreenshot::available()) {
            $project->replaceWarnings($this->id(), []);
            Narrator::write(
                "  theme-screenshot: neither imagick nor gd is loaded; no preview card written\n"
            );
            return;
        }

        // Undecodable assets are only worth reporting if none of them worked —
        // one broken candidate the next one covers for changed nothing about
        // the delivered theme, and warnings.json is the repair pass's queue.
        $undecodable = [];
        foreach (self::heroCandidates($project) as $filename) {
            try {
                $photo = ThemeScreenshot::cover($project->readText('theme/assets/' . $filename));
            } catch (\Throwable) {
                $undecodable[] = 'assets/' . $filename;
                continue;
            }
            if ($photo !== null) {
                self::writeCard($project, self::PHOTO_PATH, $photo);
                $project->replaceWarnings($this->id(), []);
                Narrator::write("  theme-screenshot: cropped from assets/{$filename}\n");
                return;
            }
            $undecodable[] = 'assets/' . $filename;
        }

        $palette = $project->exists('theme/theme.json')
            ? ContrastFixStep::paletteMap($project->readJson('theme/theme.json'))
            : [];
        $poster = ThemeScreenshot::poster($palette);
        $delivered = $poster === null ? 'removed' : 'a palette poster';
        $deliveredFile = $poster === null ? 'removed' : self::POSTER_PATH;
        $warnings = $undecodable === [] ? [] : [
            "file='{$deliveredFile}'; source=" . implode(', ', $undecodable)
            . "; authored=cropped photo; delivered={$delivered}; "
            . 'disposition=no generated image would decode',
        ];
        $project->replaceWarnings($this->id(), $warnings);

        if ($poster === null) {
            Narrator::write("  theme-screenshot: could not draw the palette poster; skipped\n");
            return;
        }
        self::writeCard($project, self::POSTER_PATH, $poster);
        Narrator::write("  theme-screenshot: composed from the theme palette\n");
    }

    /**
     * Write one card and remove the other, so the theme never holds two and
     * WordPress's png-before-jpg preference cannot serve the stale one.
     */
    private static function writeCard(Project $project, string $path, string $bytes): void
    {
        // Remove the other card first: WordPress prefers .png over .jpg, so a
        // leftover poster would outrank a photo that has already been written.
        $other = $path === self::PHOTO_PATH ? self::POSTER_PATH : self::PHOTO_PATH;
        @unlink($project->path($other));
        $project->writeText($path, $bytes);
    }

    /**
     * Photographic theme assets that exist on disk, best first: the front
     * page's images in document order (so the hero leads), then any remaining
     * generated image, so a site whose only picture sits in the header still
     * gets a real screenshot.
     *
     * @return list<string> filenames under theme/assets/
     */
    private static function heroCandidates(Project $project): array
    {
        $ordered = [];
        foreach ([...self::frontPageAssets($project), ...self::generatedAssets($project)] as $filename) {
            $safe = self::assetFilename($filename);
            if ($safe === null) {
                continue;
            }
            $rel = 'theme/assets/' . $safe;
            if (
                GeminiImage::mimeForFilename($safe) === 'image/jpeg'
                && is_file($project->path($rel))
            ) {
                $ordered[$safe] = true;
            }
        }
        return array_keys($ordered);
    }

    /**
     * Asset filenames referenced by the front page's content, in document
     * order. Both spellings are matched: assemble-pages writes the theme
     * "theme:./assets/<file>" form, and generate-images later rewrites the
     * same references to "/wp-content/themes/<slug>/assets/<file>".
     *
     * @return list<string>
     */
    private static function frontPageAssets(Project $project): array
    {
        if (!$project->exists('plugin/pages.json')) {
            return [];
        }
        $pages = (array) ($project->readJson('plugin/pages.json')['pages'] ?? []);
        $front = null;
        foreach ($pages as $page) {
            if (!empty($page['front'])) {
                $front = (string) ($page['slug'] ?? '');
                break;
            }
        }
        // pages.json is ordered; without an explicit front flag the first page
        // is the homepage.
        $front ??= (string) ($pages[0]['slug'] ?? '');
        if ($front === '' || !$project->exists("plugin/pages/{$front}.html")) {
            return [];
        }

        // Anchored on the two spellings the pipeline actually writes, so a
        // reference to some other plugin's assets/ directory cannot be mistaken
        // for a theme asset.
        preg_match_all(
            '~(?:theme:\./|/wp-content/themes/[^/"\']+/)assets/([A-Za-z0-9._-]+\.[A-Za-z0-9]+)~',
            $project->readText("plugin/pages/{$front}.html"),
            $matches
        );
        return $matches[1];
    }

    /**
     * Every asset the image step reports as generated, in images.json order.
     *
     * @return list<string>
     */
    private static function generatedAssets(Project $project): array
    {
        if (!$project->exists('images.json')) {
            return [];
        }
        $filenames = [];
        foreach ((array) $project->readJson('images.json') as $spec) {
            $filename = is_array($spec) ? (string) ($spec['filename'] ?? '') : '';
            $safe = self::assetFilename($filename);
            if ($safe !== null && ($spec['status'] ?? '') === 'completed') {
                $filenames[] = $safe;
            }
        }
        return $filenames;
    }

    /**
     * A theme-assets filename that cannot walk out of theme/assets/.
     * images.json is generated, but a host-merged or truncated spec must not
     * become a path; basename alone still leaves ".." and odd separators.
     */
    private static function assetFilename(string $filename): ?string
    {
        $normalized = str_replace('\\', '/', $filename);
        $base = basename($normalized);
        if ($base === '' || $base === '.' || $base === '..' || $base !== $normalized) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]+\.[A-Za-z0-9]+$/', $base) !== 1) {
            return null;
        }
        return $base;
    }
}
