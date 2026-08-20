<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Optional site-wide CSS device. One reviewed utility, used on at most one
 * band per page. Motifs that are not in this list are not promises.
 *
 * Selectors target the marked section root so a nested heading is not
 * required — a 1px rule on a nested h2 is easy to miss.
 */
final class Device
{
    public const ALL = ['none', 'hairline-rule', 'section-numeral', 'stamp'];

    public const DEFAULT = 'none';

    /** Every device class starts with this, so a pass can find them all. */
    public const CLASS_PREFIX = 'device--';

    public static function explicit(mixed $raw): ?string
    {
        return BoundedChoice::explicit($raw, self::ALL);
    }

    public static function className(?string $device): ?string
    {
        $device = self::explicit($device);
        if ($device === null || $device === 'none') {
            return null;
        }
        return self::CLASS_PREFIX . $device;
    }

    public static function kitCss(?string $device): ?string
    {
        $device = self::explicit($device);
        if ($device === null || $device === 'none') {
            return null;
        }

        $css = match ($device) {
            'hairline-rule' => <<<CSS
                .device--hairline-rule {
                    box-shadow: inset 0 1px 0 0 currentColor;
                }

                CSS,
            'section-numeral' => <<<CSS
                body {
                    counter-reset: device-folio;
                }
                .device--section-numeral {
                    counter-increment: device-folio;
                    position: relative;
                    padding-left: 4.5rem;
                }
                .device--section-numeral::before {
                    content: counter(device-folio, decimal-leading-zero);
                    position: absolute;
                    left: var(--wp--preset--spacing--md, 1.5rem);
                    top: var(--wp--preset--spacing--lg, 3rem);
                    font-size: var(--wp--preset--font-size--caption);
                    font-family: var(--wp--preset--font-family--accent, var(--wp--preset--font-family--heading));
                    letter-spacing: 0.16em;
                    color: currentColor;
                    opacity: 0.65;
                }

                CSS,
            'stamp' => <<<CSS
                .device--stamp {
                    position: relative;
                }
                .device--stamp::after {
                    content: "";
                    position: absolute;
                    top: 1.5rem;
                    right: 1.5rem;
                    width: 3.25rem;
                    height: 3.25rem;
                    border: 2px solid currentColor;
                    transform: rotate(-8deg);
                    pointer-events: none;
                    opacity: 0.55;
                }
                /* At narrow widths the mark and the heading were sharing the
                   same corner: at 320px the heading ran to x=296 and the stamp
                   sat at x=236-300, overlapping vertically too. Below the
                   content measure it stops floating and takes its own row. */
                @media (max-width: 480px) {
                    .device--stamp::after {
                        position: static;
                        display: block;
                        margin: 0 0 1rem auto;
                    }
                }

                CSS,
            default => null,
        };
        if ($css === null) {
            return null;
        }

        return "/* Committed '{$device}' device. Written by the build, never by a model. */\n"
            . $css;
    }
}
