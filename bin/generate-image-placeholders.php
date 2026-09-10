<?php
declare(strict_types=1);

/** Rebuild the tiny product assets; GD is needed here, never at build runtime. */
$directory = dirname(__DIR__) . '/assets/image-placeholders';
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create ' . $directory);
}
foreach (['1:1', '2:3', '3:4', '4:3', '3:2', '4:5', '9:16', '16:9', '21:9'] as $ratio) {
    [$width, $height] = array_map('intval', explode(':', $ratio));
    foreach (['jpg', 'png'] as $format) {
        $image = imagecreatetruecolor($width * 24, $height * 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 229, 231, 235));
        $path = $directory . '/' . str_replace(':', '-', $ratio) . '.' . $format;
        $written = $format === 'png' ? imagepng($image, $path, 9) : imagejpeg($image, $path, 85);
        if (!$written) {
            throw new RuntimeException('Could not write ' . $path);
        }
    }
}
