<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\SectionRole;

/**
 * Generate one page section from a self-contained input.
 *
 * Input shape:
 * - site_spec, theme_json, language, design_direction, outline, site_pages:
 *   prompt context (outline is the OWNING page's outline)
 * - page: slug/title/path of the page the section belongs to
 * - section: slug/title/role/type/purpose/content_notes plus the assigned
 *   layout_archetype/background/vertical_density/handoff. Role is required
 *   and must be one of hero/content/closing.
 * - neighbors: the preceding/following composition summary
 *
 * Static authoring rules come from the package's prompt templates; no Project
 * or project artifact is read or written here.
 */
final class SectionUnit extends AbstractMarkupUnit
{
    /** Prefix for a page section part's request key and filename. */
    public const KEY_PREFIX = 'page-';

    /** The part key (request key and file basename) for one page's section. */
    public static function partKey(string $pageSlug, string $sectionSlug): string
    {
        return self::KEY_PREFIX . $pageSlug . '--' . $sectionSlug;
    }

    public function key(array $input): string
    {
        $section = $this->section($input);
        $slug = trim($this->sectionString($section, 'slug'));
        if ($slug === '') {
            throw new \InvalidArgumentException("unit input 'section.slug' must be a non-empty string");
        }
        $pageSlug = trim($this->pageString($input, 'slug'));
        if ($pageSlug === '') {
            throw new \InvalidArgumentException("unit input 'page.slug' must be a non-empty string");
        }
        return self::partKey($pageSlug, $slug);
    }

    /**
     * @param array{
     *   site_spec:string|array<mixed>,
     *   language:string,
     *   theme_json:string|array<mixed>,
     *   design_direction:string,
     *   outline:string,
     *   site_pages:string,
     *   page:array{slug:string,title?:string,path?:string},
     *   section:array{
     *     slug:string,role:string,title?:string,type?:string,purpose?:string,content_notes?:string,
     *     layout_archetype:string,background:string,vertical_density:string,handoff:string
     *   },
     *   neighbors:string
     * } $input
     */
    public function request(array $input): array
    {
        $section = $this->section($input);
        $slug = trim($this->sectionString($section, 'slug'));
        $pageSlug = trim($this->pageString($input, 'slug'));
        $role = $this->sectionRole($section);
        $compositionVars = [];
        foreach (['layout_archetype', 'background', 'vertical_density', 'handoff'] as $field) {
            $compositionVars[$field] = $this->sectionString($section, $field);
            if (trim($compositionVars[$field]) === '') {
                throw new \RuntimeException("sections: page '{$pageSlug}' section '{$slug}' is missing {$field} from page-plan");
            }
        }

        $composition = $this->renderer->render('section-composition.md', [
            'layout_archetype' => $compositionVars['layout_archetype'],
            'background'       => $compositionVars['background'],
            'vertical_density' => $compositionVars['vertical_density'],
            'handoff'          => $compositionVars['handoff'],
            'neighbors'        => $this->inputString($input, 'neighbors'),
        ]);

        return $this->renderedRequest('section.md', $this->commonVars($input) + [
            'site_pages'        => $this->inputString($input, 'site_pages'),
            'page_title'        => $this->pageString($input, 'title'),
            'page_path'         => $this->pageString($input, 'path', '/'),
            'section_title'     => $this->sectionString($section, 'title'),
            'section_slug'      => $slug,
            'section_role'      => $role,
            'section_type'      => $this->sectionString($section, 'type', 'content'),
            'section_purpose'   => $this->sectionString($section, 'purpose'),
            'content_notes'     => $this->sectionString($section, 'content_notes'),
            'composition'       => $composition,
            'image_instructions' => $this->renderer->render('image-generation.md', []),
        ]);
    }

    public function finish(string $raw, array $input): string
    {
        return GeneratedMarkup::normalize($raw, $this->key($input));
    }

    /** @return array<string,mixed> */
    private function section(array $input): array
    {
        if (!isset($input['section']) || !is_array($input['section'])) {
            throw new \InvalidArgumentException("unit input 'section' must be an array");
        }
        return $input['section'];
    }

    /** Require a string-valued page field when present. */
    private function pageString(array $input, string $key, string $default = ''): string
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

    /** Require a string-valued section field when present. */
    private function sectionString(array $section, string $key, string $default = ''): string
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
    private function sectionRole(array $section): string
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
