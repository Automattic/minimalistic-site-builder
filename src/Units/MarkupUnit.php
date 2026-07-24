<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Units;

/**
 * One Project-free, request-scoped markup generator.
 *
 * request() and finish() expose the two pure-of-Project halves so an in-process
 * host can batch many units through Llm::completeBatch(). generate() composes
 * those same halves for hosts that execute one unit per HTTP request.
 */
interface MarkupUnit
{
    /** Stable request/result key for this input. */
    public function key(array $input): string;

    /**
     * Render one self-contained LLM request without calling the LLM.
     *
     * @return array{prompt:string,model?:string,temperature?:float,cached_prefixes?:list<string>}
     */
    public function request(array $input): array;

    /** Normalize and validate one raw LLM response. */
    public function finish(string $raw, array $input): string;

    /** Render, execute, normalize, and return one unit without Project state. */
    public function generate(array $input): string;
}
