<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Transcript for analyze-url/describe calls.
 *
 * The describe call is the slowest and priciest external call in the pipeline
 * but is not an Llm, so it never reaches logs/llms/. This mirrors ImageLogger,
 * which exists for the same reason on the image-generation side.
 */
final class InspirationLogger
{
    private static ?string $dir = null;

    /** Monotonic within a process so two calls for one URL never collide. */
    private static int $seq = 0;

    public static function dir(): ?string
    {
        return self::$dir;
    }

    public static function setDir(?string $dir): void
    {
        self::$dir = $dir;
        if ($dir !== null && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    /**
     * @param array{endpoint:string,url:string,status:int,headers?:list<string>} $request
     * @param array<mixed>                                                       $result
     */
    public static function log(string $url, array $request, array $result = [], ?string $error = null): void
    {
        $dir = self::$dir;
        if ($dir === null || !is_dir($dir)) {
            return;
        }

        $path = sprintf('%s/%03d-%s.txt', $dir, ++self::$seq, self::slug($url));
        @file_put_contents($path, self::format($url, $request, $result, $error));
    }

    /**
     * @param array{endpoint:string,url:string,status:int,headers?:list<string>} $request
     * @param array<mixed>                                                       $result
     */
    public static function format(string $url, array $request, array $result = [], ?string $error = null): string
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $out = "URL: {$url}\n\n=== REQUEST ===\n"
            . json_encode(self::redactAuthorization($request), $flags) . "\n";
        if ($error !== null) {
            $out .= "\n=== ERROR ===\n{$error}\n";
        }
        if ($result !== []) {
            $out .= "\n=== RESPONSE ===\n" . json_encode($result, $flags) . "\n";
        }
        return $out;
    }

    /**
     * Redacts authorization keys and Bearer credentials found in string values.
     *
     * Value scanning assumes Bearer-style credentials. A caller adding x-api-key,
     * a bare token key, or a query-string credential must extend this redaction.
     *
     * @return array<mixed>
     */
    private static function redactAuthorization(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_string($key) && strcasecmp($key, 'authorization') === 0) {
                $value[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($entry)) {
                $value[$key] = self::redactAuthorization($entry);
                continue;
            }
            if (is_string($entry)) {
                $value[$key] = (string) preg_replace('/bearer\s+\S+/i', '[REDACTED]', $entry);
            }
        }
        return $value;
    }

    private static function slug(string $url): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $url));
        return trim(substr($slug, 0, 60), '-') ?: 'url';
    }
}
