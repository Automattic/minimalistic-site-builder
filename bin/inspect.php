<?php
declare(strict_types=1);

use Automattic\SiteBuild\ProjectStore;

/**
 * Quick content/quality inspector for a generated site.
 *   php bin/inspect.php <slug>
 *
 * Prints each page's outline (headings, buttons, images) and checks
 * that colors/fonts used in markup actually exist as theme.json presets.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$slug = $argv[1] ?? null;
if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/inspect.php <slug>\n");
    exit(1);
}
$project = (new ProjectStore(repo_path('projects')))->open($slug);

$spec = $project->readJson('siteSpec.json');
echo "# {$spec['name']}  ({$slug})\n";
echo 'tagline: ' . ($spec['tagline'] ?? $spec['topic'] ?? '–') . "\n";
echo 'vibe: ' . ($spec['visual_vibe'] ?? '–') . "\n";
// Fonts are a design decision; they live in theme.json now, not the spec.
$themeForFonts = json_decode($project->readText('theme/theme.json'), true);
$specFonts = array_map(
    static fn ($f) => trim(explode(',', (string) ($f['fontFamily'] ?? ''))[0], " \"'"),
    $themeForFonts['settings']['typography']['fontFamilies'] ?? []
);
echo 'fonts: ' . ($specFonts === [] ? '?' : implode(' / ', $specFonts)) . "\n\n";

// The site's content lives in the companion plugin (one file per page); the
// chrome lives in the theme parts. Inspect both.
$pages = $project->exists('plugin/pages.json')
    ? ($project->readJson('plugin/pages.json')['pages'] ?? [])
    : [];
$all = '';
foreach (['parts/header.html', 'parts/footer.html'] as $rel) {
    if ($project->exists('theme/' . $rel)) {
        $all .= "\n" . $project->readText('theme/' . $rel);
    }
}

foreach ($pages as $page) {
    $slug = (string) ($page['slug'] ?? '');
    $rel = "plugin/pages/{$slug}.html";
    if (!$project->exists($rel)) {
        continue;
    }
    $markup = $project->readText($rel);
    $all .= "\n" . $markup;

    $frontMark = !empty($page['front']) ? ' (front)' : '';
    echo "## Page: {$page['title']}{$frontMark} — /{$slug}/\n";
    if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/s', $markup, $hm, PREG_SET_ORDER)) {
        foreach ($hm as $h) {
            echo str_repeat('  ', (int) $h[1] - 1) . 'H' . $h[1] . ': ' . trim(strip_tags($h[2])) . "\n";
        }
    }

    if (preg_match_all('/wp-block-button__link[^>]*>(.*?)<\/a>/s', $markup, $bm) && $bm[1] !== []) {
        echo "  CTAs:\n";
        foreach ($bm[1] as $b) {
            echo '  • ' . trim(strip_tags($b)) . "\n";
        }
    }

    $imgs = preg_match_all('/<img[^>]*alt="([^"]*)"/', $markup, $im);
    if ($imgs > 0) {
        echo "  Images ({$imgs}):\n";
        foreach ($im[1] as $alt) {
            echo '  • ' . ($alt === '' ? '(empty alt)' : $alt) . "\n";
        }
    }
    echo "\n";
}
$front = $all;

// Preset usage vs declared.
$theme = json_decode($project->readText('theme/theme.json'), true);
$declaredColors = array_column($theme['settings']['color']['palette'] ?? [], 'slug');
$declaredFonts = array_column($theme['settings']['typography']['fontFamilies'] ?? [], 'slug');

preg_match_all('/"(?:background|text)Color":"([a-z0-9-]+)"/', $front, $cm);
$usedColors = array_unique($cm[1] ?? []);
preg_match_all('/"fontFamily":"([a-z0-9-]+)"/', $front, $fm);
$usedFonts = array_unique($fm[1] ?? []);

echo "\n## Token usage\n";
echo '  colors used: ' . implode(', ', $usedColors) . "\n";
$badColors = array_diff($usedColors, $declaredColors);
echo '  ' . ($badColors === [] ? 'all colors declared ✅' : 'UNDECLARED colors: ' . implode(', ', $badColors) . ' ⚠️') . "\n";
echo '  fonts used: ' . implode(', ', $usedFonts) . "\n";
$badFonts = array_diff($usedFonts, $declaredFonts);
echo '  ' . ($badFonts === [] ? 'all fonts declared ✅' : 'UNDECLARED fonts: ' . implode(', ', $badFonts) . ' ⚠️') . "\n";

echo "\n## Sections expected vs structure\n";
echo '  sections (' . count($spec['sections'] ?? []) . "):\n";
foreach ($spec['sections'] ?? [] as $s) {
    echo "    - {$s}\n";
}
