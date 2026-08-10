<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonBoolean;
use Automattic\SiteBuild\BlockSerializer\Json\JsonDecoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNumber;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;

/**
 * Lifts bare HTML `<li>` children of an authored wp:list block into
 * wp:list-item inner blocks before the block fixer runs.
 *
 * The section author sometimes writes a list as plain markup —
 * `<!-- wp:list --><ul><li>…</li></ul><!-- /wp:list -->` — without the
 * wp:list-item delimiters Core's grammar requires. Re-serialization
 * regenerates a list's save output from its inner blocks only, so every
 * bare item would be dropped and an empty `<ul>` delivered. Mirroring the
 * authored HTML into real block structure ahead of the fixer preserves the
 * content without touching the pinned transform itself.
 *
 * The lift is deliberately bounded: the list body must be exactly one flat
 * `<ul>`/`<ol>` whose direct children are bare `<li>` elements, existing
 * wp:list-item blocks, or a mixture of both. Nested lists, stray text between
 * items, and unbalanced markup remain byte-identical for the fixer's own
 * degradation path. Empty bare items are the one reviewed removal: they are
 * dead UI, so the smallest harmful unit is removed with a durable warning.
 * Lifted output is already block-structured, so a second pass is idempotent.
 */
final class BareListItemLift
{
    /** @return array{markup:string, notes:list<string>, warnings:list<string>} */
    public static function fix(string $markup): array
    {
        if (!str_contains($markup, '<!-- wp:list')) {
            return ['markup' => $markup, 'notes' => [], 'warnings' => []];
        }

        $notes = [];
        $warnings = [];
        $ordinal = -1;
        $fixed = preg_replace_callback(
            '/(<!-- wp:list(?=\s)(?:(?!-->)[\s\S])*-->)([\s\S]*?)(<!-- \/wp:list -->)/',
            function (array $block) use (&$notes, &$warnings, &$ordinal): string {
                $ordinal++;
                $list = self::flatListBody($block[2]);
                if ($list === null
                    || preg_match('/<(?:ul|ol)\b/i', $list['items']) === 1
                    || preg_match('/<!-- wp:list(?=\s)/', $list['items']) === 1
                ) {
                    return $block[0];
                }

                $items = self::liftItems($list['items'], $ordinal);
                if ($items === null || ($items['lifted'] === 0 && $items['removed'] === 0)) {
                    return $block[0];
                }

                $mirrored = [];
                $opener = self::mirrorWrapperSemantics(
                    $block[1],
                    $list['tag'],
                    $list['opening'],
                    $mirrored,
                );
                if ($opener === null) {
                    // Invalid comment JSON is outside this bounded repair. The
                    // block fixer will isolate the file and retain its bytes.
                    return $block[0];
                }

                if ($items['lifted'] > 0) {
                    $notes[] = "wp:list[{$ordinal}]: lifted {$items['lifted']} bare <li> item(s) "
                        . 'into wp:list-item blocks';
                }
                if ($mirrored !== []) {
                    $notes[] = "wp:list[{$ordinal}]: mirrored HTML-only list semantics into comment attributes: "
                        . implode(', ', $mirrored);
                }
                if ($items['removed'] > 0) {
                    $notes[] = "wp:list[{$ordinal}]: removed {$items['removed']} empty bare <li> item(s)";
                    array_push($warnings, ...$items['warnings']);
                }

                return $opener
                    . $list['leading'] . $list['opening']
                    . "\n" . trim($items['markup']) . "\n"
                    . $list['closing'] . $list['trailing']
                    . $block[3];
            },
            $markup,
        );

        return ['markup' => $fixed ?? $markup, 'notes' => $notes, 'warnings' => $warnings];
    }

    /**
     * @return array{leading:string,opening:string,tag:string,items:string,closing:string,trailing:string}|null
     */
    private static function flatListBody(string $body): ?array
    {
        $leadingLength = strspn($body, " \t\r\n\f");
        $leading = substr($body, 0, $leadingLength);
        $rest = substr($body, $leadingLength);
        $opening = MarkupScan::wrapperTag($rest, 0);
        if ($opening === null || preg_match('/^<(ul|ol)(?=[\s>])/i', $opening, $tag) !== 1) {
            return null;
        }

        $afterOpening = substr($rest, strlen($opening));
        if (preg_match('/<\/(ul|ol)>([\x20\t\r\n\f]*)$/i', $afterOpening, $close, PREG_OFFSET_CAPTURE) !== 1
            || strtolower($close[1][0]) !== strtolower($tag[1])
        ) {
            return null;
        }

        return [
            'leading' => $leading,
            'opening' => $opening,
            'tag' => strtolower($tag[1]),
            'items' => substr($afterOpening, 0, $close[0][1]),
            'closing' => substr($close[0][0], 0, strlen($close[0][0]) - strlen($close[2][0])),
            'trailing' => $close[2][0],
        ];
    }

