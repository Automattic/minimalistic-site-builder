<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Resolve the color hexes named in planning artifacts against the palette the
 * theme actually delivered.
 *
 * The design direction proposes a palette; theme-json is free to author a
 * different hex for the same slug (the direction palette only fills slugs the
 * model omitted), and page-plan runs concurrently with theme-json, so neither
 * artifact can see the delivered colors. Left alone, both keep quoting the
 * proposed hexes — inside the direction prose ("Terracotta #A6432A carries the
 * brand") and inside each section's content_notes — and every markup prompt
 * then carries two disagreeing sets of colors with nothing to rank them.
 *
 * This maps each drifted slug's proposed hex onto its delivered hex and
 * rewrites both artifacts in one pass. Pure text and array transforms; the
 * step owns the I/O.
 */
final class PaletteReconciliation
{
    /**
     * Proposed hex => delivered hex, for the slugs whose color drifted.
     *
     * Keys are uppercase `#RRGGBB`. Two cases are deliberately left out,
     * because rewriting them would replace one wrong color with another:
     *
     * - the proposed hex is still in the delivered palette under some other
     *   slug, so the text naming it still names a real theme color;
     * - two slugs proposed the same hex and drifted to different colors, so
     *   there is no single answer for the text that names it.
     *
     * @param array<string,string> $proposed  slug => hex from designDirection.json
     * @param array<string,string> $delivered slug => hex from theme.json
     * @return array{
     *     substitutions:array<string,string>,
     *     ambiguous:list<string>,
     *     skipReasons:array<string, 'still-in-palette'|'collided'>
     * }
     *         substitutions map proposed hex => delivered hex as theme.json
     *         spelled it. ambiguous lists every skipped slug; skipReasons
     *         names why each one was left alone.
     */
    public static function plan(array $proposed, array $delivered): array
    {
        $deliveredHexes = [];
        foreach ($delivered as $hex) {
            $normalized = self::normalizeHex($hex);
            if ($normalized !== null) {
                $deliveredHexes[$normalized] = true;
            }
        }

        $candidates = [];
        $ambiguous = [];
        $skipReasons = [];
        foreach ($proposed as $slug => $hex) {
            $from = self::normalizeHex($hex);
            $toRaw = is_string($delivered[$slug] ?? null) ? trim($delivered[$slug]) : '';
            $to = self::normalizeHex($toRaw);
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            $slug = (string) $slug;
            if (isset($deliveredHexes[$from])) {
                // Still a real theme color, just no longer this slug's.
                $ambiguous[] = $slug;
                $skipReasons[$slug] = 'still-in-palette';
                continue;
            }
            $candidates[$from][] = ['slug' => $slug, 'to' => $to, 'toRaw' => $toRaw];
        }

        $substitutions = [];
        foreach ($candidates as $from => $targets) {
            $distinct = array_unique(array_column($targets, 'to'));
            if (count($distinct) > 1) {
                foreach ($targets as $target) {
                    $ambiguous[] = $target['slug'];
                    $skipReasons[$target['slug']] = 'collided';
                }
                continue;
            }
            $substitutions[$from] = $targets[0]['toRaw'];
        }

        sort($ambiguous);
        return [
            'substitutions' => $substitutions,
            'ambiguous' => $ambiguous,
            'skipReasons' => $skipReasons,
        ];
    }

    /**
     * Apply every substitution to one string in a single pass, so a rewritten
     * hex can never be rewritten again by a later rule.
     *
     * Matching is case-insensitive and stops at the token boundary: an
     * eight-digit `#RRGGBBAA` is a different color and is left alone. The
     * replacement is the delivered hex exactly as theme.json spelled it,
     * including 3-digit shorthand.
     *
     * @param array<string,string> $substitutions proposed hex => delivered hex
     */
    public static function rewriteText(string $text, array $substitutions): string
    {
        if ($substitutions === [] || $text === '') {
            return $text;
        }
        $alternation = implode('|', array_map(
            static fn (string $hex): string => preg_quote(substr($hex, 1), '/'),
            array_keys($substitutions),
        ));
        $pattern = '/#(' . $alternation . ')(?![0-9A-Fa-f])/i';

        $rewritten = preg_replace_callback(
            $pattern,
            static function (array $match) use ($substitutions): string {
                $key = '#' . strtoupper($match[1]);
                return $substitutions[$key] ?? $match[0];
            },
            $text,
        );
        return is_string($rewritten) ? $rewritten : $text;
    }

    /**
     * Apply rewriteText() to every string in a decoded artifact, preserving
     * structure, key order and non-string values.
     *
     * @param array<array-key,mixed> $data
     * @param array<string,string>   $substitutions
     * @return array{0:array<array-key,mixed>,1:int} rewritten data, strings changed
     */
    public static function rewriteData(array $data, array $substitutions): array
    {
        $changed = 0;
        if ($substitutions === []) {
            return [$data, 0];
        }
        $walk = static function (mixed $value) use (&$walk, $substitutions, &$changed): mixed {
            if (is_string($value)) {
                $rewritten = self::rewriteText($value, $substitutions);
                if ($rewritten !== $value) {
                    $changed++;
                }
                return $rewritten;
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $value[$key] = $walk($item);
                }
            }
            return $value;
        };

        /** @var array<array-key,mixed> $rewritten */
        $rewritten = $walk($data);
        return [$rewritten, $changed];
    }

    /**
     * The slug => hex map of a theme.json color palette, ignoring malformed
     * entries (theme-json already warned about anything it could not repair).
     *
     * @param array<array-key,mixed> $theme
     * @return array<string,string>
     */
    public static function themePalette(array $theme): array
    {
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            return [];
        }
        $map = [];
        foreach ($palette as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            $color = is_string($entry['color'] ?? null) ? trim($entry['color']) : '';
            if ($slug === '' || self::normalizeHex($color) === null) {
                continue;
            }
            $map[$slug] = $color;
        }
        return $map;
    }

    /**
     * The slug => hex map a design direction proposed.
     *
     * @param array<array-key,mixed> $direction
     * @return array<string,string>
     */
    public static function directionPalette(array $direction): array
    {
        $palette = $direction['palette'] ?? null;
        if (!is_array($palette)) {
            return [];
        }
        $map = [];
        foreach ($palette as $slug => $hex) {
            if (!is_string($slug) || !is_string($hex) || self::normalizeHex($hex) === null) {
                continue;
            }
            $map[trim($slug)] = $hex;
        }
        return $map;
    }

    /** Uppercase `#RRGGBB`, expanding `#RGB`, or null when the value is not one. */
    private static function normalizeHex(mixed $hex): ?string
    {
        if (!is_string($hex)) {
            return null;
        }
        $trimmed = strtoupper(trim($hex));
        if (preg_match('/^#[0-9A-F]{6}$/', $trimmed) === 1) {
            return $trimmed;
        }
        if (preg_match('/^#[0-9A-F]{3}$/', $trimmed) === 1) {
            return '#' . $trimmed[1] . $trimmed[1] . $trimmed[2] . $trimmed[2] . $trimmed[3] . $trimmed[3];
        }
        return null;
    }
}
