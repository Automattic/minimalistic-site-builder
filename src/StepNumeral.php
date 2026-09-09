<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Bounded step-numeral device (frm W6c). Decorative numbers stay banned;
 * this is the one committed form in which an ordinal returns, and only on
 * a process section (steps, method, workflow, timeline). The section
 * author marks ONE paragraph per step item with the digit; the kit paints
 * it as a chip or a ghost figure; the delivery boundary removes every
 * numeral the direction did not commit, every numeral outside a process
 * section or outside the first slot of a step item, and renumbers the
 * survivors in document order so the sequence is always 1..n.
 */
final class StepNumeral
{
    public const ALL = ['none', 'chip', 'ghost'];

    public const DEFAULT = 'none';

    public const CLASS_NAME = 'step-numeral';

    /** Section type or slug words that make a section a process (frm W6c). */
    private const PROCESS_WORDS = [
        'process', 'step', 'steps', 'how-it-works', 'how', 'method', 'methodology', 'approach',
        'workflow', 'timeline', 'journey', 'phase', 'phases', 'roadmap', 'onboarding', 'stages',
    ];

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function meaning(string $numeral): string
    {
        return match ($numeral) {
            'chip'  => 'in a process section each step item may open with ONE wp:paragraph carrying'
                . ' "className":"' . self::CLASS_NAME . '" and "fontSize":"caption" whose whole text is the'
                . ' step\'s digit ("1", "2"); the build paints it as a small round chip and renumbers the'
                . ' steps in order. Everywhere else numbers stay banned',
            'ghost' => 'in a process section each step item may open with ONE wp:paragraph carrying'
                . ' "className":"' . self::CLASS_NAME . '" whose whole text is the step\'s digit ("1", "2");'
                . ' the build paints it as a large translucent figure in the heading face and renumbers'
                . ' the steps in order. Everywhere else numbers stay banned',
            default => 'no step numerals; decorative numbers are banned everywhere',
        };
    }

    /**
     * Bounded phrases a brief uses to ask for a numbered row outside a
     * process (frm PR-3w): "services listed as a numbered stack". The
     * numeral device is committed by the direction; the brief decides
     * which extra section counts as ordered.
     *
     * @var list<string>
     */
    private const STATED_NUMBERED_PHRASES = [
        'numbered stack', 'numbered list', 'numbered rows', 'numbered ledger', 'numbered items', 'numbered lines',
        'numbered services', 'numbered cards', 'numbered columns', 'numbered',
    ];

