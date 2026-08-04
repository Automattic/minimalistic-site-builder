<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\MarkupSanitizer;
use Automattic\SiteBuild\Warnings;

/** Footer-specific normalization applied by FooterUnit after the shared intake. */
final class FooterMarkup
{
    /**
     * A one-page footer's dynamic site-title must not render a link back to
     * the page the visitor is already viewing. Apply the explicit attribute to
     * every generated site-title so a nested lockup cannot evade the rule.
     */
    public static function withoutSiteTitleLinks(string $markup): string
    {
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'site-title') {
                continue;
            }
            $attrs = $document->attrs($index) ?? [];
            $attrs['isLink'] = false;
            $document->setAttrs($index, $attrs);
        }
        return $document->render();
    }

    /**
     * Cap footer AI_IMAGE placeholders to non-portrait aspect ratios.
     *
     * A portrait-oriented placeholder — the `portrait` (9:16) or
     * `card-portrait` (3:4) keyword, or any numeric ratio taller than wide —
     * generates an asset that, rendered in a footer column, stretches the
     * whole band and strands blank space beside the short utility rows. The
     * documented `<img alt>` form and any mirrored block-JSON "alt" value are
     * rewritten together so block re-serialization cannot restore the
     * portrait spec. Recovered source-form placeholders are capped here too,
     * before CollectImagesStep turns their raw `AI_IMAGE:...|ratio:...` URL or
     * src into a canonical asset path. Idempotent: a capped placeholder no
     * longer matches any pattern.
     *
     * @param list<string> $notes appended to once per rewritten placeholder
     */
    public static function withoutPortraitImagePlaceholders(string $markup, array &$notes = []): string
    {
        $patterns = [
            '/(?<prefix>alt\s*=\s*(?<quote>["\'])AI_IMAGE:(?:(?!\k{quote}).)*?\|\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>\s*\k{quote})/is',
            '/(?<prefix>"alt"\s*:\s*"AI_IMAGE:(?:[^"\\\\]|\\\\.)*?\|\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>\s*")/is',
            '/(?<prefix>\bsrc\s*=\s*(?<quote>["\'])\s*AI_IMAGE:(?:(?!\k{quote}).)*?\|\s*ratio\s*:\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>(?=\s*(?:\||\k{quote})))/is',
            '/(?<prefix>\bsrc\s*=\s*(?<quote>["\'])\s*AI_IMAGE:(?:(?!\k{quote}).)*?\|\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>\s*\k{quote})/is',
            '/(?<prefix>\bsrc\s*=\s*AI_IMAGE:[^\s>"\'`=]*?\|ratio:)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>(?=\||\s|\/?>))/i',
            '/(?<prefix>\bsrc\s*=\s*AI_IMAGE:[^\s>"\'`=]*\|)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>(?=\s|\/?>))/i',
            '/(?<prefix>"(?:url|src)"\s*:\s*"\s*AI_IMAGE:(?:[^"\\\\]|\\\\.)*?\|\s*ratio\s*:\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>(?=\s*(?:\||")))/is',
            '/(?<prefix>"(?:url|src)"\s*:\s*"\s*AI_IMAGE:(?:[^"\\\\]|\\\\.)*?\|\s*)(?<ratio>card-portrait|portrait|\d+:\d+)(?<suffix>\s*")/is',
        ];
        foreach ($patterns as $pattern) {
            $markup = (string) preg_replace_callback(
                $pattern,
                static function (array $m) use (&$notes): string {
                    $ratio = strtolower($m['ratio']);
                    if (preg_match('/^(\d+):(\d+)$/', $ratio, $wh) === 1
                        && (int) $wh[1] >= (int) $wh[2]
                    ) {
                        return $m[0]; // numeric square/landscape — already footer-safe
                    }
                    $notes[] = "file='parts/footer.html'; block='AI_IMAGE placeholder'; "
                        . "authored aspect-ratio={$ratio}; delivered=square; "
                        . 'disposition=a portrait-oriented footer image stretches the band and '
                        . 'strands blank space beside the utility rows, so the placeholder was '
                        . 'capped to square';
                    return $m['prefix'] . 'square' . $m['suffix'];
                },
                $markup
            );
        }
        return $markup;
    }

    /**
     * Enforce the concrete surface shared by the footer and closing sections.
     *
     * The comment attribute is the source of truth consumed by downstream
     * layout/contrast passes. Multiple generated roots — or a single root that
     * is not a wp:group — are wrapped without dropping content, and any saved
     * root element is synchronized as well so stale generated classes/styles
     * cannot be sourced back into the block before re-serialization.
     *
     * @param list<string> $notes appended to when opaque authored styling must
     *                            be removed to make the surface enforceable
     */
    public static function withRootBackgroundColor(string $markup, string $color, array &$notes = []): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $color) !== 1) {
            throw new \InvalidArgumentException("invalid root background color slug '{$color}'");
        }

        $markup = self::withRootBackgroundColorAttrs($markup, $color, $notes);
        return self::withRootBackgroundColorSavedHtml($markup, $color, $notes);
    }

    /**
     * Enforce the surface on the root group's comment attributes, wrapping a
     * multi-root or non-group document into a single root group first.
     *
     * @param list<string> $notes
     */
    private static function withRootBackgroundColorAttrs(string $markup, string $color, array &$notes): string
    {
        $document = BlockMarkup::parse($markup);
        $roots = array_values(array_filter(
            $document->indices(),
            static fn (int $index): bool => $document->parent($index) === null
        ));
        if (
            $roots !== []
            && (count($roots) > 1 || $document->name($roots[0]) !== 'group')
        ) {
            $markup = '<!-- wp:group -->' . "\n"
                . '<div class="wp-block-group">' . "\n"
                . $markup . "\n"
                . '</div>' . "\n"
                . '<!-- /wp:group -->';
            $document = BlockMarkup::parse($markup);
            $roots = array_values(array_filter(
                $document->indices(),
                static fn (int $index): bool => $document->parent($index) === null
            ));
        }
        $root = $roots[0] ?? null;
        if ($root === null || $document->name($root) !== 'group') {
            throw new \RuntimeException('footer root is not a wp:group');
        }
        $attrs = $document->attrs($root) ?? [];

        unset($attrs['gradient']);
        if (array_key_exists('style', $attrs) && !is_array($attrs['style'])) {
            self::recordRemovedRootStyle($notes, 'style', $attrs['style'], $color);
            unset($attrs['style']);
        } elseif (is_array($attrs['style'] ?? null)) {
            unset($attrs['style']['background']);
            if (array_key_exists('color', $attrs['style']) && !is_array($attrs['style']['color'])) {
                self::recordRemovedRootStyle($notes, 'style.color', $attrs['style']['color'], $color);
                unset($attrs['style']['color']);
            } elseif (is_array($attrs['style']['color'] ?? null)) {
                unset($attrs['style']['color']['background'], $attrs['style']['color']['gradient']);
                if ($attrs['style']['color'] === []) {
                    unset($attrs['style']['color']);
                }
            }
            foreach (['css', 'variation'] as $opaqueStyle) {
                if (!array_key_exists($opaqueStyle, $attrs['style'])) {
                    continue;
                }
                self::recordRemovedRootStyle(
                    $notes,
                    "style.{$opaqueStyle}",
                    $attrs['style'][$opaqueStyle],
                    $color
                );
                unset($attrs['style'][$opaqueStyle]);
            }
            if ($attrs['style'] === []) {
                unset($attrs['style']);
            }
        }
        if (is_string($attrs['className'] ?? null)) {
            $tokens = self::withoutBackgroundClassTokens($attrs['className']);
            if ($tokens === []) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $tokens);
            }
        }
        $attrs['backgroundColor'] = $color;
        $document->setAttrs($root, $attrs);
        return $document->render();
    }

    /**
     * Synchronize the root group's saved HTML wrapper with the enforced
     * surface: background class tokens and inline background declarations are
     * rewritten on the opening tag.
     *
     * @param list<string> $notes
     */
    private static function withRootBackgroundColorSavedHtml(string $markup, string $color, array &$notes): string
    {
        $document = BlockMarkup::parse($markup);
        $root = $document->topLevel();
        if ($root === null || $document->name($root) !== 'group') {
            throw new \RuntimeException('footer root group disappeared during surface repair');
        }
        $ownHtml = $document->ownHtml($root);
        if (preg_match(
            '~\A(?:(?:\s+)|(?:<!--(?:(?!-->).)*-->))*(?<tag><[a-z][a-z0-9:-]*(?=[\s>])'
                . '(?:[^>"\']+|"[^"]*"|\'[^\']*\')*>)~is',
            $ownHtml,
            $opening,
            PREG_OFFSET_CAPTURE
        ) !== 1) {
            $withoutComments = preg_replace('~<!--(?:(?!-->).)*-->~s', '', $ownHtml);
            if (is_string($withoutComments) && trim($withoutComments) === '') {
                // A wrapperless/comment-only model response is still
                // repairable: fix-blocks will serialize the group wrapper from
                // the enforced attribute later.
                return $markup;
            }
            throw new \RuntimeException('footer root has no safe saved HTML wrapper');
        }

        $tag = $opening['tag'][0];
        $classes = self::htmlAttributes($tag, 'class');
        if ($classes !== []) {
            $tokens = self::withoutBackgroundClassTokens($classes[0]['value']);
            $tokens[] = "has-{$color}-background-color";
            $tokens[] = 'has-background';
            $classValue = implode(' ', array_values(array_unique($tokens)));
            for ($index = count($classes) - 1; $index >= 0; $index--) {
                $class = $classes[$index];
                $replacement = $index === 0
                    ? ' class="' . htmlspecialchars(
                        $classValue,
                        ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) . '"'
                    : '';
                $tag = substr_replace(
                    $tag,
                    $replacement,
                    $class['attributeOffset'],
                    strlen($class['attribute'])
                );
            }
        } else {
            $tag = substr_replace(
                $tag,
                ' class="has-' . $color . '-background-color has-background"',
                strrpos($tag, '>'),
                0
            );
        }

        $styles = self::htmlAttributes($tag, 'style');
        for ($index = count($styles) - 1; $index >= 0; $index--) {
            $style = $styles[$index];
            try {
                $styleValue = self::withoutBackgroundStyleDeclarations($style['value']);
            } catch (\RuntimeException) {
                self::recordRemovedRootStyle(
                    $notes,
                    "saved HTML style attribute[{$index}]",
                    $style['value'],
                    $color
                );
                $styleValue = '';
            }
            $replacement = $styleValue === ''
                ? ''
                : substr_replace(
                    $style['attribute'],
                    $styleValue,
                    $style['valueOffset'] - $style['attributeOffset'],
                    strlen($style['value'])
                );
            $tag = substr_replace(
                $tag,
                $replacement,
                $style['attributeOffset'],
                strlen($style['attribute'])
            );
        }

        $tagStart = $document->openingOffset($root)
            + $document->openingLength($root)
            + $opening['tag'][1];
        return substr_replace($markup, $tag, $tagStart, strlen($opening['tag'][0]));
    }

    /**
     * Locate one quoted or unquoted HTML attribute inside an already isolated
     * opening tag.
     *
     * @return list<array{
     *   attribute:string,attributeOffset:int,value:string,valueOffset:int
     * }>
     */
    private static function htmlAttributes(string $tag, string $name): array
    {
        $matches = [];
        foreach (MarkupSanitizer::openingTagAttributes($tag) as $attribute) {
            if (
                $attribute['name'] !== strtolower($name)
                || $attribute['valueStart'] === null
                || $attribute['valueEnd'] === null
            ) {
                continue;
            }
            $valueStart = $attribute['valueStart'];
            $valueEnd = $attribute['valueEnd'];
            $matches[] = [
                'attribute' => substr($tag, $attribute['start'], $attribute['end'] - $attribute['start']),
                'attributeOffset' => $attribute['start'],
                'value' => substr($tag, $valueStart, $valueEnd - $valueStart),
                'valueOffset' => $valueStart,
            ];
        }
        return $matches;
    }

    /** @param list<string> $notes */
    private static function recordRemovedRootStyle(
        array &$notes,
        string $path,
        mixed $authored,
        string $color
    ): void {
        $encoded = Warnings::value($authored);
        $notes[] = "file='parts/footer.html'; block='root wp:group'; authored {$path}={$encoded}; "
            . "delivered=removed; disposition=opaque or malformed root styling removed so the assigned "
            . "'{$color}' footer surface cannot be overridden";
    }

    /** @return list<string> */
    private static function withoutBackgroundClassTokens(string $classes): array
    {
        $classes = html_entity_decode($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return array_values(array_filter(
            preg_split('/[\x09\x0A\x0C\x0D\x20]+/', trim($classes)) ?: [],
            static fn (string $token): bool => $token !== 'has-background'
                && preg_match('/^has-[a-z0-9-]+-background-color$/', $token) !== 1
                && preg_match('/^has-[a-z0-9-]+-gradient-background$/', $token) !== 1
                && $token !== 'has-background-gradient'
        ));
    }

    /**
     * Remove CSS background declarations without splitting at semicolons in a
     * quoted string, comment, escape, or function argument.
     */
    private static function withoutBackgroundStyleDeclarations(string $style): string
    {
        $declarations = [];
        $start = 0;
        $quote = null;
        $parentheses = 0;
        $inComment = false;
        $length = strlen($style);

        for ($index = 0; $index < $length; $index++) {
            $character = $style[$index];
            if ($inComment) {
                if ($character === '*' && ($style[$index + 1] ?? '') === '/') {
                    $inComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    if ($index + 1 >= $length) {
                        throw new \RuntimeException('footer root has a malformed inline style escape');
                    }
                    $index++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '/' && ($style[$index + 1] ?? '') === '*') {
                $inComment = true;
                $index++;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '\\') {
                if ($index + 1 >= $length) {
                    throw new \RuntimeException('footer root has a malformed inline style escape');
                }
                $index++;
                continue;
            }
            if ($character === '(') {
                $parentheses++;
                continue;
            }
            if ($character === ')') {
                if ($parentheses === 0) {
                    throw new \RuntimeException('footer root has malformed inline style parentheses');
                }
                $parentheses--;
                continue;
            }
            if ($character === ';' && $parentheses === 0) {
                $declarations[] = substr($style, $start, $index - $start);
                $start = $index + 1;
            }
        }
        if ($quote !== null || $inComment || $parentheses !== 0) {
            throw new \RuntimeException('footer root has a malformed inline style');
        }
        $declarations[] = substr($style, $start);

        $kept = [];
        foreach ($declarations as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }
            if (preg_match('/^([-\w]+)\s*:/', $declaration, $property) !== 1) {
                throw new \RuntimeException('footer root has a malformed inline style declaration');
            }
            if (str_starts_with(strtolower($property[1]), 'background')) {
                continue;
            }
            $kept[] = $declaration;
        }
        return implode(';', $kept);
    }
}
