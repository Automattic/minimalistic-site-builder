<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Generate one landing-page section from a self-contained input.
 *
 * Input shape:
 * - site_spec, theme_json, language, design_direction, outline: prompt context
 * - section: slug/title/type/purpose/content_notes plus the assigned
 *   layout_archetype/background/vertical_density/handoff
 * - neighbors: the preceding/following composition summary
 *
 * Static authoring rules come from the package's prompt templates; no Project
 * or project artifact is read or written here.
 */
final class SectionUnit extends AbstractMarkupUnit
{
    public const KEY_PREFIX = 'section-';

    public function key(array $input): string
    {
        $section = $this->section($input);
        $slug = trim($this->sectionString($section, 'slug'));
        if ($slug === '') {
            throw new \InvalidArgumentException("unit input 'section.slug' must be a non-empty string");
        }
        return self::KEY_PREFIX . $slug;
    }

    /**
     * @param array{
     *   site_spec:string|array<mixed>,
     *   language:string,
     *   theme_json:string|array<mixed>,
     *   design_direction:string,
     *   outline:string,
     *   section:array{
     *     slug:string,title?:string,type?:string,purpose?:string,content_notes?:string,
     *     layout_archetype:string,background:string,vertical_density:string,handoff:string
     *   },
     *   neighbors:string
     * } $input
     */
    public function request(array $input): array
    {
        $section = $this->section($input);
        $key = $this->key($input);
        $slug = substr($key, strlen(self::KEY_PREFIX));
        $compositionVars = [];
        foreach (['layout_archetype', 'background', 'vertical_density', 'handoff'] as $field) {
            $compositionVars[$field] = $this->sectionString($section, $field);
            if (trim($compositionVars[$field]) === '') {
                throw new \RuntimeException("sections: section '{$slug}' is missing {$field} from section-plan");
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
            'section_title'     => $this->sectionString($section, 'title'),
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
}
