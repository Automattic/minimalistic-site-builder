<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssSelectorMatcher;
use DOMDocument;
use DOMElement;

/**
 * Islands facts adapter for AboveFoldContract::finalizeDelivery().
 *
 * Reuses AboveFoldPartFacts::inspect() for part_keys, header, and primary-action
 * delivery (anchorsIn already scans raw id attributes). Replaces the five
 * facts that assume a top-level wp:group: opening overlay support, opening
 * surfaces, and hero envelope.
 */
final class IslandPartFacts
{
    private const VAR_DEPTH = 8;

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,string> $parts
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    public static function inspect(array $pages, array $parts, array $contract, string $css): array
    {
        $facts = AboveFoldPartFacts::inspect($pages, $parts, $contract);
        $protectionHex = is_string($contract['theme_tokens'][$contract['header']['protection_token'] ?? '']['hex'] ?? null)
            ? (string) $contract['theme_tokens'][$contract['header']['protection_token']]['hex']
            : null;
        $protectionToken = (string) ($contract['header']['protection_token'] ?? 'contrast');

        $support = [];
        $surfaces = [];
        foreach ((array) ($contract['openings'] ?? []) as $opening) {
            if (!is_array($opening)) {
                continue;
            }
            $part = (string) ($opening['part'] ?? '');
            $markup = is_string($parts[$part] ?? null) ? (string) $parts[$part] : '';
            $paint = self::openingPaint($markup, $css);
            $surfaces[$part] = $paint['surface'] !== '' ? $paint['surface'] : $protectionToken;
            $support[$part] = $markup !== '' && self::paintProtects($paint, $protectionHex);
        }

        $heroPart = (string) ($contract['hero_part'] ?? '');
        $heroMarkup = is_string($parts[$heroPart] ?? null) ? (string) $parts[$heroPart] : '';

        $facts['opening_overlay_support'] = $support;
        $facts['opening_surfaces'] = $surfaces;
        $facts['hero'] = self::heroFacts($heroMarkup, (string) ($contract['recipe'] ?? ''));
        return $facts;
    }

