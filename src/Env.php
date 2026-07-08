<?php
declare(strict_types=1);

/**
 * Tiny .env loader. No dependencies. Loads KEY=VALUE pairs into a static map
 * and exposes them via Env::get(). Values already present in the real
 * environment win, so you can override .env from the shell.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            self::$loaded = true;
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // Strip optional surrounding quotes.
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
                $val = substr($val, 1, -1);
            }
            self::$vars[$key] = $val;
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return self::$vars[$key] ?? $default;
    }

    public static function getRequired(string $key): string
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            fwrite(STDERR, "Missing required env var: {$key}\n");
            exit(1);
        }
        return $val;
    }
}
