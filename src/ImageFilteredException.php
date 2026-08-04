<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The image endpoint's safety filter rejected the prompt: Gemini returned an
 * explicit machine-readable policy/safety blockReason or finishReason without
 * image bytes. Distinct from TransientApiException because it is retryable for
 * a different reason (the filter is non-deterministic, so an identical prompt
 * can pass on a later attempt) AND repairable when retries run out —
 * GenerateImagesStep rewrites the prompt with a small LLM and regenerates.
 */
final class ImageFilteredException extends \RuntimeException
{
}
