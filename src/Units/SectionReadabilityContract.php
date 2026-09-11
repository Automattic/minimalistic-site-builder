<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Warnings;
use Automattic\SiteBuild\MarkupScan;

/** Repair text scale and absent section headings without changes to authored text. */
final class SectionReadabilityContract
{
    public static function enforce(string $markup, array $input, string $part, array &$repairs, array &$warnings): string
    {
        $theme = $input['theme_json'] ?? [];
        $theme = is_string($theme) ? json_decode($theme, true) : $theme;
        $sizes = [];
        foreach ($theme['settings']['typography']['fontSizes'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['slug'] ?? null) && is_string($entry['size'] ?? null)) {
                preg_match_all('/([0-9.]+)\s*(rem|em|px)/', (string) $entry['size'], $matches, PREG_SET_ORDER);
                if ($matches !== []) {
                    $sizes[$entry['slug']] = max(array_map(static fn ($match) => (float) $match[1] * ($match[2] === 'px' ? 1 : 16), $matches));
                }
            }
        }
        $document = BlockMarkup::parse($markup);
        $hasSectionHeading = false;
        $hasItemHeading = false;
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            $attrs = $document->attrs($index) ?? [];
            if ($name === 'heading') {
                $level = (int) ($attrs['level'] ?? 2);
                if (preg_match('/<h([1-6])\b/i', $document->ownHtml($index), $match)) {
                    $level = (int) $match[1];
                }
                $hasSectionHeading = $hasSectionHeading || $level <= 2;
                $hasItemHeading = $hasItemHeading || $level > 2;
            }
            $slug = $attrs['fontSize'] ?? null;
            $text = trim(html_entity_decode(strip_tags($document->innerHtml($index)), ENT_QUOTES | ENT_HTML5));
            if ($name === 'paragraph' && $slug !== null && !is_string($slug)) {
                $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph[{$index}]'; authored=" . Warnings::value($slug)
                    . '; delivered=unchanged; disposition=retained the malformed fontSize; repair the paragraph size';
                continue;
            }
            if ($name !== 'paragraph' || mb_strlen($text) <= 120
                || ($sizes[$slug ?? ''] ?? 0) < 20 || ($sizes['body'] ?? 0) < 16 || $sizes['body'] >= 20
                || isset($attrs['style']['typography']['fontSize'])
            ) {
                continue;
            }
            if (!$document->isStructurallySafe($index)) {
                $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph[{$index}]'; authored=" . Warnings::value($slug)
                    . '; delivered=unchanged; disposition=retained the unsafe text boundary; repair the paragraph size';
                continue;
            }
            $own = $document->ownHtml($index);
            $tag = MarkupScan::wrapperTag($own, 0);
            $changed = $tag !== null && preg_match('/^\s*<p\b/i', $tag)
                ? self::bodyTag($tag, $slug) : null;
            if ($changed === null) {
                $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph[{$index}]'; authored=" . Warnings::value($slug)
                    . '; delivered=unchanged; disposition=retained the ambiguous paragraph wrapper or inline size; repair the paragraph size';
                continue;
            }
            $attrs['fontSize'] = 'body';
            $document->setAttrs($index, $attrs);
            $document->spliceOwnHtml($index, 0, strlen($tag), $changed);
            $warnings[] = "file='theme/parts/{$part}.html'; block='paragraph[{$index}]'; authored=" . Warnings::value($slug)
                . '; delivered="body"; disposition=reduced the long paragraph to the body scale; retained all text';
            $repairs[] = ['code' => 'long-paragraph-body-scale', 'part' => $part, 'block' => "paragraph[{$index}]",
                'authored' => $slug, 'delivered' => 'body', 'disposition' => 'repaired'];
        }
        $markup = $document->render();
        $title = trim((string) ($input['section']['title'] ?? ''));
        if (!$hasSectionHeading && $hasItemHeading && $title !== '' && ($input['section']['role'] ?? '') !== 'hero') {
            $document = BlockMarkup::parse($markup);
            $root = $document->topLevel();
            $tag = $root === null ? null : MarkupScan::wrapperTag($document->ownHtml($root), 0);
            if ($root !== null && $document->name($root) === 'group' && $document->isStructurallySafe($root)
                && $tag !== null && preg_match('/^\s*<div\b/i', $tag)) {
                $heading = '<!-- wp:heading --><h2 class="wp-block-heading">'
                    . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</h2><!-- /wp:heading -->';
                $document->spliceOwnHtml($root, strlen($tag), 0, $heading);
                $markup = $document->render();
                $repairs[] = ['code' => 'planned-section-heading', 'part' => $part, 'block' => 'heading',
                    'authored' => 'absent h2', 'delivered' => $title, 'disposition' => 'repaired'];
            } else {
                $warnings[] = "file='theme/parts/{$part}.html'; block='section'; authored=absent h2; delivered=unchanged;"
                    . ' disposition=retained the unsafe section boundary; add the planned heading ' . Warnings::value($title);
            }
        }
        return $markup;
    }
    /** Return a complete body-scale wrapper or retain an ambiguous wrapper. */
    private static function bodyTag(string $tag, string $slug): ?string
    {
        $style = MarkupScan::tagAttribute($tag, 'style');
        if ($style !== null) {
            $declarations = MarkupScan::parseInlineStyle($style[0]);
            $kept = [];
            foreach ($declarations as $declaration) {
                if ($declaration['property'] === 'font-size') {
                    if (!preg_match('/^\s*[0-9.]+(?:px|rem|em)\s*(?:!important)?\s*$/i', $declaration['value'] ?? '')) {
                        return null;
                    }
                    continue;
                }
                // Preserve complex CSS for the later CSS repair boundary.
                if (preg_match('/[\\\\"\'()]/', preg_replace('/var\(--[a-z0-9-]+\)/i', '', $declaration['segment']) ?? $declaration['segment'])) {
                    return null;
                }
                $kept[] = $declaration['segment'];
            }
            $tag = substr_replace($tag, implode(';', $kept), $style[1], strlen($style[0]));
        } elseif (preg_match('/\sstyle\s*=/i', $tag)) {
            return null;
        }
        $class = MarkupScan::tagAttribute($tag, 'class');
        if ($class === null) {
            if (preg_match('/\sclass\s*=/i', $tag)) {
                return null;
            }
            return substr_replace($tag, ' class="has-body-font-size"', -1, 0);
        }
        $tokens = preg_split('/\s+/', trim($class[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, static fn ($token) => !preg_match('/^has-[a-z0-9-]+-font-size$/', $token)));
        $tokens[] = 'has-body-font-size';
        return substr_replace($tag, implode(' ', $tokens), $class[1], strlen($class[0]));
    }

}
