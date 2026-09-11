<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/** Count provider attempts separately from delivered assets. */
final class ImageRequestLedger
{
    private array $totals = [
        'attempts' => 0, 'successful_attempts' => 0, 'failed_attempts' => 0,
        'request_seconds' => 0.0, 'usage_reported_attempts' => 0,
        'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
    ];

    /** Record one completed transport attempt, including failures. */
    public function record(array $record): void
    {
        $this->totals['attempts']++;
        $this->totals[$record['ok'] ? 'successful_attempts' : 'failed_attempts']++;
        $this->totals['request_seconds'] += (float) ($record['seconds'] ?? 0);
        $usage = $record['usage'] ?? null;
        if (is_array($usage)) {
            $this->totals['usage_reported_attempts']++;
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
                $this->totals[$key] += (int) ($usage[$key] ?? 0);
            }
        }
    }

    /** A null token total means that at least one response omitted usage. */
    public function totals(): array
    {
        $out = $this->totals;
        $out['usage_complete'] = $out['usage_reported_attempts'] === $out['attempts'];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            $out['reported_' . $key] = $out[$key];
            if (!$out['usage_complete']) {
                $out[$key] = null;
            }
        }
        return $out;
    }

    /** Extract token totals only when the provider supplies them. */
    public static function usage(string $raw): ?array
    {
        $body = json_decode($raw, true);
        $usage = is_array($body) ? ($body['usageMetadata'] ?? null) : null;
        if (!is_array($usage) || !isset($usage['promptTokenCount'], $usage['candidatesTokenCount'], $usage['totalTokenCount'])) {
            return null;
        }
        return [
            'input_tokens' => (int) $usage['promptTokenCount'],
            'output_tokens' => (int) $usage['candidatesTokenCount'],
            'total_tokens' => (int) $usage['totalTokenCount'],
        ];
    }
}
