<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Transport-agnostic reference-site analysis. Implementations turn URLs into
 * design briefs. Mirrors the Llm and ImageClient interfaces: steps depend on
 * the contract, tests inject a fake, production injects the real transport.
 */
interface UrlAnalyzer
{
    /**
     * Analyze at most InspirationUrls::MAX unique URLs CONCURRENTLY. Successful
     * briefs and actionable failures are keyed by the URL that produced them.
     * A failed URL is absent from references rather than present-and-empty —
     * inspiration is best-effort and a failure must never abort a build.
     *
     * typography, layout, mood and screenshots are OPTIONAL: an implementation
     * that cannot produce them omits them and every consumer degrades. They are
     * not decoration, though — screenshots are what let the design steps see
     * the page rather than only read about it, so an implementation that can
     * supply them should. Screenshot entries are basenames of PNG files living
     * in the build's inspiration log directory; anything else resolves to
     * nothing downstream.
     *
     * @param  list<string> $urls
     * @return array{
     *     references:array<string,array{url:string,page_type:string,owner_type:string,
     *         style:string,typography?:string,layout?:string,mood?:list<string>,
     *         colors:list<array{hex:string,name:string,role:string}>,
     *         sections:list<array{category:string,description:string}>,
     *         screenshots?:list<string>}>,
     *     failures:array<string,array{url:string,
     *         kind:'gate_rejected'|'malformed_response'|'transport_error'|'http_error'|'abandoned',
     *         message:string}>
     * }
     */
    public function analyze(array $urls): array;
}
