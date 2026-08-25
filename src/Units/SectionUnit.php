<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Generate one page section from a self-contained input.
 *
 * Input shape:
 * - site_spec, theme_json, language, design_direction, outline, site_pages:
 *   prompt context (outline is the OWNING page's outline)
 * - card_style: normalized site-wide card construction enforced on delivery;
 *   list-thumb rows also receive their non-stacking and tight-gap invariants
 * - page: slug/title/path of the page the section belongs to
 * - section: slug/title/role/type/purpose/content_notes plus the assigned
 *   layout_archetype/background/vertical_density/handoff. Role is required
 *   and must be one of hero/content/closing.
 * - neighbors: the preceding/following composition summary
 * - header_contract: the header-mode contract for hero-role sections (how the
 *   site header shares the first viewport with this section); '' otherwise
 * - form_placeholders: true when the host owns a form backend and wants the
 *   section to reserve a form's place with a JP_FORM placeholder block instead
 *   of the default no-form-markup rule; absent/false keeps the default
 *
 * Static authoring rules come from the package's prompt templates; no Project
 * or project artifact is read or written here.
 */
final class SectionUnit extends AbstractPageSectionUnit
{
    private const BUILD_LAYER_MARKER = '<!-- cache-layer:build -->';
    private const PAGE_LAYER_MARKER = '<!-- cache-layer:page -->';
    private const BRIEF_LAYER_MARKER = '<!-- cache-layer:brief -->';

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
     *   header_contract:string,
     *   form_placeholders?:bool
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
            'form_instructions'  => $this->renderer->render(
                ($input['form_placeholders'] ?? false) ? 'jetpack-form.md' : 'no-forms.md',
                [],
            ),
        ]);

        // The site layer is byte-identical to the one the header, footer and
        // hero open with, so one warm-up primes the context for every markup
        // call in the batch instead of only the sections (BIGR-851).
        [$siteLayer, $buildLayer, $pageLayer, $brief] = self::cacheLayers($request['prompt'], [
            self::SITE_LAYER_MARKER,
            self::BUILD_LAYER_MARKER,
            self::PAGE_LAYER_MARKER,
            self::BRIEF_LAYER_MARKER,
        ]);
        $request['cached_prefixes'] = [$siteLayer, $buildLayer, $pageLayer];
        $request['prompt'] = $brief;
        return $request;
    }

    public function finish(string $raw, array $input): MarkupResult
    {
        $cardStyle = $this->cardStyle($input);
        $warnings = [];
        $repairs = [];
        $markup = GeneratedMarkup::normalize($raw, $this->key($input), $warnings, $repairs);
        $listThumb = ListThumbContract::enforce($markup, $this->key($input));
        $markup = $listThumb['markup'];
        array_push($repairs, ...$listThumb['repairs']);
        array_push($warnings, ...$listThumb['warnings']);
        $contract = CardStyleContract::enforce(
            $markup,
            $cardStyle,
            $this->key($input),
            themeJson: $input['theme_json'] ?? null,
        );
        $markup = $contract['markup'];
        array_push($repairs, ...$contract['repairs']);
        array_push($warnings, ...$contract['warnings']);
        $markup = $this->scrubUngroundedContact($markup, $input, $this->key($input), $warnings);
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
}
