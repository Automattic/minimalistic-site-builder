<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\BlockFixer;
use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ContrastFix;
use Automattic\SiteBuild\ContrastMath;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\Step;

/**
 * Step (post-images, deterministic): verify cover text against the REAL image.
 *
 * Input:  theme/assets/* (generated images) + theme/parts|templates/*.html
 * Output: covers rewritten (higher dimRatio and/or flipped text colors) so
 *         their inner text reaches WCAG contrast against the dimmed image;
 *         findings appended to logs/contrast-report.txt.
 *
 * ContrastFixStep runs in the pipeline before any image exists, so covers
 * are only floored to dimRatio 40 there — the LLM picked the text color
 * against an image it had never seen. Once GenerateImagesStep has produced
 * the actual pixels this step stops guessing: it measures the average color
 * of the image region behind the content (by contentPosition), composites
 * the overlay math on top (dimRatio N = N%-opacity overlay), and if the text
 * still misses its threshold bumps dimRatio in +10 steps (up to 80) or flips
 * the text to whichever of base/contrast reads, whichever is reached first.
 *
 * Mutations rewrite the block-comment JSON only, then the injected
 * BlockFixer re-serializes the saved HTML from those attributes (the same
 * contract as ContrastFixStep). Fails soft: no imagick, unreadable images,
 * or unparsable covers are skipped with a report line — a build must never
 * die on a readability polish.
 */
final class CoverContrastStep implements Step
{
    private const REPORT_FILE = 'contrast-report.txt';

    /** Above this the image is more curtain than picture. */
    public const MAX_DIM = 80;

    /**
     * A region whose interquartile luminance spread exceeds this is "busy"
     * (sky + buildings + crowd in one area): flat-sample ratios can pass
     * while the texture still defeats the text, so busy regions additionally
     * require a real overlay behind the content (BUSY_MIN_ALPHA effective
     * opacity) before any ratio is trusted.
     */
    public const BUSY_SPREAD = 0.25;

    /** Minimum effective overlay opacity (stop alpha × dim) behind text on a busy region. */
    public const BUSY_MIN_ALPHA = 0.35;

    public function __construct(private BlockFixer $fixer) {}

    public function id(): string
    {
        return 'cover-contrast';
    }

    public function label(): string
    {
        return 'Cover contrast vs real images';
    }

    public function run(Project $project): void
    {
        if (!$project->exists('theme/theme.json')) {
            return;
        }
        $themeJson = $project->readJson('theme/theme.json');
        $palette = ContrastFixStep::paletteMap($themeJson);
        $gradients = ContrastFixStep::gradientMap($themeJson);
        $helper = new ContrastFix($palette, $gradients);

        $report = [];
        $repaired = 0;
        $changedFiles = false;

        if (!extension_loaded('imagick')) {
            $report[] = 'cover-contrast: imagick not available — covers not verified against images';
        } else {
            foreach (['parts', 'templates'] as $dir) {
                foreach (glob($project->themePath($dir . '/*.html')) ?: [] as $abs) {
                    $rel = $dir . '/' . basename($abs);
                    $doc = BlockMarkup::parse($project->readText('theme/' . $rel));
                    $fileRepairs = $this->fixCovers($project, $doc, $helper, $rel, $report);
                    if ($fileRepairs > 0) {
                        $project->writeText('theme/' . $rel, $doc->render());
                        $changedFiles = true;
                        $repaired += $fileRepairs;
                    }
                }
            }
        }

        // Re-sync the saved HTML with the rewritten attributes, and re-assert
        // the header/footer layout contract the fixer can migrate away
        // (same follow-up FixBlocksStep does).
        if ($changedFiles) {
            try {
                $this->fixer->fix($project->themePath());
                foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
                    if (!$project->exists('theme/' . $rel)) {
                        continue;
                    }
                    $markup = $project->readText('theme/' . $rel);
                    $constrained = SectionsStep::constrainedPart($markup);
                    if ($constrained !== $markup) {
                        $project->writeText('theme/' . $rel, $constrained);
                    }
                }
            } catch (\RuntimeException $e) {
                $report[] = 'cover-contrast: block re-serialization failed: ' . $e->getMessage();
            }
        }

        if ($report !== []) {
            $existing = $project->exists('logs/' . self::REPORT_FILE)
                ? rtrim($project->readText('logs/' . self::REPORT_FILE)) . "\n" : '';
            $project->writeText(
                'logs/' . self::REPORT_FILE,
                $existing . "-- cover contrast (measured against generated images) --\n"
                . implode("\n", $report) . "\n"
            );
        }

