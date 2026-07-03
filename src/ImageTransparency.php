<?php
declare(strict_types=1);

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
 * The keying is a flood fill of transparency inward from the image border, one
 * fill per border seed point, each matched against the actual pixel color at
 * that seed (so a slightly off-white or unevenly rendered background still
 * keys). Flood fill — not a global color replace — so background-colored pixels
 * INSIDE the subject (a white grape, a pale highlight) are preserved. The one
 * blind spot is a background-colored pocket fully enclosed by the subject
 * (inside a closed curl): it stays opaque, which reads as a deliberate fill.
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
     * Key the border-connected background of a PNG to transparency and return
     * the re-encoded PNG bytes. Fails soft: when imagick is missing, the bytes
     * don't decode, or keying would erase essentially the whole image (the
     * "background" fill reached everywhere, i.e. the subject itself matched),
     * the input bytes are returned unchanged — a decorative asset with a baked
     * background is still better than a broken one.
     */
    public static function keyOutBackground(string $pngBytes): string
    {
        if (!self::available()) {
            return $pngBytes;
        }

        try {
            $im = new Imagick();
            $im->readImageBlob($pngBytes);
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $fuzz = self::FUZZ_PERCENT / 100 * Imagick::getQuantum();
            $transparent = new ImagickPixel('transparent');

            // Corners plus edge midpoints: enough seeds to reach every
            // border-connected background region even when the subject touches
            // an edge and splits the background apart.
            $seeds = [
                [0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1],
                [intdiv($w, 2), 0], [intdiv($w, 2), $h - 1],
                [0, intdiv($h, 2)], [$w - 1, intdiv($h, 2)],
            ];
            foreach ($seeds as [$x, $y]) {
                $seed = $im->getImagePixelColor($x, $y);
                if ($seed->getColorValue(Imagick::COLOR_ALPHA) < 0.5) {
                    continue; // already keyed by an earlier seed's fill
                }
                $im->floodFillPaintImage($transparent, $fuzz, $seed, $x, $y, false, Imagick::CHANNEL_ALPHA);
            }

            // If almost nothing opaque survived, the fill ate the subject too
            // (subject color ≈ background color) — keep the original.
            $alpha = $im->getImageChannelMean(Imagick::CHANNEL_ALPHA);
            if (($alpha['mean'] ?? 0) / Imagick::getQuantum() < 0.01) {
                return $pngBytes;
            }

            $im->setImageFormat('png');
            return $im->getImageBlob();
        } catch (Throwable) {
            return $pngBytes;
        }
    }
}
