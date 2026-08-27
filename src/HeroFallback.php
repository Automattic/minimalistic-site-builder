<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\Units\MarkupResult;

/** Reviewed topology-family fallback for the contract-critical front hero. */
final class HeroFallback
{
    /** @param array<string,mixed> $input @param array<string,mixed> $contract */
    public static function render(array $input, array $contract, string $reason = 'unusable generated hero'): MarkupResult
    {
        AboveFoldContract::assertPhase($contract, AboveFoldContract::PHASE_DELIVERY);
        $recipe = (string) $contract['recipe'];
        $metadata = HeroComposition::metadata($recipe);
        $family = (string) ($metadata['fallback_family'] ?? 'typographic');
        $section = is_array($input['section'] ?? null) ? $input['section'] : [];
        $page = is_array($input['page'] ?? null) ? $input['page'] : [];
        $siteSpec = self::decoded($input['site_spec'] ?? []);
        $title = self::visitorTitle($siteSpec, $page, $section);
        $slug = trim((string) ($section['slug'] ?? 'hero')) ?: 'hero';
        $mode = (string) ($contract['header']['mode'] ?? AboveFoldContract::MODE_STACKED);
        $surface = $mode === AboveFoldContract::MODE_OVERLAY
            ? (string) ($contract['header']['protection_token'] ?? 'contrast')
            : (string) ($section['background'] ?? 'base');
        if (!in_array($surface, ['base', 'contrast'], true)) {
            $surface = 'base';
        }
        $foreground = $surface === 'contrast' ? 'base' : 'contrast';
        $mobile = (string) $contract['mobile_transformation'];
        if (!in_array($mobile, $metadata['mobile_transformations'], true)) {
            throw new \RuntimeException(
                "aboveFold.json mobile transformation '{$mobile}' is incompatible with recipe '{$recipe}'"
            );
        }
        // assertPhase() above already held this to the recipe's own aspects,
        // so the fallback stamps it without re-checking (BIGR-925).
        $aspect = (string) $contract['media_aspect'];
        $rootClasses = 'hero-composition--' . $recipe . ' hero-mobile--' . $mobile
            . ' hero-media--' . $aspect . ' hero-fallback--' . $family;
        $attrs = [
            'anchor' => $slug,
            'className' => $rootClasses,
            'backgroundColor' => $surface,
            'textColor' => $foreground,
            'layout' => ['type' => 'constrained'],
            'style' => ['spacing' => ['margin' => ['top' => '0', 'bottom' => '0']]],
        ];
        $classes = 'wp-block-group ' . $rootClasses
            . " has-{$surface}-background-color has-background has-{$foreground}-color has-text-color";

        $body = $title === null
            ? '<!-- wp:site-title {"level":1,"isLink":false,"fontSize":"display"} /-->'
            : '<!-- wp:heading {"level":1,"fontSize":"display"} -->'
                . '<h1 class="wp-block-heading has-display-font-size">' . self::escape($title) . '</h1>'
                . '<!-- /wp:heading -->';
        $action = is_array($contract['primary_action'] ?? null) ? $contract['primary_action'] : null;
        if (is_array($action)
            && is_string($action['label'] ?? null)
            && is_string($action['destination'] ?? null)
            && trim($action['label']) !== ''
            && trim($action['destination']) !== ''
        ) {
            $body .= "\n" . '<!-- wp:buttons --><div class="wp-block-buttons">'
                . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="'
                . self::escape($action['destination']) . '">' . self::escape($action['label']) . '</a></div><!-- /wp:button -->'
                . '</div><!-- /wp:buttons -->';
        }

        $copy = '<!-- wp:group {"className":"hero-composition__copy","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group hero-composition__copy">' . $body . '</div><!-- /wp:group -->';
        $content = match ($family) {
            'cover' => '<!-- wp:group {"align":"wide","className":"hero-fallback__cover-stage","layout":{"type":"constrained"}} -->'
                . '<div class="wp-block-group alignwide hero-fallback__cover-stage">' . $copy . '</div><!-- /wp:group -->',
            'foreground-split' => '<!-- wp:columns {"align":"wide","className":"hero-fallback__split"} -->'
                . '<div class="wp-block-columns alignwide hero-fallback__split">'
                . '<!-- wp:column {"width":"62%"} --><div class="wp-block-column" style="flex-basis:62%">'
                . $copy . '</div><!-- /wp:column -->'
                . '<!-- wp:column {"width":"38%"} --><div class="wp-block-column" style="flex-basis:38%">'
                . '<!-- wp:group {"className":"hero-fallback__field","layout":{"type":"constrained"}} -->'
                . '<div class="wp-block-group hero-fallback__field"></div><!-- /wp:group -->'
                . '</div><!-- /wp:column --></div><!-- /wp:columns -->',
            default => '<!-- wp:group {"align":"wide","className":"hero-fallback__poster","layout":{"type":"constrained"}} -->'
                . '<div class="wp-block-group alignwide hero-fallback__poster">' . $copy . '</div><!-- /wp:group -->',
        };

        $markup = '<!-- wp:group ' . self::encode($attrs) . ' -->' . "\n"
            . '<div id="' . self::escape($slug) . '" class="' . $classes . '">'
            . $content . '</div>' . "\n<!-- /wp:group -->";
        $part = 'page-' . (string) ($page['slug'] ?? '') . '--' . $slug;

        return new MarkupResult(
            markup: $markup,
            repairs: [],
            warnings: [
                "file='theme/parts/{$part}.html'; block='root wp:group'; authored=" . self::value($reason)
                    . "; delivered={$family} topology-family fallback with recipe marker {$recipe}; disposition="
                    . 'unusable generated hero was replaced at its existing part key without inventing media, copy, or an action; '
                    . 'an already validated action, when present, was preserved exactly',
            ],
        );
    }

    /** @param array<string,mixed> $site @param array<string,mixed> $page @param array<string,mixed> $section */
    private static function visitorTitle(array $site, array $page, array $section): ?string
    {
        foreach ([$site['title'] ?? null, $site['name'] ?? null, $page['title'] ?? null, $section['title'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'hero') {
                return trim($value);
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('hero fallback site_spec must be an object or JSON object');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('hero fallback site_spec JSON must decode to an object');
        }
        return $decoded;
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
