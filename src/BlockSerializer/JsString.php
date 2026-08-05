<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

/** JavaScript string semantics shared across the serializer. */
final class JsString
{
    /** String.prototype.trim(): the ECMAScript WhiteSpace + LineTerminator set. */
    public static function trim(string $value): string
    {
        return preg_replace(
            '/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u',
            '',
            $value,
        ) ?? trim($value);
    }
}
