<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\CommentSerializer;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;
use Automattic\SiteBuild\BlockSerializer\NormalizedBlock;
use Automattic\SiteBuild\BlockSerializer\Registry\BlockRegistry;
use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategyRegistry;
use Automattic\SiteBuild\BlockSerializer\Serializer;

/**
 * Wrap phrasing-only headings and paragraphs inside an HTML island so
 * WordPress 7.1 can edit them in place as locked inner blocks.
 */
final class IslandEditableLeaves
{
    private const PHRASING = ['a', 'br', 'code', 'em', 'mark', 's', 'strong', 'sub', 'sup'];

    public static function wrap(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $registry = new BlockRegistry();
        $saves = new SaveStrategyRegistry($registry);
        $comments = new CommentSerializer($registry);
        $serializer = new Serializer($registry);

        $offset = 0;
        $out = '';
        $length = strlen($html);
        while ($offset < $length) {
            $candidate = self::nextCandidate($html, $offset);
            if ($candidate === null) {
                $out .= substr($html, $offset);
                break;
            }
            $out .= substr($html, $offset, $candidate['start'] - $offset);
            $wrapped = self::tryWrap($candidate, $saves, $comments, $serializer);
            $out .= $wrapped ?? substr($html, $candidate['start'], $candidate['end'] - $candidate['start']);
            $offset = $candidate['end'];
        }
        return $out;
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrap(
        array $candidate,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        $tag = $candidate['tag'];
        $open = $candidate['open'];
        $inner = $candidate['inner'];
        $attrs = self::openingAttributes($open);
        if ($attrs === null) {
            return null;
        }
        if (self::hasStyleAttribute($open . $inner) || self::hasUnsupportedInner($inner)) {
            return null;
        }

        $blockAttrs = [];
        $name = 'core/paragraph';
        if (preg_match('/^h([1-6])$/', $tag, $levelMatch) === 1) {
            $name = 'core/heading';
            $blockAttrs['level'] = (int) $levelMatch[1];
        }
        $blockAttrs['content'] = $inner;

        foreach ($attrs as $attr) {
            $attrName = strtolower($attr['name']);
            $value = $attr['value'];
            if ($attrName === 'class') {
                $classes = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($name === 'core/heading') {
                    $classes = array_values(array_filter(
                        $classes,
                        static fn (string $class): bool => $class !== 'wp-block-heading',
                    ));
                }
                if ($classes !== []) {
                    $blockAttrs['className'] = implode(' ', $classes);
                }
                continue;
            }
            if ($attrName === 'id') {
                if ($value === '') {
                    return null;
                }
                $blockAttrs['anchor'] = $value;
                continue;
            }
            if ($name === 'core/paragraph' && $attrName === 'dir') {
                if ($value !== 'ltr' && $value !== 'rtl') {
                    return null;
                }
                $blockAttrs['direction'] = $value;
                continue;
            }
            return null;
        }

        try {
            $saved = $saves->save($name, $blockAttrs, '');
            $typed = new JsonObject();
            foreach ($blockAttrs as $key => $value) {
                $typed->set($key, JsonValue::fromNative($value));
            }
            $commentAttrs = $comments->attributes(new NormalizedBlock($name, $typed, $blockAttrs));
            $emitted = $comments->delimit($name, $commentAttrs, $saved);
            $roundTrip = $serializer->transform($emitted)->html;
        } catch (\Throwable) {
            return null;
        }
        if ($roundTrip !== $emitted) {
            return null;
        }
        return $emitted;
    }

    /**
     * @return array{start:int,end:int,tag:string,open:string,inner:string}|null
     */
    private static function nextCandidate(string $html, int $offset): ?array
    {
        $length = strlen($html);
        while ($offset < $length) {
            $lt = strpos($html, '<', $offset);
            if ($lt === false) {
                return null;
            }
            if (substr($html, $lt, 4) === '<!--') {
                $offset = self::skipComment($html, $lt);
                continue;
            }
            if (($html[$lt + 1] ?? '') === '/') {
                $offset = $lt + 2;
                continue;
            }
            if (preg_match('/\G<(script|style|textarea)\b/i', $html, $raw, 0, $lt) === 1) {
                $offset = self::skipRawText($html, strtolower($raw[1]), $lt);
                continue;
            }
            if (preg_match('/\G<(h[1-6]|p)(?=[\s\/>])/i', $html, $match, 0, $lt) !== 1) {
                $offset = $lt + 1;
                continue;
            }
            $tag = strtolower($match[1]);
            $openEnd = self::tagEnd($html, $lt);
            if ($openEnd === null) {
                return null;
            }
            $open = substr($html, $lt, $openEnd - $lt);
            if (str_ends_with(rtrim($open, '>'), '/')) {
                return [
                    'start' => $lt,
                    'end'   => $openEnd,
                    'tag'   => $tag,
                    'open'  => $open,
                    'inner' => '',
                ];
            }
            $close = self::matchingClose($html, $tag, $openEnd);
            if ($close === null) {
                $offset = $openEnd;
                continue;
            }
            return [
                'start' => $lt,
                'end'   => $close['end'],
                'tag'   => $tag,
                'open'  => $open,
                'inner' => substr($html, $openEnd, $close['start'] - $openEnd),
            ];
        }
        return null;
    }

    /** @return array{start:int,end:int}|null */
    private static function matchingClose(string $html, string $tag, int $offset): ?array
    {
        $length = strlen($html);
        $depth = 1;
        while ($offset < $length) {
            $lt = strpos($html, '<', $offset);
            if ($lt === false) {
                return null;
            }
            if (substr($html, $lt, 4) === '<!--') {
                $offset = self::skipComment($html, $lt);
                continue;
            }
            if (preg_match('/\G<\/' . preg_quote($tag, '/') . '(?=[\s>])/i', $html, $closeMatch, 0, $lt) === 1) {
                $end = self::tagEnd($html, $lt);
                if ($end === null) {
                    return null;
                }
                $depth--;
                if ($depth === 0) {
                    return ['start' => $lt, 'end' => $end];
                }
                $offset = $end;
                continue;
            }
            if (preg_match('/\G<' . preg_quote($tag, '/') . '(?=[\s\/>])/i', $html, $openMatch, 0, $lt) === 1) {
                $end = self::tagEnd($html, $lt);
                if ($end === null) {
                    return null;
                }
                $open = substr($html, $lt, $end - $lt);
                if (!str_ends_with(rtrim($open, '>'), '/')) {
                    $depth++;
                }
                $offset = $end;
                continue;
            }
            $offset = $lt + 1;
        }
        return null;
    }

    private static function skipComment(string $html, int $lt): int
    {
        if (preg_match('/\G<!--\s*wp:(heading|paragraph)\b/i', $html, $match, 0, $lt) === 1) {
            $close = strpos($html, '<!-- /wp:' . strtolower($match[1]), $lt + 4);
            if ($close === false) {
                return strlen($html);
            }
            $end = strpos($html, '-->', $close);
            return $end === false ? strlen($html) : $end + 3;
        }
        $end = strpos($html, '-->', $lt + 4);
        return $end === false ? strlen($html) : $end + 3;
    }

    private static function skipRawText(string $html, string $tag, int $lt): int
    {
        $close = strpos($html, '</' . $tag, $lt + 1);
        if ($close === false) {
            return strlen($html);
        }
        $end = self::tagEnd($html, $close);
        return $end ?? strlen($html);
    }

    private static function tagEnd(string $html, int $lt): ?int
    {
        $length = strlen($html);
        $quote = '';
        for ($i = $lt + 1; $i < $length; $i++) {
            $byte = $html[$i];
            if ($quote !== '') {
                if ($byte === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                continue;
            }
            if ($byte === '>') {
                return $i + 1;
            }
        }
        return null;
    }

    /** @return list<array{name:string,value:string}>|null */
    private static function openingAttributes(string $open): ?array
    {
        if (preg_match('/^<[A-Za-z][A-Za-z0-9]*(\s[^>]*)?>$/s', $open) !== 1
            && preg_match('/^<[A-Za-z][A-Za-z0-9]*(\s[^>]*)?\/>$/s', $open) !== 1) {
            return null;
        }
        $gt = strrpos($open, '>');
        if ($gt === false) {
            return null;
        }
        $rest = substr($open, 0, $gt);
        $rest = rtrim($rest);
        if (str_ends_with($rest, '/')) {
            $rest = rtrim(substr($rest, 0, -1));
        }
        $space = strpos($rest, ' ');
        if ($space === false) {
            return [];
        }
        $attrSource = trim(substr($rest, $space + 1));
        if ($attrSource === '') {
            return [];
        }
        $attrs = [];
        $length = strlen($attrSource);
        $offset = 0;
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $attrSource, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            if (preg_match(
                '/\G([^\s=]+)(\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
                $attrSource,
                $match,
                0,
                $offset,
            ) !== 1) {
                return null;
            }
            $offset += strlen($match[0]);
            if (!isset($match[2]) || $match[2] === '') {
                return null;
            }
            $value = $match[3] ?? '';
            if ($value === '' && ($match[4] ?? '') !== '') {
                $value = $match[4];
            } elseif ($value === '' && ($match[5] ?? '') !== '') {
                $value = $match[5];
            }
            $attrs[] = [
                'name'  => $match[1],
                'value' => $value,
            ];
        }
        return $attrs;
    }

    private static function hasStyleAttribute(string $html): bool
    {
        return preg_match('/<[^>]*\sstyle\s*=/i', $html) === 1;
    }

    private static function hasUnsupportedInner(string $inner): bool
    {
        if (str_contains($inner, '<!--')) {
            return true;
        }
        if (preg_match_all('/<\/?([A-Za-z][A-Za-z0-9]*)\b/', $inner, $matches) === false) {
            return true;
        }
        foreach ($matches[1] as $name) {
            if (!in_array(strtolower($name), self::PHRASING, true)) {
                return true;
            }
        }
        return false;
    }
}