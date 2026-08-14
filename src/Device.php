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

    public static function explicit(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $device = strtolower(trim($raw));
        return in_array($device, self::ALL, true) ? $device : null;
    }

    public static function className(?string $device): ?string
    {
        $device = self::explicit($device);
        if ($device === null || $device === 'none') {
            return null;
        }
        return 'device--' . $device;
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
                    box-shadow: inset 0 3px 0 0 var(--wp--preset--color--accent);
                }

                CSS,
            'section-numeral' => <<<CSS
                .device--section-numeral {
                    counter-reset: device-folio;
                    position: relative;
                    padding-left: 4.5rem;
                }
                .device--section-numeral::before {
                    counter-increment: device-folio;
                    content: counter(device-folio, decimal-leading-zero);
                    position: absolute;
                    left: var(--wp--preset--spacing--md, 1.5rem);
                    top: var(--wp--preset--spacing--lg, 3rem);
                    font-size: var(--wp--preset--font-size--caption);
                    font-family: var(--wp--preset--font-family--accent, var(--wp--preset--font-family--heading));
                    letter-spacing: 0.16em;
                    color: var(--wp--preset--color--accent);
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
                    border: 2px solid var(--wp--preset--color--accent);
                    transform: rotate(-8deg);
                    pointer-events: none;
                    opacity: 0.85;
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
