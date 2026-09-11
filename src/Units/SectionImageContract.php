<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

use Automattic\SiteBuild\BlockMarkup;
use Automattic\SiteBuild\MediaReferenceRemoval;
use Automattic\SiteBuild\Warnings;

/** Enforce the planned asset count and retain safe text. */
final class SectionImageContract
{
    public static function enforce(string $markup, string $part, mixed $count, array &$repairs, array &$warnings): string
    {
        if ($count === null) {
            return $markup;
        }
        $limit = max(0, min(12, (int) $count));
        $sources = self::sources($markup);
        $document = BlockMarkup::parse($markup);
        $backgrounds = [];
        foreach ($document->indices() as $index) {
            if ($document->name($index) === 'cover') {
                $source = $document->attrs($index)['url'] ?? '';
                if (isset($sources[$source])) {
                    $backgrounds[$source] = $sources[$source];
                }
            }
        }
        // Keep content images before decorative cover images.
        $ordered = array_diff_key($sources, $backgrounds) + $backgrounds;
        foreach (array_slice(array_keys($ordered), $limit) as $source) {
            $position = MediaReferenceRemoval::position($markup, $source);
            $document = BlockMarkup::parse($markup);
            $path = 'image';
            $safe = true;
            foreach ($document->indices() as $index) {
                $end = $document->endOffset($index);
                if ($position !== null && $document->openingOffset($index) <= $position && ($end === null || $position < $end)) {
                    $path = $document->name($index) . '[' . $index . ']';
                    if ($document->name($index) === 'image' && $document->children($index) !== []) {
                        $safe = false;
                    }
                }
            }
            $result = $safe ? MediaReferenceRemoval::removeSourceWithReport($markup, $source)
                : ['markup' => $markup, 'removedCaptions' => []];
            $changed = $result['markup'] !== $markup;
            $markup = $result['markup'];
            $removed = MediaReferenceRemoval::position($markup, $source) === null;
            $warnings[] = "file='theme/parts/{$part}.html'; block='{$path}'; authored=" . Warnings::value($source)
                . '; delivered=' . ($removed ? 'removed' : Warnings::value($source))
                . "; disposition=" . ($removed ? "removed the asset above the planned image count {$limit}"
                    : "retained the unsafe media boundary above the planned image count {$limit}; repair this asset");
            foreach ($result['removedCaptions'] as $caption) {
                $warnings[] = "file='theme/parts/{$part}.html'; block='caption'; authored=" . Warnings::value($caption['text'])
                    . '; delivered=removed; disposition=removed the caption for the removed asset';
            }
            if ($changed) {
                $repairs[] = ['code' => 'planned-image-count', 'part' => $part, 'block' => $path,
                    'authored' => $source, 'delivered' => $removed ? 'removed' : 'partial', 'disposition' => 'repaired'];
            }
        }
        $actual = count(self::sources($markup));
        if ($actual < $limit) {
            $warnings[] = "file='theme/parts/{$part}.html'; block='section'; authored=planned image count {$limit};"
                . " delivered={$actual}; disposition=retained the section; add the absent planned subjects";
        }
        return $markup;
    }

    /** @return array<string,true> */
    private static function sources(string $markup): array
    {
        preg_match_all('~<img\b[^>]*(?<![-\w])src\s*=\s*(["\'])(.*?)\1~is', $markup, $matches);
        $sources = [];
        foreach ($matches[2] as $source) {
            if ($source !== '') {
                $sources[html_entity_decode($source, ENT_QUOTES | ENT_HTML5)] = true;
            }
        }
        $document = BlockMarkup::parse($markup);
        foreach ($document->indices() as $index) {
            $attrs = $document->attrs($index) ?? [];
            $source = match ($document->name($index)) {
                'cover' => $attrs['url'] ?? null,
                'media-text' => $attrs['mediaUrl'] ?? null,
                default => null,
            };
            if (is_string($source) && $source !== '') {
                $sources[$source] = true;
            }
        }
        return $sources;
    }
}
