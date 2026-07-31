<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

/**
 * Internal implementation behind the frozen CssTokenExtractor facade.
 *
 * The vendored scanner owns stylesheet/declaration boundaries; regexes only
 * classify already-isolated property values.
 */
final class CssTokenExtractorEngine
{
    private const PALETTE_LIMIT = 10;

    /**
     * @return array{
     *     palette: list<array{color:string,count:int}>,
     *     fonts: list<string>,
     *     spacing: list<string>
     * }
     */
    public static function extract(string $css): array
    {
        if (preg_match('//u', $css) !== 1) {
            return self::emptyResult();
        }
        try {
            $declarations = self::declarations($css);
            $customProperties = [];
            foreach ($declarations as $declaration) {
                if (str_starts_with($declaration['property'], '--')) {
                    $customProperties[$declaration['property']] = $declaration['value'];
                }
            }

            /** @var array<string,array{color:string,count:int,first:int}> $colors */
            $colors = [];
            $fonts = [];
            $spacing = [];
            $useIndex = 0;

            foreach ($declarations as $declaration) {
                $property = strtolower($declaration['property']);
                if (str_starts_with($property, '--')) {
                    continue;
                }
                $value = self::resolveOneLevel($declaration['value'], $customProperties);

                foreach (self::colorCandidates($value) as $candidate) {
                    $color = self::normalizeColor($candidate);
                    if ($color === null) {
                        continue;
                    }
                    $key = str_starts_with($color, '#')
                        ? $color
                        : strtolower((string) preg_replace('/\s+/', ' ', $color));
                    if (!isset($colors[$key])) {
                        $colors[$key] = ['color' => $color, 'count' => 0, 'first' => $useIndex++];
                    }
                    $colors[$key]['count']++;
                }

                if ($property === 'font-family') {
                    self::appendUnique($fonts, self::cleanFontStack($value));
                }
                if (self::isSpacingProperty($property)) {
                    foreach (CssValueSplitter::splitTopLevelWhitespace($value) as $token) {
                        if (self::isSpacingToken($token)) {
                            self::appendUnique($spacing, trim($token));
                        }
                    }
                }
            }

            if ($colors === [] || $fonts === []) {
                return self::emptyResult();
            }

            uasort($colors, static function (array $left, array $right): int {
                return $right['count'] <=> $left['count']
                    ?: $left['first'] <=> $right['first'];
            });
            $palette = [];
            foreach (array_slice(array_values($colors), 0, self::PALETTE_LIMIT) as $entry) {
                $palette[] = ['color' => $entry['color'], 'count' => $entry['count']];
            }

            return ['palette' => $palette, 'fonts' => $fonts, 'spacing' => $spacing];
        } catch (\Throwable) {
            return self::emptyResult();
        }
    }

    /**
     * @return list<array{property:string,value:string}>
     */
    private static function declarations(string $css): array
    {
        $declarations = [];
        $frames = [];
        $state = CssSyntaxScanner::state();
        $length = strlen($css);

        for ($offset = 0; $offset < $length;) {
            $character = $css[$offset];
            $topLevel = CssSyntaxScanner::isTopLevel($state);

            if ($topLevel && $character === '{') {
                $frames[] = $offset + 1;
            } elseif ($topLevel && $character === ';' && $frames !== []) {
                $frame = array_key_last($frames);
                self::appendDeclaration(
                    $declarations,
                    substr($css, $frames[$frame], $offset - $frames[$frame]),
                );
                $frames[$frame] = $offset + 1;
            } elseif ($topLevel && $character === '}' && $frames !== []) {
                $frame = array_key_last($frames);
                self::appendDeclaration(
                    $declarations,
                    substr($css, $frames[$frame], $offset - $frames[$frame]),
                );
                array_pop($frames);
                if ($frames !== []) {
                    $frames[array_key_last($frames)] = $offset + 1;
                }
            }

            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                $state = CssSyntaxScanner::state();
                $offset++;
                continue;
            }
            $offset = $next;
        }

