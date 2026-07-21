<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Deterministic strip of script-capable markup from generated block content.
 *
 * Every part is raw LLM output rendered verbatim on the site, and the page
 * markup is later stored as post content with the kses content filter
 * suspended (the seeder plugin — kses would mangle block comments), so
 * nothing between the model and the visitor's browser would otherwise stop a
 * <script> tag, an inline event handler, or a javascript: URL — a valid
 * core/html block can carry all three. Runs at markup intake (SectionsStep),
 * one choke point for the header, the footer, and every section; the seeder
 * plugin applies the same rules again at activation (ScaffoldPluginStep) in
 * case a page file was edited between build and seed. Keep the two in sync.
 *
 * Pure — unit-testable.
 */
final class MarkupSanitizer
{
    public static function sanitize(string $markup): string
    {
        // Script bodies go entirely; for the rest of the executable-element
        // family the tags alone go (their inner content is inert text).
        $markup = (string) preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $markup);
        $markup = (string) preg_replace('#</?(script|iframe|object|embed|applet|base)\b[^>]*>#i', '', $markup);
        // Inline event handlers (onclick, onload, …) — quoted or bare —
        // matched only inside tags so prose is never touched.
        $markup = (string) preg_replace_callback(
            '#<[a-z][^>]*>#i',
            static fn (array $m): string => (string) preg_replace(
                '#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
                '',
                $m[0]
            ),
            $markup
        );
        // Executable URL schemes; the neutralized attribute stays so the
        // block grammar (and the button-needs-an-href rule) remain intact.
        return (string) preg_replace(
            '#\b(href|src|xlink:href|formaction|action)\s*=\s*(["\'])\s*(?:javascript|vbscript|data)\s*:[^"\']*\2#i',
            '$1=$2#$2',
            $markup
        );
    }
}
