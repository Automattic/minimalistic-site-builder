<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Colour and token maths for the tree graph, ported from the x-pipeline's
 * lib/tokens.mjs. The R9 discipline, mechanically: the theme's own
 * spacing/layout pass through the token set verbatim — these derivations feed
 * the PAYLOAD (context the model must echo), and tokenChecks() then asserts
 * the echo byte-for-byte.
 *
 * Deliberately NOT built on the legacy ContrastMath/PaletteFloor classes: the
 * tree graph's gates must agree with the x-pipeline thresholds to the decimal,
 * so the math is ported rather than adapted.
 */
final class TokenMath
{
    /** @var array<string,mixed>|null Memoized design-tokens contract schema. */
    private static ?array $contract = null;

    /** WCAG relative luminance of a #rrggbb (or #rgb) colour. */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $lin = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** WCAG contrast ratio between two hex colours (>= 1, <= 21). */
    public static function contrastRatio(string $hexA, string $hexB): float
    {
        $la = self::relativeLuminance($hexA);
        $lb = self::relativeLuminance($hexB);
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * Which ink reads on this colour: 'light' means black ink wins, i.e. the
     * colour itself is light. Shipped with every slug so the model never
     * guesses lightness from a slug's name (the cream-on-cream lesson).
     */
    public static function toneOf(string $hex): string
    {
        return self::contrastRatio($hex, '#000000') >= self::contrastRatio($hex, '#ffffff') ? 'light' : 'dark';
    }

    /**
     * Palette entries annotated with hex + measured tone, for the tree prompts.
     *
     * @param array<int,array<string,mixed>> $palette
     * @return array<int,array{slug:string,color:string,tone:string}>
     */
    public static function annotatePalette(array $palette): array
    {
        return array_values(array_map(static fn (array $p): array => [
            'slug'  => (string) $p['slug'],
            'color' => (string) $p['color'],
            'tone'  => self::toneOf((string) $p['color']),
        ], $palette));
    }

    /** Linear mix of two hex colours at parameter $t (0 = all A, 1 = all B). Uppercase output. */
    public static function mixHex(string $hexA, string $hexB, float $t): string
    {
        $a = self::rgb($hexA);
        $b = self::rgb($hexB);
        $out = '';
        foreach ($a as $i => $v) {
            $out .= str_pad(dechex((int) round($v + ($b[$i] - $v) * $t)), 2, '0', STR_PAD_LEFT);
        }
        return strtoupper('#' . $out);
    }

    /**
     * The band's measured ink menus: which palette slugs may carry text on
     * this ground. safe_inks clear 4.5:1 (any text); display_only_inks clear
     * 3:1 — large ornamental display text only. A slug in neither menu is
     * rejected mechanically at generation time.
     *
     * @param array<int,array<string,mixed>> $appliedPalette
     * @return array{safe_inks: list<string>, display_only_inks: list<string>}
     */
    public static function resolveInkMenus(string $background, array $appliedPalette): array
    {
        $bySlug = self::bySlug($appliedPalette);
        $bgHex = $bySlug[$background] ?? null;
        $safe = [];
        $displayOnly = [];
        if ($bgHex === null) {
            return ['safe_inks' => $safe, 'display_only_inks' => $displayOnly];
        }
        foreach ($appliedPalette as $p) {
            if (($p['slug'] ?? null) === $background) {
                continue;
            }
            $ratio = self::contrastRatio($bgHex, (string) $p['color']);
            if ($ratio >= 4.5) {
                $safe[] = (string) $p['slug'];
            } elseif ($ratio >= 3) {
                $displayOnly[] = (string) $p['slug'];
            }
        }
        return ['safe_inks' => $safe, 'display_only_inks' => $displayOnly];
    }

    /**
     * Resolve a brief-level band name ('base'|'surface'|'contrast'|'accent')
     * into the APPLIED palette slugs the tree may spend: brief roles are
     * matched to applied entries by hex, and the TEXT slug is chosen by
     * measured contrast against the band's actual colour.
     *
     * @param array<int,array<string,mixed>> $briefPalette
     * @param array<int,array<string,mixed>> $appliedPalette
     * @return array{background: ?string, text: ?string}
     */
    public static function resolveBandColors(string $band, array $briefPalette, array $appliedPalette): array
    {
        $bySlug = self::bySlug($appliedPalette);
        $slugs = array_keys($bySlug);
        $byColor = [];
        foreach ($appliedPalette as $p) {
            $byColor[strtolower((string) $p['color'])] = (string) $p['slug'];
        }
        $roleSlug = static function (string $role) use ($briefPalette, $byColor): ?string {
            foreach ($briefPalette as $p) {
                if (($p['role'] ?? null) === $role) {
                    return $byColor[strtolower((string) $p['color'])] ?? null;
                }
            }
            return null;
        };

        $base = in_array('base', $slugs, true) ? 'base' : ($appliedPalette[0]['slug'] ?? null);
        $contrast = in_array('contrast', $slugs, true) ? 'contrast' : ($appliedPalette[1]['slug'] ?? null);

        $background = match ($band) {
            'contrast' => $contrast,
            'accent'   => $roleSlug('accent') ?? $roleSlug('primary') ?? $contrast,
            'surface'  => $roleSlug('surface') ?? $roleSlug('background') ?? $base,
            default    => $base,
        };

        $bgHex = $background !== null ? ($bySlug[$background] ?? null) : null;
        $candidates = [];
        foreach ([$roleSlug('text'), $contrast, $base] as $candidate) {
            if ($candidate !== null && $candidate !== $background && isset($bySlug[$candidate])
                && !in_array($candidate, $candidates, true)
            ) {
                $candidates[] = $candidate;
            }
        }
        $text = $candidates[0] ?? $contrast;
        if ($bgHex !== null && count($candidates) > 1) {
            $text = array_reduce(
                $candidates,
                static fn (string $best, string $s): string => self::contrastRatio($bgHex, $bySlug[$s])
                    > self::contrastRatio($bgHex, $bySlug[$best]) ? $s : $best,
                $candidates[0],
            );
        }
        return ['background' => $background, 'text' => $text];
    }

    /**
     * wp_get_global_settings() serves origin-keyed arrays ({default, theme,
     * custom}) on real instances. The theme's own scale wins; core defaults
     * are the fallback.
     *
     * @return array<mixed>
     */
    public static function originArray(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach (['theme', 'default', 'custom'] as $origin) {
                if (isset($value[$origin]) && is_array($value[$origin])) {
                    return $value[$origin];
                }
            }
            return [];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $themeTokens
     * @return array{scale_unit: string, steps: list<array{slug:string,size:string}>}
     */
    public static function deriveThemeSpacing(array $themeTokens): array
    {
        $sizes = self::originArray($themeTokens['spacing']['spacingSizes'] ?? null);
        $steps = [];
        foreach ($sizes as $size) {
            if (is_array($size)) {
                $steps[] = ['slug' => (string) ($size['slug'] ?? ''), 'size' => (string) ($size['size'] ?? '')];
            }
        }
        return ['scale_unit' => 'px', 'steps' => $steps];
    }

    /**
     * @param array<string,mixed> $themeTokens
     * @return array{contentSize: string, wideSize: string}
     */
    public static function deriveThemeLayout(array $themeTokens): array
    {
        return [
            'contentSize' => (string) ($themeTokens['layout']['contentSize'] ?? ''),
            'wideSize'    => (string) ($themeTokens['layout']['wideSize'] ?? ''),
        ];
    }

    /**
     * Canonical JSON: object keys sorted ascending byte order at every depth,
     * list order kept, no insignificant whitespace, `/` and unicode unescaped.
     */
    public static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $k => $v) {
            $value[$k] = self::canonicalize($v);
        }
        return $value;
    }

