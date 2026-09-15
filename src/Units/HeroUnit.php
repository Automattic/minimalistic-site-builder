<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\AboveFoldContract;
use Automattic\SiteBuild\HeroBlueprint;
use Automattic\SiteBuild\HeroCopyBudget;
use Automattic\SiteBuild\HeroComposition;
use Automattic\SiteBuild\HeroContentCompiler;
use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\PromptRenderer;
use Automattic\SiteBuild\SectionContent;

/**
 * Generate the front page's first section from one assigned hero recipe and
 * the canonical above-fold contract. Reads and writes no Project state.
 */
final class HeroUnit extends AbstractPageSectionUnit
{
    public const MARKER_PREFIX = 'hero-composition--';
    private const MOBILE_MARKER_PREFIX = 'hero-mobile--';

    private const BUILD_LAYER_MARKER = '<!-- cache-layer:build -->';
    private const PAGE_LAYER_MARKER = '<!-- cache-layer:page -->';
    private const BRIEF_LAYER_MARKER = '<!-- cache-layer:brief -->';

    private bool $contentMode = false;

    public function __construct(
        Llm $llm,
        PromptRenderer $renderer,
        ?string $model = null,
        ?float $temperature = null,
        ?bool $contentMode = null,
    ) {
        parent::__construct($llm, $renderer, $model, $temperature);
        $this->contentMode = $contentMode ?? SectionUnit::contentModeSelected();
    }

    /**
     * @param array{
     *   site_spec:string|array<mixed>,language:string,theme_json:string|array<mixed>,
     *   design_direction:string,outline:string,site_pages:string,
     *   page:array{slug:string,title?:string,path?:string,front:bool},
     *   section:array{
     *     slug:string,role:string,title?:string,type?:string,purpose?:string,content_notes?:string,
     *     layout_archetype:string,background:string,vertical_density:string,handoff:string,
     *     primary_action:array{label:string,intent:string,destination:string}|null
     *   },
     *   neighbors:string,hero_blueprint:string|array<mixed>,above_fold_contract:string|array<mixed>
     * } $input
     */
    public function request(array $input): array
    {
        $context = $this->context($input);
        if ($this->contentMode) {
            return $this->contentRequest($input, $context);
        }
        $recipe = $context['recipe'];
        $blueprint = $context['blueprint'];
        $mobileTransformation = $context['mobile_transformation'];
        $imageInstructions = HeroComposition::usesGeneratedImages($blueprint)
            ? $this->renderer->render('image-generation.md', [])
            : '';

        return $this->siteLayeredRequest('hero.md', $this->commonVars($input) + [
            'site_pages' => $this->inputString($input, 'site_pages'),
            'page_title' => $this->pageString($input, 'title'),
            'page_path' => $this->pageString($input, 'path', '/'),
            'section_title' => $this->sectionString($context['section'], 'title'),
            'section_slug' => $this->sectionString($context['section'], 'slug'),
            'section_role' => $context['role'],
            'section_type' => $this->sectionString($context['section'], 'type', 'hero'),
            'section_purpose' => $this->sectionString($context['section'], 'purpose'),
            'content_notes' => $this->sectionString($context['section'], 'content_notes'),
            'neighbors' => $this->inputString($input, 'neighbors'),
            'hero_blueprint' => $this->inputJson($input, 'hero_blueprint'),
            'above_fold_contract' => AboveFoldContract::frontContract($context['contract']),
            'composition_assignment' => "ASSIGNED HERO COMPOSITION for this build: **{$recipe}**. "
                . 'Build exactly this one recipe; do not substitute or blend another topology. '
                . 'The root group must carry `className` marker `'
                . self::MARKER_PREFIX . $recipe . '` and mobile marker `'
                . self::MOBILE_MARKER_PREFIX . $mobileTransformation . '`.',
            'composition_recipe' => $this->renderer->render(
                HeroComposition::recipeTemplate($recipe),
                []
            ),
            'image_instructions' => $imageInstructions,
        ]);
    }

