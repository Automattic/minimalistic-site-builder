<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Decode the small JSON envelopes returned by LLM steps.
 *
 * Provider-native structured output is the primary correctness mechanism, but
 * defensive decoding still helps with providers/models that ignore it and with
 * historical prompt-only calls. Repairs here are deliberately syntax-safe:
 * unwrap one outer Markdown fence and remove trailing commas while leaving all
 * prose inside JSON strings untouched.
 */
final class JsonDecoder
{
    /** @return array<mixed>|null */
    public static function decode(string $text): ?array
    {
        return self::decodeResult($text)['data'];
    }

    /**
     * Decode with an actionable parser error for retry/logging decisions.
     *
     * @return array{data:?array,error:?string}
     */
    public static function decodeResult(string $text): array
    {
        $json = self::stripEnvelope($text);
        $first = self::decodeStrict($json);
        if ($first['data'] !== null) {
            return $first;
        }

        $repaired = self::stripTrailingCommas($json);
        if ($repaired !== $json) {
            $second = self::decodeStrict($repaired);
            if ($second['data'] !== null) {
                return $second;
            }
            return $second;
        }

        return $first;
    }

    /** @return array{data:?array,error:?string} */
    private static function decodeStrict(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        }

        if (!is_array($data)) {
            return ['data' => null, 'error' => 'top-level JSON value must be an object or array'];
        }
        return ['data' => $data, 'error' => null];
    }

    /**
     * Strip a UTF-8 BOM and one complete outer ```json (or bare ```) fence.
     * The expression is anchored, so prose surrounding a JSON-looking fragment
     * is never silently discarded.
     */
    private static function stripEnvelope(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = trim(substr($text, 3));
        }

        if (preg_match('/\A```(?:json)?[^\S\r\n]*\R(.*)\R[ \t]*```[^\S\r\n]*\z/is', $text, $m) === 1) {
            $text = $m[1];
        }
        return trim($text);
    }

    /**
     * Remove commas immediately before } or ], tracking strings and escapes so
     * commas in user-facing prose are never changed.
     */
    private static function stripTrailingCommas(string $json): string
    {
        $out = '';
        $len = strlen($json);
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($inString) {
                $out .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $out .= $json[++$i];
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                $out .= $ch;
                continue;
            }
            if ($ch === ',') {
                $j = $i + 1;
                while ($j < $len && ctype_space($json[$j])) {
                    $j++;
                }
                if ($j < $len && ($json[$j] === '}' || $json[$j] === ']')) {
                    continue;
                }
            }
            $out .= $ch;
        }
        return $out;
    }
}
