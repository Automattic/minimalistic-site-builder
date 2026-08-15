<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

/** Effectful boundary for applying pure CSS contrast findings. */
final class CssContrastAdjuster
{
    /**
     * A 0.10 OKLab move is already plainly visible while normally preserving
     * the colour's identity. Larger brand-palette shifts require design review.
     */
    private const MAX_BACKGROUND_OKLAB_DISTANCE = 0.10;

    /**
     * @param list<array{
     *     selector:string,
     *     status:string,
     *     fg:?string,
     *     bg:?string,
     *     ratio:?float,
     *     suggested:?string
     * }> $findings
     */
    public static function apply(
        Project $project,
        string $path,
        string $css,
        string $markup,
        array $findings,
    ): string
    {
        $warnings = [];
        $adjusted = $css;
        foreach ($findings as $finding) {
            if ($finding['status'] === 'pass') {
                continue;
            }
            if ($finding['status'] === 'unverified') {
                [$foreground, $background] = self::authoredValues($adjusted, $finding['selector']);
                $warnings[] = self::receipt(
                    $path,
                    $finding['selector'],
                    $foreground,
                    $background,
                    $background,
                    null,
                    null,
                    'unverified',
                    self::unverifiedReason($adjusted, $finding['selector']),
                );
                continue;
            }
            if ($finding['status'] !== 'fail'
                || !is_string($finding['fg'])
                || !is_string($finding['bg'])) {
                $warnings[] = self::receipt(
                    $path,
                    $finding['selector'],
                    $finding['fg'] ?? 'unresolved',
                    $finding['bg'] ?? 'unresolved',
                    $finding['bg'] ?? 'unchanged',
                    $finding['ratio'] ?? null,
                    $finding['ratio'] ?? null,
                    'unverified',
                    'invalid-finding',
                );
                continue;
            }

            $currentFindings = CssContrastCheck::check($adjusted, $markup);
            $current = self::matchingFinding($currentFindings, $finding);
            if ($current !== null && $current['status'] === 'pass') {
                $warnings[] = self::receipt(
                    $path,
                    $finding['selector'],
                    $finding['fg'],
                    $finding['bg'],
                    $current['bg'] ?? $finding['bg'],
                    $finding['ratio'] ?? null,
                    $current['ratio'] ?? null,
                    'adjusted',
                    'background-moved-within-perceptual-cap',
                );
                continue;
            }
            if ($current === null || $current['status'] !== 'fail'
                || !is_string($current['fg']) || !is_string($current['bg'])) {
                $warnings[] = self::receipt(
                    $path,
                    $finding['selector'],
                    $finding['fg'],
                    $finding['bg'],
                    $finding['bg'],
                    $finding['ratio'] ?? null,
                    $finding['ratio'] ?? null,
                    'unchanged',
                    'background-declaration-target-ambiguous',
                );
                continue;
            }

            $repair = self::repairBackground($adjusted, $markup, $currentFindings, $current);
            if ($repair['css'] !== null) {
                $adjusted = $repair['css'];
                $warnings[] = self::receipt(
                    $path,
                    $finding['selector'],
                    $finding['fg'],
                    $finding['bg'],
                    $repair['background'],
                    $finding['ratio'] ?? null,
                    $repair['ratio'],
                    'adjusted',
                    'background-moved-within-perceptual-cap',
                );
                continue;
            }

            $warnings[] = self::receipt(
                $path,
                $finding['selector'],
                $finding['fg'],
                $finding['bg'],
                $finding['bg'],
                $finding['ratio'] ?? null,
                $finding['ratio'] ?? null,
                'unchanged',
                $repair['reason'],
            );
        }

        $project->addWarnings('css_contrast', $warnings);
        return $adjusted;
    }