    /**
     * The content-mode hero request: the same site, build, and page layers
     * the front page's sections send, the same schema, and a hero brief. The
     * compiler executes the blueprint; the model writes the words.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context
     * @return array{prompt:string,model?:string,temperature?:float,cached_prefixes:list<string>,json_schema:array{name:string,schema:array<string,mixed>}}
     */
    private function contentRequest(array $input, array $context): array
    {
        $blueprint = $context['blueprint'];
        $action = $context['primary_action'];
        $section = $context['section'];
        $register = (string) ($blueprint['headline_register'] ?? 'display');
        $lines = (array) ($blueprint['headline_line_target']['desktop'] ?? [1, 3]);
        $assignment = implode("\n", [
            '  Front-page hero recipe: ' . $context['recipe'] . ' (' . (string) $blueprint['media_mode'] . ')',
            '  Headline register:      ' . $register,
            '  Headline lines:         ' . (int) ($lines[0] ?? 1) . ' to ' . (int) ($lines[1] ?? 3) . ' on desktop at the display size',
            '  Media aspect:           ' . (string) $blueprint['media_aspect'],
            '  Primary action:         ' . (is_array($action)
                ? '"' . $action['label'] . '" -> ' . $action['destination'] . ' (' . $action['intent'] . ')'
                : 'none'),
            '  Neighbors:',
            $this->inputString($input, 'neighbors'),
        ]);
        $shape = implode("\n", [
            'This is the front page hero. Write the one headline the whole site is judged on: '
                . match ($register) {
                    'restrained' => 'a short, quiet line of two to eight words; the picture carries the impact.',
                    'poster'     => 'a bold poster line; every word earns its place.',
                    default      => 'a clear display line that states what the site offers.',
                },
            'Do not restate the site name as the headline. `lead` is one supporting sentence; leave `paragraphs` and `items` empty.',
            is_array($action)
                ? 'Fill `action_label` with the visitor-facing words for the planned primary action.'
                : 'Leave `action_label` empty: the hero has no planned button.',
            (string) $blueprint['media_mode'] === 'cover-image'
                ? 'The picture is a full-width backdrop behind the copy: fill `image_subject` with a scene whose focal interest sits toward the edges and whose '
                    . (string) ($blueprint['text_safe_region'] ?? 'center') . ' region stays calm and low-detail. Fill `image_context` in photographic terms.'
                : 'The picture is one contained foreground photograph beside the copy in a ' . (string) $blueprint['media_aspect']
                    . ' frame: fill `image_subject` and `image_context`. Never a cutout or a transparent asset.',
            'Leave `link_label` and `link_href` empty.',
        ]);
        $request = $this->renderedRequest('section-content.md', $this->commonVars($input) + [
            'site_pages'      => $this->inputString($input, 'site_pages'),
            'page_title'      => $this->pageString($input, 'title'),
            'page_path'       => $this->pageString($input, 'path', '/'),
            'section_title'   => $this->sectionString($section, 'title'),
            'section_slug'    => $this->sectionString($section, 'slug'),
            'section_role'    => $context['role'],
            'section_type'    => $this->sectionString($section, 'type', 'hero'),
            'section_purpose' => $this->sectionString($section, 'purpose'),
            'content_notes'   => $this->sectionString($section, 'content_notes'),
            'assignment'      => $assignment,
            'content_shape'   => $shape,
        ]);
        [$siteLayer, $buildLayer, $pageLayer, $brief] = self::cacheLayers($request['prompt'], [
            self::SITE_LAYER_MARKER,
            self::BUILD_LAYER_MARKER,
            self::PAGE_LAYER_MARKER,
            self::BRIEF_LAYER_MARKER,
        ]);
        $request['cached_prefixes'] = [$siteLayer, $buildLayer, $pageLayer];
        $request['prompt'] = $brief;
        $request['json_schema'] = ['name' => 'section_content', 'schema' => SectionContent::schema()];
        return $request;
    }