    /**
     * CSS paint on an opening: island fragment or a full design document.
     *
     * @return array{
     *     background:?string,
     *     resolved:?string,
     *     kind:string,
     *     via_var:bool,
     *     scrim:bool,
     *     surface:string,
     *     has_img:bool
     * }
     */
    public static function openingPaint(string $markup, string $css): array
    {
        $empty = [
            'background' => null,
            'resolved' => null,
            'kind' => 'none',
            'via_var' => false,
            'scrim' => false,
            'surface' => '',
            'has_img' => false,
        ];
        $fragment = self::fragmentHtml($markup);
        $dom = Html::loadUtf8Html($fragment, LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$dom instanceof DOMDocument) {
            return $empty;
        }
        $opening = self::openingElement($dom);
        if (!$opening instanceof DOMElement) {
            return $empty;
        }
        $vars = self::customProperties($css);
        $matched = self::matchedDeclarations($opening, $css);
        $scrim = $matched['scrim'];
        $authored = $matched['background'];
        $viaVar = $matched['via_var'];
        if ($authored === null) {
            $walk = $opening->getElementsByTagName('*');
            for ($i = 0; $i < $walk->length; $i++) {
                $child = $walk->item($i);
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $childMatched = self::matchedDeclarations($child, $css);
                if ($childMatched['background'] !== null) {
                    $authored = $childMatched['background'];
                    $viaVar = $childMatched['via_var'];
                    break;
                }
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($authored === null && $body instanceof DOMElement && $body !== $opening) {
            $bodyMatched = self::matchedDeclarations($body, $css);
            $authored = $bodyMatched['background'];
            $viaVar = $bodyMatched['via_var'];
        }
        $hasImg = $opening->getElementsByTagName('img')->length > 0;
        if ($authored === null || trim($authored) === '') {
            return [
                'background' => null,
                'resolved' => null,
                'kind' => 'none',
                'via_var' => false,
                'scrim' => $scrim,
                'surface' => $hasImg ? 'image' : '',
                'has_img' => $hasImg,
            ];
        }
        $resolved = self::resolveVars($authored, $vars);
        $kind = self::classify($resolved);
        $surface = ($hasImg || self::isImagePaint($resolved)) ? 'image' : '';
        return [
            'background' => $authored,
            'resolved' => $resolved,
            'kind' => $kind,
            'via_var' => $viaVar || str_contains(strtolower($authored), 'var('),
            'scrim' => $scrim,
            'surface' => $surface,
            'has_img' => $hasImg,
        ];
    }

    /**
     * @return array{background:?string,via_var:bool,scrim:bool}
     */
    private static function matchedDeclarations(DOMElement $element, string $css): array
    {
        $background = null;
        $viaVar = false;
        $scrim = false;
        foreach (self::rules($css) as $rule) {
            foreach (self::splitSelectors($rule['selector']) as $selector) {
                $isPseudo = (bool) preg_match('/::(?:before|after)\s*$/i', $selector);
                if (!self::selectorMatches($element, $selector)) {
                    continue;
                }
                foreach (self::declarations($rule['body']) as [$property, $value]) {
                    if ($value === '') {
                        continue;
                    }
                    if ($isPseudo && in_array($property, ['background', 'background-color', 'background-image'], true)
                        && strtolower($value) !== 'none'
                    ) {
                        $scrim = true;
                    }
                    if ($isPseudo) {
                        continue;
                    }
                    if (in_array($property, ['background', 'background-color'], true)) {
                        $background = $value;
                        $viaVar = str_contains(strtolower($value), 'var(');
                    } elseif ($property === 'background-image' && $background === null) {
                        $background = $value;
                        $viaVar = str_contains(strtolower($value), 'var(');
                    }
                }
            }
        }
        return ['background' => $background, 'via_var' => $viaVar, 'scrim' => $scrim];
    }

    private static function selectorMatches(DOMElement $element, string $selector): bool
    {
        $core = $selector;
        if (preg_match('/^(.*?)(::(?:before|after))\s*$/i', $selector, $match) === 1) {
            $core = trim($match[1]);
            if ($core === '') {
                return false;
            }
        }
        $parsed = CssSelectorMatcher::parse($core);
        $result = CssSelectorMatcher::matches($element, $parsed, true);
        return ($result['supported'] ?? false) === true && ($result['matches'] ?? false) === true;
    }

    /** @return list<array{selector:string,body:string}> */
    private static function rules(string $css): array
    {
        $css = self::stripComments($css);
        return self::rulesFrom($css);
    }

    /** @return list<array{selector:string,body:string}> */
    private static function rulesFrom(string $css): array
    {
        $rules = [];
        $length = strlen($css);
        $i = 0;
        $depth = 0;
        $selStart = 0;
        $bodyStart = 0;
        $selector = '';
        while ($i < $length) {
            $char = $css[$i];
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $i++;
                while ($i < $length && $css[$i] !== $quote) {
                    if ($css[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($char === '{') {
                if ($depth === 0) {
                    $selector = trim(substr($css, $selStart, $i - $selStart));
                    $bodyStart = $i + 1;
                }
                $depth++;
                $i++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($css, $bodyStart, $i - $bodyStart);
                    if ($selector !== '') {
                        if (str_starts_with($selector, '@')) {
                            array_push($rules, ...self::rulesFrom($body));
                        } else {
                            $rules[] = ['selector' => $selector, 'body' => $body];
                        }
                    }
                    $selStart = $i + 1;
                }
                $i++;
                continue;
            }
            $i++;
        }
        return $rules;
    }

    /** @return list<string> */
    private static function splitSelectors(string $selector): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($selector);
        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }
        return $parts;
    }

    /** @return list<array{0:string,1:string}> */
    private static function declarations(string $body): array
    {
        $out = [];
        foreach (explode(';', $body) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = explode(':', $declaration, 2);
            $out[] = [strtolower(trim($property)), trim($value)];
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function customProperties(string $css): array
    {
        $map = [];
        foreach (self::rules($css) as $rule) {
            foreach (self::declarations($rule['body']) as [$property, $value]) {
                if (str_starts_with($property, '--') && $value !== '') {
                    $map[$property] = $value;
                }
            }
        }
        return $map;
    }

    public static function resolveVars(string $value, array $map, int $depth = 0): string
    {
        if ($depth >= self::VAR_DEPTH) {
            return $value;
        }
        $replaced = preg_replace_callback(
            '/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*((?:[^()]+|\([^()]*\))*))?\s*\)/i',
            static function (array $match) use ($map, $depth): string {
                $name = $match[1];
                if (isset($map[$name])) {
                    return self::resolveVars($map[$name], $map, $depth + 1);
                }
                if (isset($match[2]) && trim($match[2]) !== '') {
                    return self::resolveVars($match[2], $map, $depth + 1);
                }
                return $match[0];
            },
            $value,
        );
        return is_string($replaced) ? $replaced : $value;
    }

    private static function classify(string $resolved): string
    {
        $low = strtolower(trim($resolved));
        if ($low === '' || $low === 'none') {
            return 'none';
        }
        if (str_contains($low, 'gradient')) {
            return 'gradient';
        }
        if (preg_match('/\btransparent\b/', $low) === 1 || $low === 'rgba(0, 0, 0, 0)' || $low === 'rgba(0,0,0,0)') {
            return 'transparent';
        }
        return 'flat';
    }

    private static function isImagePaint(string $resolved): bool
    {
        $low = strtolower($resolved);
        return str_contains($low, 'url(') && !str_contains($low, 'gradient');
    }

    /**
     * @param array{resolved:?string,kind:string} $paint
     */
    private static function paintProtects(array $paint, ?string $protectionHex): bool
    {
        if ($protectionHex === null || ($paint['resolved'] ?? null) === null) {
            return false;
        }
        $resolved = (string) $paint['resolved'];
        $token = ContrastMath::hexToRgb($protectionHex);
        $actual = self::colorRgb($resolved);
        return $token !== null && $actual !== null && $token === $actual;
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function colorRgb(string $value): ?array
    {
        $value = trim($value);
        $hex = ContrastMath::hexToRgb($value);
        if ($hex !== null) {
            return $hex;
        }
        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $value, $match) === 1) {
            return [(int) $match[1], (int) $match[2], (int) $match[3]];
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function heroFacts(string $markup, string $recipe): array
    {
        if (trim($markup) === '') {
            return ['present' => false, 'root_group' => false, 'recipe_marker' => false];
        }
        $fragment = self::fragmentHtml($markup);
        $hasRecipe = $recipe !== '' && (
            str_contains($markup, 'hero-composition--' . $recipe)
            || str_contains($fragment, 'hero-composition--' . $recipe)
        );
        return [
            'present' => true,
            // An island's top-level block is wp:html, not wp:group. Reporting
            // that as a missing root group is a non-actionable warning per
            // build (AGENTS.md rung 2). A well-formed island is the envelope.
            'root_group' => true,
            'recipe_marker' => $hasRecipe,
            'rhythm_degraded_image' => str_contains($markup, 'site-build-section-rhythm-degraded-image'),
        ];
    }

    public static function fragmentHtml(string $markup): string
    {
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        if ($root !== null && $document->name($root) === 'html') {
            return $document->innerHtml($root);
        }
        return $markup;
    }

    private static function openingElement(DOMDocument $dom): ?DOMElement
    {
        $mains = $dom->getElementsByTagName('main');
        $root = $mains->length > 0 ? $mains->item(0) : null;
        if (!$root instanceof DOMElement) {
            $bodies = $dom->getElementsByTagName('body');
            $root = $bodies->length > 0 ? $bodies->item(0) : $dom->documentElement;
        }
        if (!$root instanceof DOMElement) {
            return null;
        }
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['header', 'footer', 'style', 'script'], true)) {
                continue;
            }
            if ($tag === 'section') {
                return $child;
            }
            foreach ($child->childNodes as $grandchild) {
                if ($grandchild instanceof DOMElement && strtolower($grandchild->tagName) === 'section') {
                    return $grandchild;
                }
            }
            return $child;
        }
        return $root;
    }

    private static function stripComments(string $css): string
    {
        $out = '';
        $length = strlen($css);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $css[++$i];
                } elseif ($char === $quote || $char === "\n" || $char === "\r") {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $out .= $char;
                continue;
            }
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                continue;
            }
            $out .= $char;
        }
        return $out;
    }
}
