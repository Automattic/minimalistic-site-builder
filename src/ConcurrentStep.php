<?php
declare(strict_types=1);

/**
 * A step whose LLM work is expressed as a set of independent JSON requests, so
 * the requests can be fired CONCURRENTLY — either on their own (run() batches
 * the step's own requests) or merged with sibling steps' requests by a
 * ConcurrentGroup.
 *
 * Splitting run() into requests()/consume() is what lets the pipeline overlap
 * otherwise-sequential LLM calls: requests() only reads upstream artifacts and
 * renders prompts (no network), consume() validates and writes the results.
 */
interface ConcurrentStep extends Step
{
    /**
     * Render the prompts this step needs, WITHOUT calling the LLM. Each entry is
     * one request keyed by a step-local id; consume() receives the decoded
     * results under the same keys.
     *
     * @return array<string,array{prompt:string,system?:string,model?:string,max_tokens?:int,temperature?:float}>
     */
    public function requests(Project $project): array;

    /**
     * Validate and write the decoded results for the keys returned by requests().
     *
     * @param array<string,array<mixed>> $results decoded JSON keyed as requests()
     */
    public function consume(Project $project, array $results): void;
}
