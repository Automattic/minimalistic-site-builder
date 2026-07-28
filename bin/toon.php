<?php
declare(strict_types=1);

/**
 * Pure-PHP TOON ↔ JSON and TOON-block-markup converter.
 *
 *   # JSON → TOON (stdin or file)
 *   php bin/toon.php encode <<< '{"align":"center","textColor":"base"}'
 *   php bin/toon.php encode path/to.json
 *
 *   # TOON → JSON
 *   php bin/toon.php decode path/to.toon
 *   php bin/toon.php decode <<< $'align: center\ntextColor: base'
 *
 *   # Expand TOON attrs inside <!-- wp:… --> comments → standard JSON attrs
 *   php bin/toon.php expand path/to/section.html
 *   php bin/toon.php expand -   # stdin
 *
 * No Node/npm — Automattic\SiteBuild\Toon and ToonBlockAttrs only.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Automattic\SiteBuild\Toon;
use Automattic\SiteBuild\ToonBlockAttrs;

$args = array_slice($argv, 1);
$cmd = $args[0] ?? null;
$path = $args[1] ?? null;

if ($cmd === null || !in_array($cmd, ['encode', 'decode', 'expand', 'help', '-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php bin/toon.php <encode|decode|expand> [file|-]\n");
    fwrite(STDERR, "  encode  JSON → TOON\n");
    fwrite(STDERR, "  decode  TOON → JSON\n");
    fwrite(STDERR, "  expand  HTML with TOON block attrs → JSON block attrs\n");
    exit($cmd === null ? 1 : 0);
}

if (in_array($cmd, ['help', '-h', '--help'], true)) {
    echo "php bin/toon.php encode|decode|expand [file|-]\n";
    exit(0);
}

$raw = read_input($path);

try {
    if ($cmd === 'encode') {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        echo Toon::encode($data), "\n";
        exit(0);
    }
    if ($cmd === 'decode') {
        $data = Toon::decode($raw);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    // expand
    $notes = [];
    $out = ToonBlockAttrs::expand($raw, $notes);
    foreach ($notes as $n) {
        fwrite(STDERR, "# {$n}\n");
    }
    echo $out;
    if ($out !== '' && !str_ends_with($out, "\n")) {
        echo "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

function read_input(?string $path): string
{
    if ($path === null || $path === '-') {
        $data = stream_get_contents(STDIN);
        return $data === false ? '' : $data;
    }
    if (!is_file($path)) {
        fwrite(STDERR, "file not found: {$path}\n");
        exit(1);
    }
    $data = file_get_contents($path);
    return $data === false ? '' : $data;
}
