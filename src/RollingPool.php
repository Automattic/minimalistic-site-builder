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
 * unit-testable with fakes; the shared curl_multi glue lives in
 * {@see CurlMultiPool}.
 */
final class RollingPool
{
    /**
     * @param array<array-key,mixed> $items transfer input keyed by id
     * @param callable(string|int,mixed):void $start
     * @param callable(?callable=):array<array-key,mixed> $await returns when a transfer completes or a queued request can start
     * @param null|callable(string|int,mixed):bool $canStart permits a queued request to start
     * @param null|callable(string|int):void $onComplete receives each completed key, including held requests
     * @return array<array-key,mixed> results keyed and ordered as $items
     */
    public static function run(array $items, callable $start, callable $await, int $cap, ?callable $canStart = null, ?callable $onComplete = null): array
    {
        $cap = max(1, $cap);
        $pending = array_keys($items);
        $inFlight = [];
        $results = [];

        $canLaunch = function () use (&$pending, &$inFlight, $items, $canStart, $cap): bool {
            if (count($inFlight) >= $cap) {
                return false;
            }
            foreach ($pending as $key) {
                if ($canStart === null || $canStart($key, $items[$key])) {
                    return true;
                }
            }
            return false;
        };
        $launch = function () use (&$pending, &$inFlight, $items, $start, $cap, $canStart): int {
            $started = 0;
            foreach ($pending as $index => $key) {
                if (count($inFlight) >= $cap) {
                    break;
                }
                if ($canStart !== null && !$canStart($key, $items[$key])) {
                    continue;
                }
                unset($pending[$index]);
                $inFlight[$key] = true;
                $start($key, $items[$key]);
                $started++;
            }
            return $started;
        };

        $launch();
        while ($inFlight !== []) {
            $completed = $canStart === null ? $await() : $await($canLaunch);
            foreach ($completed as $key => $result) {
                if (!isset($inFlight[$key])) {
                    throw new \RuntimeException("rolling pool got a completion for request '{$key}', which is not in flight");
                }
                unset($inFlight[$key]);
                $results[$key] = $result;
                if ($onComplete !== null) {
                    $onComplete($key);
                }
            }
            $started = $launch();
            if ($completed === [] && $started === 0) {
                throw new \RuntimeException('rolling pool await returned no transfer completions');
            }
        }
        if ($pending !== []) {
            throw new \RuntimeException('rolling pool has queued requests with no active dependency');
        }

        $out = [];
        foreach ($items as $key => $_item) {
            $out[$key] = $results[$key];
        }
        return $out;
    }
}