    public function finish(string $raw, array $input): MarkupResult
    {
        $context = $this->context($input);
        if ($this->contentMode) {
            $text = trim(\Automattic\SiteBuild\CodeFences::strip($raw));
            $doc = json_decode($text, true);
            if (!is_array($doc)) {
                throw new \RuntimeException('the hero content document is not a JSON object');
            }
            $raw = HeroContentCompiler::compile($doc, $context, $input);
        }
        $key = $this->key($input);
        $warnings = [];
        $repairs = [];
        $markup = GeneratedMarkup::normalize($raw, $key, $warnings, $repairs);
        $labels = \Automattic\SiteBuild\SectionLabel::normalize($markup, 'none', $key);
        $markup = $labels['markup'];
        array_push($warnings, ...$labels['warnings']);
        $markup = GeneratedMarkup::withRootClassMarker(
            $markup,
            self::MARKER_PREFIX,
            self::MARKER_PREFIX . $context['recipe'],
            $key,
            $repairs
        );
        $markup = GeneratedMarkup::withRootClassMarker(
            $markup,
            self::MOBILE_MARKER_PREFIX,
            self::MOBILE_MARKER_PREFIX . $context['mobile_transformation'],
            $key,
            $repairs
        );
        $actionResult = GeneratedMarkup::reconcilePrimaryAction(
            $markup,
            $context['primary_action'],
            $key
        );
        $markup = $actionResult['markup'];
        array_push($repairs, ...$actionResult['repairs']);
        array_push($warnings, ...$actionResult['warnings']);
        $markup = GeneratedMarkup::dedupeHeadlineEcho($markup, $key, $repairs);
        // Remove non-copy children first so an eyebrow-only decorated shell
        // is visible as empty to the fresh parse in stripHeroEyebrow().
        $markup = GeneratedMarkup::stripHeroSeparators($markup, $key, $repairs, $warnings);
        $markup = GeneratedMarkup::stripHeroEyebrow($markup, $key, $repairs, $warnings);
        $markup = GeneratedMarkup::stripEyebrowChipChrome($markup, $key, $repairs);
        $budget = HeroCopyBudget::enforce($markup, $context['primary_action'], $key);
        $markup = $budget['markup'];
        array_push($warnings, ...$budget['warnings']);
        $markup = GeneratedMarkup::headlineFirstHeroCopy($markup, $key, $repairs, $warnings);
        $recipeMeta = HeroComposition::metadata($context['recipe']);
        if ((string) $recipeMeta['layout_archetype'] === 'full-bleed-cover') {
            $markup = GeneratedMarkup::fullBleedCoverAlignment($markup, $key, $repairs);
        }
        $markup = GeneratedMarkup::centerHeroCopy(
            $markup,
            (string) ($context['blueprint']['text_anchor'] ?? ''),
            (string) ($context['contract']['writing_direction'] ?? ''),
            $key,
            $repairs,
            $warnings,
        );
        $markup = GeneratedMarkup::clampHeroTopPadding($markup, $key, $repairs);
        $band = BandSurfaceContract::enforce(
            $markup,
            $this->sectionString($context['section'], 'background'),
            $key,
        );
        $markup = $band->markup;
        array_push($repairs, ...$band->repairs);
        array_push($warnings, ...$band->warnings);
        $before = $markup;
        $markup = GeneratedMarkup::constrainedPart($markup);
        if ($markup !== $before) {
            $repairs[] = [
                'code' => 'root-layout-constrained',
                'part' => $key,
                'disposition' => 'repaired',
            ];
        }
        array_push(
            $warnings,
            ...HeroComposition::markupWarnings($markup, $context['recipe'], $key, $context['blueprint']),
        );
        return new MarkupResult($markup, $repairs, $warnings);
    }

