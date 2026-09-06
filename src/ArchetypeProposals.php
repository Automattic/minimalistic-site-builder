<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The store for archetypes we have NOT built yet.
 *
 * A proposal is a drawing plus an argument: a self-contained HTML mockup of the
 * composition, the idea in one paragraph, why the current catalog cannot
 * express it, what it would be built from, and the risk it carries. Records
 * live one-per-file under docs/archetypes/proposals so a mockup written by the
 * model is reviewable in a diff and editable by hand afterwards.
 *
 * Every record passes through validate() on the way in, whoever wrote it. A
 * mockup is rendered inside the gallery page, so it may not carry script,
 * event handlers, or anything that reaches the network, and its CSS may only
 * style its own card. Those are the same rules whether the author is a person
 * or a model — a hand-written record with a stray <script> is exactly as
 * unwelcome.
 */
final class ArchetypeProposals
{
    /** Origins a record may declare. */
    public const ORIGINS = ['hand', 'prompt', 'auto'];

    /**
     * Where a proposal stands.
     *
     * `waiting` is the queue. `built` means the archetype now lives in a
     * code-owned catalog, so the card is history rather than work. `dropped`
     * means it was tried or considered and will not be built — the record is
     * kept on purpose, because a proposal deleted from disk is a proposal the
     * variety pass will draw again next week.
     */
    public const STATUSES = ['waiting', 'built', 'dropped'];

    /** Fields every record carries. */
    private const REQUIRED = ['id', 'family', 'title', 'idea', 'why_new', 'built_from', 'risk', 'mockup'];

    public function __construct(private readonly string $dir) {}

    public static function defaultDir(): string
    {
        return Package::root() . '/docs/archetypes/proposals';
    }

    /**
     * Every stored proposal, ordered by family then id.
     *
     * A record that no longer validates is skipped rather than thrown: the
     * gallery must still open when one file on disk went bad.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $records = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            try {
                $records[] = self::validate($decoded);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        // Family order first, then the queue before the settled records: a card
        // nobody can pick should not sit between two that can be picked.
        usort($records, static function (array $a, array $b): int {
            $families = array_flip(ArchetypeCatalog::FAMILIES);
            $rank = static fn (array $r): int => $r['status'] === 'waiting' ? 0 : 1;
            return [$families[$a['family']] ?? 9, $rank($a), $a['id']]
                <=> [$families[$b['family']] ?? 9, $rank($b), $b['id']];
        });
        return $records;
    }

    /** The ids already proposed for one family, so a generator can avoid them. */
    public function idsFor(string $family): array
    {
        return array_values(array_map(
            static fn (array $record): string => (string) $record['id'],
            array_filter($this->all(), static fn (array $r): bool => $r['family'] === $family),
        ));
    }

