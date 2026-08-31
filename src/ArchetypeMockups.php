<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Draw an archetype nobody has built yet.
 *
 * The model is given the whole catalog for one site part, every proposal
 * already waiting, and either a request in the operator's words or the standing
 * request to find a gap. It returns one composition with a self-contained
 * mockup, which ArchetypeProposals validates before it reaches disk — a drawing
 * that scripts, phones home or restyles the gallery never lands.
 *
 * The id the model picks is advisory. A collision with a cataloged archetype or
 * an existing proposal is resolved here rather than refused: the drawing is the
 * expensive part, and a second `-2` is cheaper than another model call.
 */
final class ArchetypeMockups
{
    /** How the site parts are briefed, so a proposal knows what it is composing. */
    private const FAMILY_BRIEF = [
        'header' => 'A HEADER: the site-wide identity bar above every page. It carries the site name or logo and '
            . 'the primary navigation, it is short, and it renders on every page — so it can hold no page-specific '
            . 'copy and no large imagery.',
        'hero' => 'A HERO: the opening composition of the front page, above the fold. It carries one level-1 '
            . 'headline, at most one supporting line, and at most one planned action, plus whatever media the '
            . 'topology needs.',
        'section' => 'A PAGE SECTION: one band in the body of a page, between the hero and the footer. It carries '
            . 'a heading, some copy, and whatever media, cards, rows or facts its topology needs. It sits between '
            . 'two other bands, so its top and bottom edges matter.',
        'footer' => 'A FOOTER: the closing site-wide composition. It carries site identity, utility navigation, '
            . 'and at most one action; it renders below every page, so it can hold no page-specific copy.',
    ];

    public function __construct(
        private readonly Llm $llm,
        private readonly PromptRenderer $renderer,
    ) {}

    /**
     * One proposal for one family.
     *
     * @param list<array<string,mixed>> $catalog   the cataloged archetypes (all families)
     * @param list<array<string,mixed>> $proposals every stored proposal
     * @param string                    $request   the operator's words, or '' for the variety pass
     * @return array<string,mixed> a validated, storable record
     */
    public function draw(string $family, array $catalog, array $proposals, string $request = ''): array
    {
        if (!in_array($family, ArchetypeCatalog::FAMILIES, true)) {
            throw new \InvalidArgumentException(
                'family must be one of: ' . implode(', ', ArchetypeCatalog::FAMILIES)
            );
        }
        $taken = self::takenIds($family, $catalog, $proposals);
        $scope = ArchetypeProposals::scopeClass($family, 'draft');

        $prompt = $this->renderer->render('archetype-mockup.md', [
            'family_brief' => self::FAMILY_BRIEF[$family],
            'existing' => self::describe(array_values(array_filter(
                $catalog,
                static fn (array $row): bool => $row['family'] === $family,
            )), 'summary'),
            'proposed' => self::describe(array_values(array_filter(
                $proposals,
                static fn (array $row): bool => $row['family'] === $family,
            )), 'idea'),
            'request' => $request === ''
                ? 'Find the widest gap in the list above and fill it. Choose the composition that adds the most '
                    . 'variety to what this generator can already draw — a shape a visitor would not confuse with '
                    . 'any of them. Say which of the existing archetypes it sits furthest from.'
                : 'Draw this: ' . $request,
            'scope' => $scope,
        ]);

        $raw = $this->llm->completeJson($prompt, ['temperature' => 1.0]);
        $record = $raw + ['family' => $family];
        $record['origin'] = $request === '' ? 'auto' : 'prompt';
        $record['prompt'] = $request;
        $record['created'] = date('Y-m-d');
        $record['id'] = self::freeId(is_string($raw['id'] ?? null) ? $raw['id'] : 'untitled', $taken);

        // The model wrote its mockup against the draft scope, because it could
        // not know the final id. Re-scope it to the id the record ships with.
        $final = ArchetypeProposals::scopeClass($family, $record['id']);
        if (isset($record['mockup']) && is_array($record['mockup'])) {
            foreach (['html', 'css'] as $part) {
                if (is_string($record['mockup'][$part] ?? null)) {
                    $record['mockup'][$part] = str_replace($scope, $final, $record['mockup'][$part]);
                }
            }
        }

        return ArchetypeProposals::validate($record);
    }

    /**
     * Ids the proposal may not take: every cataloged archetype in the family
     * and every proposal already waiting for it.
     *
     * @return list<string>
     */
    private static function takenIds(string $family, array $catalog, array $proposals): array
    {
        $ids = [];
        foreach ([...$catalog, ...$proposals] as $row) {
            if (($row['family'] ?? '') === $family && is_string($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<string> $taken */
    private static function freeId(string $wanted, array $taken): string
    {
        $slug = strtolower(trim($wanted));
        $slug = (string) preg_replace('~[^a-z0-9]+~', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'untitled';
        }
        if (!in_array($slug, $taken, true)) {
            return $slug;
        }
        for ($n = 2; $n < 50; $n++) {
            if (!in_array("{$slug}-{$n}", $taken, true)) {
                return "{$slug}-{$n}";
            }
        }
        return $slug . '-' . substr(hash('sha256', $slug . microtime()), 0, 6);
    }

    /**
     * A settled proposal is listed with its status, not hidden. One that was
     * built is already in the catalog; one that was dropped was considered and
     * refused, and re-drawing it is the exact waste the record exists to stop.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function describe(array $rows, string $field): string
    {
        if ($rows === []) {
            return '(none yet)';
        }
        $lines = [];
        foreach ($rows as $row) {
            $text = trim((string) ($row[$field] ?? ''));
            if (mb_strlen($text) > 400) {
                $text = mb_substr($text, 0, 400) . '…';
            }
            $status = (string) ($row['status'] ?? 'waiting');
            $label = $status === 'waiting' ? '' : ' _(' . $status . ')_';
            $lines[] = '- **' . $row['id'] . '**' . $label . ' — ' . $text;
        }
        return implode("\n", $lines);
    }
}
