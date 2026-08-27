<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\TreeGraph;

/**
 * Mechanical gate screening for the tree graph, ported from the x-pipeline's
 * lib/gates.mjs (brochure subset: the block/schema factory gates are not
 * ported). The gates themselves are the sandbox companion's (validate,
 * harness compile) and the measured verification's; this class only encodes
 * the mechanical review of their output — which warnings fail an artifact,
 * which diagnostics may be deferred, and the tree-time screens that reject an
 * artifact while a repair is still one budget-covered call.
 */
final class Gates
{
    /** Warning codes that fail an artifact outright. */
    private const WARNING_FAILS = ['W_ATTR_UNKNOWN', 'W_STYLE_UNKNOWN'];

    /** Ink thresholds, identical to the x-pipeline: under 3:1 fails, 3–4.5:1 is advisory. */
    private const INK_HARD_FLOOR = 3.0;
    private const INK_SAFE = 4.5;

    /** Measured-width slack for scrollbar and rounding. */
    private const CLAMP_SLACK = 48.0;

    /** Daylight between bands beyond this many px is a seam. */
    private const SEAM_TOLERANCE = 4.0;

    private const HEX_LITERAL = '/#[0-9a-f]{3}(?:[0-9a-f]{3}(?:[0-9a-f]{2})?)?\b/i';
    private const ABS_LENGTH = '/(?:^|[\s(,])-?\d*\.?\d+(px|rem|em|pt)\b/i';
    private const PRESET_REF = '/var:preset\||var\(\s*--wp--preset--/';

    /**
     * Review a validation document: errors fail, except an E_UNKNOWN_BLOCK
     * naming an allowed (deferred) block; W_ATTR_UNKNOWN / W_STYLE_UNKNOWN
     * fail too; every other warning passes.
     *
     * @param array<string,mixed> $validation
     * @param list<string> $allowedUnknown
     * @return array{status:string,deferred:list<string>,failures:list<array<string,mixed>>}
     */
    public static function screenTreeDiagnostics(array $validation, array $allowedUnknown = []): array
    {
        $failures = [];
        $deferred = [];
        foreach ($validation['diagnostics'] ?? [] as $d) {
            if (($d['severity'] ?? '') === 'error') {
                if (($d['code'] ?? '') === 'E_UNKNOWN_BLOCK') {
                    $name = preg_match('#[a-z0-9-]+/[a-z0-9-]+#', (string) ($d['message'] ?? ''), $m) === 1 ? $m[0] : null;
                    if ($name !== null && in_array($name, $allowedUnknown, true)) {
                        $deferred[] = $name;
                        continue;
                    }
                }
                $failures[] = ['code' => $d['code'] ?? '', 'path' => $d['path'] ?? '', 'message' => $d['message'] ?? ''];
            } elseif (in_array($d['code'] ?? '', self::WARNING_FAILS, true)) {
                $failures[] = ['code' => $d['code'] ?? '', 'path' => $d['path'] ?? '', 'message' => $d['message'] ?? ''];
            }
            // W_STATIC_NEEDS_HARNESS, W_HINT_ALLOWED_BLOCKS, W_HINT_TEMPLATE_LOCK pass.
        }
        return ['status' => $failures === [] ? 'pass' : 'fail', 'deferred' => $deferred, 'failures' => $failures];
    }

    /**
     * The compile-parity screen: content the site's own save() silently
     * dropped (content_lost), and compiled markup that does not round-trip
     * the site's own parser (all_valid false).
     *
     * @param array<string,mixed> $compiled
     * @return list<array<string,mixed>>
     */
    public static function screenContentParity(array $compiled): array
    {
        $failures = [];
        foreach ($compiled['content_lost'] ?? [] as $lost) {
            $failures[] = ['code' => 'content_lost', 'path' => $lost['path'] ?? '', 'message' => $lost['message'] ?? ''];
        }
        if (($compiled['all_valid'] ?? null) !== true) {
            foreach ($compiled['invalid'] ?? [] as $invalid) {
                $name = (string) ($invalid['name'] ?? '');
                $failures[] = [
                    'code'    => 'compile_invalid',
                    'path'    => $invalid['path'] ?? '',
                    'message' => "the compiled markup for {$name} does not round-trip the site's own parser (isValid false)",
                ];
            }
        }
        return $failures;
    }

