<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Stitch bounded single-completion continuations until generated text closes.
 */
final class ContinuationRecovery
{
    private const CONTINUATION_INSTRUCTION =
        'Continue EXACTLY where the previous output stopped; do not repeat or restate';

    /**
     * @param array<string,mixed> $opts
     * @param callable(string):bool $isClosed
     */
    public static function completeToClose(
        Llm $llm,
        string $prompt,
        array $opts,
        callable $isClosed,
        int $maxRounds = 3,
    ): string {
        if ($maxRounds < 1) {
            throw new \InvalidArgumentException('maxRounds must be greater than zero');
        }

        $stitched = $llm->complete($prompt, $opts);
        $rounds = 1;

        while (
            $llm instanceof FinishReasonAwareLlm
            && TextBatchRecovery::isTruncation($llm->lastFinishReason())
            && !$isClosed($stitched)
        ) {
            if ($rounds >= $maxRounds) {
                throw new TruncatedGenerationException($stitched);
            }

            $fragment = $llm->complete(
                self::continuationPrompt($prompt, $stitched),
                $opts,
            );
            $stitched .= $fragment;
            $rounds++;
        }

        return $stitched;
    }

    private static function continuationPrompt(string $prompt, string $stitched): string
    {
        return $prompt
            . "\n\n<previous_response>\n"
            . $stitched
            . "\n</previous_response>\n\n"
            . self::CONTINUATION_INSTRUCTION
            . '. Return only the continuation.';
    }
}

final class TruncatedGenerationException extends \RuntimeException
{
    public function __construct(private readonly string $partialText)
    {
        parent::__construct('Generation remained truncated after the continuation round cap');
    }

    public function getPartialText(): string
    {
        return $this->partialText;
    }
}
