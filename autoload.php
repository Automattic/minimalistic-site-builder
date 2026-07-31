<?php
declare(strict_types=1);

/**
 * Dependency-free PSR-4 autoloader for project and vendored source.
 *
 * No Composer. CLI loads this via src/bootstrap.php; embedding hosts require
 * this file and construct SiteBuilder.
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Automattic\\SiteBuild\\' => __DIR__ . '/src/',
        'Automattic\\BlocksEngine\\PhpTransformer\\' => __DIR__ . '/lib/blocks-engine-php-transformer/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});
