<?php
declare(strict_types=1);

/**
 * Cross-build memory of chosen design directions.
 *
 * One JSON file per projects root (projects/.direction-history.json) recording
 * the fingerprint of every direction the builder committed to: title, type
 * pairing, palette, hero composition. Two prompts read it — the design
 * direction generation prompt ("do NOT repeat these") and the direction judge
 * ("prefer the candidate most distinct from these") — so repeated builds of
 * the same brief stop converging on the model's one safe favourite.
 *
 * Persistence is best-effort: history informs variety, it is not a build
 * artifact, so a read/write failure must never break a build.
 */
final class DirectionHistory
{
    /** Entries kept on disk; older ones are dropped on append. */
    private const KEEP = 20;

    public function __construct(private string $path) {}

    /**
     * The history shared by every project in this project's store — the file
     * sits beside the project directories, not inside one, because its whole
     * point is remembering across builds.
     */
    public static function forProject(Project $project): self
    {
        return new self(dirname($project->root) . '/.direction-history.json');
    }

    /**
     * The most recent entries, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit): array
    {
        return array_reverse(array_slice($this->load(), -$limit));
    }

    /**
     * Record one committed direction. Trims the file to the KEEP most recent
     * entries. Best-effort: failures are swallowed (see class doc).
     *
     * @param array<string,mixed> $direction a normalized direction (DesignDirectionStep::normalize)
     */
    public function append(array $direction): void
    {
        try {
            $entries = $this->load();
            $entries[] = self::entryFor($direction) + ['chosen_at' => gmdate('c')];
            $json = json_encode(
                array_slice($entries, -self::KEEP),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            @file_put_contents($this->path, $json . "\n");
        } catch (\Throwable) {
            // History is advisory — never let it break a build.
        }
    }

    /**
     * The fingerprint of a direction that later builds must avoid repeating:
     * the concept title, the font pairing, the palette hexes and the hero
     * composition. Pure — unit-testable.
     *
     * @param array<string,mixed> $direction
     * @return array<string,mixed>
     */
    public static function entryFor(array $direction): array
    {
        $type = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        return [
            'title'            => trim((string) ($direction['title'] ?? '')),
            'type'             => [
                'heading' => trim((string) ($type['heading'] ?? '')),
                'body'    => trim((string) ($type['body'] ?? '')),
            ],
            'palette'          => is_array($direction['palette'] ?? null) ? $direction['palette'] : [],
            'hero_composition' => trim((string) ($direction['hero_composition'] ?? '')),
        ];
    }

    /**
     * Render entries as the compact "recently used" list injected into the
     * generation and judge prompts — one line per direction with the parts a
     * new candidate must diverge from. Pure — unit-testable.
     *
     * @param array<int,array<string,mixed>> $entries
     */
    public static function renderForPrompt(array $entries): string
    {
        if ($entries === []) {
            return '(none recorded yet)';
        }
        $lines = [];
        foreach ($entries as $entry) {
            $title = trim((string) ($entry['title'] ?? ''));
            $parts = [];
            $type = is_array($entry['type'] ?? null) ? $entry['type'] : [];
            $fonts = array_filter([trim((string) ($type['heading'] ?? '')), trim((string) ($type['body'] ?? ''))]);
            if ($fonts !== []) {
                $parts[] = 'type: ' . implode(' / ', $fonts);
            }
            $palette = is_array($entry['palette'] ?? null) ? array_filter(array_map('strval', $entry['palette'])) : [];
            if ($palette !== []) {
                $parts[] = 'palette: ' . implode(' ', $palette);
            }
            $hero = trim((string) ($entry['hero_composition'] ?? ''));
            if ($hero !== '') {
                $parts[] = 'hero: ' . $hero;
            }
            $lines[] = '- "' . ($title !== '' ? $title : 'untitled') . '"'
                . ($parts === [] ? '' : ' — ' . implode('; ', $parts));
        }
        return implode("\n", $lines);
    }

    /**
     * All stored entries, oldest first. A missing or corrupt file reads as
     * empty history.
     *
     * @return array<int,array<string,mixed>>
     */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($this->path), true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, 'is_array'));
    }
}
