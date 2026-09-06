<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Page-level call-to-action budget: the buttons a section may keep, and the
 * text link every other action becomes.
 *
 * The page plan places exactly one action on the front page, its hero, and
 * asks every other section for none; the design direction reserves the accent
 * for "where the reader must act". Section authors are told neither, so they
 * add a button wherever a link would do — the PepeneBun build (2026-09-04)
 * shipped eleven accent buttons on one home page, ten of them unplanned, three
 * of them saying "Full seed data". An accent that appears eleven times has
 * stopped meaning "act here".
 *
 * apply() keeps the first $keep `wp:button` blocks of a part in document
 * order and turns each remaining one into a `wp:paragraph` carrying the same
 * link: nothing the visitor could click is lost, only the button construction
 * around it. A card row's bottom-alignment hook (`cta-bottom`) rides along so
 * the link still sits at the card's foot, and a centered buttons row yields a
 * centered paragraph. A part whose block structure is unsafe to edit is
 * refused with an exception; the step boundary turns that into a warning and
 * delivers the part unchanged. Idempotent: a budgeted part budgets to itself.
 * Pure — unit-testable.
 */
final class CtaBudget
{
    /** The class the theme styles on a demoted action. */
    public const LINK_CLASS = 'text-action';

    /**
     * @return array{markup:string,kept:int,demoted:int,notes:list<string>}
     */
    public static function apply(string $markup, int $keep, ?string $preferLabel = null): array
    {
        if (!str_contains($markup, 'wp:button')) {
            return ['markup' => $markup, 'kept' => 0, 'demoted' => 0, 'notes' => []];
        }
        $doc = BlockMarkup::parse($markup);
        if (
            $doc->unclosedIndices() !== []
            || $doc->hasMismatchedDelimiters()
            || $doc->hasMalformedDelimiters()
        ) {
            throw new \RuntimeException('malformed block structure');
        }

        $buttons = [];
        foreach ($doc->indices() as $i) {
            if ($doc->name($i) !== 'button') {
                continue;
            }
            if (!$doc->isStructurallySafe($i)) {
                throw new \RuntimeException('button block has no safe boundary');
            }
            $parent = $doc->parent($i);
            $row = $parent !== null && $doc->name($parent) === 'buttons' && $doc->isStructurallySafe($parent)
                ? $parent
                : null;
            $buttons[] = ['index' => $i, 'row' => $row];
        }

        $keepSet = [];
        $want = is_string($preferLabel) ? self::buttonLabel($preferLabel) : '';
        if ($want !== '' && $keep > 0) {
            foreach ($buttons as $k => $button) {
                if (self::buttonLabel($doc->innerHtml($button['index'])) === $want) {
                    $keepSet[$k] = true;
                    break;
                }
            }
        }
        foreach ($buttons as $k => $_) {
            if (count($keepSet) >= $keep) {
                break;
            }
            $keepSet[$k] = true;
        }
        $demote = array_values(array_diff_key($buttons, $keepSet));
        $kept = count($keepSet);
        if ($demote === []) {
            return ['markup' => $markup, 'kept' => $kept, 'demoted' => 0, 'notes' => []];
        }

        /** @var array<int|string,array{row:?int,indices:list<int>}> $byRow demoted buttons grouped by their wp:buttons row */
        $byRow = [];
        foreach ($demote as $button) {
            $key = $button['row'] ?? 'solo:' . $button['index'];
            $byRow[$key]['row'] = $button['row'];
            $byRow[$key]['indices'][] = $button['index'];
        }

        $ops = [];
        $notes = [];
        foreach ($byRow as ['row' => $row, 'indices' => $indices]) {
            $rowAttrs = $row === null ? [] : ($doc->attrs($row) ?? []);
            $paragraphs = [];
            foreach ($indices as $i) {
                [$paragraph, $label] = self::paragraphFor($doc->innerHtml($i), $rowAttrs);
                $paragraphs[] = $paragraph;
                $notes[] = 'demoted "' . $label . '" to a text action';
            }
            $rowButtons = $row === null ? [] : array_values(array_filter(
                $doc->children($row),
                static fn (int $child): bool => $doc->name($child) === 'button',
            ));
            if ($row !== null && count($indices) === count($rowButtons)) {
                // Every button of the row goes: the row itself becomes the
                // paragraphs, so no empty buttons wrapper is left behind.
                $start = $doc->openingOffset($row);
                $ops[] = ['start' => $start, 'length' => (int) $doc->endOffset($row) - $start, 'content' => implode("\n", $paragraphs)];
                continue;
            }
            foreach ($indices as $i) {
                $start = $doc->openingOffset($i);
                $ops[] = ['start' => $start, 'length' => (int) $doc->endOffset($i) - $start, 'content' => ''];
            }
            $after = (int) $doc->endOffset($row ?? $indices[0]);
            $ops[] = ['start' => $after, 'length' => 0, 'content' => "\n" . implode("\n", $paragraphs)];
        }

        usort($ops, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        $out = $markup;
        foreach ($ops as $op) {
            $out = substr_replace($out, $op['content'], $op['start'], $op['length']);
        }
        $out = (string) preg_replace("/\n{3,}/", "\n\n", $out);

        return ['markup' => $out, 'kept' => $kept, 'demoted' => count($demote), 'notes' => $notes];
    }

    /** Case-folded, tag-stripped label used to match a planned action. */
    private static function buttonLabel(string $inner): string
    {
        if (preg_match('#<a\b[^>]*>(.*?)</a>#is', $inner, $m) === 1) {
            $inner = $m[1];
        }
        return mb_strtolower(self::plainLabel($inner));
    }

    /** The visible text of a label, tags and entities resolved, whitespace collapsed. */
    private static function plainLabel(string $markup): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', PlainText::fromMarkup($markup)));
    }

    /**
     * One demoted button as a paragraph block holding its link.
     *
     * @param array<mixed> $rowAttrs the containing wp:buttons attributes
     * @return array{0:string,1:string} the block markup and the plain label
     */
    private static function paragraphFor(string $buttonInner, array $rowAttrs): array
    {
        $classes = [self::LINK_CLASS];
        $rowClass = is_string($rowAttrs['className'] ?? null) ? $rowAttrs['className'] : '';
        if (in_array('cta-bottom', preg_split('/\s+/', trim($rowClass)) ?: [], true)) {
            $classes[] = 'cta-bottom';
        }
        $attrs = [];
        $justify = $rowAttrs['layout']['justifyContent'] ?? null;
        if ($justify === 'center') {
            $attrs['align'] = 'center';
        }
        $attrs['className'] = implode(' ', $classes);

        if (preg_match('#<a\b([^>]*)>(.*?)</a>#is', $buttonInner, $m) === 1) {
            $label = trim($m[2]);
            $anchor = '<a';
            foreach (['href', 'target', 'rel'] as $name) {
                $attr = MarkupScan::tagAttribute($m[1], $name);
                if ($attr !== null) {
                    $anchor .= ' ' . $name . '="' . $attr[0] . '"';
                }
            }
            $inner = $anchor === '<a'
                ? $label
                : $anchor . '>' . $label . '</a>';
        } else {
            $label = trim(strip_tags($buttonInner));
            $inner = $label;
        }
        $plain = self::plainLabel($label);

        $block = BlockMarkup::serializeComment('paragraph', $attrs, false) . "\n"
            . '<p class="' . implode(' ', $classes) . '">' . $inner . '</p>' . "\n"
            . '<!-- /wp:paragraph -->';
        return [$block, $plain];
    }
}
