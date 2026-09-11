<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Patterns;

/**
 * The artifact contract for the pattern composition.
 *
 * Every stage reads and writes files under the project, and those paths are
 * the interface between stages — not PHP types, which is what lets a host stop
 * after one stage, inspect what it produced, and resume. Naming them in one
 * place keeps a stage from inventing a path its neighbour does not read.
 *
 * Host inputs are seeds: written into the project before the first stage runs,
 * so they appear beside every derived artifact instead of hiding in
 * constructor state. The split between them is by size and by owner — the
 * approved inventory is large and belongs to the customer's network, the Brand
 * is small and belongs to the Brand record, and the rest is the request.
 */
final class PatternArtifacts
{
    /**
     * Site facts and guidelines, the requested pages, locale, and the
     * theme/block capabilities generation is allowed to assume.
     */
    public const REQUEST = 'host/request.json';

    /** The approved pattern inventory. Composition may emit nothing outside it. */
    public const INVENTORY = 'host/patterns.json';

    /** The Brand's theme.json partial, shaped { settings, styles }. */
    public const BRAND = 'host/brand.json';

    /** Host inputs, in the order a reader should meet them. */
    public const SEEDS = [
        'meta.json',
        self::REQUEST,
        self::INVENTORY,
        self::BRAND,
    ];

    /** Validated request + inventory, with defaults filled and versions pinned. */
    public const NORMALIZED = 'patterns/inputs.json';

    /** Which pages exist, their sections, and the shared-part policy. */
    public const PLAN = 'patterns/plan.json';

    /** Chosen patterns per page and shared part, before any content is written. */
    public const LAYOUTS = 'patterns/layouts.json';

    /**
     * Which inventory entry each section came from, and the ids assigned to it.
     *
     * Recorded separately from the layouts because a replay needs to make the
     * same choices before it reaches the model — otherwise a fixture run
     * composes a different site and the comparison it was meant to support is
     * worthless.
     */
    public const PROVENANCE = 'patterns/provenance.json';

    /** Per-page block trees after content has been written into the patterns. */
    public const PAGES = 'patterns/pages/*';

    /** Shared parts — header, footer — that every page renders inside. */
    public const PARTS = 'patterns/parts';

    /** Images to import and the navigation targets to resolve, by reference. */
    public const MEDIA = 'patterns/media.json';

    /** What the host applies: pages, shared parts, Brand, and media references. */
    public const BUNDLE = 'bundle/content.json';

    /** What the export found: counts, and every check that did not pass. */
    public const REPORT = 'patterns/report.json';
}
