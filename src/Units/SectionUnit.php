<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\ItemPattern;
use Automattic\SiteBuild\SectionComposition;
use Automattic\SiteBuild\Steps\PagePlanStep;

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
 *   layout_archetype/background/vertical_density/item_pattern/text_placement/handoff. The
 *   item pattern is null for non-list sections or one `ItemPattern` id. Role is required
 *   and must be one of hero/content/closing. The layout_archetype must be a
 *   `SectionComposition` id: it selects the one prompt fragment this request
 *   sees, and the `section-composition--<id>` root class the part delivers.
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
     *     layout_archetype:string,background:string,vertical_density:string,item_pattern:?string,text_placement:string,handoff:string
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
        $itemPattern = $this->itemPattern($input);
        $compositionVars = [];
        foreach (['layout_archetype', 'background', 'vertical_density', 'handoff'] as $field) {
            $compositionVars[$field] = $this->sectionString($section, $field);
            if (trim($compositionVars[$field]) === '') {
                throw new \RuntimeException("sections: page '{$pageSlug}' section '{$slug}' is missing {$field} from page-plan");
            }
        }
        $compositionVars['text_placement'] = strtolower(trim(
            $this->sectionString($section, 'text_placement'),
        ));
        if (!in_array($compositionVars['text_placement'], PagePlanStep::TEXT_PLACEMENTS, true)) {
            throw new \RuntimeException(
                "sections: page '{$pageSlug}' section '{$slug}' has invalid text_placement from page-plan",
            );
        }

        $archetype = $this->archetype($input);
        $composition = $this->renderer->render('section-composition.md', [
            'layout_archetype' => $compositionVars['layout_archetype'],
            'background'       => $compositionVars['background'],
            'vertical_density' => $compositionVars['vertical_density'],
            'text_placement'   => $compositionVars['text_placement'],
            'handoff'          => $compositionVars['handoff'],
            'neighbors'        => $this->inputString($input, 'neighbors'),
            'root_marker'      => SectionComposition::marker($archetype),
            // The catalog, not the model, decides whether this band pins its
            // lead region. A recipe that cannot pin renders an empty directive
            // and never reads a word about it.
            'composition_recipe' => $this->renderer->render(
                SectionComposition::recipeTemplate($archetype),
                SectionComposition::recipeVars($archetype, $itemPattern),
            ),
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
            'item_pattern_assignment' => $itemPattern === null
                ? 'ASSIGNED ITEM PATTERN: none — this section is not a repeated textual collection. Do not force its content into cards, ledger rows, an index, a specification table, or tag chips.'
                : $this->renderer->render('item-pattern.md', [
                    'item_pattern' => $itemPattern,
                    'root_marker' => ItemPattern::marker($itemPattern),
                    'item_pattern_recipe' => $this->renderer->render(
                        ItemPattern::recipeTemplate($itemPattern),
                        [],
                    ),
                ]),
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
        $archetype = $this->assignedArchetype($input);
        $itemPattern = $this->assignedItemPattern($input);
        $warnings = [];
        $repairs = [];
        $markup = GeneratedMarkup::normalize($raw, $this->key($input), $warnings, $repairs);
        if ($archetype !== null && self::hasOneGroupRoot($markup)) {
            $markup = GeneratedMarkup::withRootClassMarker(
                $markup,
                SectionComposition::MARKER_PREFIX,
                SectionComposition::marker($archetype),
                $this->key($input),
                $repairs
            );
        }
        if ($itemPattern !== null && self::hasOneGroupRoot($markup)) {
            $markup = GeneratedMarkup::withRootClassMarker(
                $markup,
                ItemPattern::MARKER_PREFIX,
                ItemPattern::marker($itemPattern),
                $this->key($input),
                $repairs,
            );
        }
        if (!self::ownsRuledSeparators($itemPattern, $archetype)) {
            $markup = GeneratedMarkup::stripSectionSeparators($markup, $this->key($input), $repairs, $warnings);
            $markup = GeneratedMarkup::stripRuleClassTokens($markup, $this->key($input), $repairs);
        }
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
        $section = $this->section($input);
        $band = BandSurfaceContract::enforce(
            $markup,
            $this->sectionString($section, 'background'),
            $this->key($input),
        );
        $markup = $band->markup;
        array_push($repairs, ...$band->repairs);
        array_push($warnings, ...$band->warnings);
        // Advisory only: the catalog reports a section that ignored its
        // assignment and the build delivers the safe parseable markup anyway.
        if ($archetype !== null) {
            array_push(
                $warnings,
                ...SectionComposition::markupWarnings(
                    $markup,
                    $archetype,
                    $this->key($input),
                    $itemPattern,
                ),
            );
        }
        if ($itemPattern !== null) {
            array_push(
                $warnings,
                ...ItemPattern::markupWarnings($markup, $itemPattern, $this->key($input)),
            );
        }
        return new MarkupResult($markup, $repairs, $warnings);
    }

    /**
     * Whether the assigned recipes draw their own rules, so the section keeps
     * its `wp:separator` blocks. Every other section is under the line ration
     * of prompts/section.md (BIGR-978).
     */
    private static function ownsRuledSeparators(?string $itemPattern, ?string $archetype): bool
    {
        return in_array($itemPattern, ['rule-row', 'spec-table'], true)
            || $archetype === 'list-with-thumbnails';
    }

    /**
     * The catalog archetype assigned to this section, for `request()`, which
     * cannot brief a recipe it does not have. A missing or unknown value is a
     * RuntimeException. Note this throw is NOT isolated per section:
     * `SectionsStep::requestsFor()` builds every request before the per-part
     * try/catch, so it ends the build. PagePlanStep coerces every delivered
     * plan value into the catalog before pages.json is written, so the
     * reachable case is a stale or hand-edited plan on a `--from=sections`
     * resume.
     */
    private function archetype(array $input): string
    {
        $section = $this->section($input);
        $archetype = trim($this->sectionString($section, 'layout_archetype'));
        if (!SectionComposition::isKnown($archetype)) {
            $slug = trim($this->sectionString($section, 'slug'));
            $pageSlug = trim($this->pageString($input, 'slug'));
            throw new \RuntimeException(
                "sections: page '{$pageSlug}' section '{$slug}' has unknown layout_archetype "
                . "'{$archetype}' — use one of: " . implode(', ', SectionComposition::ARCHETYPES)
            );
        }
        return $archetype;
    }

    /**
     * The same archetype for `finish()`, or null when the caller assigned
     * none. Delivery never throws over a plan field: `request()` is the
     * boundary that demands the assignment, and an adapter that finishes
     * markup it did not brief simply gets no marker and no catalog check.
     * Nothing is lost, so no warning is recorded (AGENTS.md rung 2).
     */
    private function assignedArchetype(array $input): ?string
    {
        $section = $this->section($input);
        $archetype = trim($this->sectionString($section, 'layout_archetype'));
        return SectionComposition::isKnown($archetype) ? $archetype : null;
    }

    /** The required planner value for request generation. */
    private function itemPattern(array $input): ?string
    {
        $section = $this->section($input);
        $raw = $section['item_pattern'] ?? null;
        if ($raw === null) {
            return null;
        }
        $pattern = is_string($raw) ? strtolower(trim($raw)) : '';
        if (!ItemPattern::isKnown($pattern)) {
            $slug = trim($this->sectionString($section, 'slug'));
            $pageSlug = trim($this->pageString($input, 'slug'));
            throw new \RuntimeException(
                "sections: page '{$pageSlug}' section '{$slug}' has unknown item_pattern — use null or one of: "
                . implode(', ', ItemPattern::ALL)
            );
        }
        return $pattern;
    }

    /** Delivery stays non-fatal when an adapter finishes unbriefed markup. */
    private function assignedItemPattern(array $input): ?string
    {
        $section = $this->section($input);
        return ItemPattern::explicit($section['item_pattern'] ?? null);
    }

    /**
     * Whether the delivered markup already is one complete top-level wp:group.
     *
     * `GeneratedMarkup::withRootClassMarker()` would otherwise WRAP a
     * multi-root or non-group part to create that root. That repair is a
     * deliberate non-goal here: `SectionsStep::assertOpeningRoot()` still owns
     * the root contract for a page opening, and wrapping would silently retire
     * its reviewed fallback. A part that fails this gate keeps its authored
     * bytes and draws the catalog's advisory root-marker warning instead.
     */
    private static function hasOneGroupRoot(string $markup): bool
    {
        $document = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => $document->parent($index) === null,
        ));
        return count($roots) === 1
            && $document->name($roots[0]) === 'group'
            && $document->endOffset($roots[0]) !== null;
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
