<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The image endpoint's safety filter rejected the prompt: Imagen returns an
 * HTTP 200 whose prediction carries a raiFilteredReason instead of image
 * bytes. Distinct from TransientApiException because it is retryable for a
 * different reason (the filter is non-deterministic, so an identical prompt
 * can pass on a later attempt) AND repairable when retries run out —
 * GenerateImagesStep rewrites the prompt with a small LLM and regenerates.
 */
final class ImageFilteredException extends \RuntimeException
{
}
