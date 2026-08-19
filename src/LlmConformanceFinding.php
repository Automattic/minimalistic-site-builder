<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * One conformance check's verdict against a host's Llm implementation.
 *
 * `detail` explains a failure in terms a host maintainer can act on: which
 * clause of the Llm contract was violated and what was observed instead. It is
 * also filled in on a pass, so a green report still shows what was measured.
 */
final class LlmConformanceFinding
{
    public const TIER_STRUCTURAL = 'structural';
    public const TIER_LIVE = 'live';

    public function __construct(
        /** Stable check id, e.g. "cached_prefixes_reach_the_model". */
        public readonly string $check,
        public readonly bool $passed,
        public readonly string $detail,
        /** TIER_STRUCTURAL needs no model; TIER_LIVE spends one completion. */
        public readonly string $tier,
        /** True when the check could not run at all (host lacks the capability). */
        public readonly bool $skipped = false,
    ) {
    }

    public static function pass(string $check, string $detail, string $tier): self
    {
        return new self($check, true, $detail, $tier);
    }

    public static function fail(string $check, string $detail, string $tier): self
    {
        return new self($check, false, $detail, $tier);
    }

    public static function skip(string $check, string $detail, string $tier): self
    {
        return new self($check, true, $detail, $tier, skipped: true);
    }
}
