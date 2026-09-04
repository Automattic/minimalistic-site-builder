<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * FROZEN CONTRACT — html-islands slice 1. Read-only after the freeze commit.
 *
 * Env key: SITE_BUILD_GRAPH = blocks | html-first | html-islands
 * Single reader of that key: StepComposition::selectedGraph().
 * meta.json `graph` already stores a name; the record format does not change.
 *
 * Resume: StepComposition::resumeGraph(?string $recorded, ?string $requested): ?string
 *   - null or '' recorded → null (caller's selection stands)
 *   - unknown or retired recorded name → InvalidArgumentException naming it
 *   - requested !== null && requested !== recorded → existing contradiction refusal
 *
 * html-islands is a known name with no composition yet. Asking to build it
 * throws a clear "not yet implemented" error at composition assembly, not
 * a fatal deep in the graph.
 */
final class Graph
{
    public const ENV = 'SITE_BUILD_GRAPH';

    public const BLOCKS = 'blocks';
    public const HTML_FIRST = 'html-first';
    public const HTML_ISLANDS = 'html-islands';

    /** @var list<string> */
    public const KNOWN = [self::BLOCKS, self::HTML_FIRST, self::HTML_ISLANDS];

    public const NOT_IMPLEMENTED = 'html-islands graph is not yet implemented';
}
