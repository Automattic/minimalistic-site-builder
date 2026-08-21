<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\FooterComposition;

/**
 * Generate the site footer from self-contained site/theme/direction/outline
 * context plus the site's page list, its assigned composition, and the front
 * page's final-section brief. Reads and writes no Project state.
 */
final class FooterUnit extends AbstractMarkupUnit
{
    public function key(array $input): string
    {
        return 'footer';
    }

    /**
     * @param array{
     *   site_spec:string|array<mixed>,language:string,theme_json:string|array<mixed>,
     *   design_direction:string,outline:string,site_pages:string,
     *   final_section_brief:string,composition_archetype:string,page_count:int
     * } $input
     */
    public function request(array $input): array
    {
        $archetype = $this->inputString($input, 'composition_archetype');
        FooterComposition::assertKnown($archetype);
        $surface = $this->surface($input, $archetype);
        $pageCount = $this->pageCount($input);
        $imageInstructions = FooterComposition::usesGeneratedImage($archetype)
            ? $this->renderer->render('image-generation.md', [])
            : '';

        return $this->siteLayeredRequest('footer.md', $this->commonVars($input) + [
            'site_pages' => $this->inputString($input, 'site_pages'),
            'nav_rule' => FooterComposition::navigationRule($pageCount),
            'composition' => $this->renderer->render('footer-composition.md', [
                'composition_assignment' => FooterComposition::assignment($archetype),
                'footer_surface' => $surface,
                'final_section_brief'    => $this->inputString($input, 'final_section_brief'),
                'composition_recipe' => $this->renderer->render(
                    FooterComposition::recipeTemplate($archetype),
                    []
                ),
            ]),
            'image_instructions' => $imageInstructions,
        ]);
    }

    public function finish(string $raw, array $input): MarkupResult
    {
        $warnings = [];
        $repairs = [];
        $key = $this->key($input);
        $markup = GeneratedMarkup::normalize($raw, $key, $warnings, $repairs);
        $before = $markup;
        $markup = GeneratedMarkup::withoutRedundantLandmark($markup, 'footer');
        if ($markup !== $before) {
            $repairs[] = self::repair('redundant-footer-landmark-removed', $key);
        }
        GeneratedMarkup::assertNoRedundantLandmark($markup, 'footer');
        $archetype = $this->inputString($input, 'composition_archetype');
        FooterComposition::assertKnown($archetype);
        if ($this->pageCount($input) === 1) {
            $before = $markup;
            $markup = FooterMarkup::withoutSiteTitleLinks($markup);
            if ($markup !== $before) {
                $repairs[] = self::repair('one-page-site-title-link-disabled', $key);
            }
        }
        $markup = FooterMarkup::withoutPortraitImagePlaceholders($markup, $warnings);
        $before = $markup;
        $markup = FooterMarkup::withRootBackgroundColor(
            $markup,
            $this->surface($input, $archetype),
            $warnings
        );
        if ($markup !== $before) {
            $repairs[] = self::repair('footer-surface-enforced', $key);
        }
        $before = $markup;
        $markup = GeneratedMarkup::constrainedPart($markup);
        if ($markup !== $before) {
            $repairs[] = self::repair('root-layout-constrained', $key);
        }
        return new MarkupResult($markup, $repairs, $warnings);
    }

    /**
     * The surface the caller resolved against every page's closing section, or
     * the archetype's own preference when the adapter did not resolve one.
     *
     * @param array<string,mixed> $input
     */
    private function surface(array $input, string $archetype): string
    {
        $surface = $input['surface'] ?? null;
        if ($surface === null || $surface === '') {
            return FooterComposition::surface($archetype);
        }
        if (!is_string($surface)) {
            throw new \InvalidArgumentException("unit input 'surface' must be a string");
        }
        return $surface;
    }

    private function pageCount(array $input): int
    {
        $pageCount = $input['page_count'] ?? null;
        if (!is_int($pageCount)) {
            throw new \InvalidArgumentException("unit input 'page_count' must be an integer");
        }
        if ($pageCount < 1) {
            throw new \InvalidArgumentException('footer page_count must be at least 1');
        }
        return $pageCount;
    }

    /** @return array{code:string,part:string,disposition:string} */
    private static function repair(string $code, string $part): array
    {
        return ['code' => $code, 'part' => $part, 'disposition' => 'repaired'];
    }
}
