<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\Units\GeneratedMarkup;

/**
 * Enforce the reviewed hero copy budget at block boundaries.
 *
 * Generated copy is intentionally repaired after primary-action reconciliation:
 * one headline, one supporting paragraph, and the one authoritative action may
 * survive. Every other copy/action block is removed independently and reported
 * as delivered-value loss; unrelated media and sibling layout bytes are never
 * rewritten.
 */
final class HeroCopyBudget
{
    /**
     * Remove or unwrap zero-action rows without applying the copy budget.
     *
     * Transformed HTML-first heroes are not generated against the legacy
     * primary-action contract, but a prior repair may still leave a
     * wp:buttons block with no direct wp:button child. A standard saved-HTML
     * shell is removed while every child block and raw inner byte survives;
     * an unprovable shell loses only its block-comment boundary.
     *
     * @return array{markup:string,warnings:list<string>}
     */
    public static function removeEmptyButtonsWrappers(string $markup, string $part): array
    {
        $warnings = [];
        while (true) {
            $document = BlockMarkup::parse($markup);
            $candidate = null;
            $ordinal = 0;
            foreach ($document->indices() as $index) {
                if ($document->name($index) !== 'buttons') {
                    continue;
                }
                $ordinal++;
                $end = $document->endOffset($index);
                if ($end === null) {
                    continue;
                }
                $children = $document->children($index);
                if (array_filter(
                    $children,
                    static fn (int $child): bool => $document->name($child) === 'button',
                ) !== []) {
                    continue;
                }

                $inner = $document->innerHtml($index);
                $unwrapped = self::unwrapSavedButtonsHtml($inner);
                $replacement = $unwrapped ?? $inner;
                $offset = $document->openingOffset($index);
                $candidate = [
                    'offset' => $offset,
                    'end' => $end,
                    'path' => "wp:buttons[{$ordinal}]",
                    'markup' => substr($markup, $offset, $end - $offset),
                    'replacement' => $replacement,
                    'saved_wrapper_removed' => $unwrapped !== null,
                ];
                break;
            }
            if ($candidate === null) {
                return ['markup' => $markup, 'warnings' => $warnings];
            }

            $markup = substr_replace(
                $markup,
                $candidate['replacement'],
                $candidate['offset'],
                $candidate['end'] - $candidate['offset'],
            );
            $warnings[] = self::zeroButtonWrapperWarning($candidate, $part);
        }
    }

    /**
     * Unwrap one proven closed saved-HTML div while preserving every inner
     * and adjacent inert byte. Null means only block comments may be removed.
     */
    private static function unwrapSavedButtonsHtml(string $inner): ?string
    {
        $element = null;
        foreach (HtmlFragment::parse($inner)->children() as $node) {
            if ($node->isElement()) {
                if ($element !== null) {
                    return null;
                }
                $element = $node;
                continue;
            }
            if ($node->isText() && trim($node->rawHtml()) !== '') {
                return null;
            }
        }
        if ($element === null || $element->tagName() !== 'div') {
            return null;
        }
        $classes = preg_split('/\s+/', trim($element->attribute('class') ?? '')) ?: [];
        if (!in_array('wp-block-buttons', $classes, true)) {
            return null;
        }
        $closing = substr(
            $inner,
            $element->innerEndOffset(),
            $element->endOffset() - $element->innerEndOffset(),
        );
        if (preg_match('~\A</\s*div\s*>\z~i', $closing) !== 1) {
            return null;
        }
        return substr($inner, 0, $element->startOffset())
            . $element->rawInnerHtml()
            . substr($inner, $element->endOffset());
    }

    /**
     * @param array{label:string,intent:string,destination:string}|null $primaryAction
     * @return array{markup:string,warnings:list<string>}
     */
    public static function enforce(
        string $markup,
        ?array $primaryAction,
        string $part,
    ): array {
        $document = BlockMarkup::parse($markup);
        $ordinals = [];
        $text = [];
        $buttons = [];
        $actionOwnedText = [];

        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            $ordinals[$name] = ($ordinals[$name] ?? 0) + 1;
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }

