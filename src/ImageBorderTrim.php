<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Removes a painted-in photo-print border from a generated opaque image.
 *
 * The image model sometimes delivers a bounded editorial subject as a printed
 * photograph: the scene sits inside a flat white border painted into the
 * pixels (BIGR-956). The prompt already avoids print vocabulary — no "frame",
 * no "contained", no "repeated image series" (see ImagePromptComposer,
 * ImageCrop) — but an A/B run over the reworded prompts still framed 2 of 8
 * samples, the same rate as the old wording. The trigger is the subject
 * treatment itself, so the border must be removed deterministically after
 * generation. A trim always ships an image (no regeneration, no gate),
 * matching how the build treats other post-generation repairs.
 *
 * Detection walks 1px lines inward from each edge and measures a run of
 * lines that are both flat (low grayscale deviation) and bright. A painted
 * border is flat in a way real scenery never is: calibrated on generated
 * samples, border lines deviate under 0.015 while real edge lines exceed
 * 0.045, and border runs read 0.997+ brightness on every side at once. The
 * scene inside must also contrast with the ring: that keeps a legitimately
 * bright, flat scene (a catalog subject on seamless white, an overexposed
 * sky) from being mistaken for a border around itself.
 *
 * Trimming is strictly best-effort: when Imagick is missing, the bytes do
 * not decode, or no border is detected, the input bytes are returned
 * unchanged.
 */
final class ImageBorderTrim
{
    /**
     * Most a 1px line's grayscale standard deviation (0..1) may reach and
     * still count as flat. Calibrated headroom: painted-border lines measure
     * ≤ 0.014 even with the site grade's film grain painted over them.
     */
    private const LINE_STD_MAX = 0.045;

    /**
     * Least mean brightness (0..1) of a flat line that counts as border. The
     * observed borders are white (≥ 0.997); the margin admits cream or
     * off-white print borders without admitting dark flat scenery, which
     * low-key sites produce legitimately on one or two edges.
     */
    private const LINE_MIN_BRIGHTNESS = 0.85;

    /** A border must be at least this wide on EVERY side. */
    private const MIN_RUN_PX = 3;

    /** Widest border considered, as a fraction of the shorter dimension. */
    private const MAX_RUN_FRACTION = 0.12;

    /**
     * Least brightness difference between the border ring and the interior
     * scene. A real border surrounds a visibly different picture; a bright
     * flat scene surrounds more of itself and is left alone.
     */
    private const INTERIOR_CONTRAST_MIN = 0.25;

    /** Extra lines removed past the run, eating the soft transition edge. */
    private const EXTRA_TRIM_PX = 2;

    /**
     * Some borders carry a thin near-black keyline between the white band
     * and the scene (the shipped tbilisi khachapuri does). After the bright
     * run and its transition, lines darker than this are still border.
     */
    private const KEYLINE_MAX_BRIGHTNESS = 0.12;

    /** Most keyline lines removed per side past the bright run. */
    private const KEYLINE_MAX_PX = 8;

