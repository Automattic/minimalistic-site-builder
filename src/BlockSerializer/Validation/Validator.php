<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Validation;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;
use Automattic\SiteBuild\BlockSerializer\Html\HtmlNode;

/**
 * Bounded port of Gutenberg's save-output token equivalence predicate.
 *
 * It intentionally covers the token/attribute forms reachable from the frozen
 * registry: insignificant inter-tag whitespace, collapsed text whitespace,
 * unordered class tokens, unordered normalized style declarations, boolean
 * attributes, comments, and case-insensitive tag/attribute names.
 */
final class Validator
{
    /** @var array<string,true> */
    private const BOOLEAN = [
        'allowfullscreen' => true, 'allowpaymentrequest' => true,
        'allowusermedia' => true, 'async' => true, 'autofocus' => true,
        'autoplay' => true, 'checked' => true, 'controls' => true,
        'default' => true, 'defer' => true, 'disabled' => true,
        'download' => true, 'formnovalidate' => true, 'hidden' => true,
        'ismap' => true, 'itemscope' => true, 'loop' => true,
        'multiple' => true, 'muted' => true, 'nomodule' => true,
        'novalidate' => true, 'open' => true, 'playsinline' => true,
        'readonly' => true, 'required' => true, 'reversed' => true,
        'selected' => true, 'typemustmatch' => true,
    ];

    /** @var array<string,true> */
    private const ENUMERATED = [
        'autocapitalize' => true, 'autocomplete' => true, 'charset' => true,
        'contenteditable' => true, 'crossorigin' => true, 'decoding' => true,
        'dir' => true, 'draggable' => true, 'enctype' => true,
        'formenctype' => true, 'formmethod' => true, 'http-equiv' => true,
        'inputmode' => true, 'kind' => true, 'method' => true,
        'preload' => true, 'scope' => true, 'shape' => true,
        'spellcheck' => true, 'translate' => true, 'type' => true,
        'wrap' => true,
    ];

    public function isValid(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }
        try {
            return $this->tokens(HtmlFragment::parse($actual)->root())
                === $this->tokens(HtmlFragment::parse($expected)->root());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    private function tokens(HtmlNode $root): array
    {
        $tokens = [];
        foreach ($root->children() as $child) {
            $this->append($child, $tokens);
        }
        return $tokens;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function append(HtmlNode $node, array &$tokens): void
    {
        if ($node->isText()) {
            $text = $node->textContent();
            if (preg_match('/^[\t\n\r\v\f ]*$/D', $text) === 1) {
                return;
            }
            $tokens[] = ['type' => 'text', 'value' => $this->collapse($text)];
            return;
        }
        if ($node->isComment()) {
            $tokens[] = ['type' => 'comment', 'value' => $this->collapseComment($node->rawHtml())];
            return;
        }
        if (!$node->isElement()) {
            return;
        }

        $attributes = [];
        foreach ($node->attributes() as $attribute) {
            $name = strtolower($attribute['name']);
            $value = $attribute['value'];
            if ($value === '' && !isset(self::BOOLEAN[$name])
                && !isset(self::ENUMERATED[$name]) && !str_starts_with($name, 'data-')) {
                continue;
            }
            if (isset(self::BOOLEAN[$name])) {
                $value = true;
            } elseif ($name === 'class') {
                $parts = preg_split('/[\t\n\r\v\f ]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $parts = array_values(array_unique($parts));
                sort($parts, SORT_STRING);
                $value = $parts;
            } elseif ($name === 'style') {
                $value = $this->styles($value);
            }
            $attributes[$name] = $value;
        }
        ksort($attributes, SORT_STRING);
        $tag = strtolower((string) $node->tagName());
        $tokens[] = ['type' => 'start', 'tag' => $tag, 'attributes' => $attributes];
        foreach ($node->children() as $child) {
            $this->append($child, $tokens);
        }
        if (!HtmlNode::isVoidTag($tag)) {
            $tokens[] = ['type' => 'end', 'tag' => $tag];
        }
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/[\t\n\r\v\f ]+/', ' ', $text));
    }

    private function collapseComment(string $comment): string
    {
        return $this->collapse(substr($comment, 4, -3));
    }

    /** @return array<string,string> */
    private function styles(string $style): array
    {
        $style = preg_replace('/;?\s*$/', '', $style) ?? $style;
        $result = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration);
            $key = trim((string) array_shift($parts));
            $value = trim(implode(':', $parts));
            $pieces = preg_split('/[\t\n\r\v\f ]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($pieces as &$piece) {
                if ((float) $piece == 0.0 && preg_match('/^[+-]?(?:0|\.0)/', $piece) === 1) {
                    $piece = '0';
                } elseif (str_starts_with($piece, '.')) {
                    $piece = '0' . $piece;
                }
            }
            unset($piece);
            $value = implode(' ', $pieces);
            $value = preg_replace('/^url\s*\([\'"\s]*(.*?)[\'"\s]*\)$/', 'url($1)', $value) ?? $value;
            $result[$key] = $value;
        }
        ksort($result, SORT_STRING);
        return $result;
    }
}