    /**
     * Local pre-check for LLM tree output: catches shape violations on the
     * schema-retry lane before a validate round trip. R1: markup (innerHTML /
     * innerContent) inside a tree is compiler output appearing in an input.
     *
     * @return list<array{path:string,message:string}>
     */
    public static function localTreeCheck(mixed $tree, string $epoch): array
    {
        if (!is_array($tree) || ($tree !== [] && array_is_list($tree))) {
            return [['path' => '', 'message' => 'expected a TreeIR object {version, epoch, blocks}']];
        }
        $issues = [];
        if (($tree['version'] ?? null) !== 1) {
            $issues[] = ['path' => '/version', 'message' => 'version must be the literal number 1'];
        }
        if (($tree['epoch'] ?? null) !== $epoch) {
            $issues[] = ['path' => '/epoch', 'message' => "epoch must be the current fingerprint \"{$epoch}\""];
        }
        $blocks = $tree['blocks'] ?? null;
        if (!is_array($blocks) || $blocks === [] || !array_is_list($blocks)) {
            $issues[] = ['path' => '/blocks', 'message' => 'blocks must be a non-empty array of BlockNode'];
            return $issues;
        }
        $walk = function (mixed $node, string $path) use (&$walk, &$issues): void {
            if (!is_array($node) || ($node !== [] && array_is_list($node))) {
                $issues[] = ['path' => $path, 'message' => 'BlockNode must be an object'];
                return;
            }
            $name = $node['name'] ?? null;
            if (!is_string($name) || preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $name) !== 1) {
                $issues[] = ['path' => "{$path}/name", 'message' => 'name must match ^[a-z0-9-]+/[a-z0-9-]+$'];
            }
            foreach (['innerHTML', 'innerContent'] as $forbidden) {
                if (array_key_exists($forbidden, $node)) {
                    $issues[] = ['path' => "{$path}/{$forbidden}", 'message' => "{$forbidden} is compiler output and never appears in a tree (R1)"];
                }
            }
            foreach (array_keys($node) as $key) {
                if (!in_array((string) $key, ['name', 'attributes', 'innerBlocks'], true)) {
                    $issues[] = ['path' => "{$path}/{$key}", 'message' => 'a BlockNode is {name, attributes?, innerBlocks?} — nothing else'];
                }
            }
            foreach ($node['innerBlocks'] ?? [] as $i => $child) {
                $walk($child, "{$path}/innerBlocks/{$i}");
            }
        };
        foreach ($blocks as $i => $node) {
            $walk($node, "/blocks/{$i}");
        }
        return $issues;
    }

    /**
     * The Layout Cascade, mechanized at the band root: ONE core/group, align
     * "full", and a declared inner layout — "constrained" for centered inner
     * content, "default" for edge-to-edge.
     *
     * @param array<string,mixed> $tree
     * @return list<array<string,mixed>>
     */
    public static function screenBandRoot(array $tree): array
    {
        $blocks = $tree['blocks'] ?? [];
        if (!is_array($blocks) || count($blocks) !== 1) {
            $count = is_array($blocks) ? count($blocks) : 0;
            return [['code' => 'band_root', 'path' => '/blocks', 'message' => "a band tree is exactly ONE root core/group (got {$count} roots)"]];
        }
        $failures = [];
        $root = $blocks[0];
        $rootName = is_array($root) ? ($root['name'] ?? null) : null;
        if ($rootName !== 'core/group') {
            $got = is_string($rootName) ? $rootName : 'nothing';
            $failures[] = ['code' => 'band_root', 'path' => '/blocks/0/name', 'message' => "the root band is a core/group (got {$got})"];
        }
        $attributes = is_array($root) && is_array($root['attributes'] ?? null) ? $root['attributes'] : [];
        if (($attributes['align'] ?? null) !== 'full') {
            $failures[] = [
                'code'    => 'band_root',
                'path'    => '/blocks/0/attributes/align',
                'message' => 'the root band carries align "full" — without it the constrained root layout clamps the band'
                    . ' to contentSize and it ships as a narrow strip in the content column (width is fixed here, never in CSS)',
            ];
        }
        $layoutType = is_array($attributes['layout'] ?? null) ? ($attributes['layout']['type'] ?? null) : null;
        if ($layoutType !== 'constrained' && $layoutType !== 'default') {
            $failures[] = [
                'code'    => 'band_root',
                'path'    => '/blocks/0/attributes/layout',
                'message' => 'the root band declares its inner layout: {"type": "constrained"} for centered inner content,'
                    . ' {"type": "default"} for edge-to-edge',
            ];
        }
        return $failures;
    }

    /**
     * The literal screen: below the token system an artifact carries slugs and
     * copy — never a design value. Hard ONLY where a preset exists to spend
     * through: hex colours anywhere in attributes; absolute lengths under
     * style.spacing or as a fontSize under style. `em` never fails.
     *
     * @param array<string,mixed> $tree
     * @return list<array<string,mixed>>
     */
    public static function screenTreeLiterals(array $tree): array
    {
        $failures = [];
        $scan = function (mixed $value, string $path, ?string $key, bool $inStyle, bool $inSpacing) use (&$scan, &$failures): void {
            if (is_string($value)) {
                if (preg_match(self::PRESET_REF, $value) === 1) {
                    return;
                }
                if (preg_match(self::HEX_LITERAL, $value) === 1) {
                    $failures[] = ['code' => 'literal_value', 'path' => $path, 'message' => "hex colour literal \"{$value}\" — spend the palette slug instead"];
                    return;
                }
                if (!$inStyle || preg_match(self::ABS_LENGTH, $value, $m) !== 1) {
                    return;
                }
                if (strtolower($m[1]) === 'em') {
                    return; // relative to its own context — a mechanic, not a design value
                }
                if ($inSpacing) {
                    $failures[] = ['code' => 'literal_value', 'path' => $path, 'message' => "absolute length \"{$value}\" under style.spacing — spend a spacing preset (var:preset|spacing|NN)"];
                } elseif ($key === 'fontSize') {
                    $failures[] = ['code' => 'literal_value', 'path' => $path, 'message' => "absolute length \"{$value}\" as a font size — use the fontSize slug attribute or a font-size preset"];
                }
                // Anything else under style (letterSpacing, border widths, radii…)
                // has no preset to spend through — allowed.
                return;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $i => $v) {
                    $scan($v, "{$path}/{$i}", $key, $inStyle, $inSpacing);
                }
                return;
            }
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $k = (string) $k;
                    $scan($v, "{$path}/{$k}", $k, $inStyle || $k === 'style', $inSpacing || ($inStyle && $k === 'spacing'));
                }
            }
        };
        $walk = function (mixed $node, string $path) use (&$walk, $scan): void {
            if (!is_array($node)) {
                return;
            }
            if (is_array($node['attributes'] ?? null)) {
                $scan($node['attributes'], "{$path}/attributes", null, false, false);
            }
            foreach ($node['innerBlocks'] ?? [] as $i => $child) {
                $walk($child, "{$path}/innerBlocks/{$i}");
            }
        };
        foreach ($tree['blocks'] ?? [] as $i => $node) {
            $walk($node, "/blocks/{$i}");
        }
        return $failures;
    }

    /**
     * The ink screen at the artifact's birth: walk the tree tracking the
     * ground and ink each node actually sits on; a declared pair under 3:1
     * fails while a repair is still one budget-covered call; 3–4.5:1 is
     * advisory (display-scale text only).
     *
     * @param array<string,mixed> $tree
     * @param array<int,array<string,mixed>> $palette
     * @return array{failures:list<array<string,mixed>>,advisories:list<array<string,mixed>>}
     */
    public static function screenTreeInk(array $tree, array $palette): array
    {
        $failures = [];
        $advisories = [];
        if ($palette === []) {
            return ['failures' => $failures, 'advisories' => $advisories];
        }
        self::walkInk($tree, $palette, function (array &$node, string $path, string $bg, string $ink, float $ratio) use (&$failures, &$advisories): void {
            $r = round($ratio, 2);
            if ($ratio < self::INK_HARD_FLOOR) {
                $failures[] = [
                    'code'    => 'ink_contrast',
                    'path'    => $path,
                    'message' => "textColor \"{$ink}\" reads {$r}:1 on its actual ground \"{$bg}\" — under the 3:1 floor;"
                        . " spend a slug from this band's safe ink menu, or move the element to a band this colour clears",
                ];
            } elseif ($ratio < self::INK_SAFE) {
                $advisories[] = [
                    'path'    => $path,
                    'message' => "textColor \"{$ink}\" reads {$r}:1 on \"{$bg}\" — legible but muddy; display-scale text only",
                ];
            }
        });
        return ['failures' => $failures, 'advisories' => $advisories];
    }

    /**
     * The deterministic rescue before the pattern baseline: swap each failing
     * DECLARED ink for the palette's closest compliant slug, in place. Never a
     * model call, never silent — every change is recorded and returned.
     *
     * @param array<string,mixed> $tree Mutated in place.
     * @param array<int,array<string,mixed>> $palette
     * @return list<array{path:string,from:string,to:string}>
     */
    public static function substituteInk(array &$tree, array $palette): array
    {
        $bySlug = TokenMath::bySlug($palette);
        $dist = static function (string $hexA, string $hexB): float {
            $rgb = static function (string $hex): array {
                $h = ltrim($hex, '#');
                if (strlen($h) === 3) {
                    $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
                }
                return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
            };
            $a = $rgb($hexA);
            $b = $rgb($hexB);
            return (($a[0] - $b[0]) ** 2) + (($a[1] - $b[1]) ** 2) + (($a[2] - $b[2]) ** 2);
        };
        $changes = [];
        self::walkInk($tree, $palette, function (array &$node, string $path, string $bg, string $ink, float $ratio) use ($palette, $bySlug, $dist, &$changes): void {
            if ($ratio >= self::INK_HARD_FLOOR) {
                return;
            }
            if (!isset($node['attributes']['textColor'])) {
                return; // inherited inks are the measured band pair's business
            }
            $bgHex = $bySlug[$bg];
            $rated = [];
            foreach ($palette as $p) {
                if (($p['slug'] ?? null) === $bg) {
                    continue;
                }
                $rated[] = [
                    'slug'  => (string) $p['slug'],
                    'ratio' => TokenMath::contrastRatio($bgHex, (string) $p['color']),
                    'd'     => $dist($bySlug[$ink], (string) $p['color']),
                ];
            }
            $safe = array_values(array_filter($rated, static fn (array $c): bool => $c['ratio'] >= self::INK_SAFE));
            usort($safe, static fn (array $a, array $b): int => $a['d'] <=> $b['d']);
            $legible = array_values(array_filter($rated, static fn (array $c): bool => $c['ratio'] >= self::INK_HARD_FLOOR));
            usort($legible, static fn (array $a, array $b): int => $b['ratio'] <=> $a['ratio']);
            $pick = $safe[0] ?? $legible[0] ?? null;
            if ($pick === null) {
                return;
            }
            $changes[] = ['path' => $path, 'from' => $ink, 'to' => $pick['slug']];
            $node['attributes']['textColor'] = $pick['slug'];
        });
        return $changes;
    }

    /**
     * Image-intent geometry: the placeholder minted for an intent is a 1×1
     * pixel, so an intent node must carry its own width and aspectRatio.
     *
     * @param array<string,mixed> $tree
     * @return list<array<string,mixed>>
     */
    public static function screenImageGeometry(array $tree): array
    {
        $failures = [];
        $walk = function (mixed $node, string $path) use (&$walk, &$failures): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['name'] ?? null) === 'core/image' && !empty($node['attributes']['metadata']['imageIntent'])) {
                foreach (['width', 'aspectRatio'] as $attr) {
                    if (empty($node['attributes'][$attr])) {
                        $failures[] = [
                            'code'    => 'image_geometry',
                            'path'    => "{$path}/attributes/{$attr}",
                            'message' => "image-intent node missing {$attr} — the placeholder behind it is a 1×1 pixel, so the"
                                . ' node must carry its own geometry (width, usually "100%", plus an aspectRatio)',
                        ];
                    }
                }
            }
            foreach ($node['innerBlocks'] ?? [] as $i => $child) {
                $walk($child, "{$path}/innerBlocks/{$i}");
            }
        };
        foreach ($tree['blocks'] ?? [] as $i => $node) {
            $walk($node, "/blocks/{$i}");
        }
        return $failures;
    }

    /**
     * The sane-heading-outline screen — exactly one h1, no level jumps.
     *
     * @param array<int,array<string,mixed>> $outline
     * @return list<array<string,mixed>>
     */
    public static function screenOutline(array $outline): array
    {
        $failures = [];
        $headings = array_values(array_filter(
            $outline,
            static fn ($n): bool => is_array($n) && ($n['role'] ?? null) === 'heading' && is_numeric($n['level'] ?? null),
        ));
        $h1s = array_values(array_filter($headings, static fn (array $h): bool => (int) $h['level'] === 1));
        if (count($h1s) !== 1) {
            $names = implode(' | ', array_map(static fn (array $h): string => (string) ($h['name'] ?? ''), $h1s));
            $failures[] = ['code' => 'outline', 'message' => 'expected exactly one h1, got ' . count($h1s) . " ({$names})"];
        }
        $prev = 0;
        foreach ($headings as $h) {
            $level = (int) $h['level'];
            if ($level > $prev + 1) {
                $from = $prev !== 0 ? $prev : 1;
                $name = (string) ($h['name'] ?? '');
                $failures[] = ['code' => 'outline', 'message' => "heading level jump: h{$from} -> h{$level} at \"{$name}\""];
            }
            $prev = $level;
        }
        return $failures;
    }

    /**
     * The measured width audit: every top-level band — section roots and both
     * template parts — spans the viewport, and header agrees with footer.
     *
     * @param array<int,array<string,mixed>> $boxTree
     * @return list<array<string,mixed>>
     */
    public static function screenBandWidths(array $boxTree, ?float $viewportWidth): array
    {
        ['parts' => $parts, 'bands' => $bands] = self::bandStructures($boxTree);
        $failures = [];
        if ($viewportWidth !== null && $viewportWidth > 0) {
            foreach ([...$parts, ...$bands] as $n) {
                $w = (float) ($n['box']['w'] ?? 0);
                if ($w < $viewportWidth - self::CLAMP_SLACK) {
                    $spans = (int) round($w);
                    $viewport = self::formatNumber($viewportWidth);
                    $failures[] = [
                        'code'    => 'band_width',
                        'message' => "band clamped to the content column: {$n['selector_path']} spans {$spans}px of a"
                            . " {$viewport}px viewport — the root band is missing align \"full\" (or fighting the"
                            . ' constrained-layout clamp with CSS, which loses)',
                    ];
                }
            }
        }
        if (count($parts) >= 2) {
            $widths = array_map(static fn (array $n): float => (float) ($n['box']['w'] ?? 0), $parts);
            if (max($widths) - min($widths) > 8) {
                $detail = implode(', ', array_map(
                    static fn (array $n): string => self::lastSegment((string) $n['selector_path']) . '=' . (int) round((float) $n['box']['w']) . 'px',
                    $parts,
                ));
                $failures[] = [
                    'code'    => 'band_width',
                    'message' => "the template parts disagree on width ({$detail}) — header and footer bookend the same"
                        . ' design and must span the same row',
                ];
            }
        }
        return $failures;
    }

    /**
     * The seam audit — bands butt flush. Any daylight between consecutive
     * bands, or between a band and a template part, is the page background
     * leaking through. Skipped when no band was measured.
     *
     * @param array<int,array<string,mixed>> $boxTree
     * @return list<array<string,mixed>>
     */
    public static function screenBandSeams(array $boxTree): array
    {
        ['parts' => $parts, 'bands' => $bands] = self::bandStructures($boxTree);
        if ($bands === []) {
            return [];
        }
        $rows = [...$parts, ...$bands];
        usort($rows, static fn (array $a, array $b): int => ((float) $a['box']['y']) <=> ((float) $b['box']['y']));
        $failures = [];
        for ($i = 1; $i < count($rows); $i++) {
            $prev = $rows[$i - 1];
            $next = $rows[$i];
            $gap = (float) $next['box']['y'] - ((float) $prev['box']['y'] + (float) $prev['box']['h']);
            if ($gap > self::SEAM_TOLERANCE) {
                $px = (int) round($gap);
                $failures[] = [
                    'code'    => 'band_seam',
                    'message' => "{$px}px of page background between bands ({$prev['selector_path']} -> {$next['selector_path']})"
                        . ' — the block-gap seam is back; bands butt flush and carry their spacing as their own padding',
                ];
            }
        }
        return $failures;
    }

    /**
     * The measured ink audit's fatal class: rendered text under 3:1 against
     * its actual ground, whatever layer painted it.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    public static function screenTextContrast(array $findings): array
    {
        $failures = [];
        foreach ($findings as $f) {
            if ((float) ($f['ratio'] ?? PHP_FLOAT_MAX) < self::INK_HARD_FLOOR) {
                $ratio = self::formatNumber((float) $f['ratio']);
                $failures[] = [
                    'code'    => 'ink_contrast',
                    'message' => "unreadable text ({$ratio}:1, {$f['color']} on {$f['background']}): \"{$f['sample']}\" at {$f['selector_path']}",
                ];
            }
        }
        return $failures;
    }

    /**
     * Walk a tree tracking the ground and ink each node sits on, visiting
     * every measurable declared pair. The visitor receives the node BY
     * REFERENCE (substitution edits textColor in place, and the traversal
     * re-reads attributes after the visit so descendants inherit the
     * corrected colour).
     *
     * @param array<string,mixed> $tree Walked (and possibly mutated) in place.
     * @param array<int,array<string,mixed>> $palette
     * @param callable(array<string,mixed>&,string,string,string,float):void $visit
     */
    private static function walkInk(array &$tree, array $palette, callable $visit): void
    {
        $bySlug = TokenMath::bySlug($palette);
        $walk = function (array &$nodes, string $path, ?string $bg, ?string $ink) use (&$walk, $bySlug, $visit): void {
            foreach ($nodes as $i => &$node) {
                if (!is_array($node)) {
                    continue;
                }
                $p = "{$path}/{$i}";
                $a = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
                // A gradient ground is unmeasurable from slugs: checks pause for
                // the subtree until a solid backgroundColor appears again.
                $gradient = array_key_exists('gradient', $a)
                    || (is_array($a['style'] ?? null) && is_array($a['style']['color'] ?? null)
                        && array_key_exists('gradient', $a['style']['color']));
                $nodeBg = $gradient ? null : ($a['backgroundColor'] ?? $bg);
                $nodeInk = $a['textColor'] ?? $ink;
                $raw = null;
                foreach (['content', 'text', 'citation'] as $textKey) {
                    if (is_string($a[$textKey] ?? null)) {
                        $raw = $a[$textKey];
                        break;
                    }
                }
                $hasText = is_string($raw) && trim((string) preg_replace('/<[^>]*>/', '', $raw)) !== '';
                // Buttons paint their own theme surface: only a button declaring
                // BOTH colours is measurable from the tree.
                $measurable = ($node['name'] ?? null) === 'core/button'
                    ? (isset($a['textColor']) && isset($a['backgroundColor']))
                    : true;
                if ($hasText && $measurable && is_string($nodeBg) && is_string($nodeInk)
                    && isset($bySlug[$nodeBg], $bySlug[$nodeInk])
                ) {
                    $visit($node, $p, $nodeBg, $nodeInk, TokenMath::contrastRatio($bySlug[$nodeBg], $bySlug[$nodeInk]));
                }
                // Re-read after visit: substitution edits textColor in place and
                // the corrected ink is what descendants inherit.
                if (isset($node['innerBlocks']) && is_array($node['innerBlocks'])) {
                    $after = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
                    $walk(
                        $node['innerBlocks'],
                        "{$p}/innerBlocks",
                        $gradient ? null : ($after['backgroundColor'] ?? $bg),
                        $after['textColor'] ?? $ink,
                    );
                }
            }
            unset($node);
        };
        if (isset($tree['blocks']) && is_array($tree['blocks'])) {
            $walk($tree['blocks'], '/blocks', null, null);
        }
    }

    /**
     * The page's top-level structures in a measured box tree: both template
     * parts, plus every direct child group of post-content (the band roots).
     *
     * @param array<int,array<string,mixed>> $boxTree
     * @return array{parts:list<array<string,mixed>>,bands:list<array<string,mixed>>}
     */
    private static function bandStructures(array $boxTree): array
    {
        $parts = [];
        $postContent = null;
        foreach ($boxTree as $n) {
            if (!is_array($n) || !isset($n['selector_path'])) {
                continue;
            }
            $last = self::lastSegment((string) $n['selector_path']);
            if (($n['block_name'] ?? null) === 'core/template-part' || str_contains($last, '.wp-block-template-part')) {
                $parts[] = $n;
            }
            if ($postContent === null
                && (($n['block_name'] ?? null) === 'core/post-content' || str_contains($last, '.wp-block-post-content'))
            ) {
                $postContent = $n;
            }
        }
        $bands = [];
        if ($postContent !== null) {
            $prefix = $postContent['selector_path'] . ' > ';
            foreach ($boxTree as $n) {
                if (!is_array($n) || !isset($n['selector_path'])) {
                    continue;
                }
                $last = self::lastSegment((string) $n['selector_path']);
                if (($n['block_name'] ?? null) !== 'core/group' && !str_contains($last, '.wp-block-group')) {
                    continue;
                }
                $path = (string) $n['selector_path'];
                if (str_starts_with($path, $prefix) && !str_contains(substr($path, strlen($prefix)), ' > ')) {
                    $bands[] = $n;
                }
            }
        }
        return ['parts' => $parts, 'bands' => $bands];
    }

    private static function lastSegment(string $selectorPath): string
    {
        $segments = explode(' > ', $selectorPath);
        return $segments[count($segments) - 1] ?? '';
    }

    /** Render a float the way JS renders a number: no trailing ".0" on whole values. */
    private static function formatNumber(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : (string) $value;
    }
}
