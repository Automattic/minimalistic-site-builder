<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Local neutral pixels, with the same format and aspect ratio as the authored image. */
final class ImagePlaceholder
{
    /** @param array<string,mixed> $spec */
    public static function bytes(array $spec): string
    {
        $ratio = GeminiImage::aspectRatio((string) ($spec['aspectRatio'] ?? 'landscape'));
        $format = strtolower(pathinfo((string) $spec['filename'], PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
        $path = Package::imagePlaceholdersDir() . '/' . str_replace(':', '-', $ratio) . '.' . $format;
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Could not read bundled image placeholder: ' . $path);
        }
        return $bytes;
    }
}
