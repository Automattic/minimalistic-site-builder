<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;

/**
 * Moves transformer-emitted inline styles into deterministic carrier classes.
 *
 * Every carrier lives on the owning block root and in its className attribute.
 * A nested styled element gets a selector-aware rule rooted at that carrier,
 * so PhpBlockFixer can regenerate the block without shifting the CSS target.
 */
final class InlineStyleHoister
{
    private const START_TAG =
        '/<[A-Za-z][A-Za-z0-9:-]*'
        . '(?:(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*)'
        . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)*\s*\/?>/s';

    /**
     * @param array<string,string> $parts filename => block markup
     * @return array{parts:array<string,string>,css:string,style_count:int}
     */
    public function hoist(array $parts): array
    {
        $rules = [];
        $styleCount = 0;
        $hoisted = [];

        foreach ($parts as $filename => $markup) {
            $result = $this->hoistMarkup($markup);
            $hoisted[$filename] = $result['markup'];
            $styleCount += $result['style_count'];
            foreach ($result['rules'] as $selector => $declarations) {
                $rules[$selector] = $declarations;
            }
        }

        ksort($rules);
        $css = '';
        foreach ($rules as $selector => $declarations) {
            $css .= $selector . '{' . $declarations . "}\n";
        }

        return [
            'parts' => $hoisted,
            'css' => $css,
            'style_count' => $styleCount,
        ];
    }

    /**
     * @return array{markup:string,rules:array<string,string>,style_count:int}
     */
    private function hoistMarkup(string $markup): array
    {
        if (!preg_match_all(self::START_TAG, $markup, $matches, PREG_OFFSET_CAPTURE)) {
            return ['markup' => $markup, 'rules' => [], 'style_count' => 0];
        }

        /** @var array<int,string> $tags */
        $tags = [];
        foreach ($matches[0] as [$tag, $tagOffset]) {
            $tags[$tagOffset] = $tag;
        }

        $blocks = BlockMarkup::parse($markup);
        $blockClasses = [];
        $tagClasses = [];
        $styledTags = [];
        $rules = [];
        $styleCount = 0;

        foreach ($tags as $tagOffset => $tag) {
            if (!preg_match('/\s+style\s*=\s*(["\'])(.*?)\1/is', $tag, $styleMatch)) {
                continue;
            }

            $declarations = trim(html_entity_decode($styleMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($declarations === '') {
                continue;
            }

            $owner = $this->owningBlock($blocks, $tagOffset);
            $target = $owner === null
                ? ['root_offset' => $tagOffset, 'descendant_selector' => null]
                : $this->target($blocks, $owner, $tagOffset, $tag);
            $descendantSelector = $target['descendant_selector'];
            $signature = $declarations . ($descendantSelector === null ? '' : "\0" . $descendantSelector);
            $className = 'se-' . hash('sha256', $signature);
            $selector = '.' . $className
                . ($descendantSelector === null ? '' : ' ' . $descendantSelector);

            $rules[$selector] = $declarations;
            $styledTags[$tagOffset] = true;
            $tagClasses[$target['root_offset']][$className] = true;
            $styleCount++;

            if ($owner !== null) {
                $blockClasses[$owner][$className] = true;
            }
        }

        if ($styledTags === []) {
            return ['markup' => $markup, 'rules' => [], 'style_count' => 0];
        }

        $operations = [];
        foreach (array_unique(array_merge(array_keys($styledTags), array_keys($tagClasses))) as $tagOffset) {
            $tag = $tags[$tagOffset] ?? null;
            if (!is_string($tag)) {
                continue;
            }
            $rewritten = isset($styledTags[$tagOffset]) ? $this->removeStyle($tag) : $tag;
            $classes = array_keys($tagClasses[$tagOffset] ?? []);
            sort($classes);
            foreach ($classes as $className) {
                $rewritten = $this->addHtmlClass($rewritten, $className);
            }
            $operations[] = [
                'start' => $tagOffset,
                'length' => strlen($tag),
                'content' => $rewritten,
            ];
        }

        usort($operations, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($operations as $operation) {
            $markup = substr_replace(
                $markup,
                $operation['content'],
                $operation['start'],
                $operation['length'],
            );
        }

        // HTML edits changed byte offsets. Structure and node order did not.
        $rewrittenBlocks = BlockMarkup::parse($markup);
        foreach ($blockClasses as $index => $classes) {
            $attrs = $rewrittenBlocks->attrs($index);
            if (!is_array($attrs)) {
                continue;
            }
            $existing = $attrs['className'] ?? '';
            if (!is_string($existing)) {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($existing), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $added = array_keys($classes);
            sort($added);
            $tokens = array_values(array_unique(array_merge($tokens, $added)));
            if ($tokens === []) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $tokens);
            }
            $rewrittenBlocks->setAttrs($index, $attrs);
        }

        return [
            'markup' => $rewrittenBlocks->render(),
            'rules' => $rules,
            'style_count' => $styleCount,
        ];
    }

    private function removeStyle(string $tag): string
    {
        return (string) preg_replace('/\s+style\s*=\s*(["\'])(.*?)\1/is', '', $tag, 1);
    }

    private function addHtmlClass(string $tag, string $className): string
    {
        if (preg_match('/\s+class\s*=\s*(["\'])(.*?)\1/is', $tag, $classMatch, PREG_OFFSET_CAPTURE)) {
            $tokens = preg_split(
                '/\s+/',
                trim(html_entity_decode($classMatch[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array($className, $tokens, true)) {
                return $tag;
            }
            $replacement = $classMatch[2][0] === '' ? $className : $classMatch[2][0] . ' ' . $className;
            return substr_replace(
                $tag,
                $replacement,
                $classMatch[2][1],
                strlen($classMatch[2][0]),
            );
        }

        return (string) preg_replace('/(\s*\/?>)$/', ' class="' . $className . '"$1', $tag, 1);
    }

    /**
     * @return array{root_offset:int,descendant_selector:?string}
     */
    private function target(BlockMarkup $blocks, int $owner, int $tagOffset, string $tag): array
    {
        $innerStart = $blocks->openingOffset($owner) + $blocks->openingLength($owner);
        $fragment = HtmlFragment::parse($blocks->ownHtml($owner));
        $root = $fragment->root()->elementChildren()[0] ?? null;
        if (!$root instanceof HtmlNode) {
            return ['root_offset' => $tagOffset, 'descendant_selector' => null];
        }

        $rootOffset = $innerStart + $root->startOffset();
        $styled = $this->elementAtOffset($root, $tagOffset - $innerStart);
        if (!$styled instanceof HtmlNode || $styled === $root) {
            return ['root_offset' => $rootOffset, 'descendant_selector' => null];
        }

        $selector = $this->descendantSelector($root, $styled);
        if ($selector === '') {
            $tagName = strtolower((string) preg_replace('/^<\s*([A-Za-z][A-Za-z0-9:-]*).*$/s', '$1', $tag));
            $selector = '> ' . $tagName;
        }
        return ['root_offset' => $rootOffset, 'descendant_selector' => $selector];
    }

    private function elementAtOffset(HtmlNode $node, int $offset): ?HtmlNode
    {
        if ($node->isElement() && $node->startOffset() === $offset) {
            return $node;
        }
        foreach ($node->elementChildren() as $child) {
            $found = $this->elementAtOffset($child, $offset);
            if ($found instanceof HtmlNode) {
                return $found;
            }
        }
        return null;
    }

    private function descendantSelector(HtmlNode $root, HtmlNode $target): string
    {
        $path = [];
        $node = $target;
        while ($node !== $root) {
            $parent = $node->parent();
            if (!$parent instanceof HtmlNode) {
                return '';
            }
            $path[] = $this->selectorSegment($node, $parent);
            $node = $parent;
        }
        return '> ' . implode(' > ', array_reverse($path));
    }

    private function selectorSegment(HtmlNode $node, HtmlNode $parent): string
    {
        $tag = strtolower((string) $node->tagName());
        $structuralClass = $this->structuralClass($node);
        $segment = $tag . ($structuralClass === null ? '' : '.' . $structuralClass);

        $matching = array_values(array_filter(
            $parent->elementChildren(),
            function (HtmlNode $sibling) use ($tag, $structuralClass): bool {
                if (strtolower((string) $sibling->tagName()) !== $tag) {
                    return false;
                }
                return $structuralClass === null || $this->hasClass($sibling, $structuralClass);
            },
        ));
        if (count($matching) <= 1) {
            return $segment;
        }

        $sameTag = array_values(array_filter(
            $parent->elementChildren(),
            static fn (HtmlNode $sibling): bool =>
                strtolower((string) $sibling->tagName()) === $tag,
        ));
        foreach ($sameTag as $index => $sibling) {
            if ($sibling === $node) {
                return $segment . ':nth-of-type(' . ($index + 1) . ')';
            }
        }
        return $segment;
    }

    private function structuralClass(HtmlNode $node): ?string
    {
        $tokens = preg_split('/\s+/', trim((string) $node->attribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (preg_match('/^(?:wp-block-|wp-element-)[A-Za-z0-9_-]+$/', $token) === 1) {
                return $token;
            }
        }
        return null;
    }

    private function hasClass(HtmlNode $node, string $className): bool
    {
        $tokens = preg_split('/\s+/', trim((string) $node->attribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array($className, $tokens, true);
    }

    private function owningBlock(BlockMarkup $blocks, int $tagOffset): ?int
    {
        $owner = null;
        $ownerOffset = -1;
        foreach ($blocks->indices() as $index) {
            $innerStart = $blocks->openingOffset($index) + $blocks->openingLength($index);
            $innerEnd = $blocks->innerEndOffset($index);
            if ($tagOffset < $innerStart || $tagOffset >= $innerEnd) {
                continue;
            }
            $openingOffset = $blocks->openingOffset($index);
            if ($openingOffset > $ownerOffset) {
                $owner = $index;
                $ownerOffset = $openingOffset;
            }
        }
        return $owner;
    }
}