        echo sprintf(
            "  cover-contrast: %d cover(s) adjusted (details: logs/%s)\n",
            $repaired, self::REPORT_FILE
        );
    }

    /**
     * Find every image-backed cover with inner text in a parsed document and
     * repair the failing ones in place. Returns how many covers changed.
     *
     * @param list<string> $report
     */
    private function fixCovers(Project $project, BlockMarkup $doc, ContrastFix $helper, string $rel, array &$report): int
    {
        $repairs = 0;
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'cover') {
                continue;
            }
            $attrs = $doc->attrs($i) ?? [];
            $url = $attrs['url'] ?? null;
            if (!is_string($url) || ($path = $this->assetPath($project, $url)) === null) {
                continue;
            }
            $texts = $this->coverTexts($doc, $i, $helper);
            if ($texts === []) {
                continue;
            }

            $position = is_string($attrs['contentPosition'] ?? null) ? $attrs['contentPosition'] : '';
            $image = self::regionStats($path, $position);
            if ($image === null) {
                $report[] = "[{$rel}] cover image unreadable, skipped: " . basename($path);
                continue;
            }

            $overlay = self::overlayForPosition($helper->coverOverlayColors($attrs), $position);
            $dim = (int) ($attrs['dimRatio'] ?? 100);
            $candidates = array_filter([
                'base'     => $helper->rgbFor('base'),
                'contrast' => $helper->rgbFor('contrast'),
            ]);

            $plan = self::planCover($texts, $overlay, $dim, $image, $candidates);
            $luma = sprintf(
                'image luminance %.2f–%.2f',
                ContrastMath::luminance($image[0]),
                ContrastMath::luminance($image[count($image) - 1])
            );

            if ($plan['dim'] === $dim && $plan['swaps'] === [] && $plan['overlay'] === null) {
                $report[] = "[{$rel}] cover ok at dimRatio {$dim} ({$luma})";
                continue;
            }

            if ($plan['overlay'] !== null) {
                // The designed overlay can't make any text color read (busy
                // image, or a gradient with ~zero alpha behind the content):
                // replace it with a solid palette overlay. Strip the stale
                // gradient/overlay class tokens so the fixer's custom-class
                // rescue can't keep the old span styling around.
                $oldOverlaySlug = is_string($attrs['overlayColor'] ?? null) ? $attrs['overlayColor'] : null;
                $gradientSlug = is_string($attrs['gradient'] ?? null) ? $attrs['gradient'] : null;
                unset($attrs['gradient'], $attrs['customGradient']);
                $attrs['overlayColor'] = $plan['overlay'];
                foreach (array_filter([
                    $gradientSlug !== null ? "has-{$gradientSlug}-gradient-background" : null,
                    'wp-block-cover__gradient-background',
                    'has-background-gradient',
                    $oldOverlaySlug !== null ? "has-{$oldOverlaySlug}-background-color" : null,
                ]) as $stale) {
                    $doc->replaceInOwnHtml($i, ' ' . $stale, '');
                }
            }
            if ($plan['dim'] !== $dim || $plan['overlay'] !== null) {
                $attrs['dimRatio'] = $plan['dim'];
                $doc->setAttrs($i, $attrs);
                ContrastFix::swapDimClass($doc, $i, $dim, $plan['dim']);
            }
            $ownSlugs = array_column($texts, 'ownSlug', 'index');
            foreach ($plan['swaps'] as $textIndex => $slug) {
                $textAttrs = $doc->attrs($textIndex) ?? [];
                if (($textAttrs['textColor'] ?? null) === $slug) {
                    continue;
                }
                $textAttrs['textColor'] = $slug;
                unset($textAttrs['style']['color']['text']);
                ContrastFix::pruneEmpty($textAttrs);
                $doc->setAttrs($textIndex, $textAttrs);
                $oldSlug = $ownSlugs[$textIndex] ?? null;
                if ($oldSlug !== null && $oldSlug !== $slug) {
                    $doc->replaceInOwnHtml($textIndex, "has-{$oldSlug}-color", "has-{$slug}-color");
                }
            }
            $repairs++;

            $report[] = sprintf(
                '[%s] cover repaired: dimRatio %d → %d%s%s (%s)%s',
                $rel, $dim, $plan['dim'],
                $plan['overlay'] === null ? '' : ", overlay → solid {$plan['overlay']} (designed overlay ineffective behind the content)",
                $plan['swaps'] === [] ? '' : ', text → ' . implode(', ', array_unique($plan['swaps'])),
                $luma,
                $plan['pass'] ? '' : ' — still below threshold at max dim, best effort'
            );
        }
        return $repairs;
    }

    /**
     * Decide how to make a cover's text readable against the measured image:
     * first try raising dimRatio alone (keeping the design's colors), then
     * dimRatio plus swapping failing texts to a candidate palette color.
     *
     * When neither works the overlay itself is the problem — a busy image
     * whose dark and bright areas defeat every text color, or a gradient
     * whose stop behind the content has ~zero alpha (gradient darkening the
     * bottom, contentPosition at the top), which makes raising dimRatio a
     * no-op. Escalation: replace the overlay with a SOLID palette color at
     * the lowest dim that makes a candidate text color read everywhere
     * ('overlay' in the result). Last resort: max dim + best candidate.
     *
     * Pure — unit-testable without imagick or files.
     *
     * @param list<array{index:int, rgb: array{0:int,1:int,2:int}, threshold: float}> $texts
     * @param list<array{rgb: array{0:int,1:int,2:int}, alpha: float}> $overlay
     * @param list<array{0:int,1:int,2:int}> $image region samples behind the
     *        content (see regionStats) — every one must pass
     * @param array<string, array{0:int,1:int,2:int}> $candidates slug => rgb
     * @return array{dim:int, swaps: array<int,string>, overlay: ?string, pass: bool}
     */
    public static function planCover(array $texts, array $overlay, int $dim, array $image, array $candidates): array
    {
        $start = max($dim, ContrastFix::COVER_DIM_FLOOR);
        $steps = [];
        for ($d = $start; $d <= self::MAX_DIM; $d += 10) {
            $steps[] = $d;
        }
        if ($steps === []) {
            $steps = [$dim]; // already dimmed past the cap — evaluate as-is
        }

        // On a busy region, ratios against flat samples aren't enough — the
        // texture itself defeats text without a real veil behind it. Only
        // trust dim steps whose effective overlay opacity reaches the busy
        // minimum; a zero-alpha gradient stop can never qualify, which is
        // what pushes misaligned gradients into the solid-overlay escalation.
        $busy = ContrastMath::luminance($image[count($image) - 1]) - ContrastMath::luminance($image[0])
            > self::BUSY_SPREAD;
        $maxStopAlpha = array_reduce($overlay, static fn (float $m, array $s) => max($m, $s['alpha']), 0.0);
        $trusted = array_values(array_filter(
            $steps,
            fn (int $d) => !$busy || $maxStopAlpha * $d / 100 >= self::BUSY_MIN_ALPHA
        ));

        // Keep the designed text colors if any trusted dim step lets them all pass.
        foreach ($trusted as $d) {
            if (self::failing($texts, $overlay, $d, $image) === []) {
                return ['dim' => $d, 'swaps' => [], 'overlay' => null, 'pass' => true];
            }
        }

        // Otherwise the smallest trusted dim where a candidate color rescues
        // the failing texts (texts that already pass keep their color).
        foreach ($trusted as $d) {
            foreach ($candidates as $slug => $rgb) {
                $failing = self::failing($texts, $overlay, $d, $image);
                $rescued = array_filter(
                    $failing,
                    fn (array $t) => self::minRatio($rgb, $overlay, $d, $image) >= $t['threshold']
                );
                if (count($rescued) === count($failing)) {
                    $swaps = [];
                    foreach ($failing as $t) {
                        $swaps[$t['index']] = $slug;
                    }
                    return ['dim' => $d, 'swaps' => $swaps, 'overlay' => null, 'pass' => true];
                }
            }
        }

        // Escalation: solid overlay + matching text. Try the darker overlay
        // with the lighter text first (the common photographic treatment).
        $ordered = $candidates;
        uasort($ordered, static fn (array $a, array $b) => ContrastMath::luminance($a) <=> ContrastMath::luminance($b));
        $slugs = array_keys($ordered);
        $pairs = [];
        for ($i = 0; $i < count($slugs); $i++) {
            for ($j = count($slugs) - 1; $j >= 0; $j--) {
                if ($i !== $j) {
                    $pairs[] = [$slugs[$i], $slugs[$j]]; // [overlay, text]
                }
            }
        }
        foreach ($pairs as [$overlaySlug, $textSlug]) {
            foreach ([50, 60, 70, self::MAX_DIM] as $d) {
                $solid = [['rgb' => $candidates[$overlaySlug], 'alpha' => 1.0]];
                $ok = true;
                foreach ($texts as $t) {
                    if (self::minRatio($candidates[$textSlug], $solid, $d, $image) < $t['threshold']) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $swaps = [];
                    foreach ($texts as $t) {
                        $swaps[$t['index']] = $textSlug;
                    }
                    return ['dim' => $d, 'swaps' => $swaps, 'overlay' => $overlaySlug, 'pass' => true];
                }
            }
        }

        // Best effort: max dim, best candidate for every failing text.
        $d = $steps[count($steps) - 1];
        $swaps = [];
        foreach (self::failing($texts, $overlay, $d, $image) as $t) {
            $bestSlug = null;
            $bestRatio = self::minRatio($t['rgb'], $overlay, $d, $image);
            foreach ($candidates as $slug => $rgb) {
                $r = self::minRatio($rgb, $overlay, $d, $image);
                if ($r > $bestRatio) {
                    $bestRatio = $r;
                    $bestSlug = $slug;
                }
            }
            if ($bestSlug !== null) {
                $swaps[$t['index']] = $bestSlug;
            }
        }
        return ['dim' => $d, 'swaps' => $swaps, 'overlay' => null, 'pass' => false];
    }

    /**
     * @param list<array{index:int, rgb: array{0:int,1:int,2:int}, threshold: float}> $texts
     * @param list<array{rgb: array{0:int,1:int,2:int}, alpha: float}> $overlay
     * @param list<array{0:int,1:int,2:int}> $image
     * @return list<array{index:int, rgb: array{0:int,1:int,2:int}, threshold: float}>
     */
    private static function failing(array $texts, array $overlay, int $dim, array $image): array
    {
        return array_values(array_filter(
            $texts,
            fn (array $t) => self::minRatio($t['rgb'], $overlay, $dim, $image) < $t['threshold']
        ));
    }

    /**
     * Worst-case ratio of a text color against the overlay-dimmed image:
     * the minimum across every overlay stop × every image sample.
     *
     * @param array{0:int,1:int,2:int} $fg
     * @param list<array{rgb: array{0:int,1:int,2:int}, alpha: float}> $overlay
     * @param list<array{0:int,1:int,2:int}> $image
     */
    private static function minRatio(array $fg, array $overlay, int $dim, array $image): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($overlay as $stop) {
            foreach ($image as $sample) {
                $bg = ContrastMath::compositeOver($stop['rgb'], $stop['alpha'] * $dim / 100, $sample);
                $min = min($min, ContrastMath::ratio($fg, $bg));
            }
        }
        return $min;
    }

    /**
     * The text rows inside one cover: every text-bearing leaf with its
     * effective color (explicit, inherited within the cover, or the theme's
     * `contrast` default) and WCAG threshold (3:1 for heading-scale blocks).
     *
     * @return list<array{index:int, rgb: array{0:int,1:int,2:int}, threshold: float}>
     */
    private function coverTexts(BlockMarkup $doc, int $cover, ContrastFix $helper, ?array $inherited = null): array
    {
        $attrs = $doc->attrs($cover) ?? [];
        $own = null;
        if (is_string($attrs['textColor'] ?? null)) {
            $own = $helper->rgbFor($attrs['textColor']);
        } elseif (is_string($attrs['style']['color']['text'] ?? null)) {
            $own = $helper->resolveColorValue($attrs['style']['color']['text'])['rgb'] ?? null;
        }
        $inherited = $own ?? $inherited;

        $rows = [];
        foreach ($doc->children($cover) as $child) {
            $name = $doc->name($child);
            if (in_array($name, ['paragraph', 'heading', 'list', 'quote', 'pullquote', 'verse', 'site-title'], true)
                && ContrastFix::visibleText($doc->innerHtml($child)) !== '') {
                $childAttrs = $doc->attrs($child) ?? [];
                $ownSlug = is_string($childAttrs['textColor'] ?? null)
                    && $helper->rgbFor($childAttrs['textColor']) !== null
                    ? $childAttrs['textColor'] : null;
                $rows[] = [
                    'index'     => $child,
                    'rgb'       => $this->coverTextColor($doc, $child, $helper, $inherited),
                    'ownSlug'   => $ownSlug,
                    'threshold' => in_array($name, ['heading', 'pullquote', 'site-title'], true)
                        ? ContrastMath::LARGE_TEXT : ContrastMath::NORMAL_TEXT,
                ];
            }
            // Nested covers own their background; stop at their boundary.
            if ($name !== 'cover') {
                $rows = array_merge($rows, $this->coverTexts($doc, $child, $helper, $inherited));
            }
        }
        return $rows;
    }

    /** @param array{0:int,1:int,2:int}|null $inherited @return array{0:int,1:int,2:int} */
    private function coverTextColor(BlockMarkup $doc, int $i, ContrastFix $helper, ?array $inherited): array
    {
        $attrs = $doc->attrs($i) ?? [];
        if (is_string($attrs['textColor'] ?? null) && ($rgb = $helper->rgbFor($attrs['textColor'])) !== null) {
            return $rgb;
        }
        if (is_string($attrs['style']['color']['text'] ?? null)
            && ($resolved = $helper->resolveColorValue($attrs['style']['color']['text'])) !== null) {
            return $resolved['rgb'];
        }
        return $inherited ?? $helper->rgbFor('contrast') ?? [0, 0, 0];
    }

    /**
     * Map a cover url ("theme:./assets/x.jpg" or the Playground-served
     * "/wp-content/themes/<slug>/assets/x.jpg") to the on-disk asset, if it
     * exists.
     */
    private function assetPath(Project $project, string $url): ?string
    {
        $file = null;
        if (preg_match('~^theme:\./assets/([a-z0-9._-]+)$~i', $url, $m)) {
            $file = $m[1];
        } elseif (preg_match('~/assets/([a-z0-9._-]+)$~i', $url, $m)) {
            $file = $m[1];
        }
        if ($file === null) {
            return null;
        }
        $path = $project->themePath('assets/' . $file);
        return is_file($path) ? $path : null;
    }

    /**
     * Luminance statistics of the image region behind the cover content. The
     * content sits where contentPosition says (default: center), so sample
     * that half of the image instead of averaging light and dark areas the
     * text never touches.
     *
     * Returns three representative colors — the pixels at the 25th and 75th
     * luminance percentiles plus the mean — because a busy photo (crowds,
     * architecture) has no single background color: text must read against
     * the region's typical dark AND bright areas, and an average of the two
     * is a color that exists nowhere in the image. The interquartile ends
     * (not the extremes) keep small specks from vetoing every color.
     *
     * Null when the image can't be read.
     *
     * @return list<array{0:int,1:int,2:int}>|null [low, mean, high]
     */
    public static function regionStats(string $path, string $contentPosition): ?array
    {
        if (!extension_loaded('imagick')) {
            return null;
        }
        try {
            $im = new \Imagick($path);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w < 2 || $h < 2) {
                return null;
            }

            $pos = strtolower($contentPosition);
            $x = str_contains($pos, 'left') ? 0 : (str_contains($pos, 'right') ? intdiv($w, 2) : intdiv($w, 4));
            $y = str_contains($pos, 'top') ? 0 : (str_contains($pos, 'bottom') ? intdiv($h, 2) : intdiv($h, 4));
            $im->cropImage(intdiv($w, 2), intdiv($h, 2), $x, $y);

            $im->resizeImage(24, 24, \Imagick::FILTER_BOX, 1);
            $pixels = [];
            $sum = [0, 0, 0];
            foreach ($im->getPixelIterator() as $row) {
                foreach ($row as $px) {
                    $c = $px->getColor();
                    $rgb = [(int) $c['r'], (int) $c['g'], (int) $c['b']];
                    $pixels[] = ['rgb' => $rgb, 'lum' => ContrastMath::luminance($rgb)];
                    $sum[0] += $rgb[0];
                    $sum[1] += $rgb[1];
                    $sum[2] += $rgb[2];
                }
            }
            if ($pixels === []) {
                return null;
            }
            usort($pixels, static fn (array $a, array $b) => $a['lum'] <=> $b['lum']);
            $n = count($pixels);
            $mean = [
                (int) round($sum[0] / $n), (int) round($sum[1] / $n), (int) round($sum[2] / $n),
            ];
            return [
                $pixels[(int) floor($n * 0.25)]['rgb'],
                $mean,
                $pixels[min($n - 1, (int) floor($n * 0.75))]['rgb'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The overlay stop the content actually sits on. Overlay gradients run
     * with the cover's composition (built that way by the section prompt), so
     * a bottom-positioned content block sits on the LAST stop, top on the
     * first; centered content is evaluated against every stop (worst case).
     *
     * @param list<array{rgb: array{0:int,1:int,2:int}, alpha: float}> $overlay
     * @return list<array{rgb: array{0:int,1:int,2:int}, alpha: float}>
     */
    public static function overlayForPosition(array $overlay, string $contentPosition): array
    {
        if (count($overlay) <= 1) {
            return $overlay;
        }
        $pos = strtolower($contentPosition);
        if (str_contains($pos, 'bottom')) {
            return [$overlay[count($overlay) - 1]];
        }
        if (str_contains($pos, 'top')) {
            return [$overlay[0]];
        }
        return $overlay;
    }
}
