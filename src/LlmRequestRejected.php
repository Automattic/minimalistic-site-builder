<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * An Llm implementation refusing a request it was handed, before any transport.
 *
 * This exists so "the host rejected this" is a fact a caller can read, rather
 * than something it has to infer. LlmConformance needs exactly that: its
 * structural tier hands deliberately malformed `cached_prefixes` to a host and
 * has to tell a local rejection from a transport that merely failed. Bad
 * credentials, a DNS failure and a timeout all throw too, and the tier is
 * advertised as safe to run on every commit — precisely where a key is most
 * likely to be absent or wrong. Guessing there produces a green report for an
 * adapter that validated nothing, which is worse than no check at all.
 *
 * Implementations should throw this (or a subclass) for any request they refuse
 * on inspection, and let transport failures surface as whatever their transport
 * raises. Extends RuntimeException so existing callers catching that keep
 * working.
 */
class LlmRequestRejected extends \RuntimeException
{
}
