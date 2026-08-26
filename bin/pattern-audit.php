<?php
declare(strict_types=1);
/**
 * Pattern quality audit. Reads a BUILT project and reports whether the
 * patterns it shipped are usable. Read-only; no LLM, no network.
 *
 *   php bin/pattern-audit.php projects/<slug> [projects/<slug> ...]
 *
 * Exits non-zero if it measured nothing, or if any STRUCTURAL check failed.
 */
require_once __DIR__ . '/../src/bootstrap.php';

use Automattic\SiteBuild\BlockMarkup;

// The step deliberately keeps band CTAs on patterns whose job IS the call to action.
const CTA_EXEMPT_LABELS = ['cta', 'closing', 'contact'];

const JUNK_LABELS = ['page', 'section', 'content', 'block', 'wrapper', 'div', 'main', 'col', 'row'];

function audit(string $dir): ?array
{
    $manifestPath = $dir . '/patterns.json';
    $patternDir   = $dir . '/theme/patterns';
    if (!is_file($manifestPath) || !is_dir($patternDir)) {
        return null;
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $files = glob($patternDir . '/*.php') ?: [];
    if ($files === []) {
        return null;
    }

    $r = [
        'slug' => basename($dir), 'version' => $manifest['version'] ?? 1,
        'files' => count($files), 'declared' => count($manifest['patterns'] ?? []),
        'dropped' => count($manifest['dropped'] ?? []),
        'structural' => [], 'quality' => [], 'notes' => [],
    ];
    $fail = function (string $k) use (&$r) { $r['structural'][$k] = ($r['structural'][$k] ?? 0) + 1; };
    $note = function (string $s) use (&$r) { $r['notes'][] = $s; };

    $functions = is_file($dir . '/theme/functions.php') ? (string) file_get_contents($dir . '/theme/functions.php') : '';
    $assetsDir = $dir . '/theme/assets';
    $hasAssets = (glob($assetsDir . '/*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE) ?: []) !== [];

    $roleBySection = [];
    if (is_file($dir . '/pages.json')) {
        $plan = json_decode((string) file_get_contents($dir . '/pages.json'), true);
        foreach ($plan['pages'] ?? [] as $pg) {
            foreach ($pg['sections'] ?? [] as $s) {
                $roleBySection[($pg['slug'] ?? '') . '/' . ($s['slug'] ?? '')] = $s['role'] ?? null;
            }
        }
    }
    $coveredRoles = [];

    $titles = [];
    $labelled = 0; $junk = 0; $semantic = 0;
    $withHeading = 0; $withBody = 0; $sectionCount = 0; $bandCtas = []; $rows = []; $cards = []; $deadLinks = [];
    $byLabel = [];

    foreach ($files as $f) {
        $name = basename($f, '.php');
        $src  = (string) file_get_contents($f);

        // S1 valid PHP
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $fail('invalid_php'); $note("$name: php -l failed"); }

        // S2 header completeness + S6 slug/filename agreement
        foreach (['Title', 'Slug', 'Categories', 'Description'] as $key) {
            if (preg_match('/^ \* ' . $key . ':[ \t]*(\S.*)$/m', $src, $m) !== 1) {
                $fail('header_incomplete'); $note("$name: header missing $key");
            } elseif ($key === 'Slug' && !str_ends_with(trim($m[1]), '/' . $name)) {
                $fail('slug_mismatch'); $note("$name: Slug header '{$m[1]}' != filename");
            } elseif ($key === 'Title') {
                $titles[trim($m[1])][] = $name;
            }
        }

        $bodyAt = strpos($src, "?>\n");
        $body = $bodyAt === false ? '' : substr($src, $bodyAt + 3);

        // S3 block markup parses and is structurally whole
        try {
            $doc = BlockMarkup::parse($body);
            if ($doc->hasMalformedDelimiters() || $doc->hasMismatchedDelimiters() || $doc->unclosedIndices() !== []) {
                $fail('broken_blocks'); $note("$name: unbalanced block delimiters");
                continue;
            }
        } catch (\Throwable $e) {
            $fail('unparseable'); $note("$name: parse threw " . $e->getMessage());
            continue;
        }

        // S4 asset PHP is live, not hex-escaped into a dead string
        if (str_contains($body, 'u003C?php')) { $fail('inert_asset_php'); $note("$name: hex-escaped PHP in attrs"); }

        // S5 no PHP other than the sanctioned asset echo
        $php = preg_match_all('/<\?(?:php|=)?/', $body);
        $sanctioned = preg_match_all('/<\?php echo esc_url\( get_theme_file_uri\(/', $body);
        if ($php !== $sanctioned) { $fail('unexpected_php'); $note("$name: $php PHP tags, $sanctioned sanctioned"); }

        // S7 declared categories are registered
        if (preg_match('/^ \* Categories:[ \t]*(.+)$/m', $src, $m) === 1 && $functions !== '') {
            foreach (array_map('trim', explode(',', $m[1])) as $cat) {
                if (str_ends_with($cat, '-sections') || str_ends_with($cat, '-components')) {
                    if (!str_contains($functions, "register_block_pattern_category('$cat'")) {
                        $fail('category_unregistered'); $note("$name: category '$cat' never registered");
                    }
                }
            }
        }

        // S8 every referenced asset exists on disk. Only meaningful when the
        // build generated images; an image-less eval has no assets to find.
        if ($hasAssets && preg_match_all("/get_theme_file_uri\( 'assets\/([^']+)' \)/", $body, $m) > 0) {
            foreach (array_unique($m[1]) as $asset) {
                if (!is_file($assetsDir . '/' . $asset)) {
                    $fail('missing_asset'); $note("$name: references missing asset $asset");
                }
            }
        }

        if ($name === 'page-starter') { continue; }

        // ---- quality ----
        $entry = null;
        foreach ($manifest['patterns'] ?? [] as $p) { if (($p['slug'] ?? null) === $name) { $entry = $p; break; } }
        $label = $entry['label'] ?? null;
        if ($label === null) { $note("$name: no semantic label (shape-only)"); }
        else {
            $labelled++;
            if (in_array($label, JUNK_LABELS, true)) { $junk++; $note("$name: junk label '$label'"); }
            else { $semantic++; }
            $byLabel[$label] = ($byLabel[$label] ?? 0) + 1;
        }

        $srcKey = ($entry['source']['page'] ?? '') . '/' . ($entry['source']['section'] ?? '');
        if (isset($roleBySection[$srcKey]) && $roleBySection[$srcKey] !== null) {
            $coveredRoles[$roleBySection[$srcKey]] = true;
        }
        $isSection = ($entry['kind'] ?? 'section') === 'section';
        if ($isSection) { $sectionCount++; }
        $names = array_map(fn (int $i) => str_replace('core/', '', $doc->name($i)), $doc->indices());
        // Components are card/row fragments; only sections owe a heading.
        if ($isSection && in_array('heading', $names, true)) { $withHeading++; }
        if (array_intersect($names, ['paragraph', 'list', 'quote', 'pullquote']) !== []) { $withBody++; }

        // band-level CTA: a core/buttons with no core/column ancestor
        $band = 0;
        foreach ($doc->indices() as $i) {
            if (str_replace('core/', '', $doc->name($i)) !== 'buttons') { continue; }
            $inCol = false;
            for ($p = $doc->parent($i); $p !== null; $p = $doc->parent($p)) {
                if (str_replace('core/', '', $doc->name($p)) === 'column') { $inCol = true; break; }
            }
            if (!$inCol) { $band++; }
        }
        if ($band > 0 && !in_array($label, CTA_EXEMPT_LABELS, true)) { $bandCtas[$name] = $band; }

        $dead = preg_match_all('/href="#"/', $body);
        if ($dead > 0) { $deadLinks[$name] = $dead; }

        if (str_ends_with($name, '-row')) { $rows[] = substr($name, 0, -4); }
        if (str_ends_with($name, '-card')) { $cards[] = substr($name, 0, -5); }
    }

    $sections = max(1, count($files) - 1); // page-starter excluded
    $r['quality'] = [
        'semantic_label_rate' => round($semantic / $sections, 2),
        'unlabelled'          => $sections - $labelled,
        'junk_labels'         => $junk,
        'duplicate_titles'    => count(array_filter($titles, fn ($v) => count($v) > 1)),
        'heading_rate_sections' => $sectionCount > 0 ? round($withHeading / $sectionCount, 2) : 0,
        'body_rate'           => round($withBody / $sections, 2),
        'stray_band_ctas'      => count($bandCtas),
        'patterns_w_dead_links' => count($deadLinks),
        'orphan_components'   => count(array_diff($rows, $cards)) + count(array_diff($cards, $rows)),
        'has_starter'         => is_file($patternDir . '/page-starter.php'),
        'starter_sections'    => count($manifest['starter']['sections'] ?? []),
        'role_coverage'       => implode('+', array_keys($coveredRoles)) ?: 'none',
        'hero_role_covered'   => isset($coveredRoles['hero']),
        'closing_role_covered' => isset($coveredRoles['closing']),
    ];
    foreach ($titles as $t => $owners) {
        if (count($owners) > 1) { $note("duplicate title '$t' on: " . implode(', ', $owners)); }
    }
    foreach ($bandCtas as $n => $c) { $note("$n: $c stray band-level CTA(s) on a non-CTA pattern"); }
    foreach ($deadLinks as $n => $c) { $note("$n: $c dead href=\"#\" placeholder(s)"); }
    return $r;
}

$dirs = array_slice($argv, 1);
$audited = 0; $structuralFailures = 0;
foreach ($dirs as $dir) {
    $r = audit(rtrim($dir, '/'));
    if ($r === null) { fwrite(STDERR, "skip (no patterns): $dir\n"); continue; }
    $audited++;
    $structuralFailures += array_sum($r['structural']);
    printf("\n=== %s (manifest v%s) ===\n", $r['slug'], (string) $r['version']);
    printf("  files=%d declared=%d dropped=%d\n", $r['files'], $r['declared'], $r['dropped']);
    printf("  STRUCTURAL: %s\n", $r['structural'] === [] ? 'all pass' : json_encode($r['structural']));
    foreach ($r['quality'] as $k => $v) {
        printf("    %-24s %s\n", $k, is_bool($v) ? ($v ? 'yes' : 'NO') : (string) $v);
    }
    if ($r['notes'] !== []) {
        echo "  notes:\n";
        foreach ($r['notes'] as $n) { echo "    - $n\n"; }
    }
}
if ($audited === 0) { fwrite(STDERR, "\nMEASURED NOTHING - no project carried patterns\n"); exit(2); }
printf("\naudited %d project(s); %d structural failure(s)\n", $audited, $structuralFailures);
exit($structuralFailures > 0 ? 1 : 0);
