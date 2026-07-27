<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Decode the small JSON envelopes returned by LLM steps.
 *
 * Provider-native structured output is the primary correctness mechanism, but
 * defensive decoding still helps with providers/models that ignore it and with
 * historical prompt-only calls. Repairs here are deliberately conservative:
 * unwrap one outer Markdown fence, escape paired quotes the model left
 * unescaped in string values, and remove trailing commas only after the quote
 * pass has established the JSON grammar. Structurally ambiguous input is left
 * for model-driven recovery rather than guessed at locally.
 */
final class JsonDecoder
{
    private const CONTEXT_ROOT = 0;
    private const CONTEXT_OBJECT = 1;
    private const CONTEXT_ARRAY = 2;
    private const JSON_NUMBER_PATTERN = '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/';

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
        $result = self::decodeStrict($json);
        if ($result['data'] !== null) {
            return $result;
        }

        // Quote repair also recognizes the surrounding JSON grammar. A null
        // result means the input is structurally ambiguous, so do not feed a
        // state-corrupted candidate into the trailing-comma pass.
        $escaped = self::escapeInnerQuotes($json);
        if ($escaped === null) {
            return $result;
        }
        if ($escaped !== $json) {
            $result = self::decodeStrict($escaped);
            if ($result['data'] !== null) {
                return $result;
            }
        }

        $repaired = self::stripTrailingCommas($escaped);
        if ($repaired !== $escaped) {
            $result = self::decodeStrict($repaired);
            if ($result['data'] !== null) {
                return $result;
            }
        }

