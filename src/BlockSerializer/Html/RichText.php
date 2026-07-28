<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Html;

/**
 * RichText sourcing normalization.
 *
 * Gutenberg's RichTextData retains the selected element's DOM innerHTML as
 * its serializer-facing value. Consequently whitespace and inline formatting
 * remain authored, while entity spellings, attribute quoting, tag case, void
 * tags, and literal non-breaking spaces follow the HTML fragment serializer.
 */
final class RichText
{
    public static function normalize(
        string|HtmlNode|HtmlFragment $value,
        bool $preserveWhiteSpace = false,
    ): string {
        // preserveWhiteSpace affects RichText's internal editing record. The
        // public serialized value is originalHTML in both modes, so it does
        // not alter this compatibility boundary.
        unset($preserveWhiteSpace);

        if ($value instanceof HtmlFragment) {
            return $value->innerHtml();
        }
        if ($value instanceof HtmlNode) {
            return $value->innerHtml();
        }
        return HtmlFragment::parse($value)->innerHtml();
    }

    /**
     * Mirror RichTextData::fromHTMLString(), used when a deprecation migration
     * returns a plain string which createBlock() then casts back to RichText.
     * Unlike the DOM-sourced originalHTML path above, the RichText serializer
     * writes non-breaking spaces as literal U+00A0 bytes.
     */
    public static function fromHtmlString(string $value): string
    {
        $fragment = HtmlFragment::parse($value);
        return str_replace(
            '&nbsp;',
            "\u{00A0}",
            self::wordpressSafeInnerHtml($fragment->root()),
        );
    }

    public static function plainText(string|HtmlNode|HtmlFragment $value): string
    {
        if ($value instanceof HtmlFragment) {
            return $value->textContent();
        }
        if ($value instanceof HtmlNode) {
            return $value->textContent();
        }
        return HtmlFragment::parse($value)->textContent();
    }

    /**
     * Serialize RichText recreation for WordPress' runtime HTML processor.
     *
     * A browser's fragment serializer leaves angle brackets literal inside a
     * quoted attribute. WordPress' tag processor can treat that literal `>` as
     * the tag boundary, corrupting otherwise valid saved markup. RichText
     * recreated by a deprecation is headed back into block save output, so use
     * the stricter React-style attribute spelling at this boundary.
     */
    private static function wordpressSafeInnerHtml(HtmlNode $node): string
    {
        $html = '';
        foreach ($node->children() as $child) {
            $html .= self::wordpressSafeOuterHtml($child);
        }
        return $html;
    }

    private static function wordpressSafeOuterHtml(HtmlNode $node): string
    {
        if (!$node->isElement()) {
            return $node->outerHtml();
        }

        $tag = (string) $node->tagName();
        $html = '<' . $tag;
        foreach ($node->attributes() as $attribute) {
            $html .= ' ' . $attribute['name'] . '="'
                . self::wordpressSafeAttribute($attribute['value']) . '"';
        }
        $html .= '>';
        if (HtmlNode::isVoidTag($tag)) {
            return $html;
        }
        return $html . self::wordpressSafeInnerHtml($node) . '</' . $tag . '>';
    }

    private static function wordpressSafeAttribute(string $value): string
    {
        return str_replace(
            ['&', "\u{00A0}", '"', '<', '>'],
            ['&amp;', '&nbsp;', '&quot;', '&lt;', '&gt;'],
            $value,
        );
    }
}
