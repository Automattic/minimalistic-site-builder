<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Shared DOM loading that survives UTF-8. */
final class Html
{
    /**
     * loadHTML without an encoding hint defaults to ISO-8859-1 and
     * double-encodes UTF-8 (en-dash -> "â€“"). Prepend an XML encoding PI so
     * libxml reads bytes as UTF-8, then drop the PI node so a whole-document
     * saveHTML() can't emit a stray "<?xml ?>".
     */
    public static function loadUtf8Html(string $html, int $flags): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, $flags);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }
        return $dom;
    }
}
