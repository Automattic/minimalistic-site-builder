<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * One reader for every layout archetype the generator can build.
 *
 * Four code-owned catalogs answer "what can this generator draw": the header
 * archetypes in AboveFoldContract, the hero recipes in HeroComposition, the
 * page-section archetypes in SectionComposition, and the footer compositions in
 * FooterComposition. Each spells its metadata differently because each grew for
 * its own step. This class is the one place that flattens all four into the
 * same row shape, so a tool can list, count or illustrate them without learning
 * four vocabularies.
 *
 * It reads. It never selects an archetype, never validates delivered markup and
 * never writes: those belong to the catalogs themselves.
 */
final class ArchetypeCatalog
{
    /** The site parts, in the order a page renders them. */
    public const FAMILIES = ['header', 'hero', 'section', 'footer'];

    /**
     * Human names and one-line briefs for the families, so every consumer
     * describes them the same way.
     *
     * @var array<string,array{title:string,blurb:string}>
     */
    private const FAMILY_LABELS = [
        'header' => [
            'title' => 'Headers',
            'blurb' => 'The site-wide identity bar. One archetype per site, assigned with the hero as one above-fold contract.',
        ],
        'hero' => [
            'title' => 'Heroes',
            'blurb' => 'The opening composition of the front page. Each recipe fixes its own canvas, media mode, surfaces and copy budget.',
        ],
        'section' => [
            'title' => 'Sections',
            'blurb' => 'The page-body bands. The page plan assigns one archetype per section, plus a surface, a density and a text placement.',
        ],
        'footer' => [
            'title' => 'Footers',
            'blurb' => 'The closing site-wide composition. One archetype per site, each with a fixed surface.',
        ],
    ];

    /**
     * Facts a family's catalog cannot state about itself: an archetype that
     * code refuses to assign, or assigns only under an operator override. An
     * empty gallery card is otherwise read as "the cohort was unlucky" when it
     * really means "the generator will not deliver this".
     *
     * @var array<string,string> "family/id" => note
     */
    private const REACHABILITY = [
        'header/oversized-wordmark' => 'Unreachable today: the above-fold contract refuses this archetype whenever the front '
            . 'page has a hero, which it always does, so neither automatic assignment nor a forced HEADER_ARCHETYPE can '
            . 'deliver it (src/AboveFoldContract.php::forcedHeaderCompatible).',
        'header/centered-masthead' => 'Retired from automatic assignment (BIGR-872); reachable only through a forced '
            . 'HEADER_ARCHETYPE, and refused over an image-led hero.',
        'header/split-nav' => 'Retired from automatic assignment (BIGR-872); reachable only through a forced '
            . 'HEADER_ARCHETYPE, and refused on a one-page site.',
    ];

    /**
     * Every archetype in the generator, in family order.
     *
     * @return list<array{
     *   family:string,id:string,key:string,summary:string,source:string,
     *   facts:array<string,string>,note:string
     * }>
     */
    public static function entries(): array
    {
        return [
            ...self::headerEntries(),
            ...self::heroEntries(),
            ...self::sectionEntries(),
            ...self::footerEntries(),
        ];
    }

    /** @return array{title:string,blurb:string} */
    public static function familyLabel(string $family): array
    {
        return self::FAMILY_LABELS[$family] ?? ['title' => ucfirst($family), 'blurb' => ''];
    }