        return $result;
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
                while ($j < $len && self::isJsonWhitespace($json[$j])) {
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

    /**
     * Escape paired prose quotes in JSON string values while recognizing the
     * complete surrounding grammar. Object keys are always parsed strictly,
     * and adjacent JSON tokens or incompatible delimiters make the repair
     * fail closed. A trailing comma is recognized but retained for the
     * dedicated pass above.
     *
     * @return string|null null when the input cannot be repaired unambiguously
     */
    private static function escapeInnerQuotes(string $json): ?string
    {
        $offset = 0;
        $changed = false;
        $out = self::takeJsonWhitespace($json, $offset);
        $value = self::repairJsonValue($json, $offset, $changed, self::CONTEXT_ROOT, 0);
        if ($value === null) {
            return null;
        }
        $out .= $value . self::takeJsonWhitespace($json, $offset);
        if ($offset !== strlen($json)) {
            return null;
        }
        return $changed ? $out : $json;
    }

    private static function repairJsonValue(
        string $json,
        int &$offset,
        bool &$changed,
        int $context,
        int $depth,
    ): ?string {
        if ($depth > 512) {
            return null;
        }

        $ch = $json[$offset] ?? '';
        if ($ch === '{') {
            return self::repairJsonObject($json, $offset, $changed, $depth + 1);
        }
        if ($ch === '[') {
            return self::repairJsonArray($json, $offset, $changed, $depth + 1);
        }
        if ($ch === '"') {
            return self::repairJsonValueString($json, $offset, $changed, $context);
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);
                return $literal;
            }
        }
        if ($ch === '-' || ($ch >= '0' && $ch <= '9')) {
            $number = self::jsonNumberAt($json, $offset);
            if ($number !== null) {
                $offset += strlen($number);
                return $number;
            }
        }
        return null;
    }

    private static function repairJsonObject(
        string $json,
        int &$offset,
        bool &$changed,
        int $depth,
    ): ?string {
        $out = '{';
        $offset++;
        $out .= self::takeJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;
            return $out . '}';
        }

        while (true) {
            $key = self::takeStrictJsonString($json, $offset);
            if ($key === null) {
                return null;
            }
            $out .= $key . self::takeJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                return null;
            }
            $out .= ':';
            $offset++;
            $out .= self::takeJsonWhitespace($json, $offset);

            $value = self::repairJsonValue($json, $offset, $changed, self::CONTEXT_OBJECT, $depth);
            if ($value === null) {
                return null;
            }
            $out .= $value . self::takeJsonWhitespace($json, $offset);

            $next = $json[$offset] ?? '';
            if ($next === '}') {
                $offset++;
                return $out . '}';
            }
            if ($next !== ',') {
                return null;
            }
            $out .= ',';
            $offset++;
            $out .= self::takeJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') === '}') {
                $offset++;
                return $out . '}';
            }
        }
    }

    private static function repairJsonArray(
        string $json,
        int &$offset,
        bool &$changed,
        int $depth,
    ): ?string {
        $out = '[';
        $offset++;
        $out .= self::takeJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;
            return $out . ']';
        }

        while (true) {
            $value = self::repairJsonValue($json, $offset, $changed, self::CONTEXT_ARRAY, $depth);
            if ($value === null) {
                return null;
            }
            $out .= $value . self::takeJsonWhitespace($json, $offset);

            $next = $json[$offset] ?? '';
            if ($next === ']') {
                $offset++;
                return $out . ']';
            }
            if ($next !== ',') {
                return null;
            }
            $out .= ',';
            $offset++;
            $out .= self::takeJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') === ']') {
                $offset++;
                return $out . ']';
            }
        }
    }

    /**
     * Keys are never quote-repair targets. The first unescaped quote closes
     * the key; if that was not the intended boundary, the required colon check
     * in repairJsonObject rejects the payload.
     */
    private static function takeStrictJsonString(string $json, int &$offset): ?string
    {
        if (($json[$offset] ?? '') !== '"') {
            return null;
        }
        $start = $offset++;
        $len = strlen($json);
        while ($offset < $len) {
            $ch = $json[$offset];
            if ($ch === '\\') {
                if ($offset + 1 >= $len) {
                    return null;
                }
                $offset += 2;
                continue;
            }
            if ($ch === '"') {
                $offset++;
                return substr($json, $start, $offset - $start);
            }
            if (ord($ch) < 0x20) {
                return null;
            }
            $offset++;
        }
        return null;
    }

    private static function repairJsonValueString(
        string $json,
        int &$offset,
        bool &$changed,
        int $context,
    ): ?string {
        $out = '"';
        $offset++;
        $insertedQuotes = 0;
        $len = strlen($json);

        while ($offset < $len) {
            $ch = $json[$offset];
            if ($ch === '\\') {
                if ($offset + 1 >= $len) {
                    return null;
                }
                $out .= $ch . $json[$offset + 1];
                $offset += 2;
                continue;
            }
            if ($ch !== '"') {
                if (ord($ch) < 0x20) {
                    return null;
                }
                $out .= $ch;
                $offset++;
                continue;
            }

            $nextOffset = $offset + 1;
            while ($nextOffset < $len && self::isJsonWhitespace($json[$nextOffset])) {
                $nextOffset++;
            }
            $next = $json[$nextOffset] ?? '';
            $evenPair = $insertedQuotes % 2 === 0;
            if (self::isStringTerminator($next, $context)) {
                if ($evenPair) {
                    $out .= '"';
                    $offset++;
                    return $out;
                }
                return null;
            }
            if (self::isUnsafeQuoteContinuation($json, $nextOffset)) {
                return null;
            }

            $out .= '\\"';
            $offset++;
            $insertedQuotes++;
            $changed = true;
        }
        return null;
    }

    private static function isStringTerminator(string $next, int $context): bool
    {
        if ($context === self::CONTEXT_ROOT) {
            return $next === '';
        }
        if ($context === self::CONTEXT_OBJECT) {
            return $next === ',' || $next === '}';
        }
        return $next === ',' || $next === ']';
    }

    /**
     * If what follows can already begin another JSON token, treating the quote
     * as prose could silently merge structural values or object members.
     */
    private static function isUnsafeQuoteContinuation(string $json, int $offset): bool
    {
        $next = $json[$offset] ?? '';
        if ($next === '' || str_contains('"{}[],:', $next)) {
            return true;
        }
        if ($next === '-' || ($next >= '0' && $next <= '9')) {
            return self::jsonNumberAt($json, $offset) !== null;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                return true;
            }
        }
        return false;
    }

    private static function jsonNumberAt(string $json, int $offset): ?string
    {
        return preg_match(self::JSON_NUMBER_PATTERN, $json, $m, 0, $offset) === 1 ? $m[0] : null;
    }

    private static function takeJsonWhitespace(string $json, int &$offset): string
    {
        $start = $offset;
        $len = strlen($json);
        while ($offset < $len && self::isJsonWhitespace($json[$offset])) {
            $offset++;
        }
        return substr($json, $start, $offset - $start);
    }

    private static function isJsonWhitespace(string $ch): bool
    {
        return $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r";
    }
}
