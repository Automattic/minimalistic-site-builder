<?php
declare(strict_types=1);

/**
 * Single autoload entry point. CLI loads this via src/bootstrap.php; embedding
 * hosts require this file and construct SiteBuilder.
 *
 * Standalone: Composer's vendor/ autoloads project source AND the
 * blocks-engine transformer (+ its deps) — run `composer install` first.
 *
 * Host-managed (e.g. wpcom vendors src/ without vendor/ and provides the
 * transformer via its own autoloader): fall back to registering only the
 * project's PSR-4 prefix; the host supplies the transformer.
 */
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Automattic\\SiteBuild\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
