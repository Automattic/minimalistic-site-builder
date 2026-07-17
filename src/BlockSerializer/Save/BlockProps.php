<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Save;

/** Ordered React-style props for one save-tree element. */
final class BlockProps
{
    /** @param array<string,mixed> $props */
    public function __construct(private array $props = [])
    {
    }

    public function prependClass(string $classes): void
    {
        if (trim($classes) === '') {
            return;
        }
        $existing = isset($this->props['className']) ? trim((string) $this->props['className']) : '';
        $this->props['className'] = trim($classes . ($existing !== '' ? ' ' . $existing : ''));
    }

    public function appendClass(string $classes): void
    {
        if (trim($classes) === '') {
            return;
        }
        $existing = isset($this->props['className']) ? trim((string) $this->props['className']) : '';
        $this->props['className'] = trim(($existing !== '' ? $existing . ' ' : '') . $classes);
    }

    /** Generated-class hook prepends and de-duplicates every token. */
    public function generatedClass(string $class): void
    {
        $tokens = preg_split('/\s+/', trim($class . ' ' . (string) ($this->props['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->props['className'] = implode(' ', array_keys(array_fill_keys($tokens, true)));
    }

    /** Final Gutenberg cleanup filter de-duplicates even without a generated class. */
    public function deduplicateClasses(): void
    {
        if (!array_key_exists('className', $this->props)) {
            return;
        }
        $tokens = preg_split(
            '/\s+/',
            trim((string) $this->props['className']),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $this->props['className'] = implode(' ', array_keys(array_fill_keys($tokens, true)));
    }

    public function set(string $name, mixed $value): void
    {
        $this->props[$name] = $value;
    }

    /** @param array<string,string|int|float> $styles Existing renderer styles win, matching {...support, ...props.style}. */
    public function prependStyles(array $styles): void
    {
        $existing = is_array($this->props['style'] ?? null) ? $this->props['style'] : [];
        $this->props['style'] = array_replace($styles, $existing);
    }

    /** @param array<string,string|int|float|null> $styles */
    public function mergeStyles(array $styles): void
    {
        $existing = is_array($this->props['style'] ?? null) ? $this->props['style'] : [];
        $this->props['style'] = array_replace($existing, $styles);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        $props = $this->props;
        if (array_key_exists('className', $props) && !$props['className']) {
            unset($props['className']);
        }
        if (isset($props['style']) && is_array($props['style'])) {
            $props['style'] = array_filter($props['style'], static fn (mixed $value): bool => $value !== null && $value !== '');
            if ($props['style'] === []) {
                unset($props['style']);
            }
        }
        // PHP arrays retain the same insertion-order contract as JavaScript
        // object spreads here. In particular a generated class introduced by
        // the final filter must remain after pre-existing style/ARIA props.
        return $props;
    }
}