        return $declarations;
    }

    /**
     * @param list<array{property:string,value:string}> $declarations
     */
    private static function appendDeclaration(array &$declarations, string $source): void
    {
        $source = trim($source);
        if ($source === '') {
            return;
        }

        $state = CssSyntaxScanner::state();
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && $source[$offset] === ':') {
                $property = trim(substr($source, 0, $offset));
                $value = trim(substr($source, $offset + 1));
                if (preg_match('/^(?:--|-?[A-Za-z])[A-Za-z0-9_-]*$/', $property) === 1
                    && $value !== ''
                    && CssValueSplitter::hasBalancedParens($value)) {
                    $value = trim((string) preg_replace('/\s*!important\s*$/i', '', $value));
                    $declarations[] = ['property' => $property, 'value' => $value];
                }
                return;
            }
            $next = CssSyntaxScanner::consume($source, $offset, $state);
            if ($next === null) {
                return;
            }
            $offset = $next;
        }
    }

    /**
     * @param array<string,string> $customProperties
     */
    private static function resolveOneLevel(string $value, array $customProperties): string
    {
        $resolved = '';
        $cursor = 0;
        $state = CssSyntaxScanner::state();
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            if ($state['quote'] === ''
                && !$state['comment']
                && self::functionStartsAt($value, $offset, 'var')) {
                $open = $offset + 3;
                $end = self::functionEnd($value, $open);
                if ($end !== null) {
                    $inner = substr($value, $open + 1, $end - $open - 2);
                    [$name, $fallback] = self::splitFirstTopLevel($inner, ',');
                    $name = trim($name);
                    if (preg_match('/^--[A-Za-z_][A-Za-z0-9_-]*$/', $name) === 1) {
                        $replacement = array_key_exists($name, $customProperties)
                            ? $customProperties[$name]
                            : ($fallback === null ? substr($value, $offset, $end - $offset) : trim($fallback));
                        $resolved .= substr($value, $cursor, $offset - $cursor) . $replacement;
                        $cursor = $end;
                        $offset = $end;
                        continue;
                    }
                }
            }

            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                break;
            }
            $offset = $next;
        }

        return $resolved . substr($value, $cursor);
    }

    /** @return list<string> */
    private static function colorCandidates(string $value): array
    {
        $colors = [];
        $state = CssSyntaxScanner::state();
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            $lexical = $state['quote'] === '' && !$state['comment'];
            $tokenStart = $lexical && self::isTokenStart($value, $offset);
            if ($tokenStart && preg_match(
                '/\G#[0-9A-F]{8}(?![0-9A-F])|\G#[0-9A-F]{6}(?![0-9A-F])'
                    . '|\G#[0-9A-F]{4}(?![0-9A-F])|\G#[0-9A-F]{3}(?![0-9A-F])/i',
                $value,
                $match,
                0,
                $offset,
            ) === 1 && self::isTokenEnd($value, $offset + strlen($match[0]))) {
                $colors[] = $match[0];
                $offset += strlen($match[0]);
                continue;
            }
            if ($tokenStart && preg_match(
                '/\G(?:rgba?|hsla?|oklch)\s*\(/i',
                $value,
                $match,
                0,
                $offset,
            ) === 1) {
                $open = $offset + strrpos($match[0], '(');
                $end = self::functionEnd($value, $open);
                if ($end !== null) {
                    $colors[] = substr($value, $offset, $end - $offset);
                    $offset = $end;
                    continue;
                }
            }
            if ($lexical && preg_match('/\Gurl\s*\(/i', $value, $match, 0, $offset) === 1) {
                $open = $offset + strrpos($match[0], '(');
                $end = self::functionEnd($value, $open);
                if ($end !== null) {
                    $offset = $end;
                    continue;
                }
            }

            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                break;
            }
            $offset = $next;
        }

        return $colors;
    }

    private static function functionStartsAt(string $value, int $offset, string $name): bool
    {
        return self::isTokenStart($value, $offset)
            && strncasecmp(substr($value, $offset, strlen($name)), $name, strlen($name)) === 0
            && ($value[$offset + strlen($name)] ?? '') === '(';
    }

    private static function isTokenStart(string $value, int $offset): bool
    {
        if ($offset === 0) {
            return true;
        }
        return preg_match('/[A-Za-z0-9_-]/', $value[$offset - 1]) !== 1;
    }

    private static function isTokenEnd(string $value, int $offset): bool
    {
        return !isset($value[$offset])
            || preg_match('/[A-Za-z0-9_-]/', $value[$offset]) !== 1;
    }

    private static function functionEnd(string $value, int $open): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = $open; $offset < $length;) {
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                return null;
            }
            if ($state['parens'] === 0) {
                return $next;
            }
            $offset = $next;
        }
        return null;
    }

    private static function normalizeColor(string $color): ?string
    {
        $color = trim($color);
        if (str_starts_with($color, '#')) {
            return self::normalizeHex($color);
        }
        if (preg_match('/^([A-Za-z]+)\s*\((.*)\)$/s', $color, $match) !== 1) {
            return null;
        }
        $function = strtolower($match[1]);
        return match ($function) {
            'rgb', 'rgba' => self::normalizeRgb($match[2]),
            'hsl', 'hsla' => self::normalizeHsl($match[2]),
            'oklch' => self::normalizeOklch($match[2], $color),
            default => null,
        };
    }

    private static function normalizeHex(string $color): ?string
    {
        $hex = strtoupper(substr($color, 1));
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } elseif (strlen($hex) === 4) {
            if ($hex[3] !== 'F') {
                return null;
            }
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } elseif (strlen($hex) === 8) {
            if (substr($hex, 6, 2) !== 'FF') {
                return null;
            }
            $hex = substr($hex, 0, 6);
        }
        return preg_match('/^[0-9A-F]{6}$/', $hex) === 1 ? '#' . $hex : null;
    }

    private static function normalizeRgb(string $inner): ?string
    {
        [$parts, $alpha] = self::functionalComponents($inner);
        if ($parts === null || count($parts) !== 3 || !self::opaqueAlpha($alpha)) {
            return null;
        }
        $rgb = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))%$/', $part, $match) === 1) {
                $rgb[] = 255 * (float) $match[1] / 100;
            } elseif (preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $part) === 1) {
                $rgb[] = (float) $part;
            } else {
                return null;
            }
        }
        return self::rgbHex($rgb);
    }

    private static function normalizeHsl(string $inner): ?string
    {
        [$parts, $alpha] = self::functionalComponents($inner);
        if ($parts === null || count($parts) !== 3 || !self::opaqueAlpha($alpha)) {
            return null;
        }
        $hue = self::angleDegrees($parts[0]);
        if ($hue === null
            || preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))%$/', trim($parts[1]), $sat) !== 1
            || preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))%$/', trim($parts[2]), $light) !== 1) {
            return null;
        }
        $s = max(0.0, min(1.0, (float) $sat[1] / 100));
        $l = max(0.0, min(1.0, (float) $light[1] / 100));
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        [$r, $g, $b] = match ((int) floor($hue / 60) % 6) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };
        $m = $l - $c / 2;
        return self::rgbHex([255 * ($r + $m), 255 * ($g + $m), 255 * ($b + $m)]);
    }

    private static function normalizeOklch(string $inner, string $authored): ?string
    {
        $slash = CssValueSplitter::splitTopLevel($inner, ['/']);
        if (count($slash) > 2 || (isset($slash[1]) && !self::opaqueAlpha($slash[1]))) {
            return null;
        }
        $parts = CssValueSplitter::splitTopLevelWhitespace($slash[0] ?? '');
        if (count($parts) !== 3) {
            return null;
        }
        $l = self::percentageOrNumber($parts[0], 1.0);
        $c = self::percentageOrNumber($parts[1], 0.4);
        $h = self::angleDegrees($parts[2]);
        if ($l === null || $c === null || $h === null) {
            return null;
        }

        $radians = deg2rad($h);
        $a = $c * cos($radians);
        $b = $c * sin($radians);
        $ll = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $mm = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $ss = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
        $linear = [
            4.0767416621 * $ll - 3.3077115913 * $mm + 0.2309699292 * $ss,
            -1.2684380046 * $ll + 2.6097574011 * $mm - 0.3413193965 * $ss,
            -0.0041960863 * $ll - 0.7034186147 * $mm + 1.707614701 * $ss,
        ];
        foreach ($linear as $channel) {
            if ($channel < -0.00001 || $channel > 1.00001) {
                return $authored;
            }
        }
        $rgb = array_map(static function (float $channel): float {
            $channel = max(0.0, min(1.0, $channel));
            $encoded = $channel <= 0.0031308
                ? 12.92 * $channel
                : 1.055 * ($channel ** (1 / 2.4)) - 0.055;
            return 255 * $encoded;
        }, $linear);
        return self::rgbHex($rgb);
    }

    /**
     * @return array{0:list<string>|null,1:string|null}
     */
    private static function functionalComponents(string $inner): array
    {
        $slash = CssValueSplitter::splitTopLevel($inner, ['/']);
        if (count($slash) > 2) {
            return [null, null];
        }
        $alpha = $slash[1] ?? null;
        $comma = CssValueSplitter::splitTopLevel($slash[0] ?? '', [',']);
        if (count($comma) > 1) {
            if ($alpha === null && count($comma) === 4) {
                $alpha = array_pop($comma);
            }
            return [$comma, $alpha];
        }
        return [CssValueSplitter::splitTopLevelWhitespace($slash[0] ?? ''), $alpha];
    }

    private static function opaqueAlpha(?string $alpha): bool
    {
        if ($alpha === null) {
            return true;
        }
        $alpha = trim($alpha);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))%$/', $alpha, $match) === 1) {
            return abs((float) $match[1] - 100.0) < 0.000001;
        }
        return preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $alpha) === 1
            && abs((float) $alpha - 1.0) < 0.000001;
    }

    private static function angleDegrees(string $angle): ?float
    {
        $angle = trim($angle);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))(deg|grad|rad|turn)?$/i', $angle, $match) !== 1) {
            return null;
        }
        $degrees = match (strtolower($match[2] ?? '')) {
            'grad' => (float) $match[1] * 0.9,
            'rad' => rad2deg((float) $match[1]),
            'turn' => (float) $match[1] * 360,
            default => (float) $match[1],
        };
        return fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }

    private static function percentageOrNumber(string $value, float $percentageScale): ?float
    {
        $value = trim($value);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))%$/', $value, $match) === 1) {
            return (float) $match[1] * $percentageScale / 100;
        }
        return preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $value) === 1
            ? (float) $value
            : null;
    }

    /** @param list<float|int> $rgb */
    private static function rgbHex(array $rgb): string
    {
        return sprintf(
            '#%02X%02X%02X',
            (int) round(max(0.0, min(255.0, (float) $rgb[0]))),
            (int) round(max(0.0, min(255.0, (float) $rgb[1]))),
            (int) round(max(0.0, min(255.0, (float) $rgb[2]))),
        );
    }

    private static function cleanFontStack(string $value): ?string
    {
        $parts = self::splitTopLevelScanner($value, ',');
        if ($parts === []) {
            return null;
        }
        return implode(', ', $parts);
    }

    /** @return array{0:string,1:string|null} */
    private static function splitFirstTopLevel(string $value, string $delimiter): array
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && $value[$offset] === $delimiter) {
                return [substr($value, 0, $offset), substr($value, $offset + 1)];
            }
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                break;
            }
            $offset = $next;
        }
        return [$value, null];
    }

    /** @return list<string> */
    private static function splitTopLevelScanner(string $value, string $delimiter): array
    {
        $parts = [];
        $cursor = 0;
        $state = CssSyntaxScanner::state();
        $length = strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (CssSyntaxScanner::isTopLevel($state) && $value[$offset] === $delimiter) {
                $part = trim(substr($value, $cursor, $offset - $cursor));
                if ($part !== '') {
                    $parts[] = $part;
                }
                $cursor = $offset + 1;
            }
            $next = CssSyntaxScanner::consume($value, $offset, $state);
            if ($next === null) {
                return [];
            }
            $offset = $next;
        }
        if (!CssSyntaxScanner::isComplete($state)) {
            return [];
        }
        $part = trim(substr($value, $cursor));
        if ($part !== '') {
            $parts[] = $part;
        }
        return $parts;
    }

    private static function isSpacingProperty(string $property): bool
    {
        return preg_match(
            '/^(?:margin|padding|inset)(?:-(?:top|right|bottom|left|inline|block'
                . '|inline-start|inline-end|block-start|block-end))?$'
                . '|^(?:gap|row-gap|column-gap)$/',
            $property,
        ) === 1;
    }

    private static function isSpacingToken(string $token): bool
    {
        $token = trim($token);
        if (preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:px|r?em|vw|vh|vmin|vmax|%|ch|lh)$/i', $token) === 1) {
            return preg_match('/^[+-]?0+(?:\.0+)?[A-Za-z%]*$/', $token) !== 1;
        }
        return preg_match('/^(?:clamp|min|max|calc)\(/i', $token) === 1
            && str_ends_with($token, ')')
            && CssValueSplitter::hasBalancedParens($token);
    }

    /** @param list<string> $values */
    private static function appendUnique(array &$values, ?string $value): void
    {
        if ($value !== null && $value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }

    /** @return array{palette:array{},fonts:array{},spacing:array{}} */
    private static function emptyResult(): array
    {
        return ['palette' => [], 'fonts' => [], 'spacing' => []];
    }
}