    /**
     * @return array{markup:string,lifted:int,removed:int,warnings:list<string>}|null
     */
    private static function liftItems(string $items, int $listOrdinal): ?array
    {
        $proper = '<!-- wp:list-item(?=\s)(?:(?!-->)[\s\S])*-->'
            . '[\s\S]*?<!-- \/wp:list-item -->';
        $bare = '<li(?=[\s>])(?<attributes>(?:\s[^>]*)?)>(?<content>[\s\S]*?)<\/li>';
        $pattern = '/(?<proper>' . $proper . ')|(?<bare>' . $bare . ')/i';
        if (preg_match_all($pattern, $items, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $markup = '';
        $cursor = 0;
        $lifted = 0;
        $removed = 0;
        $warnings = [];
        $itemOrdinal = -1;
        foreach ($matches as $match) {
            $itemOrdinal++;
            $start = $match[0][1];
            $gap = substr($items, $cursor, $start - $cursor);
            if (trim($gap) !== '') {
                return null;
            }
            $markup .= $gap;

            if (($match['proper'][1] ?? -1) !== -1) {
                $markup .= $match[0][0];
            } else {
                $content = $match['content'][0];
                if (str_contains($content, '<!-- wp:')
                    || preg_match('/<\/?li\b/i', $content) === 1
                ) {
                    return null;
                }
                if (!self::hasMeaningfulContent($content)) {
                    $removed++;
                    $warnings[] = "wp:list[{$listOrdinal}]/li[{$itemOrdinal}]: authored value "
                        . Warnings::value($match['bare'][0])
                        . ' -> delivered removed; disposition: removed empty bare list item before serialization';
                } else {
                    $lifted++;
                    $markup .= "<!-- wp:list-item -->\n"
                        . '<li' . $match['attributes'][0] . '>' . $content . "</li>\n"
                        . '<!-- /wp:list-item -->';
                }
            }
            $cursor = $start + strlen($match[0][0]);
        }

        $tail = substr($items, $cursor);
        if (trim($tail) !== '') {
            return null;
        }
        $markup .= $tail;

        return [
            'markup' => $markup,
            'lifted' => $lifted,
            'removed' => $removed,
            'warnings' => $warnings,
        ];
    }

    private static function hasMeaningfulContent(string $content): bool
    {
        $withoutComments = preg_replace('/<!--(?:(?!-->)[\s\S])*-->/', '', $content) ?? $content;
        $text = html_entity_decode(strip_tags($withoutComments), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\p{Z}\x{FEFF}]+/u', '', $text) ?? $text;
        if ($text !== '') {
            return true;
        }
        return preg_match('/<(?:img|svg|video|audio|iframe|object|embed)\b/i', $withoutComments) === 1;
    }

    /**
     * Mirror wrapper semantics only when the block comment omitted them. An
     * explicit comment value remains authoritative when the two channels
     * disagree.
     *
     * @param list<string> $mirrored
     */
    private static function mirrorWrapperSemantics(
        string $opener,
        string $tag,
        string $openingTag,
        array &$mirrored,
    ): ?string {
        if ($opener === '<!-- wp:list -->') {
            $attrs = new JsonObject();
        } elseif (preg_match('/^<!-- wp:list (\{[\s\S]*\}) -->$/', $opener, $json) === 1) {
            try {
                $attrs = (new JsonDecoder($json[1], mergeDuplicateObjectKeys: true))->decode();
            } catch (\InvalidArgumentException) {
                return null;
            }
            if (!$attrs instanceof JsonObject) {
                return null;
            }
        } else {
            return null;
        }

        if ($tag === 'ol' && !$attrs->has('ordered')) {
            $attrs->set('ordered', new JsonBoolean(true));
            $mirrored[] = 'ordered=true';
        }
        if ($tag === 'ol' && !$attrs->has('start')) {
            $start = self::htmlAttribute($openingTag, 'start');
            if ($start['present'] && $start['value'] !== null
                && preg_match('/^[+-]?[0-9]+$/', $start['value']) === 1
                && strlen(ltrim($start['value'], '+-')) <= 10
            ) {
                $attrs->set('start', JsonNumber::fromLexeme($start['value']));
                $mirrored[] = 'start=' . (int) $start['value'];
            }
        }
        if ($tag === 'ol' && !$attrs->has('reversed')) {
            $reversed = self::htmlAttribute($openingTag, 'reversed');
            if ($reversed['present']) {
                $attrs->set('reversed', new JsonBoolean(true));
                $mirrored[] = 'reversed=true';
            }
        }

        return $mirrored === []
            ? $opener
            : '<!-- wp:list ' . JsJsonEncoder::serializeAttributes($attrs) . ' -->';
    }

    /** @return array{present:bool,value:?string} */
    private static function htmlAttribute(string $tag, string $name): array
    {
        $pattern = '/[\x20\t\r\n\f]' . preg_quote($name, '/')
            . '(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?(?=[\s>])/i';
        if (preg_match($pattern, $tag, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return ['present' => false, 'value' => null];
        }
        return ['present' => true, 'value' => $match[1] ?? $match[2] ?? $match[3] ?? null];
    }
}
