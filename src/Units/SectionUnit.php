<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Generate one page section from a self-contained input.
 *
 * Input shape:
 * - site_spec, theme_json, language, design_direction, outline, site_pages:
 *   prompt context (outline is the OWNING page's outline)
 * - page: slug/title/path of the page the section belongs to
 * - section: slug/title/type/purpose/content_notes plus the assigned
 *   layout_archetype/background/vertical_density/handoff
 * - neighbors: the preceding/following composition summary
 *
 * Static authoring rules come from the package's prompt templates; no Project
 * or project artifact is read or written here.
 */
final class SectionUnit extends AbstractMarkupUnit
{
    private const BUILD_LAYER_MARKER = '<!-- section-cache-layer:build -->';
    private const PAGE_LAYER_MARKER = '<!-- section-cache-layer:page -->';
    private const BRIEF_LAYER_MARKER = '<!-- section-cache-layer:brief -->';

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
     *     slug:string,title?:string,type?:string,purpose?:string,content_notes?:string,
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

        $request = $this->renderedRequest('section.md', $this->commonVars($input) + [
            'site_pages'        => $this->inputString($input, 'site_pages'),
            'page_title'        => $this->pageString($input, 'title'),
            'page_path'         => $this->pageString($input, 'path', '/'),
            'section_title'     => $this->sectionString($section, 'title'),
            'section_slug'      => $slug,
            'section_type'      => $this->sectionString($section, 'type', 'content'),
            'section_purpose'   => $this->sectionString($section, 'purpose'),
            'content_notes'     => $this->sectionString($section, 'content_notes'),
            'composition'       => $composition,
            'image_instructions' => $this->renderer->render('image-generation.md', []),
        ]);

        [$buildLayer, $pageLayer, $brief] = self::cacheLayers($request['prompt']);
        $request['cached_prefixes'] = [$buildLayer, $pageLayer];
        $request['prompt'] = $brief;
        return $request;
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

    /**
     * Split the rendered section template at its frozen cache-layer markers.
     * Cached build/page prefixes are returned with trailing newlines removed
     * and exactly "\n\n" appended; the varying brief is newline-trimmed and
     * unsuffixed. The explicit prefix separators are part of the wire contract,
     * so adjacent Anthropic blocks and OpenAI-compatible text assemble equally.
     *
     * @return array{0:string,1:string,2:string} build layer, page layer, brief
     */
    private static function cacheLayers(string $rendered): array
    {
        foreach ([self::BUILD_LAYER_MARKER, self::PAGE_LAYER_MARKER, self::BRIEF_LAYER_MARKER] as $marker) {
            if (substr_count($rendered, $marker) !== 1) {
                throw new \RuntimeException("section prompt must contain exactly one {$marker} marker");
            }
        }
        $buildPos = strpos($rendered, self::BUILD_LAYER_MARKER);
        $pagePos = strpos($rendered, self::PAGE_LAYER_MARKER);
        $briefPos = strpos($rendered, self::BRIEF_LAYER_MARKER);
        if (!is_int($buildPos) || !is_int($pagePos) || !is_int($briefPos)
            || !($buildPos < $pagePos && $pagePos < $briefPos)) {
            throw new \RuntimeException('section prompt cache layer markers are out of order');
        }

        [$beforeBuild, $afterBuild] = explode(self::BUILD_LAYER_MARKER, $rendered, 2);
        [$buildLayer, $afterPage] = explode(self::PAGE_LAYER_MARKER, $afterBuild, 2);
        [$pageLayer, $brief] = explode(self::BRIEF_LAYER_MARKER, $afterPage, 2);

        if (trim($beforeBuild, "\r\n") !== '') {
            throw new \RuntimeException('section prompt has content before the build cache layer');
        }

        // Remove only newlines belonging to the marker separators. Preserve
        // every other byte of each rendered layer, including indentation.
        $buildLayer = rtrim(ltrim($buildLayer, "\r\n"), "\r\n");
        $pageLayer = rtrim(ltrim($pageLayer, "\r\n"), "\r\n");
        $brief = trim($brief, "\r\n");
        if (in_array('', [$buildLayer, $pageLayer, $brief], true)) {
            throw new \RuntimeException('section prompt cache layers must not be empty');
        }
        return [$buildLayer . "\n\n", $pageLayer . "\n\n", $brief];
    }
}
