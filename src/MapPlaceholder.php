<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The JP_MAP placeholder contract, in one place.
 *
 * A build that runs with map placeholders enabled leaves a paragraph block
 * whose text is a map spec, for the host to replace with its own real map
 * (see prompts/jetpack-map.md, which teaches the model this same grammar).
 * Both sides read the grammar from here, for the reason FormPlaceholder gives:
 * two copies drift, and a spec the library accepts and the host cannot read
 * ships to the visitor as grey body text with nothing else noticing.
 *
 * Where this contract differs from the form one, and why:
 *
 * - A form is buildable from its spec alone. A map is not: the block needs
 *   coordinates, and only the host can turn an address into them. So the spec
 *   carries the address the site actually stated and nothing more precise —
 *   the library never asks a language model for a latitude.
 * - There is no default map to fall back on. A missing form can be replaced
 *   with a name/email/message one, because those three fields are the same
 *   everywhere. A missing map has no such default: a map needs a real place,
 *   and the library has none to invent.
 *
 * @see HostPlaceholder for how the block itself is located.
 */
final class MapPlaceholder
{
    /** The marker's name, with no spec behind it. */
    public const MARKER_NAME = 'JP_MAP';

    /** Prefix that opens a spec. */
    public const MARKER = self::MARKER_NAME . ':';

    /** Class the host locates a placeholder block by. */
    public const CLASS_NAME = 'jetpack-map-placeholder';

    /**
     * How close the map sits, and the zoom level each word means.
     *
     * A bounded vocabulary rather than a number, because the useful zoom for
     * a place is something the model can reason about from the section's
     * purpose — find the front door, or see which region is served — while a
     * raw level is a magic number it would guess at.
     */
    public const SCOPES = [
        'street'       => 16,
        'neighborhood' => 14,
        'city'         => 12,
        'region'       => 9,
    ];

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
     * Drop the marker paragraphs no host will ever substitute.
     *
     * @return array{markup:string, removed:int}
     */
    public static function stripLooseMarkers(string $markup): array
    {
        return HostPlaceholder::stripLooseMarkers($markup, self::MARKER_NAME, self::CLASS_NAME);
    }

    /**
     * A spec, decoded — or a sentence saying why it cannot be.
     *
     * @return array{address:string, title:string, scope:string, zoom:int}|string
     */
    public static function parse(string $spec): array|string
    {
        $body = trim(substr(trim($spec), strlen(self::MARKER)));
        $parts = explode('|', $body);
        if (count($parts) !== 3) {
            return count($parts) . ' pipe-separated parts, expected 3';
        }

        [$address, $title, $scope] = array_map('trim', $parts);
        if ($address === '') {
            return 'empty address';
        }
        if ($title === '') {
            return 'empty marker title';
        }
        if (!array_key_exists($scope, self::SCOPES)) {
            return "unknown scope '{$scope}'";
        }

        return [
            'address' => $address,
            'title'   => $title,
            'scope'   => $scope,
            'zoom'    => self::SCOPES[$scope],
        ];
    }
}
