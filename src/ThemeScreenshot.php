<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Draws the image WordPress shows as a theme's preview card.
 *
 * WordPress looks for `screenshot.<ext>` in the theme root at the recommended
 * 1200x900 — get_screenshot() accepts png, gif, jpg, jpeg, webp and avif, in
 * that order — and shows the empty grey placeholder when the theme has none.
 *
 * Two ways to produce it, in preference order:
 *
 *   1. cover() crops a real generated image (the site's hero) to 1200x900 and
 *      returns JPEG. Same framing rule a CSS `object-fit: cover` applies:
 *      scale to fill, centre, crop the overflow. The preview then shows the
 *      site's own picture rather than an abstraction of it.
 *   2. poster() composes a miniature of a page — hero band, headline bars,
 *      button, a row of cards — out of the theme's own palette, and returns
 *      PNG. It needs no generated pixels at all, which is what makes it the
 *      fallback: image generation is opt-in and runs outside the pipeline, so
 *      most builds reach this class with an empty assets directory.
 *
 * The formats differ because the content does. A photograph stored losslessly
 * costs well over a megabyte in every generated theme; the same crop as JPEG
 * is a tenth of that with no visible loss at card size. Flat rectangles are
 * the opposite case — PNG stores the poster in a few kilobytes and JPEG would
 * ring at every edge.
 *
 * Both prefer Imagick and fall through to GD the way GeminiImage::toJpeg
 * does, so a loaded-but-broken wand still produces a card. A host with
 * neither extension gets null from both and ships no screenshot; that is a
 * missing preview card, never a failed build.
 */
final class ThemeScreenshot
{
    /** The size WordPress recommends for a theme screenshot. */
    public const WIDTH = 1200;

    public const HEIGHT = 900;

    /**
     * JPEG quality for the cropped photo. High enough that the card shows no
     * artefacts at any size WordPress renders it, low enough that the file
     * stays a fraction of the lossless equivalent.
     */
    private const JPEG_QUALITY = 82;

    /**
     * Crop $bytes to exactly WIDTH x HEIGHT and return JPEG bytes, centring
     * the image and cropping whatever the aspect ratio leaves over.
     *
     * Returns null when no image runtime is loaded or the bytes don't decode —
     * a caller that cannot get a real screenshot falls back to poster().
     */
    public static function cover(string $bytes): ?string
    {
        // Same fall-through as GeminiImage::toJpeg: Imagick is preferred, but a
        // loaded-and-broken wand (policy.xml, missing JPEG delegate) must not
        // skip a GD crop of the same bytes.
        if (extension_loaded('imagick')) {
            $out = self::coverWithImagick($bytes);
            if ($out !== null) {
                return $out;
            }
        }
        if (function_exists('imagecreatetruecolor')) {
            return self::coverWithGd($bytes);
        }
        return null;
    }

    /**
     * A miniature page drawn from the theme's palette: a hero band in the
     * contrast colour carrying two headline bars and a button, over a row of
     * three cards on the page background. Recognisably a website at card size,
     * and every colour in it is one the generated site actually uses.
     *
     * $palette is the theme.json slug => hex map (ContrastFixStep::paletteMap).
     * Missing slugs fall back to a readable neutral, so a partial palette still
     * draws.
     *
     * @param array<string,string> $palette
     */
    public static function poster(array $palette): ?string
    {
        $base      = self::color($palette, 'base', [255, 255, 255]);
        $contrast  = self::color($palette, 'contrast', [17, 17, 17]);
        $accent    = self::color($palette, 'accent', $contrast);
        $secondary = self::color($palette, 'secondary', $contrast);

        // x, y, width, height, colour — page background first, then the hero
        // band, then what sits on top of each. Ordered back to front: the
        // renderers just paint them in sequence.
        $rects = [
            [0, 0, self::WIDTH, self::HEIGHT, $base],
            [0, 0, self::WIDTH, 560, $contrast],
            [120, 200, 620, 64, $base],   // headline, first line
            [120, 292, 460, 64, $base],   // headline, second line
            [120, 400, 540, 24, $base],   // subheading
            [120, 464, 220, 60, $accent], // button
            [120, 640, 300, 180, $secondary],
            [450, 640, 300, 180, $secondary],
            [780, 640, 300, 180, $secondary],
        ];

        if (extension_loaded('imagick')) {
            $out = self::drawWithImagick($rects);
            if ($out !== null) {
                return $out;
            }
        }
        if (function_exists('imagecreatetruecolor')) {
            return self::drawWithGd($rects);
        }
        return null;
    }

    /**
     * Whether this runtime can produce a screenshot at all. Callers use it to
     * tell "no image extension here" apart from "the source image was broken",
     * which are worth different warnings.
     */
    public static function available(): bool
    {
        return extension_loaded('imagick') || function_exists('imagecreatetruecolor');
    }

    /**
     * cropThumbnailImage is exactly the cover crop: it scales the shorter axis
     * to fit and centre-crops the longer one.
     */
    private static function coverWithImagick(string $bytes): ?string
    {
        $im = null;
        $flat = null;
        try {
            $im = new \Imagick();
            $im->readImageBlob($bytes);
            // A generated image may carry alpha; the card is opaque, so
            // composite it onto white rather than leaving that to the viewer.
            $im->setImageBackgroundColor('white');
            $flat = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            // $flat is independent, and a full-size hero is megabytes per copy;
            // drop the source before the crop and encode rather than after.
            $im->clear();
            $im = null;
            $flat->cropThumbnailImage(self::WIDTH, self::HEIGHT);
            // A crop leaves the canvas offset behind it; reset the page so the
            // written image is the crop and nothing else.
            $flat->setImagePage(0, 0, 0, 0);
            $flat->setImageFormat('jpeg');
            $flat->setImageCompressionQuality(self::JPEG_QUALITY);
            $out = $flat->getImageBlob();
            return $out === '' ? null : $out;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($flat instanceof \Imagick) {
                $flat->clear();
            }
            if ($im instanceof \Imagick) {
                $im->clear();
            }
        }
    }

    private static function coverWithGd(string $bytes): ?string
    {
        $src = null;
        $dst = null;
        $buffering = false;
        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return null;
            }
            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) {
                return null;
            }

            // The largest centred source rectangle with the target's aspect ratio:
            // whichever axis is proportionally longer is the one that gets cropped.
            if ($sw * self::HEIGHT > $sh * self::WIDTH) {
                $ch = $sh;
                $cw = (int) round($sh * self::WIDTH / self::HEIGHT);
            } else {
                $cw = $sw;
                $ch = (int) round($sw * self::HEIGHT / self::WIDTH);
            }
            $dst = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            if ($dst === false) {
                return null;
            }
            imagefilledrectangle(
                $dst,
                0,
                0,
                self::WIDTH - 1,
                self::HEIGHT - 1,
                (int) imagecolorallocate($dst, 255, 255, 255)
            );
            $copied = imagecopyresampled(
                $dst,
                $src,
                0,
                0,
                intdiv($sw - $cw, 2),
                intdiv($sh - $ch, 2),
                self::WIDTH,
                self::HEIGHT,
                $cw,
                $ch
            );
            if ($copied !== true) {
                return null;
            }

            ob_start();
            $buffering = true;
            $ok = imagejpeg($dst, null, self::JPEG_QUALITY);
            $out = (string) ob_get_clean();
            $buffering = false;
            return $ok && $out !== '' ? $out : null;
        } catch (\Throwable) {
            if ($buffering) {
                ob_end_clean();
            }
            return null;
        } finally {
            if ($src instanceof \GdImage) {
                imagedestroy($src);
            }
            if ($dst instanceof \GdImage) {
                imagedestroy($dst);
            }
        }
    }

    /** @param list<array{0:int,1:int,2:int,3:int,4:array{0:int,1:int,2:int}}> $rects */
    private static function drawWithImagick(array $rects): ?string
    {
        $im = null;
        try {
            $im = new \Imagick();
            $im->newImage(self::WIDTH, self::HEIGHT, 'white', 'png');
            $draw = new \ImagickDraw();
            foreach ($rects as [$x, $y, $w, $h, $rgb]) {
                $draw->setFillColor(new \ImagickPixel(sprintf('#%02X%02X%02X', ...$rgb)));
                $draw->rectangle($x, $y, $x + $w - 1, $y + $h - 1);
            }
            $im->drawImage($draw);
            $out = $im->getImageBlob();
            return $out === '' ? null : $out;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($im instanceof \Imagick) {
                $im->clear();
            }
        }
    }

    /** @param list<array{0:int,1:int,2:int,3:int,4:array{0:int,1:int,2:int}}> $rects */
    private static function drawWithGd(array $rects): ?string
    {
        $im = null;
        $buffering = false;
        try {
            $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            if ($im === false) {
                return null;
            }
            foreach ($rects as [$x, $y, $w, $h, $rgb]) {
                $color = (int) imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
                imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $color);
            }
            ob_start();
            $buffering = true;
            $ok = imagepng($im, null, 6);
            $out = (string) ob_get_clean();
            $buffering = false;
            return $ok && $out !== '' ? $out : null;
        } catch (\Throwable) {
            if ($buffering) {
                ob_end_clean();
            }
            return null;
        } finally {
            if ($im instanceof \GdImage) {
                imagedestroy($im);
            }
        }
    }

    /**
     * One palette slug as an RGB triple, falling back to $fallback when the
     * slug is absent or is not a hex colour this can read (theme.json allows
     * rgb()/hsl()/var() values that no rectangle fill needs to understand).
     *
     * @param array<string,string>   $palette
     * @param array{0:int,1:int,2:int} $fallback
     * @return array{0:int,1:int,2:int}
     */
    private static function color(array $palette, string $slug, array $fallback): array
    {
        return self::parseHex($palette[$slug] ?? '') ?? $fallback;
    }

    /**
     * ContrastMath is this package's sRGB parser; the only thing a flat fill
     * adds is tolerating the alpha digits theme.json may carry, which a card
     * has no use for. Same trim-and-delegate shape CssContrastAdjuster uses.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parseHex(string $value): ?array
    {
        $hex = ltrim(trim($value), '#');
        // #RGBA and #RRGGBBAA: drop the alpha digits. Four-digit form is one
        // nibble of alpha; eight-digit is two. Stripping two chars from both
        // leaves #RGBA as two digits, which ContrastMath cannot read.
        if (strlen($hex) === 4) {
            $hex = substr($hex, 0, 3);
        } elseif (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);
        }
        return ContrastMath::hexToRgb($hex);
    }
}
