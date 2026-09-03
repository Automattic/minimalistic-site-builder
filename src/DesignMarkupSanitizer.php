<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Shared trust boundary for untrusted design HTML.
 *
 * The implementation lives behind this frozen facade so every design step uses
 * one hardened sanitizer without widening the public contract.
 */
final class DesignMarkupSanitizer
{
    /**
     * @param list<string> $warnings
     */
    public static function sanitize(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $html = DesignMarkupSanitizerEngine::sanitize(
            $html,
            $path,
            $context,
            $warnings,
        );
        return self::scrubHeadStyles($html, $path, $context, $warnings);
    }

    /**
     * Scrub every fetch out of the head stylesheet at the write, not at merge.
     *
     * The engine keeps a head <style> element whole. Its text becomes
     * design/site.css and design/preview.css, and the screenshot pass, the
     * design floor and the critique render those bytes long before
     * page-styles scrubs the CSS it merges into theme/style.css. Until now a
     * model `@import url(https://…)` or `background: url(https://…)` fired
     * during the build, in the renderer (BIGR-972).
     *
     * Three rungs, smallest unit first, the same ladder theme.json custom
     * CSS climbs: CssScrub removes `@import` and declarations naming an
     * external authority; CssChecks drops every remaining resource-loading
     * declaration; when a loading form still survives, the sheet is emptied
     * rather than delivered unreviewed. Every removal is recorded.
     *
     * @param list<string> $warnings
     */
    private static function scrubHeadStyles(
        string $html,
        string $path,
        string $context,
        array &$warnings,
    ): string {
        $result = preg_replace_callback(
            '/(<style\b[^>]*>)(.*?)(<\/style\s*>)/is',
            static function (array $match) use ($path, $context, &$warnings): string {
                $css = $match[2];
                $scrubbed = CssScrub::scrub($css);
                foreach ($scrubbed['removals'] as $removal) {
                    $warnings[] = "malformed_design: {$path} context {$context}; authored "
                        . Warnings::value($removal['authored_value'])
                        . "; delivered {$removal['delivered_value']}; disposition {$removal['disposition']}";
                }
                [$repaired, $dropped] = CssChecks::dropResourceLoadingDeclarations($scrubbed['css']);
                foreach ($dropped as $declaration) {
                    $warnings[] = "malformed_design: {$path} context {$context}; authored "
                        . Warnings::value(trim($declaration['raw']))
                        . '; delivered removed; disposition removed a resource-loading CSS value'
                        . ' — a design stylesheet may not fetch images, fonts, or stylesheets';
                }
                // The fallback judges comment-free CSS: a comment that
                // mentions url() loads nothing, and emptying the whole
                // design stylesheet for it would cut far above the
                // smallest harmful unit and fail the preview contract.
                if (CssChecks::resourceLoadingProblem(CssChecks::withoutComments($repaired)) !== null) {
                    $warnings[] = "malformed_design: {$path} context {$context}; authored "
                        . Warnings::value($css)
                        . '; delivered removed; disposition removed the whole design stylesheet'
                        . ' — a resource-loading form survived declaration-level removal';
                    $repaired = '';
                }
                return $match[1] . $repaired . $match[3];
            },
            $html,
        );
        return $result ?? $html;
    }
}