    /**
     * @param list<array<string,mixed>> $before
     * @param array<string,mixed> $finding
     * @return array{css:?string,background:string,ratio:?float,reason:string}
     */
    private static function repairBackground(
        string $css,
        string $markup,
        array $before,
        array $finding,
    ): array {
        $declarations = self::stylesheetDeclarations($css);
        $customProperties = self::customProperties($declarations);
        $foreground = self::color(self::resolveVars($finding['fg'], $customProperties));
        $background = self::color(self::resolveVars($finding['bg'], $customProperties));
        if ($foreground === null || $background === null || $background['alpha'] < 1.0) {
            return self::failedRepair($finding['bg'], 'background-color-unresolved');
        }

        $targets = [];
        foreach ($declarations as $declaration) {
            if (!in_array($declaration['property'], ['background', 'background-color'], true)) {
                continue;
            }
            $resolved = self::color(self::resolveVars($declaration['evaluation'], $customProperties));
            if ($resolved !== null && $resolved['alpha'] >= 1.0 && $resolved['rgb'] === $background['rgb']) {
                $targets[] = $declaration;
            }
        }
        if ($targets === []) {
            return self::failedRepair(
                $finding['bg'],
                self::backgroundReason($declarations, $finding['selector']),
            );
        }

        $candidates = self::passingBackgrounds($foreground, $background['rgb']);
        $withinCap = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['distance'] <= self::MAX_BACKGROUND_OKLAB_DISTANCE,
        ));
        if ($withinCap === []) {
            return self::failedRepair($finding['bg'], 'perceptual-shift-cap-exceeded');
        }

        $sawConflict = false;
        foreach ($targets as $target) {
            foreach ($withinCap as $candidate) {
                $replacement = self::hex($candidate['rgb']);
                $trial = substr($css, 0, $target['value_start'])
                    . $replacement
                    . substr($css, $target['value_end']);
                $after = CssContrastCheck::check($trial, $markup);
                $delivered = self::matchingFinding($after, $finding);
                if ($delivered === null || $delivered['status'] !== 'pass') {
                    continue;
                }
                if (self::regressesPassingPair($before, $after)) {
                    $sawConflict = true;
                    continue;
                }
                return [
                    'css' => $trial,
                    'background' => $replacement,
                    'ratio' => $delivered['ratio'],
                    'reason' => '',
                ];
            }
        }

        return self::failedRepair(
            $finding['bg'],
            $sawConflict ? 'shared-background-conflict' : 'background-declaration-target-ambiguous',
        );
    }

    /** @return array{css:null,background:string,ratio:null,reason:string} */
    private static function failedRepair(string $background, string $reason): array
    {
        return ['css' => null, 'background' => $background, 'ratio' => null, 'reason' => $reason];
    }

    /**
     * @param list<array<string,mixed>> $after
     * @param array<string,mixed> $needle
     * @return array<string,mixed>|null
     */
    private static function matchingFinding(array $after, array $needle): ?array
    {
        $matches = array_values(array_filter(
            $after,
            static fn (array $candidate): bool => $candidate['selector'] === $needle['selector']
                && $candidate['fg'] === $needle['fg'],
        ));
        if (count($matches) === 1) {
            return $matches[0];
        }
        foreach ($matches as $match) {
            if ($match['bg'] === ($needle['bg'] ?? null)) {
                return $match;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $before @param list<array<string,mixed>> $after */
    private static function regressesPassingPair(array $before, array $after): bool
    {
        foreach ($before as $prior) {
            if ($prior['status'] !== 'pass') {
                continue;
            }
            $stillPasses = false;
            foreach ($after as $delivered) {
                if ($delivered['selector'] === $prior['selector']
                    && $delivered['fg'] === $prior['fg']
                    && $delivered['status'] === 'pass') {
                    $stillPasses = true;
                    break;
                }
            }
            if (!$stillPasses) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the first passing background toward each luminance extreme, then
     * rank the two alternatives by perceptual distance from the authored hue.
     *
     * @param array{rgb:array{0:int,1:int,2:int},alpha:float} $foreground
     * @param array{0:int,1:int,2:int} $background
     * @return list<array{rgb:array{0:int,1:int,2:int},distance:float}>
     */
    private static function passingBackgrounds(array $foreground, array $background): array
    {
        $candidates = [];
        foreach ([[0, 0, 0], [255, 255, 255]] as $target) {
            for ($step = 1; $step <= 255; $step++) {
                $candidate = [];
                foreach ([0, 1, 2] as $channel) {
                    $candidate[$channel] = (int) round(
                        $background[$channel]
                        + ($target[$channel] - $background[$channel]) * ($step / 255),
                    );
                }
                $rendered = ContrastMath::compositeOver(
                    $foreground['rgb'],
                    $foreground['alpha'],
                    $candidate,
                );
                if (ContrastMath::ratio($rendered, $candidate) >= ContrastMath::NORMAL_TEXT) {
                    $candidates[] = [
                        'rgb' => $candidate,
                        'distance' => self::oklabDistance($background, $candidate),
                    ];
                    break;
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        return $candidates;
    }

    /** @param array{0:int,1:int,2:int} $from @param array{0:int,1:int,2:int} $to */
    private static function oklabDistance(array $from, array $to): float
    {
        $a = self::oklab($from);
        $b = self::oklab($to);
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @param array{0:int,1:int,2:int} $rgb @return array{0:float,1:float,2:float} */
    private static function oklab(array $rgb): array
    {
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;
            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        $l = 0.4122214708 * $linear[0] + 0.5363325363 * $linear[1] + 0.0514459929 * $linear[2];
        $m = 0.2119034982 * $linear[0] + 0.6806995451 * $linear[1] + 0.1073969566 * $linear[2];
        $s = 0.0883024619 * $linear[0] + 0.2817188376 * $linear[1] + 0.6299787005 * $linear[2];
        $l = $l ** (1 / 3);
        $m = $m ** (1 / 3);
        $s = $s ** (1 / 3);
        return [
            0.2104542553 * $l + 0.793617785 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.428592205 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    /** @return array{rgb:array{0:int,1:int,2:int},alpha:float}|null */
    private static function color(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $value) === 1
            || preg_match('/^rgba?\([^)]*\)$/i', $value) === 1) {
            $colors = ContrastMath::parseCssColors($value);
            if (count($colors) === 1) {
                return $colors[0];
            }
        }
        if (preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/i', $value, $hex) === 1) {
            $digits = $hex[1];
            $rgbHex = strlen($digits) === 4
                ? $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2]
                : substr($digits, 0, 6);
            $alphaHex = strlen($digits) === 4 ? $digits[3] . $digits[3] : substr($digits, 6, 2);
            $rgb = ContrastMath::hexToRgb($rgbHex);
            return $rgb === null ? null : ['rgb' => $rgb, 'alpha' => hexdec($alphaHex) / 255];
        }
        if (preg_match('/^rgba?\((.*)\)$/is', $value, $functional) !== 1
            || str_contains($functional[1], ',')) {
            return null;
        }
        $slash = CssValueSplitter::splitTopLevel($functional[1], ['/']);
        if (count($slash) > 2) {
            return null;
        }
        $channels = CssValueSplitter::splitTopLevelWhitespace($slash[0] ?? '');
        if (count($channels) !== 3) {
            return null;
        }
        $rgb = [];
        foreach ($channels as $channel) {
            $parsed = self::rgbChannel($channel);
            if ($parsed === null) {
                return null;
            }
            $rgb[] = $parsed;
        }
        $alpha = isset($slash[1]) ? self::alphaChannel($slash[1]) : 1.0;
        return $alpha === null ? null : ['rgb' => $rgb, 'alpha' => $alpha];
    }

    private static function rgbChannel(string $channel): ?int
    {
        $channel = trim($channel);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))%$/', $channel, $percent) === 1) {
            return (int) round(max(0.0, min(100.0, (float) $percent[1])) * 255 / 100);
        }
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $channel) !== 1) {
            return null;
        }
        return (int) round(max(0.0, min(255.0, (float) $channel)));
    }

    private static function alphaChannel(string $alpha): ?float
    {
        $alpha = trim($alpha);
        if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))%$/', $alpha, $percent) === 1) {
            return max(0.0, min(100.0, (float) $percent[1])) / 100;
        }
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $alpha) !== 1) {
            return null;
        }
        return max(0.0, min(1.0, (float) $alpha));
    }

    /** @return list<array{selector:string,property:string,value:string,evaluation:string,value_start:int,value_end:int}> */
    private static function stylesheetDeclarations(string $css): array
    {
        $declarations = [];
        $offset = 0;
        $length = strlen($css);
        while ($offset < $length) {
            $open = self::nextTopLevel($css, $offset, '{');
            if ($open === null) {
                break;
            }
            $close = self::closingBrace($css, $open + 1);
            if ($close === null) {
                break;
            }
            $selector = self::clean(substr($css, $offset, $open - $offset));
            if ($selector !== '' && !str_starts_with($selector, '@')) {
                foreach (self::segments($css, $open + 1, $close) as $segment) {
                    $declaration = self::parseDeclaration($css, $segment['start'], $segment['end']);
                    if ($declaration !== null) {
                        $declaration['selector'] = $selector;
                        $declarations[] = $declaration;
                    }
                }
            }
            $offset = $close + 1;
        }
        return $declarations;
    }

    /** @return list<array{start:int,end:int}> */
    private static function segments(string $css, int $start, int $end): array
    {
        $segments = [];
        $segmentStart = $start;
        $state = CssSyntaxScanner::state();
        for ($offset = $start; $offset <= $end;) {
            if ($offset === $end || (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === ';')) {
                $segments[] = ['start' => $segmentStart, 'end' => $offset];
                $segmentStart = $offset + 1;
                $offset++;
                continue;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                break;
            }
            $offset = $next;
        }
        return $segments;
    }

    /** @return array{property:string,value:string,evaluation:string,value_start:int,value_end:int}|null */
    private static function parseDeclaration(string $css, int $start, int $end): ?array
    {
        $state = CssSyntaxScanner::state();
        $colon = null;
        for ($offset = $start; $offset < $end;) {
            if (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === ':') {
                $colon = $offset;
                break;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        if ($colon === null) {
            return null;
        }
        $property = strtolower(self::clean(substr($css, $start, $colon - $start)));
        if ($property === '') {
            return null;
        }
        $valueStart = $colon + 1;
        while ($valueStart < $end && CssSyntaxScanner::isCssWhitespace($css[$valueStart])) {
            $valueStart++;
        }
        $valueEnd = $end;
        while ($valueEnd > $valueStart && CssSyntaxScanner::isCssWhitespace($css[$valueEnd - 1])) {
            $valueEnd--;
        }
        $value = substr($css, $valueStart, $valueEnd - $valueStart);
        $masked = self::commentsMasked($value);
        if ($masked === null) {
            return null;
        }
        $evaluation = trim($masked);
        if (preg_match('/!\s*important\s*$/i', rtrim($masked), $important, PREG_OFFSET_CAPTURE) === 1) {
            $valueEnd = $valueStart + $important[0][1];
            while ($valueEnd > $valueStart && CssSyntaxScanner::isCssWhitespace($css[$valueEnd - 1])) {
                $valueEnd--;
            }
            $value = substr($css, $valueStart, $valueEnd - $valueStart);
            $evaluation = trim(substr($masked, 0, $important[0][1]));
        }
        if ($value === '' || $evaluation === '') {
            return null;
        }
        return [
            'property' => $property,
            'value' => $value,
            'evaluation' => $evaluation,
            'value_start' => $valueStart,
            'value_end' => $valueEnd,
        ];
    }

    /** @param list<array<string,mixed>> $declarations @return array<string,string> */
    private static function customProperties(array $declarations): array
    {
        $properties = [];
        foreach ($declarations as $declaration) {
            if (str_starts_with($declaration['property'], '--')) {
                $properties[$declaration['property']] = $declaration['evaluation'];
            }
        }
        return $properties;
    }

    /** @param array<string,string> $map @param array<string,bool> $visited */
    private static function resolveVars(string $value, array $map, array $visited = []): string
    {
        $value = trim($value);
        if (preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*(.*?))?\s*\)$/is', $value, $match) !== 1) {
            return $value;
        }
        $name = $match[1];
        if (isset($map[$name])) {
            if (isset($visited[$name])) {
                return $value;
            }
            $visited[$name] = true;
            return self::resolveVars($map[$name], $map, $visited);
        }
        return ($match[2] ?? '') === '' ? $value : self::resolveVars($match[2], $map, $visited);
    }

    private static function unverifiedReason(string $css, string $selector): string
    {
        return self::backgroundReason(self::stylesheetDeclarations($css), $selector);
    }

    /** @param list<array<string,mixed>> $declarations */
    private static function backgroundReason(array $declarations, string $selector): string
    {
        $matching = array_values(array_filter(
            $declarations,
            static fn (array $declaration): bool => $declaration['selector'] === $selector
                && in_array($declaration['property'], ['background', 'background-color'], true),
        ));
        foreach ($matching as $declaration) {
            if (preg_match('/gradient\s*\(/i', $declaration['evaluation']) === 1) {
                return 'background-gradient';
            }
        }
        foreach ($matching as $declaration) {
            if (preg_match('/(?:url|image(?:-set)?|cross-fade)\s*\(/i', $declaration['evaluation']) === 1) {
                return 'background-image';
            }
        }
        foreach ($matching as $declaration) {
            if (preg_match('/\bvar\s*\(/i', $declaration['evaluation']) === 1) {
                return 'background-unresolved-variable';
            }
        }
        return 'selector-or-color-context-unresolved';
    }

    /** @return array{0:string,1:string} */
    private static function authoredValues(string $css, string $selector): array
    {
        $foreground = 'unresolved';
        $background = 'unresolved';
        foreach (self::stylesheetDeclarations($css) as $declaration) {
            if ($declaration['selector'] !== $selector) {
                continue;
            }
            if ($declaration['property'] === 'color') {
                $foreground = $declaration['value'];
            } elseif (in_array($declaration['property'], ['background', 'background-color'], true)) {
                $background = $declaration['value'];
            }
        }
        return [$foreground, $background];
    }

    private static function nextTopLevel(string $css, int $offset, string $needle): ?int
    {
        $state = CssSyntaxScanner::state();
        $length = strlen($css);
        while ($offset < $length) {
            if (CssSyntaxScanner::isTopLevel($state) && $css[$offset] === $needle) {
                return $offset;
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        return null;
    }

    private static function closingBrace(string $css, int $offset): ?int
    {
        $state = CssSyntaxScanner::state();
        $depth = 0;
        $length = strlen($css);
        while ($offset < $length) {
            if (CssSyntaxScanner::isTopLevel($state)) {
                if ($css[$offset] === '{') {
                    $depth++;
                } elseif ($css[$offset] === '}') {
                    if ($depth === 0) {
                        return $offset;
                    }
                    $depth--;
                }
            }
            $next = CssSyntaxScanner::consume($css, $offset, $state);
            if ($next === null) {
                return null;
            }
            $offset = $next;
        }
        return null;
    }

    private static function clean(string $source): string
    {
        $withoutComments = preg_replace('~/\*.*?\*/~s', '', $source);
        return trim(is_string($withoutComments) ? $withoutComments : $source);
    }

    /** Replace comments outside strings with spaces so byte offsets survive. */
    private static function commentsMasked(string $source): ?string
    {
        $masked = $source;
        $quote = '';
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            $character = $source[$offset];
            if ($quote !== '') {
                if ($character === '\\') {
                    $next = CssSyntaxScanner::escapeEnd($source, $offset);
                    if ($next === null) {
                        return null;
                    }
                    $offset = $next;
                    continue;
                }
                if ($character === $quote) {
                    $quote = '';
                }
                $offset++;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $offset++;
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '*') {
                $close = strpos($source, '*/', $offset + 2);
                if ($close === false) {
                    return null;
                }
                $end = $close + 2;
                for ($index = $offset; $index < $end; $index++) {
                    if (!CssSyntaxScanner::isCssWhitespace($masked[$index])) {
                        $masked[$index] = ' ';
                    }
                }
                $offset = $end;
                continue;
            }
            if ($character === '\\') {
                $next = CssSyntaxScanner::escapeEnd($source, $offset);
                if ($next === null) {
                    return null;
                }
                $offset = $next;
                continue;
            }
            $offset++;
        }
        return $quote === '' ? $masked : null;
    }

    private static function receipt(
        string $path,
        string $selector,
        string $foreground,
        string $background,
        string $deliveredBackground,
        ?float $ratioBefore,
        ?float $ratioAfter,
        string $disposition,
        string $reason,
    ): string {
        return sprintf(
            'file=%s selector=%s authored_fg=%s authored_bg=%s delivered_bg=%s ratio_before=%s ratio_after=%s disposition=%s reason=%s',
            self::safe($path),
            self::safe($selector),
            self::safe($foreground),
            self::safe($background),
            self::safe($deliveredBackground),
            $ratioBefore === null ? 'unresolved' : sprintf('%.4f', $ratioBefore),
            $ratioAfter === null ? 'unresolved' : sprintf('%.4f', $ratioAfter),
            self::safe($disposition),
            self::safe($reason),
        );
    }

    private static function safe(string $value): string
    {
        return preg_match('//u', $value) === 1 ? $value : mb_scrub($value, 'UTF-8');
    }
}
