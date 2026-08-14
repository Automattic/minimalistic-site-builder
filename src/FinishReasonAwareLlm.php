<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Optional Llm capability exposing how the latest single completion ended.
 *
 * Implementations reset the value before every complete() attempt. The value is
 * null before the first attempt, after a failed attempt, or when the provider
 * omitted a finish reason.
 */
interface FinishReasonAwareLlm extends Llm
{
    public function lastFinishReason(): ?string;
}
