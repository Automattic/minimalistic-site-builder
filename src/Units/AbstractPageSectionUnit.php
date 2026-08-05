<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\SectionRole;

/**
 * Shared portable identity and input validation for ordinary sections and the
 * dedicated front-page hero unit.
 */
abstract class AbstractPageSectionUnit extends AbstractMarkupUnit
{
    /** Prefix for a page section part's request key and filename. */
    public const KEY_PREFIX = 'page-';

    /** The stable request key and part basename for one page section. */
    final public static function partKey(string $pageSlug, string $sectionSlug): string
    {
        return self::KEY_PREFIX . $pageSlug . '--' . $sectionSlug;
    }

    final public function key(array $input): string
    {
        $section = $this->section($input);
        $sectionSlug = trim($this->sectionString($section, 'slug'));
        if ($sectionSlug === '') {
            throw new \InvalidArgumentException("unit input 'section.slug' must be a non-empty string");
        }
        $pageSlug = trim($this->pageString($input, 'slug'));
        if ($pageSlug === '') {
            throw new \InvalidArgumentException("unit input 'page.slug' must be a non-empty string");
        }
        return self::partKey($pageSlug, $sectionSlug);
    }

    /** @return array<string,mixed> */
    final protected function section(array $input): array
    {
        if (!isset($input['section']) || !is_array($input['section'])) {
            throw new \InvalidArgumentException("unit input 'section' must be an array");
        }
        return $input['section'];
    }

    /** Require a string-valued page field when present. */
    final protected function pageString(array $input, string $key, string $default = ''): string
    {
        if (!isset($input['page']) || !is_array($input['page'])) {
            throw new \InvalidArgumentException("unit input 'page' must be an array");
        }
        if (!array_key_exists($key, $input['page'])) {
            return $default;
        }
        if (!is_string($input['page'][$key])) {
            throw new \InvalidArgumentException("unit input 'page.{$key}' must be a string");
        }
        return $input['page'][$key];
    }

    /** Require one boolean page field. */
    final protected function pageBool(array $input, string $key): bool
    {
        if (!isset($input['page']) || !is_array($input['page'])) {
            throw new \InvalidArgumentException("unit input 'page' must be an array");
        }
        if (!array_key_exists($key, $input['page']) || !is_bool($input['page'][$key])) {
            throw new \InvalidArgumentException("unit input 'page.{$key}' must be a boolean");
        }
        return $input['page'][$key];
    }

    /** Require a string-valued section field when present. */
    final protected function sectionString(array $section, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }
        if (!is_string($section[$key])) {
            throw new \InvalidArgumentException("unit input 'section.{$key}' must be a string");
        }
        return $section[$key];
    }

    /** Require a supported structural role for this section. */
    final protected function sectionRole(array $section): string
    {
        if (!array_key_exists('role', $section)) {
            throw new \InvalidArgumentException("unit input 'section.role' is required");
        }
        if (!is_string($section['role'])) {
            throw new \InvalidArgumentException("unit input 'section.role' must be a string");
        }

        $role = trim($section['role']);
        if (!in_array($role, SectionRole::ALL, true)) {
            throw new \InvalidArgumentException(
                "unit input 'section.role' must be one of: " . implode(', ', SectionRole::ALL)
            );
        }
        return $role;
    }
}
