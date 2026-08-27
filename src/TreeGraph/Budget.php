<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * The bill is a function of the brief, ported from x-pipeline's budget.mjs:
 * base = 1 (brief) + 1 (tokens) + F (furniture) + S + B + P; ceiling =
 * 2*base + I. The 2x covers one schema-retry OR one repair per artifact,
 * whichever fires. In this brochure port B and P are always 0, and a build
 * without --with-images drops I from the bill entirely (the placeholder
 * pixels still ship, minted free at publish).
 */
final class Budget
{
    /** Furniture: the header and footer template parts, one tree call each. */
    public const F = 2;

    /**
     * @param array<string,mixed> $brief
     * @return array{S:int,B:int,P:int,I:int,F:int,base:int,ceiling:int,with_images:bool}
     */
    public static function computeBudget(array $brief, bool $withImages): array
    {
        $s = 0;
        $i = 0;
        foreach ((array) ($brief['pages'] ?? []) as $page) {
            $sections = (array) ($page['sections'] ?? []);
            $s += count($sections);
            foreach ($sections as $section) {
                $i += count(self::sectionImageIntents((array) $section));
            }
        }
        $b = count((array) ($brief['custom_blocks'] ?? []));
        $p = count((array) ($brief['schema_packages'] ?? []));
        $base = 1 + 1 + self::F + $s + $b + $p;

        return [
            'S'           => $s,
            'B'           => $b,
            'P'           => $p,
            // Without images the metered generation pass is skipped whole, so
            // its calls leave the bill (the source pipeline's --no-images).
            'I'           => $withImages ? $i : 0,
            'F'           => self::F,
            'base'        => $base,
            'ceiling'     => $withImages ? 2 * $base + $i : 2 * $base,
            'with_images' => $withImages,
        ];
    }

    /**
     * A section's image intents, normalized: image_intent may be one string
     * or an array.
     *
     * @param array<string,mixed> $section
     * @return list<string>
     */
    public static function sectionImageIntents(array $section): array
    {
        $value = $section['image_intent'] ?? null;
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        return is_string($value) && $value !== '' ? [$value] : [];
    }
}
