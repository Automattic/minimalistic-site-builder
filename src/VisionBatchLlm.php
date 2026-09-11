<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Concurrent image checks with separate results for each request. */
interface VisionBatchLlm extends VisionLlm
{
    /**
     * Preserve request keys. Return null for each unavailable check.
     * A failed check must not remove a successful result or cause its repetition.
     *
     * @param array<array-key,array{prompt:string,image_bytes:string,mime:string,model?:string,max_tokens?:int,log_label?:string}> $requests
     * @return array<array-key,?string>
     */
    public function completeImageBatch(array $requests): array;
}
