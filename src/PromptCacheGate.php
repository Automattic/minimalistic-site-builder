<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Release sibling requests after the first response starts, or after a bounded delay. */
final class PromptCacheGate
{
    /**
     * Seconds a dependent waits for the writer's first response bytes before
     * it sends anyway. A released dependent pays a cache write, so the delay
     * trades latency for cost. The clock starts at handle creation, before
     * DNS, TLS, and the provider queue.
     */
    public const DEFAULT_DEADLINE_SECONDS = 20.0;
    public const DEADLINE_ENV = 'SITE_BUILD_CACHE_GATE_SECONDS';

    private bool $released = false;
    private float $started;
    private float $deadline;
    private \Closure $clock;

    public function __construct(?callable $clock = null, ?float $deadline = null)
    {
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
        $this->started = ($this->clock)();
        $this->deadline = $deadline ?? self::configuredDeadline();
    }

    /** The deadline from the environment, or the default when unset or invalid. */
    public static function configuredDeadline(): float
    {
        $raw = getenv(self::DEADLINE_ENV);
        if ($raw === false || !is_numeric($raw) || (float) $raw <= 0) {
            return self::DEFAULT_DEADLINE_SECONDS;
        }
        return (float) $raw;
    }

    public function ready(): bool
    {
        return $this->released || ($this->clock)() - $this->started >= $this->deadline;
    }

    public function release(): void
    {
        $this->released = true;
    }

    public function observe(string $raw): void
    {
        if ($this->released) {
            return;
        }
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
                    $this->release();
                    return;
                }
            }
        }
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
