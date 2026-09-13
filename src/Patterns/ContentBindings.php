<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\BlockMarkup;

/**
 * The parts of a page the model is never asked to write.
 *
 * A pattern marks them itself: `ai-bind-phone` is a slot the site's own phone
 * number goes into, `ai-show-if-booking` is a block that exists only for sites
 * that take bookings. Both are resolved from supplied facts, so a phone number
 * is the phone number and not a plausible-looking one, which is the same rule
 * the Brand follows and for the same reason.
 *
 * Extracted from Big Sky's `Replace_Content`. Two things its host supplied are
 * replaced by explicit inputs: the values come from the request's facts rather
 * than from resolving `product.wpcom.big_sky.*` paths against an agent context,
 * and the per-page "avatar already used" flag was a class static that outlived
 * the page it was reset for — here there is no static to reset, because each
 * call builds its own state and drops it.
 *
 * This runs before the model sees the page, so a bound block is never offered
 * as a slot and a hidden block is never written into.
 */
final class ContentBindings
{
    /**
     * What a pattern writes on a block to bind it. Shared, because
     * `ContentSlots` must skip exactly the blocks this claims: a prefix the
     * two spelled differently is a bound block still offered to the model.
     */
    public const BIND_PREFIX = 'ai-bind-';

    /** Blocks removed outright when the site has no value for their binding. */
    private const DROP_WHEN_EMPTY = ['hours'];

    /**
     * Bindings composing more than one fact, joined one per line.
     *
     * A footer slot is one paragraph holding an address above a phone number,
     * not two blocks, so the pattern binds the pair and gets both.
     */
    private const COMPOSED = [
        'phone-email' => ['email', 'phone'],
        'address-phone' => ['address', 'phone'],
    ];

    /**
     * Bindings this stage does not resolve, and who does.
     *
     * An avatar is an image, and an image in a bundle is a reference the host
     * resolves when it imports media — the attachment id only exists on the
     * destination site. Writing a URL here would produce a page pointing at
     * whatever the fact happened to hold, which is the hidden-fallback failure
     * PR-20 rules out. `resolve-media` owns it along with every other image.
     */
    private const DEFERRED = ['avatar'];

    private function __construct(
        private BlockMarkup $document,
        /** @var array<string, mixed> */
        private array $facts,
    ) {
    }

    /**
     * The markup with every binding resolved and every hidden block removed.
     *
     * @param array<string, mixed> $facts
     * @return array{markup: string, bound: int, hidden: list<string>, unbound: list<string>, emptied: list<string>, deferred: list<string>}
     */
    public static function apply(string $markup, array $facts): array
    {
        return (new self(BlockMarkup::parse($markup), $facts))->run($markup);
    }

    /**
     * @return array{markup: string, bound: int, hidden: list<string>, unbound: list<string>, emptied: list<string>, deferred: list<string>}
     */
    private function run(string $markup): array
    {
        $removals = [];
        $edits = [];
        $deferred = [];
        $bound = 0;

        foreach ($this->document->indices() as $index) {
            $class = (string) ($this->document->attrs($index)['className'] ?? '');

            if (!$this->isVisible($class)) {
                $removals[$index] = ['kind' => 'hidden', 'block' => $this->describe($index, $class)];
                continue;
            }

            $bind = self::bindType($class);
            if ($bind === null) {
                continue;
            }

            if (in_array($bind, self::DEFERRED, true)) {
                $deferred[] = $this->describe($index, $class);
                continue;
            }

            if ($bind === 'social-links') {
                $edit = $this->socialLinksEdit($index);
                if ($edit !== null) {
                    $edits[] = $edit;
                    ++$bound;
                }
                continue;
            }

            $value = $this->resolve($bind);

            if ($value === null) {
                // A binding that only applies to some sites, on a site that is
                // not one of them. Leaving the pattern's placeholder would put
                // invented opening hours on a business that has none.
                if (in_array($bind, self::DROP_WHEN_EMPTY, true)) {
                    $removals[$index] = ['kind' => 'unbound', 'block' => $this->describe($index, $class)];
                }
                continue;
            }

            $edit = $this->textEdit($index, $value);
            if ($edit !== null) {
                $edits[] = $edit;
                ++$bound;
            }
        }

        foreach ($this->emptiedContainers($removals) as $index) {
            $removals[$index] = ['kind' => 'emptied', 'block' => $this->describe($index, 'every child removed')];
        }

        $removals = $this->outermost($removals);
        $cuts = [];
        foreach (array_keys($removals) as $index) {
            $span = $this->removalSpan($markup, $index);
            if ($span !== null) {
                $cuts[] = $span;
            }
        }

        return [
            'markup' => self::splice($markup, $edits, $cuts),
            'bound' => $bound,
            'hidden' => self::ofKind($removals, 'hidden'),
            'unbound' => self::ofKind($removals, 'unbound'),
            'emptied' => self::ofKind($removals, 'emptied'),
            'deferred' => $deferred,
        ];
    }

