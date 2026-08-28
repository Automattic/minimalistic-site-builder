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
 * Wrap phrasing-only headings, paragraphs, images, lists, tables, and
 * quotes inside an HTML island so WordPress 7.1 can edit them in place
 * as inner blocks.
 */
final class IslandEditableLeaves
{
    public const BARE_WRAPPER_CLASS = 'island-bare-image';
    public const BARE_WRAPPER_CSS = ".island-bare-image {\n    display: contents;\n}\n";
    public const BARE_TABLE_CLASS = 'island-bare-table';
    public const BARE_TABLE_CSS = ".island-bare-table {\n    display: contents;\n}\n";

    private const PHRASING = ['a', 'br', 'code', 'em', 'mark', 's', 'span', 'strong', 'sub', 'sup'];
    private const IMG_ATTRS = ['src', 'alt', 'id', 'class', 'width', 'height', 'loading', 'title'];

    public static function cssBlocksBareImages(string $css): bool
    {
        return self::cssBlocksBareTag($css, 'img');
    }

    public static function cssBlocksBareTag(string $css, string $tag): bool
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        return preg_match('/[>+~]\s*' . preg_quote($tag, '/') . '\b/i', $css) === 1;
    }

    /**
     * @param list<string> $warnings
     */
    public static function wrap(
        string $html,
        string $css = '',
        string $path = '',
        string $context = '',
        array &$warnings = [],
    ): string {
        if ($html === '') {
            return $html;
        }
        $blockBareImg = self::cssBlocksBareTag($css, 'img');
        $blockBareTable = self::cssBlocksBareTag($css, 'table');
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
            $wrapped = self::tryWrap(
                $candidate,
                $html,
                $blockBareImg,
                $blockBareTable,
                $saves,
                $comments,
                $serializer,
            );
            if (
                $wrapped === null
                && $path !== ''
                && (
                    ($blockBareImg && $candidate['tag'] === 'img')
                    || ($blockBareTable && $candidate['tag'] === 'table')
                )
                && !self::hasCombinatorWarning($warnings, $path)
            ) {
                $target = $candidate['tag'] === 'table' ? 'table' : 'img';
                $noun = $target === 'table' ? 'tables' : 'images';
                $warnings[] = "malformed_design: {$path} context {$context}; authored CSS combinator targeting {$target}; "
                    . "delivered bare {$noun} inert; disposition skipped";
            }
            if ($wrapped !== null) {
                $out .= $wrapped;
            } elseif ($candidate['inner'] !== '' && self::canDescend($candidate['tag'])) {
                $close = substr(
                    $html,
                    $candidate['start'] + strlen($candidate['open']) + strlen($candidate['inner']),
                    $candidate['end'] - $candidate['start'] - strlen($candidate['open']) - strlen($candidate['inner']),
                );
                $out .= $candidate['open']
                    . self::wrap($candidate['inner'], $css, $path, $context, $warnings)
                    . $close;
            } else {
                $out .= substr($html, $candidate['start'], $candidate['end'] - $candidate['start']);
            }
            $offset = $candidate['end'];
        }
        return $out;
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrap(
        array $candidate,
        string $html,
        bool $blockBareImg,
        bool $blockBareTable,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        $tag = $candidate['tag'];
        if ($tag === 'figure') {
            return self::tryWrapFigure($candidate, $saves, $comments, $serializer);
        }
        if ($tag === 'img') {
            if ($blockBareImg || self::imgHasForbiddenParent($html, $candidate['start'], $candidate['end'])) {
                return null;
            }
            return self::tryWrapBareImage($candidate, $saves, $comments, $serializer);
        }
        if ($tag === 'ul' || $tag === 'ol') {
            return self::tryWrapList($candidate, $saves, $comments, $serializer);
        }
        if ($tag === 'table') {
            if ($blockBareTable) {
                return null;
            }
            return self::tryWrapTable($candidate, $saves, $comments, $serializer);
        }
        if ($tag === 'blockquote') {
            return self::tryWrapQuote($candidate, $saves, $comments, $serializer);
        }
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

        return self::emit($name, $blockAttrs, $saves, $comments, $serializer);
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrapFigure(
        array $candidate,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        if (self::hasStyleAttribute($candidate['open'] . $candidate['inner'])) {
            return null;
        }
        $figureAttrs = self::openingAttributes($candidate['open']);
        if ($figureAttrs === null) {
            return null;
        }
        $parts = self::figureParts($candidate['inner']);
        if ($parts === null) {
            return null;
        }
        $blockAttrs = self::imageBlockAttrs($parts['img'], $figureAttrs, $parts['link'], $parts['caption'], false);
        if ($blockAttrs === null) {
            return null;
        }
        return self::emit('core/image', $blockAttrs, $saves, $comments, $serializer);
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrapBareImage(
        array $candidate,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        if (self::hasStyleAttribute($candidate['open'])) {
            return null;
        }
        $blockAttrs = self::imageBlockAttrs($candidate['open'], [], null, null, true);
        if ($blockAttrs === null) {
            return null;
        }
        return self::emit('core/image', $blockAttrs, $saves, $comments, $serializer);
    }

    /**
     * @param list<array{name:string,value:string}> $figureAttrs
     * @param array{open:string,href:string,class:string,rel:string,target:string}|null $link
     * @param array{inner:string}|null $caption
     * @return array<string,mixed>|null
     */
    private static function imageBlockAttrs(
        string $imgOpen,
        array $figureAttrs,
        ?array $link,
        ?array $caption,
        bool $bare,
    ): ?array {
        $imgAttrs = self::openingAttributes($imgOpen);
        if ($imgAttrs === null) {
            return null;
        }
        $block = [];
        $imgClasses = [];
        foreach ($imgAttrs as $attr) {
            $name = strtolower($attr['name']);
            $value = $attr['value'];
            if (!in_array($name, self::IMG_ATTRS, true)) {
                return null;
            }
            if ($name === 'src') {
                if ($value === '') {
                    return null;
                }
                $block['url'] = $value;
                continue;
            }
            if ($name === 'alt') {
                $block['alt'] = $value;
                continue;
            }
            if ($name === 'title' && $value !== '') {
                $block['title'] = $value;
                continue;
            }
            if ($name === 'id' && $value !== '') {
                $block['anchor'] = $value;
                continue;
            }
            if ($name === 'class') {
                $imgClasses = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                continue;
            }
            // width, height, loading: not in save() without changing cascade.
        }
        if (!isset($block['url'])) {
            return null;
        }
        if (!isset($block['alt'])) {
            $block['alt'] = '';
        }
        $figureClasses = [];
        foreach ($figureAttrs as $attr) {
            $name = strtolower($attr['name']);
            $value = $attr['value'];
            if ($name === 'class') {
                $figureClasses = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                continue;
            }
            if ($name === 'id') {
                if ($value === '') {
                    return null;
                }
                $block['anchor'] = $value;
                continue;
            }
            return null;
        }
        $className = array_values(array_unique(array_filter(
            [...$figureClasses, ...$imgClasses],
            static fn (string $class): bool => $class !== 'wp-block-image' && $class !== self::BARE_WRAPPER_CLASS,
        )));
        if ($bare) {
            $className[] = self::BARE_WRAPPER_CLASS;
        }
        if ($className !== []) {
            $block['className'] = implode(' ', $className);
        }
        if ($link !== null) {
            $block['href'] = $link['href'];
            $block['linkDestination'] = 'custom';
            if ($link['class'] !== '') {
                $block['linkClass'] = $link['class'];
            }
            if ($link['rel'] !== '') {
                $block['rel'] = $link['rel'];
            }
            if ($link['target'] !== '') {
                $block['linkTarget'] = $link['target'];
            }
        }
        if ($caption !== null) {
            $block['caption'] = $caption['inner'];
        }
        return $block;
    }

    /**
     * @return array{img:string,link:?array{open:string,href:string,class:string,rel:string,target:string},caption:?array{inner:string}}|null
     */
    private static function figureParts(string $inner): ?array
    {
        $offset = 0;
        $length = strlen($inner);
        $img = null;
        $link = null;
        $caption = null;
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $inner, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            $lt = strpos($inner, '<', $offset);
            if ($lt !== $offset) {
                return null;
            }
            if (preg_match('/\G<img(?=[\s\/>])/i', $inner, $m, 0, $offset) === 1) {
                if ($img !== null || $caption !== null) {
                    return null;
                }
                $end = self::tagEnd($inner, $offset);
                if ($end === null) {
                    return null;
                }
                $img = substr($inner, $offset, $end - $offset);
                $offset = $end;
                continue;
            }
            if (preg_match('/\G<a(?=[\s>])/i', $inner, $m, 0, $offset) === 1) {
                if ($img !== null || $link !== null || $caption !== null) {
                    return null;
                }
                $openEnd = self::tagEnd($inner, $offset);
                if ($openEnd === null) {
                    return null;
                }
                $close = self::matchingClose($inner, 'a', $openEnd);
                if ($close === null) {
                    return null;
                }
                $open = substr($inner, $offset, $openEnd - $offset);
                $linkInner = trim(substr($inner, $openEnd, $close['start'] - $openEnd));
                if (preg_match('/^<img(?=[\s\/>])/i', $linkInner) !== 1) {
                    return null;
                }
                $imgEnd = self::tagEnd($linkInner, 0);
                if ($imgEnd === null || trim(substr($linkInner, $imgEnd)) !== '') {
                    return null;
                }
                $linkAttrs = self::openingAttributes($open);
                if ($linkAttrs === null) {
                    return null;
                }
                $href = '';
                $class = '';
                $rel = '';
                $target = '';
                foreach ($linkAttrs as $attr) {
                    $name = strtolower($attr['name']);
                    if ($name === 'href') {
                        $href = $attr['value'];
                    } elseif ($name === 'class') {
                        $class = $attr['value'];
                    } elseif ($name === 'rel') {
                        $rel = $attr['value'];
                    } elseif ($name === 'target') {
                        $target = $attr['value'];
                    } else {
                        return null;
                    }
                }
                if ($href === '') {
                    return null;
                }
                $link = ['open' => $open, 'href' => $href, 'class' => $class, 'rel' => $rel, 'target' => $target];
                $img = substr($linkInner, 0, $imgEnd);
                $offset = $close['end'];
                continue;
            }
            if (preg_match('/\G<figcaption(?=[\s>])/i', $inner, $m, 0, $offset) === 1) {
                if ($img === null || $caption !== null) {
                    return null;
                }
                $openEnd = self::tagEnd($inner, $offset);
                if ($openEnd === null) {
                    return null;
                }
                $close = self::matchingClose($inner, 'figcaption', $openEnd);
                if ($close === null) {
                    return null;
                }
                $capOpen = substr($inner, $offset, $openEnd - $offset);
                $capAttrs = self::openingAttributes($capOpen);
                if ($capAttrs === null || !self::figcaptionAttrsAllowed($capAttrs)) {
                    return null;
                }
                $capInner = substr($inner, $openEnd, $close['start'] - $openEnd);
                if (self::hasUnsupportedInner($capInner) || self::hasStyleAttribute(substr($inner, $offset, $close['end'] - $offset))) {
                    return null;
                }
                $caption = ['inner' => $capInner];
                $offset = $close['end'];
                continue;
            }
            return null;
        }
        if ($img === null) {
            return null;
        }
        return ['img' => $img, 'link' => $link, 'caption' => $caption];
    }

    /**
     * @param list<string> $warnings
     */
    private static function hasCombinatorWarning(array $warnings, string $path): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, $path) && str_contains($warning, 'combinator targeting')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{name:string,value:string}> $attrs
     */
    private static function figcaptionAttrsAllowed(array $attrs): bool
    {
        foreach ($attrs as $attr) {
            if (strtolower($attr['name']) !== 'class') {
                return false;
            }
            $classes = preg_split('/\s+/', trim($attr['value']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($classes as $class) {
                if ($class !== 'wp-element-caption' && $class !== 'wp-block-image__caption') {
                    return false;
                }
            }
        }
        return true;
    }

    private static function imgHasForbiddenParent(string $html, int $start, int $end): bool
    {
        $before = substr($html, 0, $start);
        $after = substr($html, $end);
        if (preg_match('/<a\b[^>]*>\s*$/i', $before) === 1 && preg_match('/^\s*<\/a>/i', $after) === 1) {
            return true;
        }
        if (preg_match('/<picture\b[^>]*>\s*$/i', $before) === 1) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $blockAttrs
     */
    private static function canDescend(string $tag): bool
    {
        return in_array($tag, ['ul', 'ol', 'table', 'blockquote', 'p'], true)
            || preg_match('/^h[1-6]$/', $tag) === 1;
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrapList(
        array $candidate,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        if (self::hasStyleAttribute($candidate['open'] . $candidate['inner'])) {
            return null;
        }
        $attrs = self::openingAttributes($candidate['open']);
        if ($attrs === null) {
            return null;
        }
        $block = [];
        if ($candidate['tag'] === 'ol') {
            $block['ordered'] = true;
        }
        foreach ($attrs as $attr) {
            $name = strtolower($attr['name']);
            $value = $attr['value'];
            if ($name === 'class') {
                $classes = array_values(array_filter(
                    preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    static fn (string $class): bool => $class !== 'wp-block-list',
                ));
                if ($classes !== []) {
                    $block['className'] = implode(' ', $classes);
                }
                continue;
            }
            if ($name === 'id') {
                if ($value === '') {
                    return null;
                }
                $block['anchor'] = $value;
                continue;
            }
            if ($name === 'start' && $candidate['tag'] === 'ol') {
                if (preg_match('/^-?\d+$/', $value) !== 1) {
                    return null;
                }
                $block['start'] = (int) $value;
                continue;
            }
            if ($name === 'reversed' && $candidate['tag'] === 'ol') {
                $block['reversed'] = true;
                continue;
            }
            return null;
        }
        $items = self::listItems($candidate['inner']);
        if ($items === null || $items === []) {
            return null;
        }
        $emitted = [];
        foreach ($items as $item) {
            $one = self::emitListItem($item, $saves, $comments, $serializer);
            if ($one === null) {
                return null;
            }
            $emitted[] = $one;
        }
        return self::emit('core/list', $block, $saves, $comments, $serializer, implode("\n\n", $emitted));
    }

    /**
     * @return list<array{open:string,inner:string}>|null
     */
    private static function listItems(string $inner): ?array
    {
        $offset = 0;
        $length = strlen($inner);
        $items = [];
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $inner, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            if (preg_match('/\G<li(?=[\s>])/i', $inner, $m, 0, $offset) !== 1) {
                return null;
            }
            $openEnd = self::tagEnd($inner, $offset);
            if ($openEnd === null) {
                return null;
            }
            $close = self::matchingClose($inner, 'li', $openEnd);
            if ($close === null) {
                return null;
            }
            $items[] = [
                'open'  => substr($inner, $offset, $openEnd - $offset),
                'inner' => substr($inner, $openEnd, $close['start'] - $openEnd),
            ];
            $offset = $close['end'];
        }
        return $items;
    }

    /**
     * @param array{open:string,inner:string} $item
     */
    private static function emitListItem(
        array $item,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        if (self::hasStyleAttribute($item['open'] . $item['inner'])) {
            return null;
        }
        $attrs = self::openingAttributes($item['open']);
        if ($attrs === null) {
            return null;
        }
        $block = [];
        foreach ($attrs as $attr) {
            $name = strtolower($attr['name']);
            if ($name === 'id') {
                if ($attr['value'] === '') {
                    return null;
                }
                $block['anchor'] = $attr['value'];
                continue;
            }
            return null;
        }
        $parts = self::listItemParts($item['inner']);
        if ($parts === null) {
            return null;
        }
        $block['content'] = $parts['content'];
        $nested = '';
        if ($parts['list'] !== null) {
            $nested = self::tryWrapList($parts['list'], $saves, $comments, $serializer);
            if ($nested === null) {
                return null;
            }
        }
        return self::emit('core/list-item', $block, $saves, $comments, $serializer, $nested);
    }

    /**
     * @return array{content:string,list:?array{start:int,end:int,tag:string,open:string,inner:string}}|null
     */
    private static function listItemParts(string $inner): ?array
    {
        if (preg_match('/<(ul|ol)(?=[\s>])/i', $inner, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = (int) $match[0][1];
            $prefix = substr($inner, 0, $at);
            if (self::hasUnsupportedInner($prefix)) {
                return null;
            }
            $tag = strtolower($match[1][0]);
            $openEnd = self::tagEnd($inner, $at);
            if ($openEnd === null) {
                return null;
            }
            $close = self::matchingClose($inner, $tag, $openEnd);
            if ($close === null || trim(substr($inner, $close['end'])) !== '') {
                return null;
            }
            return [
                'content' => $prefix,
                'list'    => [
                    'start' => $at,
                    'end'   => $close['end'],
                    'tag'   => $tag,
                    'open'  => substr($inner, $at, $openEnd - $at),
                    'inner' => substr($inner, $openEnd, $close['start'] - $openEnd),
                ],
            ];
        }
        if (self::hasUnsupportedInner($inner)) {
            return null;
        }
        return ['content' => $inner, 'list' => null];
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrapTable(
        array $candidate,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        $markup = $candidate['open'] . $candidate['inner'];
        if (self::hasStyleAttribute($markup) || preg_match('/\s(?:colspan|rowspan)\s*=/i', $markup) === 1) {
            return null;
        }
        $attrs = self::openingAttributes($candidate['open']);
        if ($attrs === null) {
            return null;
        }
        $block = ['hasFixedLayout' => false];
        $classes = [self::BARE_TABLE_CLASS];
        $tableClasses = [];
        foreach ($attrs as $attr) {
            $name = strtolower($attr['name']);
            $value = $attr['value'];
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                    if ($class !== 'wp-block-table' && $class !== self::BARE_TABLE_CLASS) {
                        $classes[] = $class;
                        $tableClasses[] = $class;
                    }
                }
                continue;
            }
            if ($name === 'id') {
                if ($value === '') {
                    return null;
                }
                $block['anchor'] = $value;
                continue;
            }
            return null;
        }
        $parts = self::tableParts($candidate['inner']);
        if ($parts === null) {
            return null;
        }
        $block['head'] = $parts['head'];
        $block['body'] = $parts['body'];
        if ($parts['foot'] !== []) {
            $block['foot'] = $parts['foot'];
        }
        if ($parts['caption'] !== null) {
            $block['caption'] = $parts['caption'];
        }
        if ($block['head'] === [] && $block['body'] === [] && ($block['foot'] ?? []) === []) {
            return null;
        }
        $block['className'] = implode(' ', array_values(array_unique($classes)));
        $emitted = self::emit('core/table', $block, $saves, $comments, $serializer);
        if ($emitted === null || !self::tableTextCompatible($candidate['inner'], $emitted)) {
            return null;
        }
        return self::tableFrontHtml($emitted, $tableClasses);
    }

    /**
     * save() (and later fix-blocks) emit compact table HTML. Pretty-printed
     * source keeps inter-row whitespace that strip_tags treats as word
     * boundaries; wrapping those tables would glue "open Wed" into "openWed".
     */
    private static function tableTextCompatible(string $inner, string $emitted): bool
    {
        $orig = preg_replace('/<caption\b[^>]*>.*?<\/caption>/is', '', $inner) ?? $inner;
        $emit = preg_replace('/<!--\s*\/?wp:[a-z-]+[^>]*-->/', '', $emitted) ?? $emitted;
        $emit = preg_replace('/<figcaption\b[^>]*>.*?<\/figcaption>/is', '', $emit) ?? $emit;
        $norm = static fn (string $s): string => preg_replace('/\s+/u', ' ', trim(strip_tags($s))) ?? '';
        return $norm($orig) === $norm($emit);
    }

    /**
     * Save() emits a compact table. Keep authored classes on the inner
     * <table> so layout rules still hit, and keep a boundary after rows
     * and sections so strip_tags text matches pretty-printed design HTML.
     *
     * @param list<string> $tableClasses
     */
    private static function tableFrontHtml(string $emitted, array $tableClasses): string
    {
        $emitted = preg_replace('/<\/(?:tr|thead|tbody|tfoot)>(?=<)/i', '$0 ', $emitted) ?? $emitted;
        if ($tableClasses === []) {
            return $emitted;
        }
        $class = htmlspecialchars(implode(' ', array_values(array_unique($tableClasses))), ENT_QUOTES);
        $updated = preg_replace(
            '/(<figure class="wp-block-table[^"]*">)<table>/',
            '$1<table class="' . $class . '">',
            $emitted,
            1,
        );
        return is_string($updated) ? $updated : $emitted;
    }

    /**
     * @return array{caption:?string,head:list<array{cells:list<array<string,string>>}>,body:list<array{cells:list<array<string,string>>}>,foot:list<array{cells:list<array<string,string>>}>}|null
     */
    private static function tableParts(string $inner): ?array
    {
        $offset = 0;
        $length = strlen($inner);
        $caption = null;
        $head = [];
        $body = [];
        $foot = [];
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $inner, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            if (preg_match('/\G<(caption|thead|tbody|tfoot|tr)(?=[\s>])/i', $inner, $m, 0, $offset) !== 1) {
                return null;
            }
            $tag = strtolower($m[1]);
            $openEnd = self::tagEnd($inner, $offset);
            if ($openEnd === null) {
                return null;
            }
            $open = substr($inner, $offset, $openEnd - $offset);
            $sectionAttrs = self::openingAttributes($open);
            if ($sectionAttrs === null || $sectionAttrs !== []) {
                return null;
            }
            $close = self::matchingClose($inner, $tag, $openEnd);
            if ($close === null) {
                return null;
            }
            $sectionInner = substr($inner, $openEnd, $close['start'] - $openEnd);
            if ($tag === 'caption') {
                if ($caption !== null || $head !== [] || $body !== [] || $foot !== []) {
                    return null;
                }
                if (self::hasUnsupportedInner($sectionInner) || self::hasStyleAttribute(substr($inner, $offset, $close['end'] - $offset))) {
                    return null;
                }
                $caption = $sectionInner;
            } elseif ($tag === 'tr') {
                $row = self::tableRow($sectionInner);
                if ($row === null) {
                    return null;
                }
                $body[] = $row;
            } else {
                $rows = self::tableRows($sectionInner);
                if ($rows === null) {
                    return null;
                }
                if ($tag === 'thead') {
                    $head = array_merge($head, $rows);
                } elseif ($tag === 'tfoot') {
                    $foot = array_merge($foot, $rows);
                } else {
                    $body = array_merge($body, $rows);
                }
            }
            $offset = $close['end'];
        }
        return ['caption' => $caption, 'head' => $head, 'body' => $body, 'foot' => $foot];
    }

    /**
     * @return list<array{cells:list<array<string,string>>}>|null
     */
    private static function tableRows(string $inner): ?array
    {
        $offset = 0;
        $length = strlen($inner);
        $rows = [];
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $inner, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            if (preg_match('/\G<tr(?=[\s>])/i', $inner, $m, 0, $offset) !== 1) {
                return null;
            }
            $openEnd = self::tagEnd($inner, $offset);
            if ($openEnd === null) {
                return null;
            }
            $open = substr($inner, $offset, $openEnd - $offset);
            $rowAttrs = self::openingAttributes($open);
            if ($rowAttrs === null || $rowAttrs !== []) {
                return null;
            }
            $close = self::matchingClose($inner, 'tr', $openEnd);
            if ($close === null) {
                return null;
            }
            $row = self::tableRow(substr($inner, $openEnd, $close['start'] - $openEnd));
            if ($row === null) {
                return null;
            }
            $rows[] = $row;
            $offset = $close['end'];
        }
        return $rows;
    }

    /**
     * @return array{cells:list<array<string,string>>}|null
     */
    private static function tableRow(string $inner): ?array
    {
        $offset = 0;
        $length = strlen($inner);
        $cells = [];
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $inner, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            if (preg_match('/\G<(td|th)(?=[\s>])/i', $inner, $m, 0, $offset) !== 1) {
                return null;
            }
            $tag = strtolower($m[1]);
            $openEnd = self::tagEnd($inner, $offset);
            if ($openEnd === null) {
                return null;
            }
            $close = self::matchingClose($inner, $tag, $openEnd);
            if ($close === null) {
                return null;
            }
            $open = substr($inner, $offset, $openEnd - $offset);
            $cellInner = substr($inner, $openEnd, $close['start'] - $openEnd);
            if (self::hasStyleAttribute($open . $cellInner) || self::hasUnsupportedInner($cellInner)) {
                return null;
            }
            $cellAttrs = self::openingAttributes($open);
            if ($cellAttrs === null) {
                return null;
            }
            $cell = ['content' => $cellInner, 'tag' => $tag];
            foreach ($cellAttrs as $attr) {
                $name = strtolower($attr['name']);
                if ($tag === 'th' && $name === 'scope' && in_array($attr['value'], ['col', 'row', 'colgroup', 'rowgroup'], true)) {
                    $cell['scope'] = $attr['value'];
                    continue;
                }
                return null;
            }
            $cells[] = $cell;
            $offset = $close['end'];
        }
        if ($cells === []) {
            return null;
        }
        return ['cells' => $cells];
    }

    /**
     * @param array{start:int,end:int,tag:string,open:string,inner:string} $candidate
     */
    private static function tryWrapQuote(
        array $candidate,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
    ): ?string {
        if (self::hasStyleAttribute($candidate['open'] . $candidate['inner'])) {
            return null;
        }
        $attrs = self::openingAttributes($candidate['open']);
        if ($attrs === null) {
            return null;
        }
        $block = [];
        foreach ($attrs as $attr) {
            $name = strtolower($attr['name']);
            $value = $attr['value'];
            if ($name === 'class') {
                $classes = array_values(array_filter(
                    preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                    static fn (string $class): bool => $class !== 'wp-block-quote',
                ));
                if ($classes !== []) {
                    $block['className'] = implode(' ', $classes);
                }
                continue;
            }
            if ($name === 'id') {
                if ($value === '') {
                    return null;
                }
                $block['anchor'] = $value;
                continue;
            }
            return null;
        }
        $parts = self::quoteParts($candidate['inner']);
        if ($parts === null) {
            return null;
        }
        if ($parts['citation'] !== null) {
            $block['citation'] = $parts['citation'];
        }
        $innerBlocks = [];
        foreach ($parts['blocks'] as $child) {
            $emitted = self::tryWrap($child, $child['open'] . $child['inner'], false, false, $saves, $comments, $serializer);
            if ($emitted === null) {
                return null;
            }
            $innerBlocks[] = $emitted;
        }
        if ($innerBlocks === []) {
            return null;
        }
        return self::emit('core/quote', $block, $saves, $comments, $serializer, implode("\n\n", $innerBlocks));
    }

    /**
     * @return array{blocks:list<array{start:int,end:int,tag:string,open:string,inner:string}>,citation:?string}|null
     */
    private static function quoteParts(string $inner): ?array
    {
        $offset = 0;
        $length = strlen($inner);
        $blocks = [];
        $citation = null;
        while ($offset < $length) {
            if (preg_match('/\G\s+/', $inner, $ws, 0, $offset) === 1) {
                $offset += strlen($ws[0]);
                continue;
            }
            if ($citation !== null) {
                return null;
            }
            if (preg_match('/\G<(p|h[1-6]|cite)(?=[\s>])/i', $inner, $m, 0, $offset) !== 1) {
                $rest = substr($inner, $offset);
                if ($blocks === [] && $citation === null && !self::hasUnsupportedInner($rest)) {
                    $blocks[] = [
                        'start' => $offset,
                        'end'   => $length,
                        'tag'   => 'p',
                        'open'  => '<p>',
                        'inner' => $rest,
                    ];
                    break;
                }
                return null;
            }
            $tag = strtolower($m[1]);
            $openEnd = self::tagEnd($inner, $offset);
            if ($openEnd === null) {
                return null;
            }
            $close = self::matchingClose($inner, $tag, $openEnd);
            if ($close === null) {
                return null;
            }
            $open = substr($inner, $offset, $openEnd - $offset);
            $childInner = substr($inner, $openEnd, $close['start'] - $openEnd);
            if ($tag === 'cite') {
                $citeAttrs = self::openingAttributes($open);
                if (
                    $citeAttrs === null
                    || $citeAttrs !== []
                    || self::hasUnsupportedInner($childInner)
                    || self::hasStyleAttribute($open . $childInner)
                ) {
                    return null;
                }
                $citation = $childInner;
            } else {
                $blocks[] = [
                    'start' => $offset,
                    'end'   => $close['end'],
                    'tag'   => $tag,
                    'open'  => $open,
                    'inner' => $childInner,
                ];
            }
            $offset = $close['end'];
        }
        if ($blocks === []) {
            return null;
        }
        return ['blocks' => $blocks, 'citation' => $citation];
    }

    private static function emit(
        string $name,
        array $blockAttrs,
        SaveStrategyRegistry $saves,
        CommentSerializer $comments,
        Serializer $serializer,
        string $innerBlocks = '',
    ): ?string {
        try {
            $saved = $saves->save($name, $blockAttrs, $innerBlocks);
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
            if (preg_match('/\G<(figure|img|ul|ol|table|blockquote|h[1-6]|p)(?=[\s\/>])/i', $html, $match, 0, $lt) !== 1) {
                $offset = $lt + 1;
                continue;
            }
            $tag = strtolower($match[1]);
            $openEnd = self::tagEnd($html, $lt);
            if ($openEnd === null) {
                return null;
            }
            $open = substr($html, $lt, $openEnd - $lt);
            if ($tag === 'img' || str_ends_with(rtrim($open, '>'), '/')) {
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
            $inner = substr($html, $openEnd, $close['start'] - $openEnd);
            if (
                ($tag === 'p' || preg_match('/^h[1-6]$/', $tag) === 1)
                && preg_match('/<(?:img|figure)\b/i', $inner) === 1
            ) {
                $offset = $openEnd;
                continue;
            }
            return [
                'start' => $lt,
                'end'   => $close['end'],
                'tag'   => $tag,
                'open'  => $open,
                'inner' => $inner,
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
        if (preg_match('/\G<!--\s*wp:([a-z0-9-]+)\b/i', $html, $match, 0, $lt) !== 1) {
            $end = strpos($html, '-->', $lt + 4);
            return $end === false ? strlen($html) : $end + 3;
        }
        $name = strtolower($match[1]);
        $offset = $lt + 4;
        $depth = 1;
        $length = strlen($html);
        while ($offset < $length && $depth > 0) {
            $next = strpos($html, '<!--', $offset);
            if ($next === false) {
                return $length;
            }
            if (preg_match('/\G<!--\s*\/wp:' . preg_quote($name, '/') . '(?=\s|-->)/i', $html, $m, 0, $next) === 1) {
                $depth--;
                $end = strpos($html, '-->', $next);
                $offset = $end === false ? $length : $end + 3;
                continue;
            }
            if (preg_match('/\G<!--\s*wp:' . preg_quote($name, '/') . '(?=\s|\{|-->)/i', $html, $m, 0, $next) === 1) {
                $depth++;
            }
            $offset = $next + 4;
        }
        return $offset;
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