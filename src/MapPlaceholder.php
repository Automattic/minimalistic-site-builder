<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The JP_MAP placeholder contract, in one place.
 *
 * A build that runs with map placeholders enabled leaves a paragraph block
 * whose text is a map spec, for the host to replace with its own real map.
 *
 * A form is buildable from its spec alone; a map is not. The block needs
 * coordinates and only the host can turn an address into them, so the spec
 * carries the address the site stated and nothing more precise — the library
 * never asks a language model for a latitude. For the same reason there is no
 * default map the way a missing contact form falls back to name/email/message:
 * a map needs a real place, and the library has none to invent.
 *
 * @see FormPlaceholder for why one file owns a grammar both sides read.
 * @see HostPlaceholder for how the block itself is located.
 * @see prompts/jetpack-map.md, which teaches the model this same grammar.
 */
final class MapPlaceholder
{
    public const MARKER_NAME = 'JP_MAP';

    public const MARKER = self::MARKER_NAME . ':';

    /** Class the host locates a placeholder block by. */
    public const CLASS_NAME = 'jetpack-map-placeholder';

    /**
     * Every placeholder block in some markup, whole block and spec text.
     *
     * @return list<array{block:string, spec:string}>
     */
    public static function find(string $markup): array
    {
        return HostPlaceholder::find($markup, self::MARKER_NAME, self::CLASS_NAME);
    }

    /** How many times the marker appears at all, placeholder or not. */
    public static function markerCount(string $markup): int
    {
        return HostPlaceholder::markerCount($markup, self::MARKER_NAME);
    }

    /**
     * A spec, decoded — or a sentence saying why it cannot be.
     *
     * @return array{address:string, title:string}|string
     */
    public static function parse(string $spec): array|string
    {
        $body = trim(substr(trim($spec), strlen(self::MARKER)));
        $parts = explode('|', $body);
        if (count($parts) !== 2) {
            return count($parts) . ' pipe-separated parts, expected 2';
        }

        [$address, $title] = array_map('trim', $parts);
        if ($address === '') {
            return 'empty address';
        }
        if ($title === '') {
            return 'empty marker title';
        }

        return ['address' => $address, 'title' => $title];
    }
}
