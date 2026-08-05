<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Units\MarkupResult;

/** Project-free fallback for a failed non-front page opening. */
final class PageOpeningFallback
{
    /** @param array<string,mixed> $input @param array<string,mixed> $contract */
    public static function render(array $input, array $contract, string $reason = 'unusable generated page opening'): MarkupResult
    {
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_DELIVERY);
        $page = is_array($input['page'] ?? null) ? $input['page'] : [];
        $section = is_array($input['section'] ?? null) ? $input['section'] : [];
        $pageTitle = trim((string) ($page['title'] ?? ''));
        $slug = trim((string) ($section['slug'] ?? 'opening')) ?: 'opening';
        $mode = (string) ($contract['header']['mode'] ?? AboveFoldContract::MODE_STACKED);
        $surface = $mode === AboveFoldContract::MODE_OVERLAY
            ? (string) ($contract['header']['protection_token'] ?? 'contrast')
            : 'base';
        $foreground = $surface === 'contrast' ? 'base' : 'contrast';
        $attrs = [
            'anchor' => $slug,
            'backgroundColor' => $surface,
            'textColor' => $foreground,
            'layout' => ['type' => 'constrained'],
            'style' => ['spacing' => ['margin' => ['top' => '0', 'bottom' => '0']]],
        ];
        $titleMarkup = $pageTitle !== ''
            ? '<!-- wp:heading {"level":1,"fontSize":"section-title"} -->'
                . '<h1 class="wp-block-heading has-section-title-font-size">' . self::escape($pageTitle) . '</h1>'
                . '<!-- /wp:heading -->'
            : '<!-- wp:post-title {"level":1,"isLink":false,"fontSize":"section-title"} /-->';
        $markup = '<!-- wp:group ' . self::encode($attrs) . ' -->' . "\n"
            . '<div id="' . self::escape($slug) . '" class="wp-block-group has-' . self::escape($surface)
            . '-background-color has-background has-' . self::escape($foreground) . '-color has-text-color">'
            . $titleMarkup . '</div>' . "\n<!-- /wp:group -->";
        $part = 'page-' . (string) ($page['slug'] ?? '') . '--' . $slug;

        return new MarkupResult(
            markup: $markup,
            repairs: [],
            warnings: [
                "file='theme/parts/{$part}.html'; block='root wp:group'; authored=" . self::value($reason)
                    . "; delivered=readable {$mode} page-opening fallback; disposition=failed opening retained its "
                    . 'planned key and real page title instead of promoting an uncontracted sibling',
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

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
