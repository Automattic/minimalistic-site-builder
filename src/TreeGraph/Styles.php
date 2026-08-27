<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * The style rosters and everything the tree graph does with them, ported from
 * x-pipeline's pipeline/lib/styles.mjs: load, shuffle deterministically,
 * detect user-named styles in the prompt (the pin rule), gate the brief's
 * choice, and render the chosen combo for downstream calls. The combo itself
 * is decided once, in the brief — same lane as axis and language.
 */
final class Styles
{
    /** @var array{artistic:array<int,array<string,mixed>>,ui:array<int,array<string,mixed>>}|null */
    private static ?array $cached = null;

    /**
     * The two rosters from data/styles/. Memoized per process.
     *
     * @return array{artistic:array<int,array<string,mixed>>,ui:array<int,array<string,mixed>>}
     */
    public static function load(): array
    {
        if (self::$cached === null) {
            $dir = dirname(__DIR__, 2) . '/data/styles';
            $read = static function (string $file) use ($dir): array {
                $decoded = json_decode((string) file_get_contents("{$dir}/{$file}"), true);
                if (!is_array($decoded)) {
                    throw new TreeGraphException('preflight_failed', "style roster {$dir}/{$file} is not valid JSON");
                }
                return $decoded;
            };
            self::$cached = ['artistic' => $read('artistic.json'), 'ui' => $read('ui.json')];
        }
        return self::$cached;
    }

