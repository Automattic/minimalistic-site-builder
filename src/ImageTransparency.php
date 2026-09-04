<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Turns a generated image's solid background into real PNG alpha.
 *
 * The image model cannot produce a reliable alpha channel: prompt-level
 * "transparent background" requests are ignored (the model always paints a
 * full-bleed picture). So transparency for `.png` assets is manufactured after
 * generation: the prompt asks for the subject isolated on a flat solid white
 * background — the one isolation the model DOES honor reliably (see ImagePromptComposer) — and this class keys that
 * background out.
 *
 * The keying is two passes. First, a flood fill of transparency inward from
 * the image border, one fill per border seed point, each matched against the
 * actual pixel color at that seed (so a slightly off-white or unevenly
 * rendered background still keys). Second, a global key of every remaining
 * pixel that matches a border background color — the flood fill cannot reach
 * a background pocket fully enclosed by the subject (inside a closed curl),
 * which is exactly what decorative flourishes produce dozens of. The global
 * pass is unconditional: these assets are line art on flat white by prompt
 * design (see ImagePromptComposer), where enclosed background routinely
 * outweighs the ink itself, so any "is it a deliberate white fill?" area
 * heuristic misfires on precisely the images this class exists to fix. A
 * background-colored subject fill is keyed too — the acceptable cost.
 *
 * The fuzz keying is binary, which leaves the anti-aliased edge behind: pixels
 * that blend ink with the white background land outside the fuzz and survive
 * fully opaque with their whitish color baked in — invisible on light pages, a
 * crusty white fringe on dark ones, and for thin linework the whitening can
 * swallow the entire stroke. So a third pass unmattes the survivors: each
 * pixel's distance from white becomes its ink coverage, coverage becomes
 * alpha, and the white contamination is divided back out of the color. Solid
 * ink (coverage past a threshold) passes through mathematically unchanged;
 * blends render as translucent ink instead of opaque pale gray. Over a white
 * page this composites back to exactly the source pixel, so the pass can only
 * improve how the asset sits on any other background.
 *
 * After keying, the transparent margins are trimmed to the ink's bounding box
 * (plus a small pad): the model centers a small ornament on a full-size canvas,
 * and shipping all that empty margin makes the page reserve a huge blank band
 * for a tiny motif.
 */
final class ImageTransparency
{
    /**
     * How far a pixel may deviate from the seed color and still count as
     * background, as a percentage of the quantum range. Generous enough for
     * rendering noise and slight vignetting; tight enough to stop at real
     * subject edges.
     */
    private const FUZZ_PERCENT = 10.0;

    /**
     * Ink coverage (0..1, distance from white) at and above which a pixel
     * counts as solid ink in the unmatting pass: it keeps full alpha and its
     * exact color. Below it, coverage becomes alpha and the white blended
     * into the pixel is divided back out. High enough that half-and-half
     * anti-aliasing blends (the visible fringe) are always unmatted; low
     * enough that mid-tone inks — terracotta, ochre, copper read as ~0.7+
     * coverage — stay fully solid.
     */
    private const SOLID_INK_COVERAGE = 0.6;

