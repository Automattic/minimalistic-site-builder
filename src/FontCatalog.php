<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The vendored Google Fonts catalog: family name → downloadable faces.
 *
 * Distilled from the WordPress google-fonts-to-wordpress-collection release
 * (the same catalog core's Font Library installs from) by
 * bin/distill-google-fonts-catalog.php; data/google-fonts/catalog-manifest.json
 * records the source release and hashes. Every face src is fonts.gstatic.com —
 * the distiller refuses anything else, and the consistency test re-checks the
 * vendored artifact.
 *
 * Pure lookups over static data; nothing here touches the network.
 */
final class FontCatalog
{
    /** @var array<string,array<string,mixed>> canonical name (lowercased) => family */
    private array $byName = [];

    /** @var array<string,array<string,mixed>> slug => family */
    private array $bySlug = [];

    /** @param array<int,array<string,mixed>> $families */
    private function __construct(array $families)
    {
        foreach ($families as $family) {
            $this->byName[strtolower((string) $family['name'])] = $family;
            $this->bySlug[strtolower((string) $family['slug'])] = $family;
        }
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__) . '/data/google-fonts/catalog.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read font catalog: {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['font_families']) || !is_array($decoded['font_families'])) {
            throw new \RuntimeException("Font catalog is not a font_families document: {$path}");
        }
        return new self($decoded['font_families']);
    }

    public function count(): int
    {
        return count($this->byName);
    }

    /**
     * Resolve a family reference the way it appears in generated themes: a bare
     * name ("Inter"), a slug ("inter"), or a CSS stack ("'Inter', sans-serif" —
     * the first segment names the family, quoted or not).
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $reference): ?array
    {
        $first = self::primaryFamily($reference);
        if ($first === null) {
            return null;
        }
        $normalized = strtolower($first);
        return $this->byName[$normalized]
            ?? $this->bySlug[$normalized]
            ?? null;
    }

    /**
     * The family a CSS font-family stack names: its first segment, unquoted.
     * The one parser for this — FontsPhpStep delegates here, so the scan and
     * the catalog can never disagree about which family a stack refers to.
     */
    public static function primaryFamily(string $stack): ?string
    {
        $first = trim(explode(',', $stack)[0]);
        $first = trim($first, " \t\"'");
        return $first === '' ? null : $first;
    }

    /**
     * The faces to bundle for one family given the build's scanned use.
     *
     * Exact weight matches when the family has them; a missing weight falls
     * back to the nearest available one (bundling the nearest real face beats
     * leaving the browser to synthesize from 400). Italic faces are selected
     * the same way, only when the scan saw italics. Deduplicated by src.
     *
     * @param array<string,mixed> $family a resolve() result
     * @param int[] $weights ascending scanned weights
     * @param bool $italic whether the scan saw italics
     * @return array<int,array{fontWeight:string,fontStyle:string,src:string}>
     */
    public function faces(array $family, array $weights, bool $italic): array
    {
        $byStyle = ['normal' => [], 'italic' => []];
        foreach ((array) ($family['fontFace'] ?? []) as $face) {
            $byStyle[(string) $face['fontStyle']][(int) $face['fontWeight']] = $face;
        }

        $styles = $italic && $byStyle['italic'] !== [] ? ['normal', 'italic'] : ['normal'];
        $selected = [];
        foreach ($styles as $style) {
            $available = $byStyle[$style];
            if ($available === []) {
                continue;
            }
            // Ascending, so nearest() breaks equidistant ties toward the
            // lighter weight deterministically.
            ksort($available);
            foreach ($weights as $weight) {
                $face = $available[$weight] ?? $available[self::nearest($weight, array_keys($available))];
                $selected[(string) $face['src']] = $face;
            }
        }
        return array_values($selected);
    }

    /** @param int[] $candidates non-empty */
    private static function nearest(int $weight, array $candidates): int
    {
        $best = $candidates[0];
        foreach ($candidates as $candidate) {
            if (abs($candidate - $weight) < abs($best - $weight)) {
                $best = $candidate;
            }
        }
        return $best;
    }
}
