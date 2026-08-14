<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Validates the optional `images` key on an Llm request and renders it into
 * each provider's content-block shape.
 *
 * Both providers accept the same four formats and both reject an oversized
 * image with an opaque HTTP 400, so the caps are enforced here where the
 * message can name what actually went wrong.
 */
final class ImageInput
{
    /** Formats Anthropic and OpenAI-compatible providers both accept. */
    private const MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Anthropic rejects an image whose base64 payload exceeds 5MB; base64 costs 4/3. */
    private const MAX_BYTES = 3_750_000;

    /** Enough for several page slices without letting a request balloon unnoticed. */
    private const MAX_IMAGES = 8;

    /**
     * @param  array<string,mixed> $req one Llm request
     * @return list<array{bytes:string,mime:string}> empty when the request carries no images
     */
    public static function normalize(array $req): array
    {
        if (!array_key_exists('images', $req)) {
            return [];
        }
        $raw = $req['images'];
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('images must be a list');
        }
        if (count($raw) > self::MAX_IMAGES) {
            throw new \InvalidArgumentException(
                sprintf('a request carries at most %d images, got %d', self::MAX_IMAGES, count($raw)),
            );
        }

        $out = [];
        foreach ($raw as $index => $image) {
            if (!is_array($image)) {
                throw new \InvalidArgumentException("images[{$index}] must be an array");
            }
            $bytes = $image['bytes'] ?? null;
            if (!is_string($bytes) || $bytes === '') {
                throw new \InvalidArgumentException("images[{$index}].bytes must be a non-empty string");
            }
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new \InvalidArgumentException(sprintf(
                    'images[%d] is %d bytes, over the %d-byte limit',
                    $index,
                    strlen($bytes),
                    self::MAX_BYTES,
                ));
            }
            $mime = strtolower(is_string($image['mime'] ?? null) ? trim($image['mime']) : '');
            if (!in_array($mime, self::MIME_TYPES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'images[%d].mime must be one of %s',
                    $index,
                    implode(', ', self::MIME_TYPES),
                ));
            }
            $out[] = ['bytes' => $bytes, 'mime' => $mime];
        }
        return $out;
    }

    /**
     * @param  list<array{bytes:string,mime:string}> $images
     * @return list<array<string,mixed>> Anthropic base64 image blocks
     */
    public static function anthropicBlocks(array $images): array
    {
        $blocks = [];
        foreach ($images as $image) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mime'],
                    'data' => base64_encode($image['bytes']),
                ],
            ];
        }
        return $blocks;
    }

    /**
     * @param  list<array{bytes:string,mime:string}> $images
     * @return list<array<string,mixed>> OpenAI-compatible data-URI image blocks
     */
    public static function openAiBlocks(array $images): array
    {
        $blocks = [];
        foreach ($images as $image) {
            $blocks[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['bytes'])],
            ];
        }
        return $blocks;
    }
}
