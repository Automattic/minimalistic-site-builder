<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

use Automattic\SiteBuild\BlockMarkup;

/**
 * The text in a pattern that may be rewritten, located in its own markup.
 *
 * Extracted from Big Sky's `Replace_Content`, with one change that carries the
 * rest: Big Sky parses a pattern into blocks, rewrites `attributes.content`,
 * and then rebuilds each block's saved HTML from those attributes — which is
 * what `class.block-inner-html-regenerator.php` exists for, a thousand lines of
 * per-block tag reconstruction. A block type it cannot rebuild is a block whose
 * saved HTML stops matching its attributes, and WordPress shows that as a block
 * validation error on a site that generated without complaint.
 *
 * Editing the markup in place needs none of it. A slot is a byte range holding
 * the block's text, and filling one replaces those bytes: every attribute,
 * class, inline style and a button's href survive because nothing rewrites
 * them. What Big Sky needs a regenerator to preserve, this preserves by never
 * touching it.
 *
 * The price is that a block's text has to be findable in its markup, so only
 * the blocks below are slots. That narrowing is visible — a block outside the
 * list keeps the pattern's own copy — rather than silent.
 */
final class ContentSlots
{
    /** What a pattern writes on a block to keep this stage away from it. */
    private const IGNORE_CLASS = 'ai-ignore';

    /** Blocks whose position numbers the group path, for copy that coheres. */
    private const GROUPING_BLOCKS = ['core/group', 'core/cover'];

    /**
     * @param list<array<string, mixed>> $slots
     */
    private function __construct(
        private BlockMarkup $document,
        private array $slots,
    ) {
    }

    /**
     * Every slot in a page's markup, in document order.
     *
     * `$prefix` opens the slot ids. Big Sky used `md5(uniqid(mt_rand()))`, so
     * the same page produced different ids on every run and no fixture replay
     * could line up a response with the blocks it was generated for. These are
     * derived from where the block sits, so they are the same on every run and
     * mean something in a report.
     */
    public static function in(string $markup, string $prefix): self
    {
        $document = BlockMarkup::parse($markup);
        $slots = [];

        foreach ($document->indices() as $index) {
            $slot = self::slotAt($document, $index, $prefix);
            if ($slot !== null) {
                $slots[] = $slot;
            }
        }

        return new self($document, $slots);
    }

    /**
     * The slots as a content request: what the model is told about each one.
     *
     * @return list<array<string, mixed>>
     */
    public function request(): array
    {
        return array_map(
            static fn (array $slot): array => [
                'id' => $slot['id'],
                'block' => $slot['block'],
                'class' => $slot['class'],
                'group' => $slot['group'],
                'example' => $slot['text'],
                'max_words' => $slot['max_words'],
            ],
            $this->slots,
        );
    }