    /**
     * Whether a block's `ai-show-if-*` conditions are met.
     *
     * Any one match is enough. A block with conditions on a site that declares
     * no features is removed: a conditional block shown by default is a booking
     * form on a site that takes no bookings.
     */
    private function isVisible(string $class): bool
    {
        if (!preg_match_all('/\bai-show-if-([a-z0-9_-]+)\b/', $class, $matches)) {
            return true;
        }

        $features = array_map(
            static fn ($feature): string => strtolower(trim((string) $feature)),
            is_array($this->facts['features'] ?? null) ? $this->facts['features'] : [],
        );

        foreach ($matches[1] as $condition) {
            if (in_array(strtolower($condition), $features, true)) {
                return true;
            }
        }

        return false;
    }

    /** The value a binding resolves to, or null when the site has none. */
    private function resolve(string $bind): ?string
    {
        if (isset(self::COMPOSED[$bind])) {
            return self::lines(array_map(
                fn (string $part): string => (string) ($this->facts[$part] ?? ''),
                self::COMPOSED[$bind],
            ));
        }

        $value = $this->facts[$bind] ?? null;

        // Hours arrive either as a list or as one string the host joined, and
        // read as a list of days either way.
        if ($bind === 'hours') {
            if (is_array($value)) {
                return self::lines(array_map(static fn ($entry): string => (string) $entry, $value));
            }

            return self::lines(preg_split('/\s*;\s*|\R/', (string) $value) ?: []);
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Non-empty parts, one per line.
     *
     * Escaped here rather than by the caller, because these values are site
     * metadata someone typed and they land in markup: the `<br>` this adds has
     * to be the only markup in the result.
     *
     * @param list<string> $values
     */
    private static function lines(array $values): ?string
    {
        $parts = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '') {
                $parts[] = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $parts === [] ? null : implode('<br>', $parts);
    }

    /**
     * The binding a class names, or null when it names none this knows.
     *
     * `[\w-]+` so hyphenated bindings match: plain `\w+` stops at the hyphen
     * and binds `phone-email` to `phone`, which is a footer showing a phone
     * number where it should show a phone number and an address.
     */
    private static function bindType(string $class): ?string
    {
        if (!preg_match('/\b' . self::BIND_PREFIX . '([\w-]+)\b/', $class, $matches)) {
            return null;
        }

        $bind = $matches[1];
        $known = array_merge(
            ['address', 'email', 'phone', 'hours', 'social-links'],
            array_keys(self::COMPOSED),
            self::DEFERRED,
        );

        return in_array($bind, $known, true) ? $bind : null;
    }

    /**
     * Writing a resolved value into a block that holds text.
     *
     * A binding may also sit on a container — `ai-bind-hours` on the group
     * wrapping a heading and the hours — where there is no text to write and
     * the binding exists only to remove the group when the site has no hours.
     *
     * @return array{start: int, length: int, content: string}|null
     */
    private function textEdit(int $index, string $value): ?array
    {
        $span = BlockText::spanIn($this->document, $index);
        if ($span === null) {
            return null;
        }

        [$start, $length] = $span;
        $inner = $this->document->openingOffset($index) + $this->document->openingLength($index);

        return ['start' => $inner + $start, 'length' => $length, 'content' => $value];
    }

    /**
     * Rebuilding a `core/social-links` block from the site's own accounts.
     *
     * Each account becomes a void `core/social-link` block, which is the whole
     * saved form of one: no HTML of its own, so there is nothing to keep in
     * step. Big Sky rebuilds the parsed block's `innerContent` slots instead,
     * and caps them at the pattern's original count — a site with more
     * accounts than the pattern drew silently loses the extra ones.
     *
     * An empty list leaves the pattern's own links alone rather than emptying
     * the block.
     *
     * @return array{start: int, length: int, content: string}|null
     */
    private function socialLinksEdit(int $index): ?array
    {
        $links = is_array($this->facts['links'] ?? null) ? $this->facts['links'] : [];
        $blocks = [];

        foreach ($links as $link) {
            $service = strtolower(trim((string) ($link['type'] ?? '')));
            $url = trim((string) ($link['url'] ?? ''));

            // `website` has no matching service, so core renders it as the
            // generic chain icon with no label. Skipped, as Big Sky skips it.
            if ($service === '' || $url === '' || $service === 'website') {
                continue;
            }

            $blocks[] = BlockMarkup::serializeComment(
                'social-link',
                ['url' => $url, 'service' => $service],
                true,
            );
        }

        if ($blocks === []) {
            return null;
        }

        $children = $this->document->children($index);
        if ($children === []) {
            return null;
        }

        $start = $this->document->openingOffset($children[0]);
        $end = $this->document->endOffset($children[count($children) - 1]);
        if ($end === null) {
            return null;
        }

        return ['start' => $start, 'length' => $end - $start, 'content' => implode("\n", $blocks)];
    }

    /**
     * Containers whose visible content was entirely removed.
     *
     * A `core/buttons` with no buttons still renders its own row of padding,
     * so removing the last button leaves a gap that looks like a layout bug.
     * Only this one container, because it is the one whose emptiness shows.
     *
     * @param array<int, array{kind: string, block: string}> $removals
     * @return list<int>
     */
    private function emptiedContainers(array $removals): array
    {
        $emptied = [];

        foreach ($this->document->indices() as $index) {
            if (isset($removals[$index])
                || BlockText::coreName($this->document->name($index)) !== 'core/buttons') {
                continue;
            }

            $children = $this->document->children($index);
            if ($children === []) {
                continue;
            }

            foreach ($children as $child) {
                if (!isset($removals[$child])) {
                    continue 2;
                }
            }

            $emptied[] = $index;
        }

        return $emptied;
    }

    /**
     * Removals with every block already inside another removed block dropped.
     *
     * Removing a parent and a child both would cut the child's bytes twice,
     * the second time out of markup that had already moved. It also reports
     * two blocks gone where a reader would count one.
     *
     * @param array<int, array{kind: string, block: string}> $removals
     * @return array<int, array{kind: string, block: string}>
     */
    private function outermost(array $removals): array
    {
        $kept = [];

        foreach ($removals as $index => $removal) {
            for ($at = $this->document->parent($index); $at !== null; $at = $this->document->parent($at)) {
                if (isset($removals[$at])) {
                    continue 2;
                }
            }
            $kept[$index] = $removal;
        }

        return $kept;
    }

    /**
     * Removals of one kind, named. Kept apart because they mean different
     * things to a reader: a hidden block is a choice the site's features made,
     * an unbound one is a fact the site does not have, and an emptied
     * container is the consequence of the other two.
     *
     * @param array<int, array{kind: string, block: string}> $removals
     * @return list<string>
     */
    private static function ofKind(array $removals, string $kind): array
    {
        $named = [];
        foreach ($removals as $removal) {
            if ($removal['kind'] === $kind) {
                $named[] = $removal['block'];
            }
        }

        return $named;
    }

    /**
     * A whole block's bytes, including the blank line that separated it, so
     * removing one does not leave the gap it used to separate behind.
     *
     * @return array{start: int, length: int, content: string}|null
     */
    private function removalSpan(string $markup, int $index): ?array
    {
        if (!$this->document->isStructurallySafe($index)) {
            return null;
        }

        $start = $this->document->openingOffset($index);
        $end = $this->document->endOffset($index);
        if ($end === null) {
            return null;
        }

        $after = $end;
        while ($after < strlen($markup) && str_contains(" \t\n\r", $markup[$after])) {
            ++$after;
        }

        // A block at the end of its container has no separator after it, and
        // then the blank line before it is the one left behind.
        if ($after === $end) {
            while ($start > 0 && str_contains(" \t\n\r", $markup[$start - 1])) {
                --$start;
            }
        }

        return ['start' => $start, 'length' => $after - $start, 'content' => ''];
    }

    /** A block named the way a report should name it. */
    private function describe(int $index, string $class): string
    {
        return BlockText::coreName($this->document->name($index)) . ' (' . $class . ')';
    }

    /**
     * Edits applied to the source, last first so earlier offsets stay valid.
     *
     * Everything a cut covers is dropped before anything is applied, never
     * while applying. Writing into a block and then cutting the range it sits
     * in means cutting a range whose length was measured before the write,
     * which takes the neighbouring markup with it. That covers both a bound
     * block inside a hidden one and a removed block inside a removed parent.
     *
     * @param list<array{start: int, length: int, content: string}> $edits
     * @param list<array{start: int, length: int, content: string}> $cuts
     */
    private static function splice(string $markup, array $edits, array $cuts): string
    {
        $all = array_merge(self::outside($edits, $cuts), $cuts);
        usort($all, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($all as $edit) {
            $markup = substr_replace($markup, $edit['content'], $edit['start'], $edit['length']);
        }

        return $markup;
    }

    /**
     * The edits no cut covers.
     *
     * @param list<array{start: int, length: int, content: string}> $edits
     * @param list<array{start: int, length: int, content: string}> $cuts
     * @return list<array{start: int, length: int, content: string}>
     */
    private static function outside(array $edits, array $cuts): array
    {
        $kept = [];
        foreach ($edits as $edit) {
            foreach ($cuts as $cut) {
                if (self::covers($cut, $edit)) {
                    continue 2;
                }
            }
            $kept[] = $edit;
        }

        return $kept;
    }

    /**
     * @param array{start: int, length: int, content: string} $outer
     * @param array{start: int, length: int, content: string} $inner
     */
    private static function covers(array $outer, array $inner): bool
    {
        return $inner['start'] >= $outer['start']
            && $inner['start'] + $inner['length'] <= $outer['start'] + $outer['length'];
    }
}