    /** How many archetypes each family holds, keyed by family. */
    public static function counts(): array
    {
        $counts = array_fill_keys(self::FAMILIES, 0);
        foreach (self::entries() as $entry) {
            $counts[$entry['family']]++;
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    private static function headerEntries(): array
    {
        $descriptions = self::headerDescriptions();
        $entries = [];
        foreach (AboveFoldContract::HEADER_ARCHETYPES as $archetype) {
            $entries[] = self::row(
                'header',
                $archetype,
                $descriptions[$archetype] ?? '',
                'src/AboveFoldContract.php + prompts/header.md',
                [],
            );
        }
        return $entries;
    }

    /** @return list<array<string,mixed>> */
    private static function heroEntries(): array
    {
        $entries = [];
        foreach (HeroComposition::catalog() as $recipe => $meta) {
            $entries[] = self::row(
                'hero',
                (string) $recipe,
                self::recipeSummary((string) $meta['prompt']),
                'src/HeroComposition.php + prompts/' . $meta['prompt'],
                array_filter([
                    'canvas' => implode(', ', (array) $meta['canvases']),
                    'media' => implode(', ', (array) $meta['media_modes']),
                    'images' => $meta['min_images'] . '–' . $meta['max_images'],
                    'surfaces' => implode(', ', (array) $meta['backgrounds']),
                    'header modes' => implode(', ', (array) $meta['header_modes']),
                    'copy' => (string) $meta['copy_capacity'],
                    'media aspect' => implode(', ', (array) ($meta['media_aspects'] ?? [])),
                    'media weight' => implode(', ', (array) ($meta['media_weights'] ?? [])),
                    'section shape' => (string) $meta['layout_archetype'],
                ], static fn (string $value): bool => $value !== ''),
            );
        }
        return $entries;
    }

    /** @return list<array<string,mixed>> */
    private static function sectionEntries(): array
    {
        $entries = [];
        foreach (SectionComposition::catalog() as $archetype => $meta) {
            $facts = [
                'surfaces' => implode(', ', (array) $meta['backgrounds']),
                'default surface' => (string) $meta['default_background'],
                'images' => $meta['min_images'] . '–' . $meta['max_images'],
                'copy' => (string) $meta['copy_capacity'],
                'needs a row block' => $meta['requires_row'] ? 'yes' : 'no',
            ];
            if (((array) $meta['requires_context']) !== []) {
                $facts['only when'] = implode(', ', (array) $meta['requires_context']);
            }
            $entries[] = self::row(
                'section',
                (string) $archetype,
                self::recipeSummary((string) $meta['prompt']),
                'src/SectionComposition.php + prompts/' . $meta['prompt'],
                $facts,
            );
        }
        return $entries;
    }

    /** @return list<array<string,mixed>> */
    private static function footerEntries(): array
    {
        $entries = [];
        foreach (FooterComposition::ARCHETYPES as $archetype) {
            $template = FooterComposition::recipeTemplate($archetype);
            $entries[] = self::row(
                'footer',
                $archetype,
                self::recipeSummary($template),
                'src/FooterComposition.php + prompts/' . $template,
                [
                    'surface' => FooterComposition::surface($archetype),
                    'uses a generated image' => FooterComposition::usesGeneratedImage($archetype) ? 'yes' : 'no',
                ],
            );
        }
        return $entries;
    }

    /**
     * @param array<string,string> $facts
     * @return array<string,mixed>
     */
    private static function row(string $family, string $id, string $summary, string $source, array $facts): array
    {
        $key = $family . '/' . $id;
        return [
            'family' => $family,
            'id' => $id,
            'key' => $key,
            'brief' => self::brief($summary),
            'summary' => $summary,
            'source' => $source,
            'facts' => $facts,
            'note' => self::REACHABILITY[$key] ?? '',
        ];
    }

    /**
     * The one line a reader needs before the picture.
     *
     * `summary` is the prompt fragment verbatim, which is written for the model
     * and carries block names and JSON. That is the right reference text and
     * the wrong opening line, so the first sentence is lifted out and the rest
     * stays available behind it.
     */
    private static function brief(string $summary): string
    {
        if ($summary === '') {
            return '';
        }
        // Split on a sentence end that is followed by a capital, so `wp:group`,
        // `e.g.` and a decimal ratio never end the sentence early.
        $sentences = preg_split('~(?<=[.!?])\s+(?=[A-Z])~', $summary) ?: [$summary];
        $brief = trim((string) $sentences[0]);
        if (mb_strlen($brief) > 210) {
            $brief = rtrim(mb_substr($brief, 0, 207), " ,;:—-") . '…';
        }
        return $brief;
    }

    /**
     * The authoring intent in the words the generator itself is given: the
     * first prose paragraph of the archetype's prompt fragment.
     */
    public static function recipeSummary(string $relativePath): string
    {
        $path = Package::promptsDir() . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            return '';
        }
        $paragraph = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                if ($paragraph !== []) {
                    break;
                }
                continue;
            }
            if (str_starts_with($trimmed, '-')) {
                if ($paragraph !== []) {
                    break;
                }
                continue;
            }
            $paragraph[] = $trimmed;
        }
        // Footer fragments open with their own "**id** — " label; hero and
        // section ones do not. Drop it so every family reads the same way.
        $summary = (string) preg_replace('~^\*\*[a-z-]+\*\*\s*—\s*~u', '', implode(' ', $paragraph));
        return trim(str_replace('**', '', $summary));
    }

    /**
     * The header archetypes are described where they are authored: the numbered
     * catalog inside prompts/header.md. The ids stay AboveFoldContract's.
     *
     * @return array<string,string>
     */
    private static function headerDescriptions(): array
    {
        $path = Package::promptsDir() . '/header.md';
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        $found = [];
        if (preg_match_all('~^\d+\.\s+\*\*([a-z-]+)\*\*\s+—\s+(.+)$~m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // Split only where a capital starts the next sentence, so the
                // abbreviated "e.g." inside these entries never cuts a
                // description in half.
                $sentences = preg_split('~(?<=\.)\s+(?=[A-Z])~', trim($match[2])) ?: [];
                $description = '';
                foreach ($sentences as $sentence) {
                    $description = trim($description . ' ' . $sentence);
                    if (mb_strlen($description) >= 220) {
                        break;
                    }
                }
                $found[$match[1]] = $description;
            }
        }
        return $found;
    }
}
