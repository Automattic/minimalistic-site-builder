<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Turns a generated image's solid background into real PNG alpha.
 *
 * Imagen cannot produce an alpha channel: `outputOptions.mimeType: image/png`
 * only changes the encoding, and prompt-level "transparent background" requests
 * are ignored (the model always paints a full-bleed picture). So transparency
 * for `.png` assets is manufactured after generation: the prompt asks for the
 * subject isolated on a flat solid white background — the one isolation Imagen
 * DOES honor reliably (see ImagePromptComposer) — and this class keys that
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

    /** Whether the runtime can key backgrounds at all. */
    public static function available(): bool
    {
        return extension_loaded('imagick');
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

            // If almost nothing opaque survived, the fill ate the subject too
            // (subject color ≈ background color) — keep the original. Checked
            // BEFORE the global pass: thin line art can legitimately end up
            // with ~1% ink once its enclosed pockets are keyed, and must not
            // trip this guard.
            $alpha = $im->getImageChannelMean(\Imagick::CHANNEL_ALPHA);
            if (($alpha['mean'] ?? 0) / \Imagick::getQuantum() < 0.01) {
                return $pngBytes;
            }

            // Global pass: key background-colored pockets the border fill
            // could not reach (enclosed by the subject). Unconditional — in
            // thin line art the enclosed background often outweighs the ink,
            // so no share-of-subject heuristic can tell pockets from fills.
            foreach ($seedColors as $seed) {
                $im->transparentPaintImage($seed, 0.0, $fuzz, false);
            }

            $im->setImageFormat('png');
            return $im->getImageBlob();
        } catch (\Throwable) {
            return $pngBytes;
        }
    }
}
