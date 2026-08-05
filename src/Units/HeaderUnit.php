<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\AboveFoldContract;

/**
 * Generate the site header from self-contained site/theme/direction/outline
 * context plus the planned hero brief, the site's page list, and the
 * navigation rule that fits the site's shape. Reads and writes no Project
 * state.
 */
final class HeaderUnit extends AbstractMarkupUnit
{
    public function key(array $input): string
    {
        return 'header';
    }

    /**
     * @param array{
     *   site_spec:string|array<mixed>,language:string,theme_json:string|array<mixed>,
     *   design_direction:string,outline:string,hero_brief:string,
     *   site_pages:string,nav_rule:string,above_fold_contract:string|array<mixed>,
     *   header_behavior:string
     * } $input
     */
    public function request(array $input): array
    {
        $contract = $this->contract($input);
        $archetype = (string) $contract['header']['archetype'];
        return $this->renderedRequest('header.md', $this->commonVars($input) + [
            'hero_brief' => $this->inputString($input, 'hero_brief'),
            'site_pages' => $this->inputString($input, 'site_pages'),
            'nav_rule'   => $this->inputString($input, 'nav_rule'),
            'above_fold_contract' => AboveFoldContract::frontContract($contract),
            'archetype_assignment' => "ASSIGNED HEADER ARCHETYPE for this build: **{$archetype}**. "
                . 'Build exactly this one; every other catalog entry is reference only.',
            'header_behavior' => $this->inputString($input, 'header_behavior'),
        ]);
    }

    public function finish(string $raw, array $input): MarkupResult
    {
        $warnings = [];
        $repairs = [];
        $key = $this->key($input);
        $markup = GeneratedMarkup::normalize($raw, $key, $warnings, $repairs);
        $before = $markup;
        $markup = GeneratedMarkup::withoutRedundantLandmark($markup, 'header');
        if ($markup !== $before) {
            $repairs[] = self::repair('redundant-header-landmark-removed', $key);
        }
        GeneratedMarkup::assertNoRedundantLandmark($markup, 'header');
        $archetype = (string) $this->contract($input)['header']['archetype'];
        $markup = GeneratedMarkup::withRootClassMarker(
            $markup,
            'header-archetype--',
            'header-archetype--' . $archetype,
            $key,
            $repairs
        );
        $before = $markup;
        $markup = GeneratedMarkup::constrainedPart($markup);
        if ($markup !== $before) {
            $repairs[] = self::repair('root-layout-constrained', $key);
        }
        return new MarkupResult($markup, $repairs, $warnings);
    }

    /** @return array<string,mixed> */
    private function contract(array $input): array
    {
        $contract = $this->inputArrayOrJson($input, 'above_fold_contract');
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_DELIVERY);
        return $contract;
    }

    /** @return array{code:string,part:string,disposition:string} */
    private static function repair(string $code, string $part): array
    {
        return ['code' => $code, 'part' => $part, 'disposition' => 'repaired'];
    }
}
