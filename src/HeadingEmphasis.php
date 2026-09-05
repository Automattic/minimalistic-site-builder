<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared bounded vocabulary and deterministic execution for heading
 * emphasis: how ONE clause inside a heading is set apart from the rest.
 *
 * The reference corpus carries its heading personality in exactly this
 * device: a muted clause beside a strong one (two-tone), one word swapped
 * into the italic accent face (italic-word), or a highlighter band behind
 * two words (highlight). The model marks the clause with the `emph` class;
 * this kit paints it, so the markup never carries a colour, a face or a
 * background of its own.
 */
final class HeadingEmphasis
{
    public const ALL = ['none', 'two-tone', 'italic-word', 'highlight'];

    public const DEFAULT = 'none';

    /** The one inline hook the section and hero prompts teach. */
    public const CLASS_NAME = 'emph';

    /**
     * Two-tone keeps 70% of the heading ink. The theme floor puts `contrast`
     * on `base` at 4.5:1 or better and heading ink is display-scale (3:1
     * floor), so the muted clause stays readable wherever the heading is.
     */
    public const TWO_TONE_INK_SHARE = 70;

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    /** What the model must mark, per commitment; the prompts quote this. */
    public static function meaning(string $emphasis): string
    {
        return match ($emphasis) {
            'two-tone'    => 'the heading is one line of two tones: wrap the QUIETER clause (the longer, explanatory'
                . ' half) in <span class="' . self::CLASS_NAME . '">…</span> and leave the strong clause bare;'
                . ' the build mutes the wrapped clause to ' . self::TWO_TONE_INK_SHARE . '% of the heading ink',
            'italic-word' => 'wrap ONE to THREE key words in <span class="' . self::CLASS_NAME . '">…</span>;'
                . ' the build sets them in italic, in the accent face when the direction commits one',
            'highlight'   => 'wrap ONE to THREE key words in <span class="' . self::CLASS_NAME . '">…</span>;'
                . ' the build draws a translucent accent highlighter band behind them',
            default       => 'no heading emphasis; headings are one tone, one face',
        };
    }

    /**
     * Build-owned execution. `none` and unknown values ship no kit. Every
     * rule keys on the heading block so a stray span in a paragraph or a
     * button paints nothing.
     */
    public static function kitCss(?string $raw): ?string
    {
        $emphasis = self::explicit($raw);
        if ($emphasis === null || $emphasis === 'none') {
            return null;
        }
        $hook = self::CLASS_NAME;
        $share = self::TWO_TONE_INK_SHARE;
        $rules = match ($emphasis) {
            'two-tone' => <<<CSS
                .wp-block-heading .{$hook} {
                    color: color-mix(in srgb, currentColor {$share}%, transparent);
                    font-weight: inherit;
                }

                CSS,
            'italic-word' => <<<CSS
                .wp-block-heading .{$hook} {
                    font-family: var(--wp--preset--font-family--accent, inherit);
                    font-style: italic;
                    font-weight: inherit;
                }

                CSS,
            'highlight' => <<<CSS
                .wp-block-heading .{$hook} {
                    padding-inline: 0.08em;
                    background-image: linear-gradient(
                        transparent 58%,
                        color-mix(in srgb, var(--wp--preset--color--accent, currentColor) 38%, transparent) 58%
                    );
                    background-repeat: no-repeat;
                    -webkit-box-decoration-break: clone;
                    box-decoration-break: clone;
                }

                CSS,
        };
        return "/* Committed '{$emphasis}' heading emphasis. The model marks one clause per\n"
            . "   heading with the `{$hook}` class; this kit paints it. Written by the build,\n"
            . "   never by a model. */\n" . $rules;
    }
}
