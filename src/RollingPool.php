<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Drive a batch of transfers through a bounded rolling pool: at most $cap in
 * flight, and the moment one completes the next pending item starts. A slow
 * member holds only its own slot — unlike windowing, it never blocks
 * unrelated items behind a batch-wide barrier. Pure orchestration ($start
 * begins one transfer; $await blocks until at least one in-flight transfer
 * completes and returns those results keyed by item id), so it is
 * unit-testable with fakes; the curl_multi glue lives in each client
 * (AnthropicClient::streamMulti, WpcomImageClient::multiRequest).
 */
final class RollingPool
{
    /**
     * @param array<array-key,mixed> $items transfer input keyed by id
     * @param callable(string|int,mixed):void $start
     * @param callable():array<array-key,mixed> $await
     * @return array<array-key,mixed> results keyed and ordered as $items
     */
    public static function run(array $items, callable $start, callable $await, int $cap): array
    {
        $cap = max(1, $cap);
        $pending = array_keys($items);
        $inFlight = [];
        $results = [];

        $launch = function () use (&$pending, &$inFlight, $items, $start, $cap): void {
            while ($pending !== [] && count($inFlight) < $cap) {
                $key = array_shift($pending);
                $inFlight[$key] = true;
                $start($key, $items[$key]);
            }
        };

        $launch();
        while ($inFlight !== []) {
            $completed = $await();
            if ($completed === []) {
                throw new \RuntimeException('rolling pool await returned no transfer completions');
            }
            foreach ($completed as $key => $result) {
                if (!isset($inFlight[$key])) {
                    throw new \RuntimeException("rolling pool got a completion for request '{$key}', which is not in flight");
                }
                unset($inFlight[$key]);
                $results[$key] = $result;
            }
            $launch();
        }

        $out = [];
        foreach ($items as $key => $_item) {
            $out[$key] = $results[$key];
        }
        return $out;
    }
}
