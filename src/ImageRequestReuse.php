<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Reuse only equivalent image requests with the same authored contract. */
final class ImageRequestReuse
{
    /** Return duplicate index => representative index. Preserve original indices. */
    public static function aliases(array $specs, array $requests): array
    {
        $groups = [];
        foreach ($specs as $i => $spec) {
            $request = $requests[$i];
            $key = self::key($spec, $request);
            $groups[$key][] = $i;
        }
        $aliases = [];
        foreach ($groups as $indices) {
            $representative = $indices[0];
            foreach ($indices as $i) {
                if (ImageQa::applies($specs[$i])) {
                    $representative = $i;
                    break;
                }
            }
            foreach ($indices as $i) {
                if ($i !== $representative) {
                    $aliases[$i] = $representative;
                }
            }
        }
        return $aliases;
    }
    /** Include the complete pixel contract and exclude only the destination filename. */
    public static function key(array $spec, array $request): string
    {
        return hash('sha256', json_encode([
                $request['prompt'], $request['aspect_ratio'], $request['sample_image_size'], $request['mime'],
                ImageKind::effectiveKind($spec), $spec['role'] ?? '',
                $spec['subject'] ?? '', $spec['pageContext'] ?? '', $spec['aspectRatio'] ?? '',
                $spec['style'] ?? '',
        ], JSON_THROW_ON_ERROR));
    }

}