    /** Write one validated record, returning the file it landed in. */
    public function save(array $record): string
    {
        $record = self::validate($record);
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("cannot create proposal directory: {$this->dir}");
        }
        $file = $this->dir . '/' . $record['family'] . '--' . $record['id'] . '.json';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \InvalidArgumentException('proposal does not serialize to JSON');
        }
        file_put_contents($file, $json . "\n");
        return $file;
    }

    /** The CSS scope class a proposal's mockup must style, and nothing else. */
    public static function scopeClass(string $family, string $id): string
    {
        return 'mock-' . $family . '-' . $id;
    }

    /**
     * Normalize and check one record.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when the record is unusable or unsafe
     */
    public static function validate(array $record): array
    {
        foreach (self::REQUIRED as $field) {
            if (!isset($record[$field])) {
                throw new \InvalidArgumentException("proposal is missing '{$field}'");
            }
        }
        $family = strtolower(trim((string) $record['family']));
        if (!in_array($family, ArchetypeCatalog::FAMILIES, true)) {
            throw new \InvalidArgumentException(
                "proposal family must be one of: " . implode(', ', ArchetypeCatalog::FAMILIES)
            );
        }
        $id = strtolower(trim((string) $record['id']));
        if (preg_match('~^[a-z][a-z0-9]*(-[a-z0-9]+)*$~', $id) !== 1) {
            throw new \InvalidArgumentException("proposal id '{$id}' must be kebab-case");
        }

        $mockup = $record['mockup'];
        if (!is_array($mockup) || !is_string($mockup['html'] ?? null) || !is_string($mockup['css'] ?? null)) {
            throw new \InvalidArgumentException('proposal mockup needs html and css strings');
        }
        $scope = self::scopeClass($family, $id);
        self::assertInert($mockup['html'], 'mockup html');
        self::assertInert($mockup['css'], 'mockup css');
        self::assertScoped($mockup['css'], $scope);

        $origin = strtolower(trim((string) ($record['origin'] ?? 'hand')));
        if (!in_array($origin, self::ORIGINS, true)) {
            $origin = 'hand';
        }
        $status = strtolower(trim((string) ($record['status'] ?? 'waiting')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'waiting';
        }

        return [
            'id' => $id,
            'family' => $family,
            'title' => trim((string) $record['title']),
            'idea' => trim((string) $record['idea']),
            'why_new' => trim((string) $record['why_new']),
            'built_from' => trim((string) $record['built_from']),
            'risk' => trim((string) $record['risk']),
            'mockup' => ['scope' => $scope, 'html' => trim($mockup['html']), 'css' => trim($mockup['css'])],
            'origin' => $origin,
            'status' => $status,
            'status_note' => trim((string) ($record['status_note'] ?? '')),
            'prompt' => trim((string) ($record['prompt'] ?? '')),
            'created' => trim((string) ($record['created'] ?? '')),
        ];
    }

    /**
     * Move one proposal to a new status, keeping everything else it says.
     *
     * @throws \InvalidArgumentException on an unknown status or a missing record
     */
    public function setStatus(string $family, string $id, string $status, string $note = ''): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('status must be one of: ' . implode(', ', self::STATUSES));
        }
        $file = $this->dir . '/' . $family . '--' . $id . '.json';
        if (!is_file($file)) {
            throw new \InvalidArgumentException("no proposal at {$family}/{$id}");
        }
        $record = json_decode((string) file_get_contents($file), true);
        if (!is_array($record)) {
            throw new \InvalidArgumentException("proposal {$family}/{$id} is not readable JSON");
        }
        $record['status'] = $status;
        $record['status_note'] = $note;
        return $this->save($record);
    }

    /**
     * A mockup renders inside the gallery, so it may not script, handle events
     * or reach the network. A proposal is a drawing, not an application.
     *
     * `style` is refused in the markup for the same reason `assertScoped()`
     * exists: a stylesheet inside the html would apply to the whole document
     * and never pass through the scope check, which reads the css field only.
     * A mockup that wants rules has a css field for them.
     */
    private static function assertInert(string $source, string $label): void
    {
        $patterns = [
            '~<\s*script~i' => 'script tags',
            '~\son[a-z]+\s*=~i' => 'inline event handlers',
            '~javascript:~i' => 'javascript: urls',
            '~<\s*(iframe|object|embed|form|link|meta|base|style|svg|template)\b~i'
                => 'embedded, network-reaching or style-carrying elements',
            '~@import~i' => 'css imports',
            '~https?://~i' => 'remote urls',
            '~url\(\s*[\'"]?//~i' => 'protocol-relative urls',
        ];
        foreach ($patterns as $pattern => $what) {
            if (preg_match($pattern, $source) === 1) {
                throw new \InvalidArgumentException("{$label} may not contain {$what}");
            }
        }
    }

    /**
     * Every rule in a mockup's stylesheet must *start* at that mockup's own
     * scope class. One selector that reaches outside its card would restyle the
     * gallery around it, and a proposal that repaints the tool is unreviewable.
     *
     * The check is on the leftmost compound selector, not on the selector as a
     * whole: `body:has(.mock-hero-x)` names the scope but matches the document,
     * and `.mock-hero-x-evil` merely starts with the same characters. Both have
     * to be refused, so the leftmost compound is stripped of its functional
     * pseudo-class arguments and then matched on a whole class token.
     */
    private static function assertScoped(string $css, string $scope): void
    {
        // Strip comments and at-rule wrappers (@media/@supports/@container),
        // then check what remains: every selector list before a { must be
        // anchored on the scope class in each of its comma-separated parts.
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $stripped = (string) preg_replace('~@(media|supports|container)[^{]*\{~i', '', $stripped);
        if (preg_match_all('~([^{}]+)\{~', $stripped, $matches) === false) {
            return;
        }
        foreach ($matches[1] ?? [] as $selectorList) {
            foreach (self::splitSelectorList($selectorList) as $selector) {
                if ($selector === '' || str_starts_with($selector, '@')) {
                    continue;
                }
                if (!self::anchoredOnScope($selector, $scope)) {
                    throw new \InvalidArgumentException(
                        "mockup css selector '{$selector}' must start at .{$scope}"
                    );
                }
            }
        }
    }

    /**
     * Split on the commas that separate selectors, ignoring the ones inside a
     * functional pseudo-class such as `:is(a, b)`.
     *
     * @return list<string>
     */
    private static function splitSelectorList(string $selectorList): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($selectorList) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);
        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** Does this selector's leftmost compound carry the scope class itself? */
    private static function anchoredOnScope(string $selector, string $scope): bool
    {
        // Everything up to the first combinator is the compound that decides
        // what the rule can match at all. A combinator inside a functional
        // pseudo-class does not end it, so track parenthesis depth.
        $compound = '';
        $depth = 0;
        foreach (str_split($selector) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && in_array($char, [' ', "\t", "\n", '>', '+', '~'], true)) {
                break;
            }
            $compound .= $char;
        }
        // `body:has(.scope)` names the scope only inside an argument list, and
        // matches the whole document — drop those arguments before looking.
        $compound = (string) preg_replace('~\([^()]*\)~', '', $compound);
        return preg_match('~(^|[^\w-])\.' . preg_quote($scope, '~') . '(?![\w-])~', $compound) === 1;
    }
}
