<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Claude Code used as a subscription-backed completion transport. */
final class ClaudeCliLlm extends HarnessCliLlm
{
    public function __construct(
        string $model,
        string $binary = 'claude',
        int $cap = 4,
        int $timeoutSeconds = 300,
    ) {
        parent::__construct($binary, $model, $cap, $timeoutSeconds);
    }

    protected function argvFor(array $request, string $model): array
    {
        throw new \LogicException('ClaudeCliLlm argv implementation pending.');
    }

    protected function parseResponse(string $stdout, string $stderr, int $exit): array
    {
        throw new \LogicException('ClaudeCliLlm response implementation pending.');
    }
}
