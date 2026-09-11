<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\Warnings;

/** Repair text scale and absent section headings without changes to authored text. */
final class SectionReadabilityContract
{
    public static function enforce(string $markup, array $input, string $part, array &$repairs, array &$warnings): string
    {
        $theme = $input['theme_json'] ?? [];
        $theme = is_string($theme) ? json_decode($theme, true) : $theme;
        $sizes = [];
        foreach ($theme['settings']['typography']['fontSizes'] ?? [] as $entry) {
            if (isset($entry['slug'], $entry['size'])) {
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
            $attrs['fontSize'] = 'body';
            $document->setAttrs($index, $attrs);
            $own = $document->ownHtml($index);
            $changed = preg_replace_callback('/^\s*<p\b[^>]*>/i',
                static fn ($match) => str_replace('has-' . $slug . '-font-size', 'has-body-font-size', $match[0]), $own) ?? $own;
            if ($changed !== $own) {
                $document->spliceOwnHtml($index, 0, strlen($own), $changed);
            }
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
            if ($root !== null && $document->name($root) === 'group' && $document->isStructurallySafe($root)
                && preg_match('/<div\b[^>]*>/', $document->ownHtml($root), $match, PREG_OFFSET_CAPTURE)) {
                $heading = '<!-- wp:heading --><h2 class="wp-block-heading">'
                    . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</h2><!-- /wp:heading -->';
                $document->spliceOwnHtml($root, $match[0][1] + strlen($match[0][0]), 0, $heading);
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
}