    /**
     * The clause of a brief that asks for a numbered row, or null: the
     * comma-separated item that holds the phrase.
     */
    public static function statedNumbered(string $brief): ?string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $brief) ?? $brief, 'UTF-8');
        foreach (preg_split('/[,.;:]/u', $text) ?: [] as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            foreach (self::STATED_NUMBERED_PHRASES as $phrase) {
                if (preg_match('/(?<![\p{L}-])' . preg_quote($phrase, '/') . '(?![\p{L}-])/u', $clause) === 1) {
                    return $clause;
                }
            }
        }
        return null;
    }

    /**
     * The stated numbered clause from a build's meta, the user's own words
     * first and the refined brief second, or null.
     *
     * @param array<string,mixed> $meta
     */
    public static function statedNumberedFor(array $meta): ?string
    {
        foreach (['original_prompt', 'prompt'] as $key) {
            $text = $meta[$key] ?? null;
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $clause = self::statedNumbered($text);
            if ($clause !== null) {
                return $clause;
            }
        }
        return null;
    }

    /**
     * The authoring line for a section the brief numbers (frm PR-3w), or
     * '' when the section is not stated or the direction committed no
     * numeral. The section prompt's Step numerals rule stays process-only;
     * this line names the one extra section it applies to.
     */
    public static function numberedDirective(?string $numeral, bool $stated): string
    {
        $committed = self::explicit($numeral);
        if (!$stated || $committed === null || $committed === 'none') {
            return '';
        }
        return '- Numbered row (the brief asks for a numbered stack here): this section counts as a process section for'
            . ' the Step numerals rule. Each item opens with exactly ONE `wp:paragraph` carrying'
            . ' `"className":"' . self::CLASS_NAME . '"`' . ($committed === 'chip' ? ' and `"fontSize":"caption"`' : '')
            . ' whose whole text is the item\'s digit ("1", "2", "3"). Never "01", never words.';
    }

    public static function isProcessSection(string $type, string $slug = ''): bool
    {
        $haystack = strtolower($type . ' ' . $slug);
        $tokens = preg_split('/[^a-z]+/', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (in_array($token, self::PROCESS_WORDS, true)) {
                return true;
            }
        }
        return str_contains($haystack, 'how-it-works') || str_contains($haystack, 'how it works');
    }

    public static function kitCss(?string $raw): ?string
    {
        $numeral = self::explicit($raw);
        if ($numeral === null || $numeral === 'none') {
            return null;
        }
        $hook = self::CLASS_NAME;
        if ($numeral === 'chip') {
            return <<<CSS
                /* Committed 'chip' step numeral: a small round chip with the step's
                   digit, painted by the build in the surface's own ink. */
                p.{$hook} {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    inline-size: 2.25em;
                    block-size: 2.25em;
                    margin-block: 0 0.75rem;
                    border-radius: 50%;
                    background-color: color-mix(in srgb, currentColor 10%, transparent);
                    box-shadow: inset 0 0 0 1px color-mix(in srgb, currentColor 22%, transparent);
                    font-family: var(--wp--preset--font-family--heading, inherit);
                    font-size: var(--wp--preset--font-size--caption, 0.8rem);
                    font-weight: 600;
                    font-variant-numeric: tabular-nums;
                    line-height: 1;
                    letter-spacing: 0;
                }

                CSS;
        }
        return <<<CSS
            /* Committed 'ghost' step numeral: a large translucent figure in the
               heading face above the step's heading, painted by the build. */
            p.{$hook} {
                margin-block: 0 0.25rem;
                font-family: var(--wp--preset--font-family--heading, inherit);
                font-size: var(--wp--preset--font-size--display, 3rem);
                font-weight: 700;
                font-variant-numeric: tabular-nums;
                line-height: 0.9;
                letter-spacing: -0.03em;
                opacity: 0.22;
            }

            CSS;
    }

    /**
     * @return array{markup:string,warnings:list<string>,repairs:list<array<string,mixed>>}
     */
    public static function normalize(string $markup, ?string $numeral, string $part, bool $isProcess): array
    {
        $document = BlockMarkup::parse($markup);
        $found = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'paragraph' || !$document->isStructurallySafe($index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $tokens = is_string($attrs['className'] ?? null)
                ? preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: []
                : [];
            $own = $document->ownHtml($index);
            if (!in_array(self::CLASS_NAME, $tokens, true)
                && preg_match('/\bclass="[^"]*\b' . self::CLASS_NAME . '\b[^"]*"/', $own) !== 1) {
                continue;
            }
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $text = trim(html_entity_decode(strip_tags($document->innerHtml($index)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $found[] = [
                'index' => $index,
                'start' => $document->openingOffset($index),
                'end' => $end,
                'text' => $text,
                'first' => self::isFirstInItem($document, $index),
            ];
        }
        if ($found === []) {
            return ['markup' => $markup, 'warnings' => [], 'repairs' => []];
        }
        $committed = self::explicit($numeral);
        $committed = $committed === 'none' ? null : $committed;
        $remove = [];
        $keep = [];
        foreach ($found as $item) {
            if ($committed === null) {
                $remove[] = $item + ['why' => 'the direction committed no step numeral, so the decorative-number ban applies'];
            } elseif (!$isProcess) {
                $remove[] = $item + ['why' => 'a step numeral belongs to a process section only'];
            } elseif (!$item['first']) {
                $remove[] = $item + ['why' => 'a step numeral opens its step item; anywhere else it is a decorative number'];
            } elseif (preg_match('/^\d{1,2}$/', $item['text']) !== 1) {
                $remove[] = $item + ['why' => 'a step numeral is the step\'s digit alone'];
            } else {
                $keep[] = $item;
            }
        }
        $warnings = [];
        $repairs = [];
        $out = $markup;
        foreach (array_reverse($remove) as $item) {
            $authored = substr($markup, $item['start'], $item['end'] - $item['start']);
            $length = $item['end'] - $item['start'];
            if (($out[$item['end']] ?? '') === "\n") {
                $length++;
            }
            $out = substr_replace($out, '', $item['start'], $length);
            $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph." . self::CLASS_NAME . "'; authored="
                . Warnings::value($authored) . '; delivered=removed; disposition=' . $item['why'];
        }
        $warnings = array_reverse($warnings);
        if ($keep !== []) {
            // Renumber survivors in document order so the sequence is 1..n.
            $renumbered = BlockMarkup::parse($out);
            $position = 0;
            $changed = false;
            foreach ($renumbered->indices() as $index) {
                if ($renumbered->name($index) !== 'paragraph') {
                    continue;
                }
                $own = $renumbered->ownHtml($index);
                if (preg_match('/\bclass="[^"]*\b' . self::CLASS_NAME . '\b[^"]*"/', $own) !== 1) {
                    continue;
                }
                $position++;
                if (preg_match('/^(\s*<p\b[^>]*>\s*)(\d{1,2})(\s*<\/p>\s*)$/su', $own, $m) === 1 && $m[2] !== (string) $position) {
                    $renumbered->spliceOwnHtml($index, 0, strlen($own), $m[1] . $position . $m[3]);
                    $repairs[] = [
                        'part' => $part,
                        'block' => 'paragraph.' . self::CLASS_NAME,
                        'authored' => $m[2],
                        'delivered' => (string) $position,
                        'note' => 'step numerals renumbered in document order',
                    ];
                    $changed = true;
                }
            }
            if ($changed) {
                $out = $renumbered->render();
            }
        }
        return ['markup' => $out, 'warnings' => $warnings, 'repairs' => $repairs];
    }

    /** True when the paragraph is the first child of a step item: a column or a group marked as an item or card. */
    private static function isFirstInItem(BlockMarkup $document, int $index): bool
    {
        $parent = $document->parent($index);
        if ($parent === null) {
            return false;
        }
        $children = $document->children($parent);
        if (($children[0] ?? null) !== $index) {
            return false;
        }
        $name = $document->name($parent);
        if ($name === 'column') {
            return true;
        }
        if ($name !== 'group') {
            return false;
        }
        $attrs = $document->attrs($parent) ?? [];
        $tokens = is_string($attrs['className'] ?? null)
            ? preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: []
            : [];
        foreach ($tokens as $token) {
            if ($token === ItemPattern::ITEM_MARKER || str_starts_with($token, 'card-style--') || $token === 'card-body') {
                return true;
            }
        }
        return false;
    }

    private const NUMBERED_STOPWORDS = [
        'one', 'two', 'three', 'four', 'five', 'six', 'with', 'and', 'the', 'card', 'cards', 'highlighted',
        'highlight', 'highlights', 'inverted', 'featured', 'accent', 'violet', 'purple', 'blue', 'green', 'red',
        'orange', 'yellow', 'pink', 'black', 'white', 'dark', 'light', 'row', 'grid', 'large', 'small', 'each',
    ];
    public static function clauseAppliesTo(?string $clause, array $section, bool $includePurpose = false): bool
    {
        if ($clause === null || trim($clause) === '') {
            return false;
        }
        $stems = [];
        foreach (preg_split('/[^\\p{L}]+/u', mb_strtolower($clause, 'UTF-8')) ?: [] as $word) {
            if (mb_strlen($word, 'UTF-8') >= 4 && !in_array($word, self::NUMBERED_STOPWORDS, true)) {
                $stems[] = mb_substr($word, 0, 4, 'UTF-8');
            }
        }
        if ($stems === []) {
            return false;
        }
        $haystack = mb_strtolower(implode(' ', array_map(
            static fn (string $key): string => (string) ($section[$key] ?? ''),
            $includePurpose ? ['slug', 'title', 'type', 'purpose'] : ['slug', 'title', 'type'],
        )), 'UTF-8');
        foreach (preg_split('/[^\\p{L}]+/u', $haystack) ?: [] as $word) {
            if (mb_strlen($word, 'UTF-8') >= 4 && in_array(mb_substr($word, 0, 4, 'UTF-8'), $stems, true)) {
                return true;
            }
        }
        return false;
    }
}
