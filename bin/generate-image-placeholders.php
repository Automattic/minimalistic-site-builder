<?php
declare(strict_types=1);

/** Rebuild the tiny product assets; GD is needed here, never at build runtime. */
require_once __DIR__ . '/../src/bootstrap.php';

/** Light to dark neutral grey, so the placeholder stays visible on a light and on a dark background. */
const PLACEHOLDER_LIGHT = [243, 244, 246];
const PLACEHOLDER_DARK = [75, 85, 99];
/** The short side of the smallest raster, in pixels; a larger tile keeps the ramp smooth when a theme scales it up. */
const PLACEHOLDER_SHORT_SIDE = 120;

$directory = Automattic\SiteBuild\Package::imagePlaceholdersDir();
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create ' . $directory);
}
foreach (['1:1', '2:3', '3:4', '4:3', '3:2', '4:5', '9:16', '16:9', '21:9'] as $ratio) {
    [$horizontal, $vertical] = array_map('intval', explode(':', $ratio));
    $scale = (int) ceil(PLACEHOLDER_SHORT_SIDE / min($horizontal, $vertical));
    $width = $horizontal * $scale;
    $height = $vertical * $scale;
    $image = imagecreatetruecolor($width, $height);
    $steps = [];
    for ($step = 0; $step < 256; $step++) {
        $mix = $step / 255;
        $steps[$step] = imagecolorallocate(
            $image,
            (int) round(PLACEHOLDER_LIGHT[0] + ($mix * (PLACEHOLDER_DARK[0] - PLACEHOLDER_LIGHT[0]))),
            (int) round(PLACEHOLDER_LIGHT[1] + ($mix * (PLACEHOLDER_DARK[1] - PLACEHOLDER_LIGHT[1]))),
            (int) round(PLACEHOLDER_LIGHT[2] + ($mix * (PLACEHOLDER_DARK[2] - PLACEHOLDER_LIGHT[2])))
        );
    }
    /** A diagonal ramp: every row shifts the horizontal ramp, so the corners hold the two extremes. */
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $mix = (($x / max(1, $width - 1)) + ($y / max(1, $height - 1))) / 2;
            imagesetpixel($image, $x, $y, $steps[(int) round($mix * 255)]);
        }
    }
    foreach (['jpg', 'png'] as $format) {
        $path = $directory . '/' . str_replace(':', '-', $ratio) . '.' . $format;
        $written = $format === 'png' ? imagepng($image, $path, 9) : imagejpeg($image, $path, 90);
        if (!$written) {
            throw new RuntimeException('Could not write ' . $path);
        }
    }
}
