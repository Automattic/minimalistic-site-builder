<?php
/**
 * Build a pattern inventory from a theme's registered patterns.
 *
 * The composition never fetches a catalogue: the inventory is a host input,
 * and what a host has is a theme it already deployed. This turns the second
 * into the first, which is also how the fixtures are made — a fixture built by
 * hand drifts from what the theme actually registers, and then a build passes
 * against markup no site has.
 *
 * Usage:
 *   php bin/pattern-inventory.php --theme=path/to/theme --out=host/patterns.json
 *                                 [--only=slug,slug] [--categories=a,b]
 *                                 [--theme-uri=/wp-content/themes/name]
 *
 * A pattern file is PHP and this runs it, because that is what rendering one
 * means — the copy inside comes from translation calls. Point it at a theme
 * you trust, the same way you would trust it enough to deploy.
 */

declare(strict_types=1);

$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $flags[$m[1]] = $m[2];
    }
}

$theme = rtrim((string) ($flags['theme'] ?? ''), '/');
$out = (string) ($flags['out'] ?? '');
if ($theme === '' || !is_dir($theme . '/patterns') || $out === '') {
    fwrite(STDERR, "--theme=<dir with patterns/> and --out=<file.json> are required\n");
    exit(1);
}

$only = array_filter(array_map('trim', explode(',', (string) ($flags['only'] ?? ''))));
$wanted = array_filter(array_map('trim', explode(',', (string) ($flags['categories'] ?? ''))));

/**
 * The WordPress functions a pattern file calls to put its copy on the page.
 * Stubbed rather than loaded, so this needs a theme directory and not a site.
 */
require_once __DIR__ . '/../src/Patterns/pattern-render-stubs.php';

// Patterns point at their theme's own images through this. The convention is
// where WordPress puts a theme; a host whose theme lives elsewhere says so.
pattern_theme_uri((string) ($flags['theme-uri'] ?? '/wp-content/themes/' . basename($theme)));

$patterns = [];
$skipped = [];

$files = glob($theme . '/patterns/*.php') ?: [];
sort($files);

foreach ($files as $file) {
    $source = (string) file_get_contents($file);

    $slug = pattern_header($source, 'Slug');
    $title = pattern_header($source, 'Title');
    if ($slug === '') {
        $skipped[] = basename($file) . ' (no Slug header)';
        continue;
    }

    $short = str_contains($slug, '/') ? explode('/', $slug)[1] : $slug;
    if ($only !== [] && !in_array($short, $only, true) && !in_array($slug, $only, true)) {
        continue;
    }

    $categories = array_values(array_filter(array_map(
        'trim',
        explode(',', pattern_header($source, 'Categories')),
    )));

    if ($wanted !== [] && array_intersect($categories, $wanted) === []) {
        continue;
    }

    $content = pattern_render($file);
    if ($content === null) {
        $skipped[] = basename($file) . ' (would not render)';
        continue;
    }

    $patterns[] = [
        'id' => $slug,
        'title' => $title,
        'categories' => $categories,
        'content' => $content,
    ];
}

if ($patterns === []) {
    fwrite(STDERR, "No pattern matched. Nothing written.\n");
    exit(1);
}

$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create {$dir}\n");
    exit(1);
}

file_put_contents(
    $out,
    json_encode(['patterns' => $patterns], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

printf("%d patterns written to %s\n", count($patterns), $out);
foreach ($skipped as $note) {
    printf("  skipped %s\n", $note);
}

/** One field from a pattern file's header block. */
function pattern_header(string $source, string $field): string
{
    if (preg_match('/^\s*\*\s*' . $field . ':\s*(.+)$/mi', $source, $m)) {
        return trim($m[1]);
    }

    return '';
}

/** A pattern file's markup, or null when running it failed. */
function pattern_render(string $file): ?string
{
    ob_start();
    try {
        include $file;
    } catch (\Throwable) {
        ob_end_clean();
        return null;
    }

    $markup = trim((string) ob_get_clean());

    return $markup === '' ? null : $markup;
}