    /**
     * @return array{
     *   section:array<string,mixed>,role:string,blueprint:array<mixed>,recipe:string,
     *   mobile_transformation:string,
     *   primary_action:array{label:string,intent:string,destination:string}|null,
     *   contract:array<string,mixed>
     * }
     */
    private function context(array $input): array
    {
        if (!$this->pageBool($input, 'front')) {
            throw new \InvalidArgumentException('HeroUnit requires unit input page.front=true');
        }
        $section = $this->section($input);
        $role = $this->sectionRole($section);
        $primaryAction = $this->primaryAction($section);

        $blueprint = $this->inputArrayOrJson($input, 'hero_blueprint');
        $recipe = trim(is_string($blueprint['recipe'] ?? null) ? $blueprint['recipe'] : '');
        if ($recipe === '') {
            throw new \InvalidArgumentException("unit input 'hero_blueprint.recipe' must be a non-empty string");
        }
        HeroComposition::assertKnown($recipe);
        $blueprintRepairs = [];
        $blueprintWarnings = [];
        $normalizedBlueprint = HeroBlueprint::normalize(
            $blueprint,
            $recipe,
            $blueprintRepairs,
            $blueprintWarnings,
        );
        if ($normalizedBlueprint !== $blueprint
            || $blueprintRepairs !== []
            || $blueprintWarnings !== []
        ) {
            throw new \InvalidArgumentException(
                "unit input 'hero_blueprint' must be the complete normalized fixed point"
            );
        }
        // Resolve metadata here as part of request preflight. The unit does not
        // independently reinterpret compatibility; the catalog remains the one
        // source of truth and the upstream selector owns the assignment.
        $metadata = HeroComposition::metadata($recipe);
        $mobileTransformation = trim(
            is_string($blueprint['mobile_transformation'] ?? null)
                ? $blueprint['mobile_transformation']
                : ''
        );
        if (
            $mobileTransformation === ''
            || !in_array($mobileTransformation, $metadata['mobile_transformations'], true)
        ) {
            throw new \InvalidArgumentException(
                "unit input 'hero_blueprint.mobile_transformation' must be compatible with recipe '{$recipe}'"
            );
        }

        $contract = $this->inputArrayOrJson($input, 'above_fold_contract');
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_DELIVERY);
        /** @var array<string,mixed> $page */
        $page = $input['page'];
        $expectedPart = 'page-' . trim((string) ($page['slug'] ?? ''))
            . '--' . trim((string) ($section['slug'] ?? ''));
        $contractAction = null;
        if (is_array($contract['primary_action'] ?? null)) {
            $contractAction = [];
            foreach (['label', 'intent', 'destination', 'treatment'] as $field) {
                $contractAction[$field] = $contract['primary_action'][$field] ?? null;
            }
        }
        $expectedAction = $primaryAction === null ? null : $primaryAction + [
            'treatment' => $blueprint['cta_treatment'],
        ];
        $direction = (string) $contract['writing_direction'];
        $expectedRegions = [
            'text_safe' => self::region((string) $blueprint['text_safe_region'], $direction),
            'focal' => self::region((string) $blueprint['focal_region'], $direction),
        ];
        $expectedViewport = [
            'height_profile' => $blueprint['height_profile'],
            'headline_register' => $blueprint['headline_register'],
            'headline_line_target' => $blueprint['headline_line_target'],
        ];
        $contractViewport = array_intersect_key(
            (array) ($contract['viewport'] ?? []),
            $expectedViewport,
        );
        $projection = HeroComposition::planProjection($blueprint);
        if (($contract['recipe'] ?? null) !== $recipe
            || ($contract['mobile_transformation'] ?? null) !== $mobileTransformation
            || ($contract['front_page'] ?? null) !== ($page['slug'] ?? null)
            || ($contract['hero_section'] ?? null) !== ($section['slug'] ?? null)
            || ($contract['hero_part'] ?? null) !== $expectedPart
            || $contractAction !== $expectedAction
            || $contractViewport !== $expectedViewport
            || ($contract['regions'] ?? null) !== $expectedRegions
            || ($section['layout_archetype'] ?? null) !== $projection['layout_archetype']
            || !in_array($section['background'] ?? null, $projection['allowed_backgrounds'], true)
        ) {
            throw new \InvalidArgumentException(
                'unit hero blueprint, page/section projection, action, and above-fold contract must describe one assignment'
            );
        }

        return [
            'section' => $section,
            'role' => $role,
            'blueprint' => $blueprint,
            'recipe' => $recipe,
            'mobile_transformation' => $mobileTransformation,
            'primary_action' => $primaryAction,
            'contract' => $contract,
        ];
    }

    /** @return array{logical:string,physical:?string} */
    private static function region(string $logical, string $writingDirection): array
    {
        $physical = match ($logical) {
            'start' => $writingDirection === 'rtl' ? 'right' : 'left',
            'end' => $writingDirection === 'rtl' ? 'left' : 'right',
            'center' => 'center',
            'full' => 'full',
            default => null,
        };
        return ['logical' => $logical, 'physical' => $physical];
    }

    /** @return array{label:string,intent:string,destination:string}|null */
    private function primaryAction(array $section): ?array
    {
        if (!array_key_exists('primary_action', $section)) {
            throw new \InvalidArgumentException("unit input 'section.primary_action' is required");
        }
        if ($section['primary_action'] === null) {
            return null;
        }
        if (!is_array($section['primary_action'])) {
            throw new \InvalidArgumentException("unit input 'section.primary_action' must be an array or null");
        }
        $action = [];
        foreach (['label', 'intent', 'destination'] as $field) {
            $value = $section['primary_action'][$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException(
                    "unit input 'section.primary_action.{$field}' must be a non-empty string"
                );
            }
            $action[$field] = $value;
        }
        return $action;
    }
}
