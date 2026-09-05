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
    public const ALL = ['none', 'section-badge'];

    public const DEFAULT = 'none';

    /** The one class the section prompt teaches for the badge paragraph. */
    public const BADGE_CLASS = 'section-badge';

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
            p.{$hook}.has-text-align-center {
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
            if (!in_array(self::BADGE_CLASS, $tokens, true)
                && preg_match('/\bclass="[^"]*\b' . self::BADGE_CLASS . '\b[^"]*"/', $own) !== 1) {
                continue;
            }
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $badges[] = ['index' => $index, 'start' => $document->openingOffset($index), 'end' => $end];
        }
        if ($badges === []) {
            return ['markup' => $markup, 'warnings' => []];
        }
        $committed = self::explicit($label) === 'section-badge';
        $remove = [];
        foreach ($badges as $position => $badge) {
            if (!$committed) {
                $remove[] = $badge + ['why' => 'the direction committed no section label, so the eyebrow ban applies'];
            } elseif ($isOpening) {
                $remove[] = $badge + ['why' => 'a page opening never carries a section badge'];
            } elseif ($position > 0) {
                $remove[] = $badge + ['why' => 'a section carries at most one badge; only the first was kept'];
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
            $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph." . self::BADGE_CLASS . "'; authored="
                . Warnings::value($authored) . '; delivered=removed; disposition=' . $badge['why'];
        }
        return ['markup' => $out, 'warnings' => array_reverse($warnings)];
    }
}
