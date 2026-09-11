<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The browser-tab icon, derived from a generated brand mark.
 *
 * Deliberately GD, not Imagick. Everything else that post-processes the mark —
 * keying, padding, recoloring — lives in ImageTransparency, which needs Imagick,
 * and the Dotcom build host has no imagick module: there every one of those
 * calls returns its input, the mark is dropped as unkeyable, and no generated
 * site has ever carried a favicon. An icon needs none of that work. It wants a
 * square opaque image, which an unkeyed render on white already is.
 *
 * Fails soft, like ImageTransparency: anything unusable returns null and the
 * caller ships no icon rather than a broken one.
 */
final class SiteIcon
{
    /** WordPress serves site_icon from the source, so give it 512 to resize. */
    public const SIDE = 512;

    /** Per-channel distance from the ground before a pixel counts as ink. */
    private const CHANNEL_TOLERANCE = 16;

    /** Share of the square the mark fills; the rest is breathing room. */
    private const FILL = 0.88;

    /**
     * A square opaque PNG of a mark, sized to fit, or null when there is no
     * mark on the render to make one of.
     *
     * Generation asks for a square and does not always get one — the audited
     * sample came back 1264x848 — so the ink is measured and fitted, not
     * centre-cropped: a wide lockup spanning more than the render's short side
     * would lose both ends to a crop, invisibly, its middle still being ink.
     *
     * `$groundHex` is the ground to paint behind the mark. Pass the header's
     * own background so a host with GD and a host with Imagick cut the same
     * icon: the Imagick path flattens the keyed mark over that colour, and two
     * builds of one site should not put different tiles in the tab. White is
     * the fallback, because an unkeyed render already sits on one.
     */
    public static function fromMark(string $pngBytes, ?string $groundHex = null): ?string
    {
        if ($pngBytes === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($pngBytes);
        if ($source === false) {
            return null;
        }

        // A palette PNG answers imagecolorat() with an index, not a packed
        // colour, and inkBounds() would then measure index arithmetic. An
        // 8-bit render is ordinary currency here, and the failure would be
        // silent, so convert before a single pixel is read.
        if (!imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        // Mark generation can come back blank, and a white square in the tab
        // reads worse than the default WordPress mark it would replace.
        $ink = self::inkBounds($source);
        if ($ink === null) {
            return null;
        }
        [$left, $top, $right, $bottom] = $ink;
        $inkWidth = $right - $left + 1;
        $inkHeight = $bottom - $top + 1;

        $scale = min(self::SIDE / $inkWidth, self::SIDE / $inkHeight) * self::FILL;
        $drawWidth = max(1, (int) round($inkWidth * $scale));
        $drawHeight = max(1, (int) round($inkHeight * $scale));

        $canvas = imagecreatetruecolor(self::SIDE, self::SIDE);
        // A tab has no bar behind it, and iOS composites a transparent touch
        // icon onto black, so the icon carries its own ground.
        [$red, $green, $blue] = ContrastMath::hexToRgb((string) $groundHex) ?? [255, 255, 255];
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, $red, $green, $blue));
        $copied = imagecopyresampled(
            $canvas,
            $source,
            intdiv(self::SIDE - $drawWidth, 2),
            intdiv(self::SIDE - $drawHeight, 2),
            $left,
            $top,
            $drawWidth,
            $drawHeight,
            $inkWidth,
            $inkHeight
        );
        imagedestroy($source);
        if (!$copied) {
            imagedestroy($canvas);
            return null;
        }

        ob_start();
        $written = imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $written && $bytes !== '' ? $bytes : null;
    }

    /**
     * The box holding everything that differs from the render's own ground, or
     * null when nothing does. Every pixel, no sampling: 37ms on the audited
     * 1.07MP render, once per build, against image generation that costs
     * 30-60s an image.
     *
     * @return array{int,int,int,int}|null left, top, right, bottom
     */
    private static function inkBounds(\GdImage $source): ?array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        // The corner is the ground on every mark render the prompt asks for.
        // A render whose corner is ink inverts the measurement, which fails
        // soft: the box still covers the mark, only with less breathing room.
        $ground = imagecolorat($source, 0, 0);

        $left = $width;
        $top = $height;
        $right = -1;
        $bottom = -1;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                if (!self::differs(imagecolorat($source, $x, $y), $ground)) {
                    continue;
                }
                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x);
                $bottom = max($bottom, $y);
            }
        }

        return $right < 0 ? null : [$left, $top, $right, $bottom];
    }

    /** Whether two packed GD colors differ on any channel beyond tolerance. */
    private static function differs(int $a, int $b): bool
    {
        return abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF)) > self::CHANNEL_TOLERANCE
            || abs((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF)) > self::CHANNEL_TOLERANCE
            || abs(($a & 0xFF) - ($b & 0xFF)) > self::CHANNEL_TOLERANCE;
    }
}