    /** Whether the runtime can key backgrounds at all. */
    public static function available(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Whether this Imagick build can run the edge-unmatting pass.
     *
     * The pass is written against ImageMagick 7 semantics: it names
     * ALPHACHANNEL_OFF (7-only; 6.x has ALPHACHANNEL_DEACTIVATE), and its
     * coverage math relies on 7's DivideDst and levelImage behaviour —
     * swapping the constant alone makes 6.x turn the *subject* translucent
     * rather than the fringe. Most Linux distributions still package the 6.x
     * line, so this is a live limitation, not a theoretical one: on those
     * hosts a decorative .png keeps the white baked into its anti-aliased
     * edges (a fringe on dark pages). Detected rather than discovered by
     * exception, so the skip is deliberate and reported.
     */
    public static function canUnmatteEdges(): bool
    {
        return self::available() && defined('Imagick::ALPHACHANNEL_OFF');
    }

    /**
     * Key the background of a PNG — border-connected regions plus enclosed
     * background-colored pockets, per the class docblock — to transparency
     * and return the re-encoded PNG bytes. Fails soft: when imagick is
     * missing, the bytes don't decode, or keying would erase essentially the
     * whole image (the "background" fill reached everywhere, i.e. the subject
     * itself matched), the input bytes are returned unchanged — a decorative
     * asset with a baked background is still better than a broken one.
     */
    public static function keyOutBackground(string $pngBytes): string
    {
        if (!self::available()) {
            return $pngBytes;
        }

        try {
            $im = new \Imagick();
            $im->readImageBlob($pngBytes);
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $fuzz = self::FUZZ_PERCENT / 100 * \Imagick::getQuantum();
            $transparent = new \ImagickPixel('transparent');

            // Corners plus edge midpoints: enough seeds to reach every
            // border-connected background region even when the subject touches
            // an edge and splits the background apart.
            $seeds = [
                [0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1],
                [intdiv($w, 2), 0], [intdiv($w, 2), $h - 1],
                [0, intdiv($h, 2)], [$w - 1, intdiv($h, 2)],
            ];
            $seedColors = [];
            foreach ($seeds as [$x, $y]) {
                $seed = $im->getImagePixelColor($x, $y);
                if ($seed->getColorValue(\Imagick::COLOR_ALPHA) < 0.5) {
                    continue; // already keyed by an earlier seed's fill
                }
                $seedColors[] = $seed;
                $im->floodFillPaintImage($transparent, $fuzz, $seed, $x, $y, false, \Imagick::CHANNEL_ALPHA);
            }

            // If essentially nothing opaque survived, the fill ate the subject
            // too (subject color ≈ background color) — keep the original.
            // The threshold must sit BELOW the ink share of the thinnest real
            // asset: a hairline divider on a 1408x768 canvas is ~0.2-0.8% ink,
            // and a guard at 1% was returning those with their white background
            // baked in — a white box on the page, the worst outcome this class
            // exists to prevent. A true wipeout keys everything, so 0.05%
            // still catches it while every hairline passes.
            $alpha = $im->getImageChannelMean(\Imagick::CHANNEL_ALPHA);
            if (($alpha['mean'] ?? 0) / \Imagick::getQuantum() < 0.0005) {
                return $pngBytes;
            }

            // Global pass: key background-colored pockets the border fill
            // could not reach (enclosed by the subject). Unconditional — in
            // thin line art the enclosed background often outweighs the ink,
            // so no share-of-subject heuristic can tell pockets from fills.
            foreach ($seedColors as $seed) {
                $im->transparentPaintImage($seed, 0.0, $fuzz, false);
            }

            self::unmatteEdges($im);
            self::trimToInk($im);

            $im->setImageFormat('png');
            return $im->getImageBlob();
        } catch (\Throwable) {
            return $pngBytes;
        }
    }

    /**
     * Centre $pngBytes on a transparent square of side max(w, h, $minSide).
     * Padding only — no resampling. Fails soft: any error returns the input.
     */
    public static function padToSquare(string $pngBytes, int $minSide = 512): string
    {
        return self::withImage($pngBytes, static function (\Imagick $im) use ($minSide): ?\Imagick {
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $side = max($w, $h, $minSide);
            if ($w === $side && $h === $side) {
                return null;
            }
            $canvas = new \Imagick();
            $canvas->newImage($side, $side, new \ImagickPixel('transparent'));
            $canvas->compositeImage(
                $im,
                \Imagick::COMPOSITE_OVER,
                intdiv($side - $w, 2),
                intdiv($side - $h, 2),
            );
            return $canvas;
        });
    }

    /**
     * True when all four corner pixels are transparent within 0.01. PNG
     * quantisation does not guarantee exact-zero alpha, so a keyed mark whose
     * corners land at 1/255 must still count. A successful keyOutBackground
     * ends in trimToInk(), which leaves a transparent pad, so opaque corners
     * mean the key was abandoned (or ink runs edge to edge).
     */
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
                if ($im->getImagePixelColor($x, $y)->getColorValue(\Imagick::COLOR_ALPHA) > 0.01) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Paint every surviving ink pixel to $hex and keep the alpha mask.
     * The site title's color is the source of truth: a white title on a dark
     * header must produce a white mark, not the model's default black ink.
     * Fails soft: missing Imagick, a bad hex, or any Imagick error returns
     * the input bytes.
     */
    public static function recolorInk(string $pngBytes, string $hex): string
    {
        if (ContrastMath::hexToRgb($hex) === null) {
            return $pngBytes;
        }
        return self::withImage($pngBytes, static function (\Imagick $im) use ($hex): \Imagick {
            // COPYOPACITY is not portable here: some ImageMagick builds copy
            // the source intensity rather than its alpha channel, making red
            // ink only ~21% opaque. A full colorize replaces RGB while leaving
            // the existing alpha mask byte-for-byte in place.
            $im->colorizeImage(new \ImagickPixel($hex), new \ImagickPixel('white'));
            return $im;
        });
    }

    /**
     * Composite the keyed mark onto an opaque $hex ground and drop the alpha.
     * The header wants a transparent mark; a favicon wants the opposite. A
     * white-on-transparent PNG is invisible on a light browser tab, and iOS
     * composites a transparent touch icon onto black — so the site icon gets
     * the same mark on the header's own background instead of sharing the
     * logo's transparency. Fails soft like the rest of the class.
     */
    public static function flattenOver(string $pngBytes, string $hex): string
    {
        if (ContrastMath::hexToRgb($hex) === null) {
            return $pngBytes;
        }
        return self::withImage($pngBytes, static function (\Imagick $im) use ($hex): \Imagick {
            $canvas = new \Imagick();
            $canvas->newImage($im->getImageWidth(), $im->getImageHeight(), new \ImagickPixel($hex));
            $canvas->compositeImage($im, \Imagick::COMPOSITE_OVER, 0, 0);
            // Leave no alpha for a browser or an iOS home screen to fill in.
            $canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
            return $canvas;
        });
    }

    /**
     * Decode $pngBytes, hand the handle to $fn, and re-encode what it returns
     * as PNG. Owns the fail-soft contract the byte-returning methods share: no
     * imagick, bytes that do not decode, or any throw returns the input
     * unchanged, and so does $fn returning null for "nothing to change".
     *
     * @param \Closure(\Imagick): ?\Imagick $fn
     */
    private static function withImage(string $pngBytes, \Closure $fn): string
    {
        if (!self::available()) {
            return $pngBytes;
        }
        try {
            $im = new \Imagick();
            $im->readImageBlob($pngBytes);
            $out = $fn($im);
            if ($out === null) {
                return $pngBytes;
            }
            $out->setImageFormat('png');
            return $out->getImageBlob();
        } catch (\Throwable) {
            return $pngBytes;
        }
    }

    /**
     * Turn the white anti-aliasing baked into surviving pixels into real
     * translucency (see the class docblock). Per pixel: ink coverage
     * t = max(1-R, 1-G, 1-B) scaled so t >= SOLID_INK_COVERAGE is 1, alpha
     * becomes (keyed alpha × t), and the color is unmatted from white as
     * C' = 1 - (1-C)/t — an identity where t is 1, so solid ink is untouched.
     * Failure-soft: any error leaves the hard-keyed image as it was.
     */
    private static function unmatteEdges(\Imagick $im): void
    {
        if (!self::canUnmatteEdges()) {
            Narrator::write("    (image: edge unmatting needs ImageMagick 7; keeping hard-keyed edges)\n");
            return;
        }
        try {
            // N = 1 - C: per-channel white contamination.
            $neg = clone $im;
            $neg->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OFF);
            $neg->negateImage(false);

            // t = max channel of N, boosted so solid ink saturates to 1.
            $t = clone $neg;
            $t->separateImageChannel(\Imagick::CHANNEL_RED);
            foreach ([\Imagick::CHANNEL_GREEN, \Imagick::CHANNEL_BLUE] as $channel) {
                $other = clone $neg;
                $other->separateImageChannel($channel);
                $t->compositeImage($other, \Imagick::COMPOSITE_LIGHTEN, 0, 0);
            }
            $t->levelImage(0.0, 1.0, self::SOLID_INK_COVERAGE * \Imagick::getQuantum());

            // C' = 1 - N/t (DivideDst = dst/src, clamped; t=0 only where the
            // key passes already zeroed the alpha, so the junk color there is
            // never seen).
            $neg->compositeImage($t, \Imagick::COMPOSITE_DIVIDEDST, 0, 0);
            $neg->negateImage(false);

            // alpha' = keyed alpha × t: keyed background stays fully
            // transparent no matter its coverage reading.
            $keyedAlpha = clone $im;
            $keyedAlpha->separateImageChannel(\Imagick::CHANNEL_ALPHA);
            $t->compositeImage($keyedAlpha, \Imagick::COMPOSITE_MULTIPLY, 0, 0);

            $neg->compositeImage($t, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $im->setImage($neg);
        } catch (\Throwable $e) {
            // Keep the hard-keyed image: a fringe beats no asset. Say so
            // though — swallowing this silently is what let an Imagick 7-only
            // constant ship a white fringe on every anti-aliased edge for
            // every ImageMagick 6 host, invisibly.
            Narrator::write("    (image: edge unmatting skipped: {$e->getMessage()})\n");
        }
    }

    /**
     * Crop the now-transparent margins down to the ink's bounding box plus a
     * small transparent pad. The model centers a small ornament on a full-size
     * canvas, so after keying, most of the asset is empty margin — which the
     * page then reserves layout space for (a hairline rule shipped as a
     * 1408x768 image renders as a huge blank band with a line in the middle).
     * Trimming makes the asset's canvas mean what it shows. Failure-soft: any
     * trim error leaves the image as it was.
     */
    private static function trimToInk(\Imagick $im): void
    {
        try {
            $trimmed = clone $im;
            $trimmed->trimImage(0.0);
            $tw = $trimmed->getImageWidth();
            $th = $trimmed->getImageHeight();
            if ($tw < 1 || $th < 1 || ($tw === $im->getImageWidth() && $th === $im->getImageHeight())) {
                return;
            }
            $trimmed->setImagePage(0, 0, 0, 0);

            // A sliver of breathing room so anti-aliased edges don't sit on
            // the canvas edge; proportional, at least a couple of pixels.
            $pad = max(2, intdiv(max($tw, $th), 50));
            $trimmed->setImageBackgroundColor(new \ImagickPixel('transparent'));
            $trimmed->extentImage($tw + 2 * $pad, $th + 2 * $pad, -$pad, -$pad);

            $im->setImage($trimmed);
        } catch (\Throwable) {
            // keep the untrimmed image
        }
    }
}
