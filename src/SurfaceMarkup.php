<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;

/** Limit textures to one optional section per page. Preserve all section content. */
final class SurfaceMarkup
{
    /**
     * $eligible excludes the hero, shared parts, and templates.
     *
     * @return array{markup:string, warnings:list<string>}
     */
    public static function sanitize(string $markup, ?string $surface, bool $eligible, bool &$used): array
    {
        if (!str_contains($markup, Surface::CLASS_PREFIX)) {
            return ['markup' => $markup, 'warnings' => []];
        }
        $doc = BlockMarkup::parse($markup);
        $root = $doc->topLevel();
        $committed = Surface::className($surface);
        $warnings = [];
        $keep = null;
        $reason = 'only one plain section root may use the texture';
        if ($root !== null) {
            $attrs = $doc->attrs($root) ?? [];
            $htmlRoots = HtmlFragment::parse($markup)->root()->elementChildren();
            $htmlRoot = HtmlFragment::parse($doc->ownHtml($root))->root()->elementChildren()[0] ?? null;
            $tokens = self::tokens($attrs['className'] ?? null);
            $tokens = array_unique(array_merge($tokens, self::tokens($htmlRoot?->attribute('class'))));
            $reason = match (true) {
                $committed === null => 'the direction selects no texture',
                !$eligible => 'the hero and shared parts remain plain',
                $used => 'one section on this page already uses the texture',
                $doc->name($root) !== 'group', !$doc->isStructurallySafe($root),
                    $doc->hasMalformedDelimiters(), $doc->hasMismatchedDelimiters(),
                    count(array_filter($doc->indices(), fn (int $i): bool => $doc->parent($i) === null)) !== 1
                    => 'the section needs one complete group root',
                count($htmlRoots) !== 1 || $htmlRoot === null || !in_array($htmlRoot->tagName(), ['div', 'section'], true)
                    => 'the texture requires a section container',
                isset($attrs['style']['background']['backgroundImage']),
                    preg_match('/background(?:-image)?\s*:[^;]*(?:url\(|gradient\()/i', $htmlRoot?->attribute('style') ?? '') === 1
                    => 'the section already has a background image',
                str_contains($markup, Device::CLASS_PREFIX)
                    => 'the section already has a decorative device',
                preg_match('/<!--\s+wp:(?:table|details|navigation)\b|<(?:table|form|nav)\b/i', $markup) === 1
                    => 'tables, forms, navigation, and disclosure sections remain plain',
                !in_array($committed, $tokens, true) => 'the section does not select the committed texture',
                default => null,
            };
            if ($reason === null) {
                $keep = $committed;
            }
        }

        foreach ($doc->indices() as $i) {
            $attrs = $doc->attrs($i) ?? [];
            $tokens = self::tokens($attrs['className'] ?? null);
            $kept = self::filter($tokens, $i === $root ? $keep : null);
            foreach (array_diff($tokens, $kept) as $token) {
                $warnings[] = 'path=' . $doc->name($i) . '[' . $i . '].className; authored=' . $token
                    . '; delivered=removed; disposition=' . ($reason ?? 'only the section root may use the committed texture');
            }
            if ($tokens !== $kept) {
                if ($kept === []) {
                    unset($attrs['className']);
                } else {
                    $attrs['className'] = implode(' ', $kept);
                }
                $doc->setAttrs($i, $attrs);
            }
        }
        $out = $doc->render();
        $html = HtmlFragment::parse($out);
        $rootElement = $html->root()->elementChildren()[0] ?? null;
        $edits = [];
        foreach ($html->querySelectorAll('[class]') as $element) {
            $tokens = self::tokens($element->attribute('class'));
            $kept = self::filter($tokens, $element === $rootElement ? $keep : null);
            if ($tokens === $kept) {
                continue;
            }
            foreach (array_diff($tokens, $kept) as $token) {
                $warnings[] = 'path=html/' . $element->tagName() . '@' . $element->startOffset()
                    . '.class; authored=' . $token . '; delivered=removed; disposition='
                    . ($reason ?? 'only the section root may use the committed texture');
            }
            $start = $element->startOffset();
            $length = $element->innerStartOffset() - $start;
            $tag = substr($out, $start, $length);
            // Match complete attributes so a quoted value cannot imitate a class attribute.
            $tag = preg_replace_callback(
                '/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/',
                static fn (array $m): string => strtolower($m[1]) === 'class'
                    ? 'class="' . htmlspecialchars(implode(' ', $kept), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                    : $m[0],
                $tag,
            );
            $edits[] = [$start, $length, $tag];
        }
        foreach (array_reverse($edits) as [$start, $length, $tag]) {
            $out = substr_replace($out, $tag, $start, $length);
        }
        if ($keep !== null) {
            $used = true;
        }
        return ['markup' => $out, 'warnings' => $warnings];
    }

    /** @return list<string> */
    private static function tokens(mixed $value): array
    {
        return is_string($value) ? (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
    }

    /** @param list<string> $tokens @return list<string> */
    private static function filter(array $tokens, ?string $keep): array
    {
        return array_values(array_filter($tokens, static fn (string $token): bool =>
            !str_starts_with($token, Surface::CLASS_PREFIX) || $token === $keep));
    }
}
