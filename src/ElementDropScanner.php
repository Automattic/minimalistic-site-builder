<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;

/**
 * Reports original source DOM elements that do not survive with the same tag.
 *
 * A fallback row means upstream surfaced the source element explicitly, so it
 * is not dropped. A marker on the same serialized tag means the element
 * survived. A selector-bearing asset or the marker on a different tag records
 * replacement of the original element, even when its content remains usable.
 */
final class ElementDropScanner
{
    /**
     * @param list<array{selector:string,tag:string,marker:string}> $probes
     * @param array<int,array<string,mixed>> $fallbacks
     * @param array<int,array<string,mixed>> $assets
     * @return list<array{selector:string,tag:string,disposition:'replaced'|'dropped',evidence:string}>
     */
    public function scan(array $probes, array $fallbacks, array $assets, string $serializedMarkup): array
    {
        $fallbackSelectors = $this->selectors($fallbacks);
        $assetSelectors = $this->selectors($assets);
        $dropped = [];

        foreach ($probes as $probe) {
            $selector = trim($probe['selector']);
            $tag = strtolower(trim($probe['tag']));
            $marker = trim($probe['marker']);
            if ($selector === '' || $tag === '' || $marker === '') {
                throw new \InvalidArgumentException('Element drop probes require selector, tag, and marker.');
            }

            if (isset($fallbackSelectors[$selector])) {
                continue;
            }

            $markerTags = $this->markerTags($serializedMarkup, $marker);
            if (isset($markerTags[$tag])) {
                continue;
            }

            $key = $selector . "\0" . $tag;
            $replacementEvidence = isset($assetSelectors[$selector])
                ? $selector
                : array_key_first($markerTags);
            $dropped[$key] = [
                'selector' => $selector,
                'tag' => $tag,
                'disposition' => $replacementEvidence === null ? 'dropped' : 'replaced',
                'evidence' => $replacementEvidence ?? '',
            ];
        }

        $dropped = array_values($dropped);
        usort(
            $dropped,
            static fn (array $left, array $right): int =>
                [$left['selector'], $left['tag']] <=> [$right['selector'], $right['tag']],
        );
        return $dropped;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,true>
     */
    private function selectors(array $rows): array
    {
        $selectors = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['selector', 'source_selector'] as $key) {
                $value = $row[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $selectors[trim($value)] = true;
                }
            }
        }
        return $selectors;
    }

    /** @return array<string,true> */
    private function markerTags(string $markup, string $marker): array
    {
        $tags = [];
        $visit = function (HtmlNode $node) use (&$visit, &$tags, $marker): void {
            if ($node->isElement()) {
                $tokens = preg_split(
                    '/\s+/',
                    trim((string) $node->attribute('class')),
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [];
                if (in_array($marker, $tokens, true)) {
                    $tags[strtolower((string) $node->tagName())] = true;
                }
            }
            foreach ($node->elementChildren() as $child) {
                $visit($child);
            }
        };
        $visit(HtmlFragment::parse($markup)->root());
        return $tags;
    }
}