            if (in_array($name, ['heading', 'paragraph'], true)) {
                if (self::insideActionRegion($document, $index)) {
                    // Text nested into a control belongs to that action's
                    // removal boundary. It cannot occupy the hero's headline
                    // or standfirst slot independently of the button.
                    $actionOwnedText[$index] = true;
                } elseif (self::insideCopyRegion($document, $index)) {
                    $attrs = $document->attrs($index) ?? [];
                    $offset = $document->openingOffset($index);
                    $text[] = [
                        'index' => $index,
                        'name' => $name,
                        'level' => $name === 'heading' ? self::headingLevel($attrs['level'] ?? 2) : null,
                        'path' => "wp:{$name}[{$ordinals[$name]}]",
                        'offset' => $offset,
                        'end' => $end,
                        'markup' => substr($markup, $offset, $end - $offset),
                        'authored' => self::readingText($document->innerHtml($index)),
                        'raw_survivor' => self::hasRawNonTextPayload($document, $index),
                    ];
                }
            }

            if ($name === 'button') {
                $offset = $document->openingOffset($index);
                $block = substr($markup, $offset, $end - $offset);
                $ownBlock = self::withoutChildBlocks($document, $markup, $index);
                $buttons[] = [
                    'index' => $index,
                    'path' => "wp:button[{$ordinals[$name]}]",
                    'offset' => $offset,
                    'end' => $end,
                    'markup' => $block,
                    'authored' => self::readingText($document->innerHtml($index)),
                    'href' => self::buttonHref($ownBlock),
                    'raw_survivor' => self::hasRawNonTextPayload($document, $index),
                    'primary' => $primaryAction !== null
                        && GeneratedMarkup::containsPrimaryAction(
                            $ownBlock,
                            $primaryAction,
                        ),
                ];
            }
        }

        $keepHeading = null;
        foreach ($text as $candidate) {
            if ($candidate['name'] === 'heading'
                && $candidate['level'] === 1
                && $candidate['authored'] !== ''
            ) {
                $keepHeading = $candidate['index'];
                break;
            }
        }
        if ($keepHeading === null) {
            foreach ($text as $candidate) {
                if ($candidate['name'] === 'heading' && $candidate['authored'] !== '') {
                    // A missing H1 remains a residual topology defect, but
                    // retaining one authored heading is safer than deleting
                    // the hero's only prospective headline.
                    $keepHeading = $candidate['index'];
                    break;
                }
            }
        }
        if ($keepHeading === null) {
            foreach ($text as $candidate) {
                if ($candidate['name'] === 'heading') {
                    // Structural fallback only when every generated heading
                    // is empty: keep one boundary for the residual topology
                    // warning rather than deleting the entire headline slot.
                    $keepHeading = $candidate['index'];
                    break;
                }
            }
        }

        $headlineEnd = null;
        foreach ($text as $candidate) {
            if ($candidate['index'] === $keepHeading) {
                $headlineEnd = $candidate['end'];
                break;
            }
        }

        // Prefer the first paragraph already authored after the retained
        // headline. A plain pre-H1 line is the support paragraph only when no
        // post-headline standfirst exists; headlineFirstHeroCopy can then move
        // that sole block without changing its bytes. This ordering prevents
        // budget enforcement from retaining an ambiguous pre-H1 line while
        // deleting the model's correctly placed standfirst.
        $keepParagraph = null;
        if ($headlineEnd !== null) {
            foreach ($text as $candidate) {
                if ($candidate['name'] === 'paragraph' && $candidate['offset'] >= $headlineEnd) {
                    if ($candidate['authored'] === '') {
                        continue;
                    }
                    $keepParagraph = $candidate['index'];
                    break;
                }
            }
        }
        foreach ($text as $candidate) {
            if ($keepParagraph === null
                && $candidate['name'] === 'paragraph'
                && $candidate['authored'] !== ''
            ) {
                $keepParagraph = $candidate['index'];
                break;
            }
        }
        if ($keepParagraph === null) {
            foreach ($text as $candidate) {
                if ($candidate['name'] === 'paragraph') {
                    $keepParagraph = $candidate['index'];
                    break;
                }
            }
        }

        $keepButton = null;
        foreach ($buttons as $candidate) {
            if ($candidate['primary']) {
                $keepButton = $candidate['index'];
                break;
            }
        }

        $removals = [];
        foreach ($text as $candidate) {
            $keep = $candidate['name'] === 'heading'
                ? $candidate['index'] === $keepHeading
                : $candidate['index'] === $keepParagraph;
            if (!$keep) {
                $removals[] = $candidate + ['kind' => 'text'];
            }
        }
        foreach ($buttons as $candidate) {
            if ($candidate['index'] !== $keepButton) {
                $removals[] = $candidate + ['kind' => 'button'];
            }
        }
        // Action-owned text is not independently deleted, but when its button
        // is removed it is part of that complete action boundary rather than
        // content selected to survive the hero budget.
        $removalIndices = array_fill_keys(array_merge(
            array_column($removals, 'index'),
            array_keys($actionOwnedText),
        ), true);
        foreach ($removals as &$removal) {
            $removal['safe'] = !($removal['raw_survivor'] ?? false)
                && !self::hasSurvivingDescendant(
                    $document,
                    $removal['index'],
                    $removalIndices,
                );
        }
        unset($removal);

        // Safety is a property of the whole nested removal transaction. An
        // unsafe descendant must freeze its containing candidate so the outer
        // edit cannot erase raw/media payload; that newly unsafe ancestor must
        // in turn freeze every contained candidate. Iterate to the fixed-point
        // closure instead of relying on one direction through the tree.
        do {
            $changed = false;
            $unsafeCandidates = array_column(array_values(array_filter(
                $removals,
                static fn (array $removal): bool => !$removal['safe'],
            )), 'index');
            foreach ($removals as &$removal) {
                if (!$removal['safe']) {
                    continue;
                }
                foreach ($unsafeCandidates as $unsafe) {
                    if (self::isDescendantOf($document, $removal['index'], $unsafe)
                        || self::isDescendantOf($document, $unsafe, $removal['index'])
                    ) {
                        $removal['safe'] = false;
                        $changed = true;
                        break;
                    }
                }
            }
            unset($removal);
        } while ($changed);

        $unsafeCandidates = array_column(array_values(array_filter(
            $removals,
            static fn (array $removal): bool => !$removal['safe'],
        )), 'index');

        $buttonWrappers = array_values(array_filter(
            self::emptyButtonWrappers($document, $markup, $removals),
            static function (array $wrapper) use ($document, $unsafeCandidates): bool {
                foreach ($unsafeCandidates as $unsafe) {
                    if (self::isDescendantOf($document, $wrapper['index'], $unsafe)) {
                        return false;
                    }
                }
                return true;
            },
        ));
        $textWrappers = self::dedicatedTextWrappers($document, $markup, $removals);
        if ($removals === [] && $buttonWrappers === [] && $textWrappers === []) {
            $headlineWarning = self::headlineTopologyWarning($document, $markup, $part);
            return [
                'markup' => $markup,
                'warnings' => $headlineWarning === null ? [] : [$headlineWarning],
            ];
        }

        // Generated block signatures can be structurally balanced while still
        // nesting leaf blocks. Merge overlapping safe targets before editing
        // so a child removal can never invalidate its parent's original byte
        // span. A malformed target that contains any surviving block is left
        // intact: enforcing its budget boundary must not discard unrelated
        // media, layout, or the one copy/action block selected to survive.
        $ranges = self::mergedRanges(array_merge(array_map(
            static fn (array $removal): array => [$removal['offset'], $removal['end']],
            array_values(array_filter(
                $removals,
                static fn (array $removal): bool => $removal['safe'],
            )),
        ), array_map(
            static fn (array $wrapper): array => [$wrapper['offset'], $wrapper['end']],
            $buttonWrappers,
        ), array_map(
            static fn (array $wrapper): array => [$wrapper['offset'], $wrapper['end']],
            $textWrappers,
        )));
        foreach (array_reverse($ranges) as [$start, $end]) {
            $markup = substr_replace($markup, '', $start, $end - $start);
        }

        $deliveredPaths = self::pathsByOffset(BlockMarkup::parse($markup));
        $warnings = [];
        foreach ($removals as $removal) {
            $authored = $removal['kind'] === 'button'
                ? [
                    'label' => $removal['authored'],
                    'href' => $removal['href'],
                    'markup' => $removal['markup'],
                ]
                : (($removal['raw_survivor'] ?? false)
                    ? $removal['markup']
                    : ($removal['authored'] !== '' ? $removal['authored'] : '(empty text block)'));
            if (!$removal['safe']) {
                $deliveredOffset = self::offsetAfterRanges($removal['offset'], $ranges);
                $path = $deliveredPaths[$deliveredOffset] ?? $removal['path'];
                $warnings[] = "file='theme/parts/{$part}.html'; block='{$path}'; authored="
                    . self::value($authored)
                    . '; delivered=' . self::value($authored)
                    . '; disposition=the excess block overlaps a malformed nested boundary or owns raw non-text '
                    . 'payload selected to survive the hero budget; the whole nested transaction was retained so '
                    . 'no selected content was lost and the residual overrun was queued for later repair';
                continue;
            }
            $warnings[] = "file='theme/parts/{$part}.html'; block='{$removal['path']}'; authored="
                . self::value($authored)
                . '; delivered=removed; disposition=hero copy budget retained one headline, one supporting '
                . 'paragraph, and only the authoritative primary action; this excess block was removed while '
                . 'preserving its siblings';
        }
        foreach ($buttonWrappers as $wrapper) {
            $warnings[] = self::emptyButtonWrapperWarning($wrapper, $part);
        }
        foreach ($textWrappers as $wrapper) {
            $warnings[] = "file='theme/parts/{$part}.html'; block='{$wrapper['path']}'; authored="
                . self::value($wrapper['markup'])
                . '; delivered=removed; disposition=the dedicated generated wp:group became empty after all of '
                . 'its excess text children were removed in the same transaction; its complete painted/layout '
                . 'boundary was removed so it could not leave dead UI, while sibling blocks were preserved';
        }
        $headlineWarning = self::headlineTopologyWarning(BlockMarkup::parse($markup), $markup, $part);
        if ($headlineWarning !== null) {
            $warnings[] = $headlineWarning;
        }
        return ['markup' => $markup, 'warnings' => $warnings];
    }

    /** @param array{path:string,markup:string,covered_button_indices:list<int>} $wrapper */
    private static function emptyButtonWrapperWarning(array $wrapper, string $part): string
    {
        $disposition = $wrapper['covered_button_indices'] === []
            ? 'the generated wp:buttons container was already empty and could only render dead layout space'
            : 'the generated wp:buttons container became empty after its excess action block(s) were removed '
                . 'in the same transaction and could only render dead layout space';
        return "file='theme/parts/{$part}.html'; block='{$wrapper['path']}'; authored="
            . self::value($wrapper['markup'])
            . "; delivered=removed; disposition={$disposition}, so its complete boundary was removed while "
            . 'sibling blocks were preserved';
    }

    /** @param array{path:string,markup:string,replacement:string,saved_wrapper_removed:bool} $wrapper */
    private static function zeroButtonWrapperWarning(array $wrapper, string $part): string
    {
        if (trim($wrapper['replacement']) === '') {
            return "file='theme/parts/{$part}.html'; block='{$wrapper['path']}'; authored="
                . self::value($wrapper['markup'])
                . '; delivered=removed; disposition=the wp:buttons container had no direct core/button child '
                . 'and no visible payload, so its complete boundary was removed as dead layout space';
        }
        if (!$wrapper['saved_wrapper_removed']) {
            return "file='theme/parts/{$part}.html'; block='{$wrapper['path']}'; authored="
                . self::value($wrapper['markup'])
                . '; delivered=unwrapped; disposition=the wp:buttons block had no direct core/button child, '
                . 'so its block-comment boundary was removed; its unprovable saved HTML and all raw/child '
                . 'payload were retained byte-for-byte rather than risking content loss';
        }
        return "file='theme/parts/{$part}.html'; block='{$wrapper['path']}'; authored="
            . self::value($wrapper['markup'])
            . '; delivered=unwrapped; disposition=the wp:buttons container had no direct core/button child, '
            . 'so its invalid wrapper boundary was removed while all raw payload and child block bytes were retained';
    }

    /** Report a residual missing/empty level-1 headline against delivered bytes. */
    private static function headlineTopologyWarning(
        BlockMarkup $document,
        string $markup,
        string $part,
    ): ?string {
        $hasCopyRegion = false;
        $firstHeading = null;
        foreach ($document->indices() as $index) {
            if (self::hasCopyRegionMarker($document, $index)) {
                $hasCopyRegion = true;
            }
            if ($document->name($index) !== 'heading'
                || !self::insideCopyRegion($document, $index)
                || $document->endOffset($index) === null
            ) {
                continue;
            }
            $firstHeading ??= $index;
            $attrs = $document->attrs($index) ?? [];
            if (self::headingLevel($attrs['level'] ?? 2) === 1
                && self::readingText($document->innerHtml($index)) !== ''
            ) {
                return null;
            }
        }
        if (!$hasCopyRegion) {
            // HeroComposition already reports the missing objective region;
            // avoid a second warning with no stable headline path.
            return null;
        }

        if ($firstHeading === null) {
            return "file='theme/parts/{$part}.html'; block='hero-composition__copy'; authored="
                . self::value(['required' => 'one visible wp:heading level 1'])
                . '; delivered=missing; disposition=the generated copy region has no complete heading that can '
                . 'be promoted without inventing visitor-facing copy; the residual headline slot was retained '
                . 'for later repair instead of aborting the build';
        }

        $start = $document->openingOffset($firstHeading);
        $end = (int) $document->endOffset($firstHeading);
        $attrs = $document->attrs($firstHeading) ?? [];
        $authored = [
            'level' => $attrs['level'] ?? 2,
            'text' => self::readingText($document->innerHtml($firstHeading)),
            'markup' => substr($markup, $start, $end - $start),
        ];
        return "file='theme/parts/{$part}.html'; block='"
            . self::ordinalPath($document, $firstHeading)
            . "'; authored=" . self::value($authored)
            . '; delivered=' . self::value($authored)
            . '; disposition=the hero budget retained the best available generated headline, but it is not one '
            . 'visible level-1 heading; changing its semantic level was not proven safe, so the residual topology '
            . 'defect was queued for later repair';
    }

    /**
     * Find outermost group shells dedicated entirely to safely removed text.
     *
     * @param list<array<string,mixed>> $removals
     * @return list<array{index:int,offset:int,end:int,path:string,markup:string}>
     */
    private static function dedicatedTextWrappers(
        BlockMarkup $document,
        string $markup,
        array $removals,
    ): array {
        $safeText = [];
        foreach ($removals as $removal) {
            if ($removal['kind'] === 'text' && $removal['safe']) {
                $safeText[$removal['index']] = true;
            }
        }
        if ($safeText === []) {
            return [];
        }

        $memo = [];
        $wrappers = [];
        foreach (array_keys($safeText) as $candidate) {
            $wrapper = self::outermostDedicatedTextWrapper($document, $candidate, $safeText, $memo);
            if ($wrapper === null || isset($wrappers[$wrapper])) {
                continue;
            }
            $end = $document->endOffset($wrapper);
            if ($end === null) {
                continue;
            }
            $offset = $document->openingOffset($wrapper);
            $wrappers[$wrapper] = [
                'index' => $wrapper,
                'offset' => $offset,
                'end' => $end,
                'path' => self::ordinalPath($document, $wrapper),
                'markup' => substr($markup, $offset, $end - $offset),
            ];
        }
        return array_values($wrappers);
    }

    /**
     * @param array<int,bool> $safeText
     * @param array<int,bool> $memo
     */
    private static function outermostDedicatedTextWrapper(
        BlockMarkup $document,
        int $candidate,
        array $safeText,
        array &$memo,
    ): ?int {
        $wrapper = null;
        for ($parent = $document->parent($candidate); $parent !== null; $parent = $document->parent($parent)) {
            if ($document->name($parent) !== 'group'
                || $document->parent($parent) === null
                || self::hasCopyRegionMarker($document, $parent)
                || !self::isDedicatedTextWrapper($document, $parent, $safeText, $memo)
            ) {
                break;
            }
            $wrapper = $parent;
        }
        return $wrapper;
    }

    /**
     * @param array<int,bool> $safeText
     * @param array<int,bool> $memo
     */
    private static function isDedicatedTextWrapper(
        BlockMarkup $document,
        int $group,
        array $safeText,
        array &$memo,
    ): bool {
        if (array_key_exists($group, $memo)) {
            return $memo[$group];
        }
        if ($document->parent($group) === null || self::hasCopyRegionMarker($document, $group)) {
            return $memo[$group] = false;
        }
        $end = $document->endOffset($group);
        $children = $document->children($group);
        if ($end === null || $children === []) {
            return $memo[$group] = false;
        }
        foreach ($children as $child) {
            if (isset($safeText[$child])) {
                continue;
            }
            if ($document->name($child) === 'group'
                && self::isDedicatedTextWrapper($document, $child, $safeText, $memo)
            ) {
                continue;
            }
            return $memo[$group] = false;
        }

        // Remove direct children from a snapshot. Any residual raw copy,
        // media, or extra element makes this an authored container rather than
        // a dedicated shell, so its bytes stay intact.
        $innerStart = $document->openingOffset($group) + $document->openingLength($group);
        $shell = $document->innerHtml($group);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $childEnd = $document->endOffset($child);
            if ($childEnd === null) {
                return $memo[$group] = false;
            }
            $relative = $document->openingOffset($child) - $innerStart;
            $shell = substr_replace(
                $shell,
                '',
                $relative,
                $childEnd - $document->openingOffset($child),
            );
        }
        $shell = (string) preg_replace('/<!--.*?-->/s', '', $shell);
        return $memo[$group] = preg_match(
            '~\A\s*<(?<tag>div|section|article|main|aside|header|footer|nav)\b[^>]*>\s*</\k<tag>>\s*\z~is',
            $shell,
        ) === 1;
    }

    /**
     * @param list<array<string,mixed>> $removals
     * @return list<array{
     *   index:int,offset:int,end:int,path:string,markup:string,covered_button_indices:list<int>
     * }>
     */
    private static function emptyButtonWrappers(
        BlockMarkup $document,
        string $markup,
        array $removals,
    ): array {
        $safeButtons = [];
        foreach ($removals as $removal) {
            if ($removal['kind'] === 'button' && $removal['safe']) {
                $safeButtons[$removal['index']] = true;
            }
        }

        $wrappers = [];
        $ordinal = 0;
        foreach ($document->indices() as $index) {
            if ($document->name($index) !== 'buttons') {
                continue;
            }
            $ordinal++;
            $end = $document->endOffset($index);
            if ($end === null) {
                continue;
            }
            $children = $document->children($index);
            $covered = [];
            $allRemoved = $children !== [];
            foreach ($children as $child) {
                if (!isset($safeButtons[$child])) {
                    $allRemoved = false;
                    break;
                }
                $covered[] = $child;
            }
            if ($children !== [] && !$allRemoved) {
                continue;
            }
            if (!self::isEmptyButtonsShell($document, $index, $children)) {
                continue;
            }
            $offset = $document->openingOffset($index);
            $wrappers[] = [
                'index' => $index,
                'offset' => $offset,
                'end' => $end,
                'path' => "wp:buttons[{$ordinal}]",
                'markup' => substr($markup, $offset, $end - $offset),
                'covered_button_indices' => $covered,
            ];
        }
        return $wrappers;
    }

    /** @param list<int> $children */
    private static function isEmptyButtonsShell(
        BlockMarkup $document,
        int $wrapper,
        array $children,
    ): bool {
        $innerStart = $document->openingOffset($wrapper) + $document->openingLength($wrapper);
        $shell = $document->innerHtml($wrapper);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $childEnd = $document->endOffset($child);
            if ($childEnd === null) {
                return false;
            }
            $relative = $document->openingOffset($child) - $innerStart;
            $shell = substr_replace(
                $shell,
                '',
                $relative,
                $childEnd - $document->openingOffset($child),
            );
        }
        $shell = (string) preg_replace('/<!--.*?-->/s', '', $shell);
        return preg_match('~\A\s*<div\b[^>]*>\s*</div>\s*\z~is', $shell) === 1;
    }

    /** @param array<int,bool> $removalIndices */
    private static function hasSurvivingDescendant(
        BlockMarkup $document,
        int $candidate,
        array $removalIndices,
    ): bool {
        foreach ($document->indices() as $index) {
            if ($index === $candidate) {
                continue;
            }
            for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
                if ($parent !== $candidate) {
                    continue;
                }
                if (!isset($removalIndices[$index])) {
                    return true;
                }
                break;
            }
        }
        return false;
    }

    /** Inline phrasing belongs to generated copy/control labels. */
    private const INLINE_TEXT_TAGS = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'del', 'em', 'i',
        'ins', 'kbd', 'mark', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small',
        'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];

    /** Whether a generated text leaf owns visible raw media/structure. */
    private static function hasRawNonTextPayload(BlockMarkup $document, int $index): bool
    {
        if ($document->isVoid($index)) {
            return false;
        }
        $shell = self::shellWithoutChildBlocks($document, $index);
        if ($shell === null) {
            return true;
        }
        $shell = preg_replace('/<!--(?!\s*\/?wp:).*?-->/s', '', $shell) ?? $shell;
        if (trim($shell) === '') {
            return false;
        }
        if (preg_match(
            '~\A\s*<(?<tag>div|section|article|main|aside|header|footer|nav)\b[^>]*>\s*</\k<tag>>\s*\z~is',
            $shell,
        ) === 1) {
            return false;
        }
        if (!preg_match_all('/<\s*\/?\s*([a-z][a-z0-9-]*)\b[^>]*>/i', $shell, $tags)) {
            return false;
        }
        $allowed = self::INLINE_TEXT_TAGS;
        if ($document->name($index) === 'button') {
            $allowed[] = 'div';
        } else {
            array_push($allowed, 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6');
        }
        foreach ($tags[1] as $tag) {
            if (!in_array(strtolower($tag), $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    /** Return a block's own raw HTML after parsed child blocks are masked. */
    private static function shellWithoutChildBlocks(BlockMarkup $document, int $index): ?string
    {
        $innerStart = $document->openingOffset($index) + $document->openingLength($index);
        $shell = $document->innerHtml($index);
        $children = $document->children($index);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $end = $document->endOffset($child);
            if ($end === null) {
                return null;
            }
            $start = $document->openingOffset($child);
            $shell = substr_replace($shell, '', $start - $innerStart, $end - $start);
        }
        return $shell;
    }

    private static function isDescendantOf(BlockMarkup $document, int $index, int $ancestor): bool
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            if ($parent === $ancestor) {
                return true;
            }
        }
        return false;
    }

    private static function headingLevel(mixed $level): int
    {
        if (is_int($level)) {
            return $level;
        }
        if (is_float($level) && is_finite($level) && floor($level) === $level) {
            return (int) $level;
        }
        if (is_string($level) && preg_match('/^[1-6]$/', $level) === 1) {
            return (int) $level;
        }
        return 2;
    }

    /** Return one block without any nested block delimiters or payload. */
    private static function withoutChildBlocks(
        BlockMarkup $document,
        string $markup,
        int $index,
    ): string {
        $start = $document->openingOffset($index);
        $end = $document->endOffset($index);
        if ($end === null) {
            return '';
        }
        $block = substr($markup, $start, $end - $start);
        $children = $document->children($index);
        for ($position = count($children) - 1; $position >= 0; $position--) {
            $child = $children[$position];
            $childEnd = $document->endOffset($child);
            if ($childEnd === null) {
                return '';
            }
            $relative = $document->openingOffset($child) - $start;
            $block = substr_replace(
                $block,
                '',
                $relative,
                $childEnd - $document->openingOffset($child),
            );
        }
        return $block;
    }

    private static function insideCopyRegion(BlockMarkup $document, int $index): bool
    {
        for ($cursor = $index; $cursor !== null; $cursor = $document->parent($cursor)) {
            $className = ($document->attrs($cursor) ?? [])['className'] ?? '';
            if (!is_string($className)) {
                continue;
            }
            $classes = preg_split(
                '/\s+/',
                trim($className),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array('hero-composition__copy', $classes, true)) {
                return true;
            }
        }
        return false;
    }

    private static function hasCopyRegionMarker(BlockMarkup $document, int $index): bool
    {
        $className = ($document->attrs($index) ?? [])['className'] ?? '';
        if (!is_string($className)) {
            return false;
        }
        $classes = preg_split('/\s+/', trim($className), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array('hero-composition__copy', $classes, true);
    }

    /** A stable one-based block ordinal within the generated part. */
    private static function ordinalPath(BlockMarkup $document, int $index): string
    {
        $name = $document->name($index);
        $ordinal = 0;
        foreach ($document->indices() as $candidate) {
            if ($document->name($candidate) === $name) {
                $ordinal++;
            }
            if ($candidate === $index) {
                break;
            }
        }
        return "wp:{$name}[{$ordinal}]";
    }

    /** @return array<int,string> opening byte offset => delivered global block ordinal */
    private static function pathsByOffset(BlockMarkup $document): array
    {
        $ordinals = [];
        $paths = [];
        foreach ($document->indices() as $index) {
            $name = $document->name($index);
            $ordinals[$name] = ($ordinals[$name] ?? 0) + 1;
            $paths[$document->openingOffset($index)] = "wp:{$name}[{$ordinals[$name]}]";
        }
        return $paths;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private static function offsetAfterRanges(int $offset, array $ranges): int
    {
        $shift = 0;
        foreach ($ranges as [$start, $end]) {
            if ($end > $offset) {
                break;
            }
            $shift += $end - $start;
        }
        return $offset - $shift;
    }

    /** Whether generated text is owned by an action rather than hero copy. */
    private static function insideActionRegion(BlockMarkup $document, int $index): bool
    {
        for ($parent = $document->parent($index); $parent !== null; $parent = $document->parent($parent)) {
            if ($document->name($parent) === 'button') {
                return true;
            }
        }
        return false;
    }

    /** Extract the authored destination from a button's own (childless) block. */
    private static function buttonHref(string $markup): string
    {
        if (preg_match(
            '~<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~is',
            $markup,
            $match,
        ) !== 1) {
            return '';
        }
        $href = $match[1] !== ''
            ? $match[1]
            : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        return html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param list<array{0:int,1:int}> $ranges @return list<array{0:int,1:int}> */
    private static function mergedRanges(array $ranges): array
    {
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
                continue;
            }
            $merged[] = [$start, $end];
        }
        return $merged;
    }

    private static function readingText(string $html): string
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function value(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
