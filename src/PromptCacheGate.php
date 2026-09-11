<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Release sibling requests after the first response starts, or after a bounded delay. */
final class PromptCacheGate
{
    private bool $released = false;
    private float $started;
    private \Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
        $this->started = ($this->clock)();
    }

    public function ready(): bool
    {
        return $this->released || ($this->clock)() - $this->started >= 10.0;
    }

    public function release(): void
    {
        $this->released = true;
    }

    public function observe(string $raw): void
    {
        if (!$this->released && self::hasMessageStart($raw)) {
            $this->release();
        }
    }

    public static function hasMessageStart(string $raw): bool
    {
        $events = preg_split('/\r?\n\r?\n/', $raw);
        array_pop($events);
        foreach ($events as $event) {
            foreach (preg_split('/\r?\n/', $event) as $line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = json_decode(trim(substr($line, 5)), true);
                if (is_array($data) && ($data['type'] ?? '') === 'message_start'
                    && is_array($data['message'] ?? null)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function applies(array $bodies): bool
    {
        if (count($bodies) < 2) {
            return false;
        }
        foreach ($bodies as $body) {
            if (!is_array($body['messages'][0]['content'] ?? null)) {
                continue;
            }
            foreach ($body['messages'][0]['content'] as $block) {
                if (isset($block['cache_control'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
