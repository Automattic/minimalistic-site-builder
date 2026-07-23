<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Json;

/** JSON string codec over UTF-8 plus WTF-8 bytes for lone UTF-16 surrogates. */
final class JsStringCodec
{
    /** Decode one complete quoted JSON string token. */
    public static function decode(string $token): string
    {
        $length = strlen($token);
        if ($length < 2 || $token[0] !== '"' || $token[$length - 1] !== '"') {
            throw new \InvalidArgumentException('JSON string token must be quoted');
        }

        $output = '';
        for ($offset = 1; $offset < $length - 1;) {
            $byte = ord($token[$offset]);
            if ($byte < 0x20) {
                throw new \InvalidArgumentException('Unescaped control character in JSON string');
            }
            if ($byte !== 0x5c) {
                $width = self::utf8Width($token, $offset, allowSurrogate: false);
                $output .= substr($token, $offset, $width);
                $offset += $width;
                continue;
            }

            $offset++;
            if ($offset >= $length - 1) {
                throw new \InvalidArgumentException('Unterminated JSON escape');
            }
            $escape = $token[$offset++];
            $simple = [
                '"' => '"',
                '\\' => '\\',
                '/' => '/',
                'b' => "\x08",
                'f' => "\x0c",
                'n' => "\x0a",
                'r' => "\x0d",
                't' => "\x09",
            ];
            if (isset($simple[$escape])) {
                $output .= $simple[$escape];
                continue;
            }
            if ($escape !== 'u') {
                throw new \InvalidArgumentException("Invalid JSON escape \\{$escape}");
            }

            $unit = self::hexUnit($token, $offset);
            $offset += 4;
            if ($unit >= 0xd800 && $unit <= 0xdbff
                && substr($token, $offset, 2) === '\\u') {
                $low = self::hexUnit($token, $offset + 2);
                if ($low >= 0xdc00 && $low <= 0xdfff) {
                    $offset += 6;
                    $codepoint = 0x10000 + (($unit - 0xd800) << 10) + ($low - 0xdc00);
                    $output .= self::utf8($codepoint);
                    continue;
                }
            }
            if ($unit >= 0xd800 && $unit <= 0xdfff) {
                // JavaScript strings can retain an unmatched UTF-16 code unit.
                // WTF-8 gives that otherwise-unrepresentable unit a reversible
                // byte spelling inside the PHP-only typed model.
                $output .= self::utf8($unit);
                continue;
            }
            $output .= self::utf8($unit);
        }
        return $output;
    }

    /** Encode a JavaScript string represented as UTF-8/WTF-8. */
    public static function encode(string $value): string
    {
        $output = '"';
        $length = strlen($value);
        for ($offset = 0; $offset < $length;) {
            $byte = ord($value[$offset]);
            if ($byte < 0x80) {
                $output .= match ($byte) {
                    0x08 => '\\b',
                    0x09 => '\\t',
                    0x0a => '\\n',
                    0x0c => '\\f',
                    0x0d => '\\r',
                    0x22 => '\\"',
                    0x5c => '\\\\',
                    default => $byte < 0x20
                        ? sprintf('\\u%04x', $byte)
                        : $value[$offset],
                };
                $offset++;
                continue;
            }

            $width = self::utf8Width($value, $offset, allowSurrogate: true);
            if ($width === -3) {
                $unit = (($byte & 0x0f) << 12)
                    | ((ord($value[$offset + 1]) & 0x3f) << 6)
                    | (ord($value[$offset + 2]) & 0x3f);
                $output .= sprintf('\\u%04x', $unit);
                $offset += 3;
                continue;
            }
            $output .= substr($value, $offset, $width);
            $offset += $width;
        }
        return $output . '"';
    }

    private static function hexUnit(string $token, int $offset): int
    {
        $hex = substr($token, $offset, 4);
        if (strlen($hex) !== 4 || preg_match('/^[0-9a-fA-F]{4}$/D', $hex) !== 1) {
            throw new \InvalidArgumentException('Invalid Unicode escape');
        }
        return (int) hexdec($hex);
    }

    private static function utf8(int $codepoint): string
    {
        if ($codepoint <= 0x7f) {
            return chr($codepoint);
        }
        if ($codepoint <= 0x7ff) {
            return chr(0xc0 | ($codepoint >> 6))
                . chr(0x80 | ($codepoint & 0x3f));
        }
        if ($codepoint <= 0xffff) {
            return chr(0xe0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3f))
                . chr(0x80 | ($codepoint & 0x3f));
        }
        return chr(0xf0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3f))
            . chr(0x80 | (($codepoint >> 6) & 0x3f))
            . chr(0x80 | ($codepoint & 0x3f));
    }

    /**
     * Return byte width, or -3 for a valid three-byte WTF-8 surrogate when
     * allowed. Throws for every other malformed UTF-8 sequence.
     */
    private static function utf8Width(string $value, int $offset, bool $allowSurrogate): int
    {
        $length = strlen($value);
        $first = ord($value[$offset]);
        if ($first < 0x80) {
            return 1;
        }
        $continuation = static fn (int $at): bool =>
            $at < $length && (ord($value[$at]) & 0xc0) === 0x80;

        if ($first >= 0xc2 && $first <= 0xdf && $continuation($offset + 1)) {
            return 2;
        }
        if ($first >= 0xe0 && $first <= 0xef
            && $continuation($offset + 1) && $continuation($offset + 2)) {
            $second = ord($value[$offset + 1]);
            if ($first === 0xe0 && $second < 0xa0) {
                throw new \InvalidArgumentException('Overlong UTF-8 in JSON string');
            }
            if ($first === 0xed && $second >= 0xa0) {
                if ($allowSurrogate) {
                    return -3;
                }
                throw new \InvalidArgumentException('Raw UTF-8 surrogate in JSON string');
            }
            return 3;
        }
        if ($first >= 0xf0 && $first <= 0xf4
            && $continuation($offset + 1)
            && $continuation($offset + 2)
            && $continuation($offset + 3)) {
            $second = ord($value[$offset + 1]);
            if (($first === 0xf0 && $second < 0x90)
                || ($first === 0xf4 && $second > 0x8f)) {
                throw new \InvalidArgumentException('Out-of-range UTF-8 in JSON string');
            }
            return 4;
        }
        throw new \InvalidArgumentException("Malformed UTF-8 at byte {$offset}");
    }
}
