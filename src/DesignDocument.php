<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared load/locate/sanitize boundary for untrusted design HTML.
 *
 * Island-pages and transform-chrome both consume this so landmark location
 * and sanitizing cannot drift apart. parse() keeps genuine tag mismatches
 * as structural errors and drops the HTML5-unknown-tag notices libxml
 * emits for every real design page.
 */
final class DesignDocument
{
    /** libxml XML_HTML_UNKNOWN_TAG — HTML5 elements the HTML4 parser does not know. */
    private const LIBXML_HTML_UNKNOWN_TAG = 801;

    /** libxml XML_ERR_TAG_NAME_MISMATCH — also used for "Unexpected end tag". */
    private const LIBXML_TAG_MISMATCH = 76;

    private function __construct(private \DOMDocument $dom)
    {
    }

    /**
     * @param list<string> $structuralErrors
     */
    public static function parse(string $html, array &$structuralErrors = []): ?self
    {
        $structuralErrors = [];
        if ($html === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return null;
        }

        foreach ($errors as $error) {
            if (self::isStructuralError($error)) {
                $structuralErrors[] = trim($error->message);
            }
        }

        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }

        return new self($dom);
    }

    public function main(): ?\DOMElement
    {
        $element = $this->dom->getElementsByTagName('main')->item(0);
        return $element instanceof \DOMElement ? $element : null;
    }

    public function header(): ?\DOMElement
    {
        return $this->topLevelLandmark('header');
    }

    public function footer(): ?\DOMElement
    {
        return $this->topLevelLandmark('footer');
    }

    public function html(\DOMElement $element): string
    {
        return (string) $this->dom->saveHTML($element);
    }

    /**
     * @param list<string> $warnings
     */
    public function sanitizedHtml(
        \DOMElement $element,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        return DesignMarkupSanitizer::sanitize(
            $this->html($element),
            $path,
            $context,
            $warnings,
        );
    }

    public function styles(): string
    {
        $css = '';
        foreach ($this->dom->getElementsByTagName('style') as $style) {
            $css .= $style->textContent;
        }
        return $css;
    }

    private static function isStructuralError(\LibXMLError $error): bool
    {
        if ($error->code === self::LIBXML_HTML_UNKNOWN_TAG) {
            return false;
        }
        if (stripos($error->message, 'processing instruction') !== false) {
            return false;
        }
        return $error->code === self::LIBXML_TAG_MISMATCH;
    }

    private function topLevelLandmark(string $tag): ?\DOMElement
    {
        foreach ($this->dom->getElementsByTagName($tag) as $element) {
            if (!$this->isDescendantOfTag($element, 'main')) {
                return $element;
            }
        }
        return null;
    }

    private function isDescendantOfTag(\DOMElement $element, string $tag): bool
    {
        for ($node = $element->parentNode; $node !== null; $node = $node->parentNode) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === $tag) {
                return true;
            }
        }
        return false;
    }
}
