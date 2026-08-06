<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Units\MarkupResult;

/** Project-free, mode-aware fallback for an unusable generated header. */
final class HeaderFallback
{
    /** @param array<string,mixed> $input @param array<string,mixed> $contract */
    public static function render(array $input, array $contract, string $reason = 'unusable generated header'): MarkupResult
    {
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_DELIVERY);
        $header = $contract['header'];
        $mode = (string) $header['mode'];
        $archetype = (string) $header['archetype'];
        $foreground = (string) $header['foreground_token'];
        $attrs = [
            'className' => trim(($mode === 'overlay' ? 'header-overlay ' : '') . 'header-archetype--' . $archetype),
            'textColor' => $foreground,
            'layout' => ['type' => 'constrained'],
            'style' => [
                'spacing' => [
                    'padding' => [
                        'top' => 'var:preset|spacing|sm',
                        'bottom' => 'var:preset|spacing|sm',
                    ],
                ],
            ],
        ];
        if ($mode !== 'overlay') {
            $attrs['backgroundColor'] = (string) $header['protection_token'];
        }
        $classes = [
            'wp-block-group',
            'has-' . $foreground . '-color',
            'has-text-color',
            'header-archetype--' . $archetype,
        ];
        if ($mode === 'overlay') {
            $classes[] = 'header-overlay';
        } else {
            $surface = (string) $attrs['backgroundColor'];
            $classes[] = 'has-' . $surface . '-background-color';
            $classes[] = 'has-background';
        }

        // The title rides in an align:wide row so even worst-case chrome
        // shares the page's wide band: a bare site-title in a constrained
        // root sits at contentSize while every section row sits at wideSize,
        // which reads as a misaligned header (BIGR-778).
        $row = [
            'align' => 'wide',
            'layout' => [
                'type' => 'flex',
                'flexWrap' => 'nowrap',
                'justifyContent' => 'space-between',
                'verticalAlignment' => 'center',
            ],
        ];
        $markup = '<!-- wp:group ' . self::encode($attrs) . ' -->' . "\n"
            . '<div class="' . implode(' ', $classes) . '">'
            . '<!-- wp:group ' . self::encode($row) . ' -->' . "\n"
            . '<div class="wp-block-group alignwide">'
            . '<!-- wp:site-title {"textColor":"' . self::escapeJson($foreground) . '"} /-->'
            . '</div>' . "\n" . '<!-- /wp:group -->'
            . '</div>' . "\n<!-- /wp:group -->";

        return new MarkupResult(
            markup: $markup,
            repairs: [],
            warnings: [
                "file='theme/parts/header.html'; block='part root'; authored=" . self::value($reason)
                    . "; delivered=mode-aware {$mode}/{$archetype} header fallback; disposition=unusable generated "
                    . 'header was replaced with readable deterministic chrome while the rest of the site was preserved',
            ],
        );
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function value(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function escapeJson(string $value): string
    {
        return trim((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"');
    }
}
