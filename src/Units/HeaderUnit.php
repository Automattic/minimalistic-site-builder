<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

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
     *   site_pages:string,nav_rule:string,archetype_assignment:string
     * } $input
     */
    public function request(array $input): array
    {
        return $this->renderedRequest('header.md', $this->commonVars($input) + [
            'hero_brief' => $this->inputString($input, 'hero_brief'),
            'site_pages' => $this->inputString($input, 'site_pages'),
            'nav_rule'   => $this->inputString($input, 'nav_rule'),
            'archetype_assignment' => $this->inputString($input, 'archetype_assignment'),
        ]);
    }

    public function finish(string $raw, array $input, array &$notes = []): string
    {
        return GeneratedMarkup::constrainedPart(GeneratedMarkup::normalize($raw, $this->key($input), $notes));
    }
}
