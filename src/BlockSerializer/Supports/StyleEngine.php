<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Supports;

/** Ordered port of @wordpress/style-engine getCSSRules() for the frozen domain. */
final class StyleEngine
{
    /** @param array<string,mixed> $style @return array<string,string|int|float> camelCase CSS property => value */
    public function declarations(array $style): array
    {
        $rules = [];

        // The order is contractual: border, color, dimensions, outline,
        // spacing, typography, shadow, then background.
        $this->rule($rules, $style, ['border', 'color'], 'borderColor');
        $this->rule($rules, $style, ['border', 'style'], 'borderStyle');
        $this->rule($rules, $style, ['border', 'width'], 'borderWidth');
        $this->box($rules, $style, ['border', 'radius'], 'borderRadius', 'border%sRadius', [
            'topLeft', 'topRight', 'bottomLeft', 'bottomRight',
        ]);
        foreach (['top', 'right', 'bottom', 'left'] as $edge) {
            foreach (['color', 'style', 'width'] as $property) {
                $this->rule(
                    $rules,
                    $style,
                    ['border', $edge, $property],
                    'border' . ucfirst($edge) . ucfirst($property),
                );
            }
        }

        $this->rule($rules, $style, ['color', 'text'], 'color');
        $this->rule($rules, $style, ['color', 'gradient'], 'background');
        $this->rule($rules, $style, ['color', 'background'], 'backgroundColor');

        foreach (['height', 'minHeight', 'minWidth', 'aspectRatio', 'width', 'objectFit'] as $property) {
            $this->rule($rules, $style, ['dimensions', $property], $property);
        }
        foreach (['color', 'style', 'offset', 'width'] as $property) {
            $this->rule($rules, $style, ['outline', $property], 'outline' . ucfirst($property));
        }
        $this->box($rules, $style, ['spacing', 'margin'], 'margin', 'margin%s');
        $this->box($rules, $style, ['spacing', 'padding'], 'padding', 'padding%s');

        $typography = [
            'fontFamily', 'fontSize', 'fontStyle', 'fontWeight', 'letterSpacing',
            'lineHeight', 'textColumns', 'textDecoration', 'textIndent',
            'textShadow', 'textTransform', 'writingMode',
        ];
        foreach ($typography as $property) {
            $css = $property === 'textColumns' ? 'columnCount' : $property;
            $this->rule($rules, $style, ['typography', $property], $css);
        }
        $this->rule($rules, $style, ['shadow'], 'boxShadow');

        $backgroundImage = $this->get($style, ['background', 'backgroundImage']);
        $backgroundGradient = $this->get($style, ['background', 'gradient']);
        if ($this->truthy($backgroundImage) || $this->truthy($backgroundGradient)) {
            $parts = [];
            if ($this->truthy($backgroundGradient)) {
                $parts[] = $this->cssValue($backgroundGradient);
            }
            if (is_array($backgroundImage) && $this->truthy($backgroundImage['url'] ?? null)) {
                $parts[] = "url( '" . $this->encodeUri((string) $backgroundImage['url']) . "' )";
            } elseif ($this->truthy($backgroundImage)) {
                $parts[] = $this->cssValue($backgroundImage);
            }
            $rules['backgroundImage'] = implode(', ', $parts);
        }
        foreach (['backgroundPosition', 'backgroundRepeat', 'backgroundSize', 'backgroundAttachment'] as $property) {
            $this->rule($rules, $style, ['background', $property], $property);
        }

        return $rules;
    }

    public static function kebabCase(string $property): string
    {
        $property = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $property) ?? $property;
        $property = preg_replace('/([A-Za-z])([0-9])/', '$1-$2', $property) ?? $property;
        return strtolower($property);
    }

    /** @param array<string,string|int|float> $rules @param array<string,mixed> $style @param list<string> $path */
    private function rule(array &$rules, array $style, array $path, string $property): void
    {
        $value = $this->get($style, $path);
        if ($this->truthy($value)) {
            $rules[$property] = $this->cssValue($value);
        }
    }

    /**
     * @param array<string,string|int|float> $rules
     * @param array<string,mixed> $style
     * @param list<string> $path
     * @param list<string> $sides
     */
    private function box(
        array &$rules,
        array $style,
        array $path,
        string $defaultProperty,
        string $individualPattern,
        array $sides = ['top', 'right', 'bottom', 'left'],
    ): void {
        $box = $this->get($style, $path);
        if (!$this->truthy($box)) {
            return;
        }
        if (is_string($box)) {
            $rules[$defaultProperty] = $this->cssValue($box);
            return;
        }
        if (!is_array($box)) {
            return;
        }
        foreach ($sides as $side) {
            $value = $box[$side] ?? null;
            if ($this->truthy($value)) {
                $rules[str_replace('%s', ucfirst($side), $individualPattern)] = $this->cssValue($value);
            }
        }
    }

    /** @param array<string,mixed> $source @param list<string> $path */
    private function get(array $source, array $path): mixed
    {
        $value = $source;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === []);
    }

    private function cssValue(mixed $value): string|int|float
    {
        // Keep numbers typed until HtmlSerializer applies React's CSS-unit
        // rules and JavaScript's shortest-round-trip decimal spelling.
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;
        if (!str_starts_with($value, 'var:preset|')) {
            return $value;
        }
        $parts = explode('|', substr($value, strlen('var:preset|')));
        // Older attribute values used a double hyphen between the preset type
        // and slug. The pinned style engine normalizes that legacy separator
        // before it constructs the CSS custom-property name.
        if (count($parts) === 1) {
            $parts[0] = preg_replace('/--+/', '-', $parts[0]) ?? $parts[0];
        }
        $parts = array_map(static fn (string $part): string => self::kebabCase($part), $parts);
        return 'var(--wp--preset--' . implode('--', $parts) . ')';
    }

    private function encodeUri(string $uri): string
    {
        // encodeURI leaves URI syntax intact but escapes spaces and unsafe
        // bytes. rawurlencode each path fragment closely mirrors it here.
        return preg_replace_callback(
            '/[^A-Za-z0-9;,\/?:@&=+$\-_.!~*\'()#%]/u',
            static fn (array $match): string => rawurlencode($match[0]),
            $uri,
        ) ?? $uri;
    }
}