    /** Whether the runtime can trim borders at all. */
    public static function available(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Detect and crop a painted print border; re-encode in the input format.
     *
     * @return array{bytes:string,trimmed:int} `trimmed` is the widest border
     *         removed from any side in px; 0 means no border was detected
     *         and `bytes` is the unmodified input.
     */
    public static function trimPaintedBorder(string $bytes): array
    {
        $untouched = ['bytes' => $bytes, 'trimmed' => 0];
        if (!self::available()) {
            return $untouched;
        }

        try {
            $im = new \Imagick();
            $im->readImageBlob($bytes);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();

            $gray = clone $im;
            $gray->transformImageColorspace(\Imagick::COLORSPACE_GRAY);

            $cap = (int) floor(min($w, $h) * self::MAX_RUN_FRACTION);
            $runs = [
                'top'    => self::brightFlatRun($gray, 'top', $cap),
                'bottom' => self::brightFlatRun($gray, 'bottom', $cap),
                'left'   => self::brightFlatRun($gray, 'left', $cap),
                'right'  => self::brightFlatRun($gray, 'right', $cap),
            ];
            if (min(array_column($runs, 'px')) < self::MIN_RUN_PX) {
                return $untouched;
            }

            $trim = [];
            foreach ($runs as $side => $run) {
                $base = min($run['px'] + self::EXTRA_TRIM_PX, $cap);
                $trim[$side] = $base + self::keylineRun($gray, $side, $base);
            }
            $innerW = $w - $trim['left'] - $trim['right'];
            $innerH = $h - $trim['top'] - $trim['bottom'];
            if ($innerW < 1 || $innerH < 1) {
                return $untouched;
            }

            $interior = clone $gray;
            $interior->cropImage($innerW, $innerH, $trim['left'], $trim['top']);
            $interiorMean = $interior->getImageChannelMean(\Imagick::CHANNEL_GRAY)['mean']
                / \Imagick::getQuantum();
            $interior->destroy();
            $ringMean = array_sum(array_column($runs, 'mean')) / 4;
            if ($ringMean - $interiorMean < self::INTERIOR_CONTRAST_MIN) {
                return $untouched;
            }

            $im->cropImage($innerW, $innerH, $trim['left'], $trim['top']);
            $im->setImagePage(0, 0, 0, 0);
            if (strtoupper($im->getImageFormat()) === 'JPEG') {
                $im->setImageCompressionQuality(92);
            }
            $out = $im->getImageBlob();
            $gray->destroy();
            $im->destroy();
            return ['bytes' => $out, 'trimmed' => max($trim)];
        } catch (\Throwable) {
            return $untouched;
        }
    }

    /**
     * How many consecutive lines from $from inward are near-black — the
     * border's inner keyline. No flatness demand: the keyline's rounded
     * corners leave a few bright pixels in an otherwise black line. Only ever
     * called once a bright border ring is already confirmed, and hard-capped,
     * so on a genuinely dark scene it can cost at most a few scene lines.
     */
    private static function keylineRun(\Imagick $gray, string $side, int $from): int
    {
        $px = 0;
        while ($px < self::KEYLINE_MAX_PX) {
            $line = self::lineStats($gray, $side, $from + $px);
            if ($line['mean'] >= self::KEYLINE_MAX_BRIGHTNESS) {
                break;
            }
            $px++;
        }
        return $px;
    }

    /**
     * How many consecutive 1px lines from one edge inward are flat AND
     * bright, and their average brightness.
     *
     * @return array{px:int,mean:float}
     */
    private static function brightFlatRun(\Imagick $gray, string $side, int $cap): array
    {
        $px = 0;
        $meanSum = 0.0;
        for ($i = 0; $i < $cap; $i++) {
            $line = self::lineStats($gray, $side, $i);
            if ($line['std'] > self::LINE_STD_MAX || $line['mean'] < self::LINE_MIN_BRIGHTNESS) {
                break;
            }
            $px++;
            $meanSum += $line['mean'];
        }
        return ['px' => $px, 'mean' => $px > 0 ? $meanSum / $px : 0.0];
    }

    /**
     * Grayscale mean and standard deviation (0..1) of the 1px line $depth
     * lines inward from one edge.
     *
     * @return array{mean:float,std:float}
     */
    private static function lineStats(\Imagick $gray, string $side, int $depth): array
    {
        $w = $gray->getImageWidth();
        $h = $gray->getImageHeight();
        $line = clone $gray;
        match ($side) {
            'top'    => $line->cropImage($w, 1, 0, $depth),
            'bottom' => $line->cropImage($w, 1, 0, $h - 1 - $depth),
            'left'   => $line->cropImage(1, $h, $depth, 0),
            'right'  => $line->cropImage(1, $h, $w - 1 - $depth, 0),
        };
        $stats = $line->getImageChannelMean(\Imagick::CHANNEL_GRAY);
        $line->destroy();
        $quantum = (float) \Imagick::getQuantum();
        return ['mean' => $stats['mean'] / $quantum, 'std' => $stats['standardDeviation'] / $quantum];
    }
}