    /**
     * The tokens contract gate: schema first, then the R9 byte-equality
     * checks, brief-palette coverage, the theme slugs base/contrast, and the
     * measured base/contrast pair at 4.5:1.
     *
     * @param array<string,mixed> $tokens
     * @param array<string,mixed> $themeSpacing
     * @param array<string,mixed> $themeLayout
     * @param array<int,array<string,mixed>> $briefPalette
     * @return list<array{path:string,message:string}>
     */
    public static function tokenChecks(array $tokens, array $themeSpacing, array $themeLayout, array $briefPalette): array
    {
        $issues = Schema::validate(self::contract(), $tokens);
        if ($issues !== []) {
            return $issues; // contract first; the rest assumes shape
        }

        if (self::canonicalJson($tokens['spacing']) !== self::canonicalJson($themeSpacing)) {
            $issues[] = ['path' => '/spacing', 'message' => "R9 violation: spacing must be byte-equal to the theme's own (copy theme_spacing verbatim)"];
        }
        if (self::canonicalJson($tokens['layout']) !== self::canonicalJson($themeLayout)) {
            $issues[] = ['path' => '/layout', 'message' => "R9 violation: layout must be byte-equal to the theme's own (copy theme_layout verbatim)"];
        }
        $have = [];
        foreach ($tokens['palette'] as $p) {
            $have[strtolower((string) $p['color'])] = true;
        }
        foreach ($briefPalette as $p) {
            if (!isset($have[strtolower((string) $p['color'])])) {
                $issues[] = ['path' => '/palette', 'message' => "brief color {$p['color']} ({$p['name']}) is missing from the palette"];
            }
        }
        $bySlug = self::bySlug($tokens['palette']);
        foreach (['base', 'contrast'] as $required) {
            if (!isset($bySlug[$required])) {
                $issues[] = ['path' => '/palette', 'message' => "palette must keep the theme slug \"{$required}\" (mapped onto this world) so theme template parts keep resolving"];
            }
        }
        // The theme wires body text straight to `contrast` on `base`: base is
        // the ground, contrast is the INK — the pair must read.
        if (isset($bySlug['base'], $bySlug['contrast'])) {
            $ratio = self::contrastRatio($bySlug['base'], $bySlug['contrast']);
            if ($ratio < 4.5) {
                $formatted = sprintf('%.2f', $ratio);
                $issues[] = [
                    'path'    => '/palette',
                    'message' => "contrast {$bySlug['contrast']} on base {$bySlug['base']} reads at {$formatted}:1 — body text"
                        . ' needs at least 4.5:1. "contrast" means the ink colour against the ground, not a'
                        . ' high-contrast-looking dark; on a dark base it must be LIGHT',
                ];
            }
        }
        return $issues;
    }

    /**
     * @param array<int,array<string,mixed>> $palette
     * @return array<string,string> slug => hex
     */
    public static function bySlug(array $palette): array
    {
        $map = [];
        foreach ($palette as $p) {
            if (isset($p['slug'], $p['color'])) {
                $map[(string) $p['slug']] = (string) $p['color'];
            }
        }
        return $map;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    /** @return array<string,mixed> */
    private static function contract(): array
    {
        if (self::$contract === null) {
            $file = dirname(__DIR__, 2) . '/schemas/tree/design-tokens.schema.json';
            $decoded = json_decode((string) file_get_contents($file), true);
            self::$contract = is_array($decoded) ? $decoded : [];
        }
        return self::$contract;
    }
}
