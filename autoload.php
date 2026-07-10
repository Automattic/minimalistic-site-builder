<?php
declare(strict_types=1);

/**
 * Dependency-free PSR-4 autoloader: Automattic\SiteBuild\ → src/.
 *
 * No Composer. CLI loads this via src/bootstrap.php; embedding hosts require
 * this file and construct SiteBuilder.
 */
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
