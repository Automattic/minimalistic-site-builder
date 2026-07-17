<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Attributes;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Repair;

/** Gutenberg's three byte-affecting built-in validation repairs. */
final class CompatibilityRepairs
{
    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $supports
     * @param callable(array<string,mixed>):string $render
     * @return array{attributes:array<string,mixed>,repairs:list<Repair>}
     */
    public function apply(
        array $attributes,
        array $supports,
        string $originalContent,
        string $blockPath,
        callable $render,
    ): array {
        $updated = $attributes;
        $repairs = [];

        // A wrapperless container starts with serialized inner blocks. Their
        // first element is not this block's root and must never be mistaken
        // for a custom class/anchor/ARIA recovery candidate.
        if (trim($originalContent) === '' || preg_match('/^\s*<!--\s+wp:/', $originalContent) === 1) {
            return ['attributes' => $updated, 'repairs' => $repairs];
        }

        if (($supports['customClassName'] ?? true) !== false) {
            $withoutClass = $updated;
            unset($withoutClass['className']);
            $defaultClasses = $this->rootClasses($render($withoutClass));
            $actualClasses = $this->rootClasses($originalContent);
            $custom = array_values(array_filter(
                $actualClasses,
                static fn (string $class): bool => !in_array($class, $defaultClasses, true),
            ));
            $value = $custom === [] ? null : implode(' ', $custom);
            $old = $updated['className'] ?? null;
            if ($value === null) {
                unset($updated['className']);
            } else {
                $updated['className'] = $value;
            }
            if ($old !== $value) {
                $repairs[] = new Repair('custom-class-recovery', $blockPath);
            }
        }

        if (($supports['anchor'] ?? false) !== false) {
            $value = $this->rootAttribute($originalContent, 'id');
            if ($value !== null && $value !== '' && ($updated['anchor'] ?? null) !== $value) {
                $updated['anchor'] = $value;
                $repairs[] = new Repair('anchor-recovery', $blockPath);
            }
        }

        if (($supports['ariaLabel'] ?? false) !== false) {
            $value = $this->rootAttribute($originalContent, 'aria-label');
            if ($value !== null && $value !== '' && ($updated['ariaLabel'] ?? null) !== $value) {
                $updated['ariaLabel'] = $value;
                $repairs[] = new Repair('aria-label-recovery', $blockPath);
            }
        }

        return ['attributes' => $updated, 'repairs' => $repairs];
    }

    /** @return list<string> */
    private function rootClasses(string $html): array
    {
        $class = $this->rootAttribute($html, 'class') ?? '';
        return preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function rootAttribute(string $html, string $attribute): ?string
    {
        $root = HtmlFragment::parse($html)->root()->elementChildren()[0] ?? null;
        return $root?->attribute($attribute);
    }
}
