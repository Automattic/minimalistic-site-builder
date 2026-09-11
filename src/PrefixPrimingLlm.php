<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Use one requested result to prepare the cache before the other requests start. */
interface PrefixPrimingLlm extends Llm
{
    public function canPrimeBatch(array $requests): bool;

    public function completePrimedBatch(array $requests): TextBatchResult;
}
