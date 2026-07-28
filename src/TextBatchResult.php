<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Raw-text batch output plus per-member degradation notes.
 *
 * Notes stay associated with their response key so a caller can persist them
 * only when that member's normalized output is actually delivered.
 */
final class TextBatchResult
{
    /**
     * @param array<array-key,string> $texts raw assistant text, keyed as the requests
     * @param array<array-key,list<string>> $notes degradation notes keyed as the requests
     */
    public function __construct(
        public readonly array $texts,
        public readonly array $notes = [],
    ) {
        foreach ($texts as $key => $text) {
            if (!is_string($text)) {
                throw new \InvalidArgumentException("text batch result '{$key}' is not a string");
            }
        }
        foreach ($notes as $key => $messages) {
            if (!array_key_exists($key, $texts)) {
                throw new \InvalidArgumentException("text batch notes reference unknown result '{$key}'");
            }
            if (!is_array($messages) || !array_is_list($messages)) {
                throw new \InvalidArgumentException("text batch notes for '{$key}' are not a list");
            }
            foreach ($messages as $message) {
                if (!is_string($message) || trim($message) === '') {
                    throw new \InvalidArgumentException("text batch notes for '{$key}' contain an invalid message");
                }
            }
        }
    }

    /** @return list<string> */
    public function notesFor(string|int $key): array
    {
        return $this->notes[$key] ?? [];
    }
}
