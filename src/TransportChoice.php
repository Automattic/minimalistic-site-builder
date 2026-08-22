<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * One resolved transport decision: what to build, and why it was chosen.
 *
 * `reason` is not decoration — it is echoed before any spend, because "which
 * transport?" is reconstructable after the fact and "why that one?" is not.
 */
final class TransportChoice
{
    public const KIND_API        = 'api';
    public const KIND_CLAUDE_CLI = 'claude-cli';
    public const KIND_CODEX_CLI  = 'codex-cli';
    public const KIND_GROK_CLI   = 'grok-cli';

    public const KINDS = [self::KIND_API, self::KIND_CLAUDE_CLI, self::KIND_CODEX_CLI, self::KIND_GROK_CLI];

    public function __construct(
        public readonly string $kind,
        public readonly string $reason,
        public readonly ?string $binary = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                "unknown transport kind '{$kind}'; known: " . implode(', ', self::KINDS)
            );
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('TransportChoice reason must be non-empty');
        }
    }

    /** Whether this choice spends a flat subscription rather than a metered key. */
    public function isSubscription(): bool
    {
        return $this->kind !== self::KIND_API;
    }
}
