<?php
declare(strict_types=1);
namespace Automattic\SiteBuild;

/** Start one useful request for each shared prefix before its dependent requests. */
final class PromptCacheSchedule
{
    /** @var array<array-key,array<array-key,bool>> */
    private array $dependencies = [];
    /** @var array<array-key,PromptCacheGate> */
    private array $gates = [];

    /** @param array<array-key,array<string,mixed>> $bodies */
    public function __construct(array $bodies, private readonly ?\Closure $clock = null)
    {
        // Prefer a deep request so it also creates the common site prefix.
        uasort($bodies, static fn (array $a, array $b): int => self::size($b) <=> self::size($a));
        $owners = [];
        foreach ($bodies as $key => $body) {
            $this->dependencies[$key] = [];
            $identity = $body;
            unset($identity['messages'], $identity['max_tokens'], $identity['stream']);
            $prefix = json_encode($identity);
            $content = $body['messages'][0]['content'] ?? [];
            foreach (is_array($content) ? $content : [] as $block) {
                $prefix .= json_encode($block);
                if (!isset($block['cache_control'])) {
                    continue;
                }
                $hash = hash('sha256', $prefix);
                $owners[$hash] ??= $key;
                if ($owners[$hash] !== $key) {
                    $this->dependencies[$key][$owners[$hash]] = true;
                }
            }
        }
    }

    public function canStart(string|int $key): bool
    {
        foreach ($this->dependencies[$key] as $owner => $_) {
            if (!isset($this->gates[$owner]) || !$this->gates[$owner]->ready()) {
                return false;
            }
        }
        return true;
    }

    public function start(string|int $key): void
    {
        $this->gates[$key] = new PromptCacheGate($this->clock);
    }

    public function observe(string|int $key, string $raw): void
    {
        $this->gates[$key]->observe($raw);
    }

    public function release(string|int $key): void
    {
        $this->gates[$key] ??= new PromptCacheGate($this->clock);
        $this->gates[$key]->release();
    }

    private static function size(array $body): int
    {
        $size = 0;
        $content = $body['messages'][0]['content'] ?? [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (isset($block['cache_control'])) {
                $size += strlen((string) ($block['text'] ?? ''));
            }
        }
        return $size;
    }
}