    /**
     * One normalization for names, aliases and prompts: lowercase, diacritics
     * stripped, punctuation collapsed to spaces — so "Art-Deco", "art deco"
     * and "Sōsaku-hanga" all meet their roster entries.
     */
    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = (string) preg_replace('~\p{M}~u', '', $decomposed);
            }
        } else {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($transliterated)) {
                $text = $transliterated;
            }
        }
        $text = mb_strtolower($text);
        return trim((string) preg_replace('~[^a-z0-9]+~', ' ', $text));
    }

    /**
     * Deterministic Fisher–Yates: the seed comes from the caller (the user
     * prompt), never from random_int() — a random order would change between
     * runs of the same prompt and break resume determinism. Different prompts
     * see different orders, which is where the anti-bias lives.
     *
     * sha256(seed) -> two 32-bit words XORed -> mulberry32, faithfully ported
     * with explicit unsigned 32-bit arithmetic.
     *
     * @param list<mixed> $items
     * @return list<mixed>
     */
    public static function seededShuffle(array $items, string $seed): array
    {
        $hex = hash('sha256', $seed);
        $state = ((int) hexdec(substr($hex, 0, 8)) ^ (int) hexdec(substr($hex, 8, 8))) & 0xFFFFFFFF;

        $rand = static function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $state;
            $t = self::imul($t ^ ($t >> 15), $t | 1);
            $sum = ($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
            $t = ($t ^ $sum) & 0xFFFFFFFF;
            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };

        $out = array_values($items);
        for ($i = count($out) - 1; $i > 0; $i--) {
            $j = (int) floor($rand() * ($i + 1));
            [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
        }
        return $out;
    }

    /** Math.imul: 32-bit truncating multiply on unsigned 32-bit patterns. */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $aLo = $a & 0xFFFF;
        $aHi = ($a >> 16) & 0xFFFF;
        // (aHi*2^16*b + aLo*b) mod 2^32; mask the high half before shifting so
        // nothing overflows PHP's signed 64-bit int.
        return (($aLo * $b) + ((($aHi * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    /**
     * The pin rule, deterministically in code — "the user named it" is never
     * the model's to judge. Longest names match first and consume their span,
     * so "gothic revival" does not also pin "Gothic".
     *
     * @param array{artistic:array,ui:array}|null $styles
     * @return array{artistic:?string,ui:?string,flexible:?array{artistic:string,ui:string},also_named:list<string>}
     */
    public static function matchPinnedStyles(string $prompt, ?array $styles = null): array
    {
        $styles ??= self::load();

        $candidates = [];
        foreach (['artistic', 'ui'] as $list) {
            foreach ($styles[$list] as $entry) {
                $patterns = array_merge([(string) $entry['name']], array_map('strval', (array) ($entry['aliases'] ?? [])));
                foreach ($patterns as $raw) {
                    $pattern = self::normalize($raw);
                    if ($pattern !== '') {
                        $candidates[] = ['pattern' => $pattern, 'list' => $list, 'name' => (string) $entry['name']];
                    }
                }
            }
        }

        // Which lists each normalized pattern lives in (a shared alias makes a
        // pattern bi-list even when the two rosters share no exact names).
        $owners = [];
        foreach ($candidates as $c) {
            $owners[$c['pattern']][$c['list']] = $c['name'];
        }

        $text = ' ' . self::normalize($prompt) . ' ';
        $patterns = array_values(array_unique(array_column($candidates, 'pattern')));
        usort($patterns, static function (string $a, string $b): int {
            return strlen($b) - strlen($a) ?: strcmp($a, $b);
        });

        $found = []; // ['pattern' => ..., 'at' => ...]
        $seen = [];
        foreach ($patterns as $pattern) {
            $at = strpos($text, " {$pattern} ");
            if ($at === false || isset($seen[$pattern])) {
                continue;
            }
            $seen[$pattern] = true;
            $found[] = ['pattern' => $pattern, 'at' => $at];
            // Consume the span so a shorter name cannot re-match inside it.
            $text = substr($text, 0, $at + 1)
                . str_repeat(' ', strlen($pattern))
                . substr($text, $at + 1 + strlen($pattern));
        }
        usort($found, static fn (array $a, array $b): int => $a['at'] <=> $b['at']); // earliest mention wins a contested slot

        $pins = ['artistic' => null, 'ui' => null, 'flexible' => null, 'also_named' => []];
        foreach ($found as $f) {
            $o = $owners[$f['pattern']];
            $artistic = $o['artistic'] ?? null;
            $ui = $o['ui'] ?? null;
            if ($artistic !== null && $ui !== null) {
                if ($pins['artistic'] === null && $pins['ui'] === null && $pins['flexible'] === null) {
                    $pins['flexible'] = ['artistic' => $artistic, 'ui' => $ui];
                } elseif ($pins['artistic'] === null && $pins['ui'] !== null) {
                    $pins['artistic'] = $artistic;
                } elseif ($pins['ui'] === null && $pins['artistic'] !== null) {
                    $pins['ui'] = $ui;
                } else {
                    $pins['also_named'][] = $artistic;
                }
            } elseif ($artistic !== null) {
                if ($pins['artistic'] === null) {
                    $pins['artistic'] = $artistic;
                } else {
                    $pins['also_named'][] = $artistic;
                }
            } elseif ($pins['ui'] === null) {
                $pins['ui'] = $ui;
            } else {
                $pins['also_named'][] = $ui;
            }
        }
        return $pins;
    }

    /**
     * The brief-side gate: membership in the rosters, pins never overridden.
     * Rides the same validate() lane as the cross-checks, so a violation
     * burns the one metered schema-retry with the exact correction.
     *
     * @param array<string,mixed> $brief
     * @param array{artistic:array,ui:array}|null $styles
     * @param array{artistic:?string,ui:?string,flexible:?array,also_named:array}|null $pins
     * @return list<array{path:string,message:string}>
     */
    public static function styleChecks(array $brief, ?array $styles = null, ?array $pins = null): array
    {
        $styles ??= self::load();
        $issues = [];
        $style = $brief['style'] ?? null;
        if (!is_array($style)) {
            return $issues; // the schema's required[] reports the absence
        }
        foreach (['artistic', 'ui'] as $list) {
            $chosen = $style[$list] ?? null;
            if (!is_string($chosen)) {
                continue; // schema reports it
            }
            $inList = false;
            foreach ($styles[$list] as $entry) {
                if (($entry['name'] ?? null) === $chosen) {
                    $inList = true;
                    break;
                }
            }
            if (!$inList) {
                $near = null;
                foreach ($styles[$list] as $entry) {
                    if (self::normalize((string) $entry['name']) === self::normalize($chosen)) {
                        $near = (string) $entry['name'];
                        break;
                    }
                }
                $issues[] = [
                    'path'    => "/style/{$list}",
                    'message' => $near !== null
                        ? "\"{$chosen}\" is almost a {$list} styles list entry — write it exactly as \"{$near}\""
                        : "\"{$chosen}\" is not in the {$list} styles list — choose an entry from the list provided, exactly as written",
                ];
            }
        }
        if ($pins !== null) {
            foreach (['artistic', 'ui'] as $list) {
                if (($pins[$list] ?? null) !== null && ($style[$list] ?? null) !== $pins[$list]) {
                    $issues[] = [
                        'path'    => "/style/{$list}",
                        'message' => "the request names \"{$pins[$list]}\" — it is set in stone, never overridden: style.{$list} must be exactly \"{$pins[$list]}\"; only the other slot is yours to choose",
                    ];
                }
            }
            $flexible = $pins['flexible'] ?? null;
            if (is_array($flexible)
                && ($style['artistic'] ?? null) !== $flexible['artistic']
                && ($style['ui'] ?? null) !== $flexible['ui']
            ) {
                $issues[] = [
                    'path'    => '/style',
                    'message' => "the request names \"{$flexible['artistic']}\", which exists as both an artistic and a UI style — it must occupy one of the two slots (style.artistic \"{$flexible['artistic']}\" or style.ui \"{$flexible['ui']}\")",
                ];
            }
        }
        return $issues;
    }

    /**
     * What the brief call is told about the pin state, alongside the
     * shuffled lists.
     *
     * @param array{artistic:?string,ui:?string,flexible:?array,also_named:array} $pins
     */
    public static function renderPinNote(array $pins): string
    {
        $lines = [];
        if (($pins['artistic'] ?? null) !== null) {
            $lines[] = "The request names the artistic style: \"{$pins['artistic']}\". It is SET IN STONE — style.artistic must be exactly that; you choose only the UI design style, as the best match for it.";
        }
        if (($pins['ui'] ?? null) !== null) {
            $lines[] = "The request names the UI design style: \"{$pins['ui']}\". It is SET IN STONE — style.ui must be exactly that; you choose only the artistic style, as the best match for it.";
        }
        if (is_array($pins['flexible'] ?? null)) {
            $lines[] = "The request names \"{$pins['flexible']['artistic']}\", which appears in BOTH lists. It must fill one of the two slots (your judgment which); the other slot is your free choice.";
        }
        if (($pins['also_named'] ?? []) !== []) {
            $quoted = array_map(static fn (string $n): string => "\"{$n}\"", $pins['also_named']);
            $lines[] = 'Also mentioned, not binding (their slot is already fixed): ' . implode(', ', $quoted) . '.';
        }
        if ($lines === []) {
            return 'The request names no style from either list: both choices are fully yours — pick the pairing that best serves this site, not the safest one.';
        }
        return implode("\n", $lines);
    }

    /**
     * The combo, rendered for every downstream writing call (tokens, trees,
     * furniture). Empty string when the brief carries no style, so old
     * projects resume exactly as they were.
     *
     * @param array<string,mixed>|null $style
     * @param array{artistic:array,ui:array}|null $styles
     */
    public static function renderStyleNote(?array $style, ?array $styles = null): string
    {
        if (!is_array($style) || empty($style['artistic']) || empty($style['ui'])) {
            return '';
        }
        $styles ??= self::load();
        $entry = static function (string $list, string $name) use ($styles): ?array {
            foreach ($styles[$list] as $candidate) {
                if (($candidate['name'] ?? null) === $name) {
                    return $candidate;
                }
            }
            return null;
        };
        $cueLine = static function (?array $e): string {
            if ($e === null || !is_array($e['cues'] ?? null)) {
                return '';
            }
            $cues = $e['cues'];
            return ' — palette: ' . ($cues['palette'] ?? '') . '; type: ' . ($cues['typography'] ?? '')
                . '; composition: ' . ($cues['composition'] ?? '') . '; texture: ' . ($cues['texture'] ?? '');
        };
        return implode("\n", [
            'THE STYLE COMBO (decided once in the brief; every call obeys it):',
            "- Artistic style: {$style['artistic']}" . $cueLine($entry('artistic', (string) $style['artistic'])),
            "- UI design style: {$style['ui']}" . $cueLine($entry('ui', (string) $style['ui'])),
            '- Why this combo: ' . ($style['rationale'] ?? ''),
            'The artistic style drives mood, color story, texture and imagery; the UI design style drives layout, density, component shapes and navigation feel. Express both ONLY through the site\'s tokens and block supports — the combo never licenses raw CSS, off-token values, or breaking the site axis.',
        ]);
    }
}
