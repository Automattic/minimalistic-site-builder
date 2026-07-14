<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * Generate the site footer from self-contained site/theme/direction/outline
 * context. Reads and writes no Project state.
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
     *   design_direction:string,outline:string
     * } $input
     */
    public function request(array $input): array
    {
        return $this->renderedRequest('footer.md', $this->commonVars($input));
    }

    public function finish(string $raw, array $input): string
    {
        return GeneratedMarkup::constrainedPart(GeneratedMarkup::normalize($raw, $this->key($input)));
    }
}
