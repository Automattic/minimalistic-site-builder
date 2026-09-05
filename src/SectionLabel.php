<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Bounded section-label device: how a non-hero section names itself above
 * its heading. Eyebrows stay banned by default; this is the one committed
 * form in which a small label may return (frm W6a).
 *
 * `section-badge` is a pill with a dot and one or two words, set once per
 * section, never in a page opening. The section author emits a marked
 * paragraph; the kit paints it; the delivery boundary strips any badge the
 * direction did not commit, any badge inside an opening, and every badge
 * after the first in a section.
 */
final class SectionLabel
{
    public const ALL = ['none', 'section-badge', 'side-label'];

    public const DEFAULT = 'none';

    /** The one class the section prompt teaches for the badge paragraph. */
    public const BADGE_CLASS = 'section-badge';

    /** The split label column device (frm W6b): a label in the leading column beside the section's content. */
    public const SIDE_CLASS = 'side-label';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function meaning(string $label): string
    {
        return match ($label) {
            'section-badge' => 'each non-hero section may open its heading stack with ONE pill badge naming its topic'
                . ' in one or two words: a wp:paragraph with "className":"' . self::BADGE_CLASS . '" and'
                . ' "fontSize":"caption", placed directly above the section heading. The build paints the pill,'
                . ' its hairline and its dot; author no colour, border, background or uppercase on it',
            'side-label'    => 'each non-hero section may be a split whose leading column carries the topic label:'
                . ' ONE wp:columns ("align":"wide") with a leading wp:column ("width":"25%") holding ONE'
                . ' wp:paragraph with "className":"' . self::SIDE_CLASS . '" and "fontSize":"caption" (one or two'
                . ' words naming the topic), and a trailing wp:column ("width":"75%") holding the whole heading'
                . ' stack and body. The build paints the label and keeps it in view while the column scrolls;'
                . ' author no colour, letter-spacing or uppercase on it, and never place it above a heading',
            default         => 'no section labels; a heading is the first text line of every section',
        };
    }

    /** Build-owned execution of the badge. `none` ships no kit. */
    public static function kitCss(?string $raw): ?string
    {
        $label = self::explicit($raw);
        if ($label === null || $label === 'none') {
            return null;
        }
        if ($label === 'side-label') {
            $hook = self::SIDE_CLASS;
            return <<<CSS
                /* Committed 'side-label' section label: the topic in the leading
                   column of a split, beside the section's heading stack. Written
                   by the build, never by a model. Caption size, quietly tracked
                   uppercase, the heading ink at reduced strength, one accent dot;
                   the column keeps the label in view while the content scrolls. */
                p.{$hook} {
                    display: flex;
                    align-items: center;
                    gap: 0.6em;
                    margin: 0;
                    font-size: var(--wp--preset--font-size--caption, 0.8rem);
                    font-weight: 500;
                    line-height: 1.3;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                    opacity: 0.78;
                }
                p.{$hook}::before {
                    content: "";
                    flex: none;
                    inline-size: 0.5em;
                    block-size: 0.5em;
                    border-radius: 50%;
                    background-color: var(--wp--preset--color--accent, currentColor);
                }
                @media (min-width: 782px) {
                    .wp-block-column:has(> p.{$hook}) {
                        position: sticky;
                        top: calc(var(--header-safe-top, 0px) + 1.5rem);
                        align-self: start;
                    }
                }

                CSS;
        }
        $hook = self::BADGE_CLASS;
        return <<<CSS
            /* Committed 'section-badge' section label: one pill with a dot above a
               section heading. Written by the build, never by a model. The pill
               keeps the heading ink; only the dot takes the accent. */
            p.{$hook} {
                display: inline-flex;
                align-items: center;
                gap: 0.5em;
                inline-size: fit-content;
                max-inline-size: 100%;
                margin-block: 0 0.75rem;
                margin-inline: 0 auto !important;
                padding: 0.3em 0.85em;
                border-radius: var(--shape-radius-pill, 9999px);
                box-shadow: inset 0 0 0 1px color-mix(in srgb, currentColor 18%, transparent);
                font-size: var(--wp--preset--font-size--caption, 0.8rem);
                font-weight: 500;
                line-height: 1.2;
                letter-spacing: 0;
                text-transform: none;
            }
            /* A badge follows its heading's alignment: centered when the
               author centered the badge, or when the heading right after it
               is centered (a centered stack centers every element). */
            p.{$hook}.has-text-align-center,
            p.{$hook}:has(+ .wp-block-heading.has-text-align-center) {
                margin-inline: auto !important;
            }
            p.{$hook}.has-text-align-right {
                margin-inline: auto 0 !important;
            }
            p.{$hook}::before {
                content: "";
                flex: none;
                inline-size: 0.5em;
                block-size: 0.5em;
                border-radius: 50%;
                background-color: var(--wp--preset--color--accent, currentColor);
            }

            CSS;
    }

