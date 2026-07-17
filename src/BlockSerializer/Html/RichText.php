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
        return str_replace(
            '&nbsp;',
            "\u{00A0}",
            HtmlFragment::parse($value)->innerHtml(),
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
}