    /**
     * The markup with each supplied text written into its slot, and a record
     * of every slot that kept the pattern's own copy instead.
     *
     * A slot with no text in `$textById` is not an error — Big Sky continues
     * with the original content when generation fails, and so does this. It is
     * counted, because a page that kept every placeholder and reported success
     * is the failure this whole stage exists to make visible.
     *
     * @param array<string, string> $textById
     * @return array{markup: string, filled: int, kept: list<string>, flattened: list<string>}
     */
    public function fill(array $textById): array
    {
        $filled = 0;
        $kept = [];
        $flattened = [];

        foreach ($this->slots as $slot) {
            $id = (string) $slot['id'];
            $text = trim((string) ($textById[$id] ?? ''));

            if ($text === '') {
                $kept[] = $id;
                continue;
            }

            if ($slot['has_markup']) {
                $flattened[] = $id;
            }

            $this->document->spliceOwnHtml(
                (int) $slot['node'],
                (int) $slot['start'],
                (int) $slot['length'],
                htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
            ++$filled;
        }

        return [
            'markup' => $this->document->render(),
            'filled' => $filled,
            'kept' => $kept,
            'flattened' => $flattened,
        ];
    }

    /**
     * The slot at one node, or null when that block holds no rewritable text.
     *
     * @return array<string, mixed>|null
     */
    private static function slotAt(BlockMarkup $document, int $index, string $prefix): ?array
    {
        $class = (string) ($document->attrs($index)['className'] ?? '');
        if (str_contains($class, self::IGNORE_CLASS) || str_contains($class, ContentBindings::BIND_PREFIX)) {
            return null;
        }

        $span = BlockText::spanIn($document, $index);
        if ($span === null) {
            return null;
        }

        [$start, $length] = $span;
        $block = BlockText::coreName($document->name($index));
        $raw = substr($document->ownHtml($index), $start, $length);
        $text = BlockText::plainText($raw);

        return [
            'id' => $prefix . '-' . implode('-', self::path($document, $index)),
            'node' => $index,
            'start' => $start,
            'length' => $length,
            'block' => $block,
            'class' => $class,
            'group' => self::groupPath($document, $index),
            'text' => $text,
            'max_words' => self::maxWords($block, $class, $text),
            'has_markup' => strip_tags($raw) !== $raw,
        ];
    }

    /**
     * How many words a piece of copy is.
     *
     * `str_word_count()` is ASCII: it reads "Únete ahora al club" as five
     * words, so a Spanish placeholder measures longer than it is and the limit
     * derived from it lets a sentence through. Whitespace-separated tokens are
     * what "how wide is this on the page" actually means, in any language.
     */
    public static function wordCount(string $text): int
    {
        return preg_match_all('/\S+/u', $text) ?: 0;
    }

    /**
     * How long the replacement may be.
     *
     * The pattern's own copy is the measure: it was designed to fit there, and
     * a two-word button that comes back a sentence long breaks the layout it
     * was chosen for. Big Sky's floors for empty text carry over.
     */
    private static function maxWords(string $block, string $class, string $text): int
    {
        $words = self::wordCount($text);
        $isLink = str_contains($class, 'link');

        if ($words === 0) {
            $words = match (true) {
                $block === 'core/heading' => 3,
                $block === 'core/button', $isLink => 2,
                default => 0,
            };
        }

        return $isLink ? min($words ?: 2, 2) : $words;
    }

    /**
     * Which group or cover each ancestor is, numbered from one.
     *
     * Slots sharing a prefix sit in the same part of the page, which is how the
     * model knows a heading and the paragraph under it describe one thing.
     * Big Sky concatenated the numbers, so "11" was both the eleventh group and
     * the first inside the first; the separator here keeps them apart.
     */
    private static function groupPath(BlockMarkup $document, int $index): string
    {
        $path = [];

        foreach (self::ancestors($document, $index) as $ancestor) {
            if (in_array(BlockText::coreName($document->name($ancestor)), self::GROUPING_BLOCKS, true)) {
                $path[] = self::position($document, $ancestor);
            }
        }

        return implode('.', $path);
    }

    /**
     * The node's position in the tree, outermost first. Two slots in different
     * patterns cannot collide, because a page's sections are concatenated
     * before this runs and every top-level index is distinct.
     *
     * @return list<int>
     */
    private static function path(BlockMarkup $document, int $index): array
    {
        $path = [];

        foreach (self::ancestors($document, $index) as $ancestor) {
            $path[] = self::position($document, $ancestor);
        }
        $path[] = self::position($document, $index);

        return $path;
    }

    /**
     * Every ancestor of a node, outermost first.
     *
     * @return list<int>
     */
    private static function ancestors(BlockMarkup $document, int $index): array
    {
        $ancestors = [];

        for ($at = $document->parent($index); $at !== null; $at = $document->parent($at)) {
            array_unshift($ancestors, $at);
        }

        return $ancestors;
    }

    /** Which child of its parent this node is, numbered from one. */
    private static function position(BlockMarkup $document, int $index): int
    {
        $parent = $document->parent($index);
        $siblings = $parent === null
            ? array_values(array_filter(
                $document->indices(),
                static fn (int $i): bool => $document->parent($i) === null,
            ))
            : $document->children($parent);

        return (int) array_search($index, $siblings, true) + 1;
    }
}