    /**
     * Delivery-boundary guard for one section part. Removes every badge the
     * commitment does not cover: all of them when the direction committed
     * `none` or the part is a page opening, every one after the first
     * otherwise. Removals are recorded as durable warnings so a drifting
     * section author is visible in warnings.json.
     *
     * @return array{markup:string,warnings:list<string>}
     */
    public static function normalize(string $markup, ?string $label, string $part, bool $isOpening = false): array
    {
        $document = BlockMarkup::parse($markup);
        $badges = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'paragraph' || !$document->isStructurallySafe($index)) {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $tokens = is_string($attrs['className'] ?? null)
                ? preg_split('/\s+/', trim($attrs['className']), -1, PREG_SPLIT_NO_EMPTY) ?: []
                : [];
            $own = $document->ownHtml($index);
            $device = null;
            foreach ([self::BADGE_CLASS => 'section-badge', self::SIDE_CLASS => 'side-label'] as $class => $name) {
                if (in_array($class, $tokens, true)
                    || preg_match('/\bclass="[^"]*\b' . $class . '\b[^"]*"/', $own) === 1) {
                    $device = $name;
                    break;
                }
            }
            if ($device === null) {
                continue;
            }
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $badges[] = [
                'index' => $index,
                'start' => $document->openingOffset($index),
                'end' => $end,
                'device' => $device,
                'split' => $device === 'side-label' && self::inLeadingColumn($document, $index),
            ];
        }
        if ($badges === []) {
            return ['markup' => $markup, 'warnings' => []];
        }
        $committed = self::explicit($label);
        $committed = $committed === 'none' ? null : $committed;
        $remove = [];
        $kept = 0;
        foreach ($badges as $badge) {
            $class = $badge['device'] === 'side-label' ? self::SIDE_CLASS : self::BADGE_CLASS;
            if ($committed === null) {
                $remove[] = $badge + ['why' => 'the direction committed no section label, so the eyebrow ban applies'];
            } elseif ($badge['device'] !== $committed) {
                $remove[] = $badge + ['why' => "the direction committed {$committed}, not {$class}; an uncommitted label is an eyebrow"];
            } elseif ($isOpening) {
                $remove[] = $badge + ['why' => $badge['device'] === 'side-label'
                    ? 'a page opening never carries a side label'
                    : 'a page opening never carries a section badge'];
            } elseif ($badge['device'] === 'side-label' && !$badge['split']) {
                $remove[] = $badge + ['why' => 'a side label lives in the leading column of a split; above a heading it is an eyebrow'];
            } elseif ($kept > 0) {
                $remove[] = $badge + ['why' => $badge['device'] === 'side-label'
                    ? 'a section carries at most one side label; only the first was kept'
                    : 'a section carries at most one badge; only the first was kept'];
            } else {
                $kept++;
            }
        }
        if ($remove === []) {
            return ['markup' => $markup, 'warnings' => []];
        }
        $warnings = [];
        $out = $markup;
        foreach (array_reverse($remove) as $badge) {
            $authored = substr($markup, $badge['start'], $badge['end'] - $badge['start']);
            $length = $badge['end'] - $badge['start'];
            // Take the trailing newline with the block so no blank line is left.
            if (($out[$badge['end']] ?? '') === "\n") {
                $length++;
            }
            $out = substr_replace($out, '', $badge['start'], $length);
            $class = $badge['device'] === 'side-label' ? self::SIDE_CLASS : self::BADGE_CLASS;
            $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph." . $class . "'; authored="
                . Warnings::value($authored) . '; delivered=removed; disposition=' . $badge['why'];
        }
        return ['markup' => $out, 'warnings' => array_reverse($warnings)];
    }

    /**
     * True when the paragraph is a direct child of the FIRST column of a
     * wp:columns row with at least two columns: the split the side-label
     * device is defined by (frm W6b).
     */
    private static function inLeadingColumn(BlockMarkup $document, int $index): bool
    {
        $column = $document->parent($index);
        if ($column === null || $document->name($column) !== 'column') {
            return false;
        }
        $row = $document->parent($column);
        if ($row === null || $document->name($row) !== 'columns') {
            return false;
        }
        $columns = array_values(array_filter(
            $document->children($row),
            static fn (int $child): bool => $document->name($child) === 'column',
        ));
        return count($columns) >= 2 && $columns[0] === $column;
    }
}
