<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Generate one page section from a self-contained input.
 *
 * Input shape:
 * - site_spec, theme_json, language, design_direction, outline, site_pages:
 *   prompt context (outline is the OWNING page's outline)
 * - card_style: normalized site-wide card construction enforced on delivery
 * - page: slug/title/path of the page the section belongs to
 * - section: slug/title/role/type/purpose/content_notes plus the assigned
 *   layout_archetype/background/vertical_density/handoff. Role is required
 *   and must be one of hero/content/closing.
 * - neighbors: the preceding/following composition summary
 * - header_contract: the header-mode contract for hero-role sections (how the
 *   site header shares the first viewport with this section); '' otherwise
 *
 * Static authoring rules come from the package's prompt templates; no Project
 * or project artifact is read or written here.
 */
final class SectionUnit extends AbstractPageSectionUnit
{
    private const BUILD_LAYER_MARKER = '<!-- section-cache-layer:build -->';
    private const PAGE_LAYER_MARKER = '<!-- section-cache-layer:page -->';
    private const BRIEF_LAYER_MARKER = '<!-- section-cache-layer:brief -->';

    /**
     * @param array{
     *   site_spec:string|array<mixed>,
     *   language:string,
     *   theme_json:string|array<mixed>,
     *   design_direction:string,
     *   card_style?:string,
     *   outline:string,
     *   site_pages:string,
     *   page:array{slug:string,title?:string,path?:string},
     *   section:array{
     *     slug:string,role:string,title?:string,type?:string,purpose?:string,content_notes?:string,
     *     layout_archetype:string,background:string,vertical_density:string,handoff:string
     *   },
     *   neighbors:string,
     *   header_contract:string
     * } $input
     */
    public function request(array $input): array
    {
        // Validate the machine-readable execution input before spending an LLM
        // call. Missing means flush for standalone callers whose persisted
        // design direction predates the field; SectionsStep always supplies it.
        $cardStyle = $this->cardStyle($input);
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

        $request = $this->renderedRequest('section.md', $this->commonVars($input) + [
            'site_pages'        => $this->inputString($input, 'site_pages'),
            'card_style'        => $cardStyle,
            'page_title'        => $this->pageString($input, 'title'),
            'page_path'         => $this->pageString($input, 'path', '/'),
            'section_title'     => $this->sectionString($section, 'title'),
            'section_slug'      => $slug,
            'section_role'      => $role,
            'section_type'      => $this->sectionString($section, 'type', 'content'),
            'section_purpose'   => $this->sectionString($section, 'purpose'),
            'content_notes'     => $this->sectionString($section, 'content_notes'),
            'composition'       => $composition,
            'header_contract'   => $this->inputString($input, 'header_contract'),
            'image_instructions' => $this->renderer->render('image-generation.md', []),
        ]);

        [$buildLayer, $pageLayer, $brief] = self::cacheLayers($request['prompt']);
        $request['cached_prefixes'] = [$buildLayer, $pageLayer];
        $request['prompt'] = $brief;
        return $request;
    }

    public function finish(string $raw, array $input): MarkupResult
    {
        $cardStyle = $this->cardStyle($input);
        $warnings = [];
        $repairs = [];
        $markup = GeneratedMarkup::normalize($raw, $this->key($input), $warnings, $repairs);
        $contract = CardStyleContract::enforce($markup, $cardStyle, $this->key($input));
        $markup = $contract['markup'];
        array_push($repairs, ...$contract['repairs']);
        array_push($warnings, ...$contract['warnings']);
        return new MarkupResult($markup, $repairs, $warnings);
    }

    private function cardStyle(array $input): string
    {
        $style = $input['card_style'] ?? 'flush';
        if (!is_string($style) || !in_array($style, CardStyleContract::STYLES, true)) {
            throw new \InvalidArgumentException(
                "unit input 'card_style' must be one of: " . implode(', ', CardStyleContract::STYLES),
            );
        }
        return $style;
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
