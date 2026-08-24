<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * The faces every model reaches for, and a deterministic way off them.
 *
 * Two lists, learned differently. The first is this pipeline's own measured
 * reflexes: across 128 audited builds, 128 sites drew on 13 heading families
 * and five of them set more than half of everything. The second is the wider
 * monoculture the industry keeps landing on, taken from impeccable's detector.
 *
 * Naming our five in the expansion prompt worked — they went to zero — and the
 * very next round settled on `Space Grotesk` twice in five builds, because a
 * list learned from one corpus only covers that corpus. Prose alone also cannot
 * hold: it is a suggestion the model may decline. So this substitutes instead,
 * from the Google catalog the build already ships, with no extra model call.
 *
 * Pure apart from the catalog it is handed — unit-testable.
 */
final class FontMonoculture
{
    /**
     * Lowercased family names that read as a default rather than a choice.
     *
     * None of these are bad faces, and any of them can be right when a brief
     * genuinely asks. They are excluded from the UNPROMPTED pick for the same
     * reason `utilitarian` left the register rotation: a uniform reach lands on
     * them far more often than a designer would choose them.
     *
     * @var list<string>
     */
    public const OVERUSED = [
        // The older ubiquity.
        'inter', 'roboto', 'open sans', 'lato', 'montserrat', 'arial', 'helvetica',
        // The current wave.
        'fraunces', 'instrument sans', 'instrument serif',
        'geist', 'geist sans', 'geist mono', 'mona sans',
        'plus jakarta sans', 'space grotesk', 'recoleta',
        // This pipeline's own measured reflexes.
        'archivo', 'archivo black', 'playfair display', 'cormorant garamond',
    ];

    /** Generic fallbacks we can group a catalog family by. */
    private const CATEGORIES = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'];

    /**
     * Where a substitution may land, per generic category.
     *
     * Curated, not computed, and that is a deliberate limitation. The shipped
     * catalog carries only name, slug, stack and weights — no subset, script,
     * or popularity data — so nothing in it distinguishes a text face from one
     * of the 213 Noto script variants. Picking blind across 1,949 families
     * produced "Noto Serif Ahom" and "Noto Sans Malayalam" for an English
     * bakery: mechanically correct, unusable in practice, and worse than the
     * default it replaced.
     *
     * So the pool is a hand-picked shelf of established Latin text and display
     * faces, none of them on OVERUSED. Every name is asserted to resolve in the
     * catalog by a unit test, so a catalog update cannot silently leave a
     * dangling reference. Extend it freely; the ordering only needs to be
     * stable, not meaningful.
     *
     * @var array<string,list<string>>
     */
    private const POOL = [
        'serif' => [
            'Source Serif 4', 'Spectral', 'Bitter', 'Zilla Slab', 'Faustina',
            'Petrona', 'Literata', 'Vollkorn', 'Alegreya', 'Cardo',
            'Gelasio', 'Frank Ruhl Libre', 'Eczar', 'Martel', 'Halant',
            'Yeseva One', 'Abril Fatface', 'Alfa Slab One',
        ],
        // Kept apart from the lists above: see DISPLAY_ONLY.
        'sans-serif' => [
            'Karla', 'Chivo', 'Public Sans', 'Libre Franklin', 'Manrope',
            'Hanken Grotesk', 'Barlow', 'Work Sans', 'Rubik', 'Asap',
            'Cabin', 'Jost', 'Epilogue', 'Sora', 'Figtree',
            'Oswald', 'Bebas Neue', 'Anton',
        ],
        'monospace' => [
            'JetBrains Mono', 'Source Code Pro', 'Fira Code', 'Inconsolata', 'Cousine',
        ],
    ];

    /**
     * Faces from POOL that set headlines and nothing else.
     *
     * Anton at 16px is unreadable body copy, and substituting one INTO a body
     * slot would trade a dull page for a broken one. The heading slot keeps the
     * whole shelf; body and accent draw from what is left.
     *
     * @var list<string>
     */
    private const DISPLAY_ONLY = [
        'Yeseva One', 'Abril Fatface', 'Alfa Slab One',
        'Oswald', 'Bebas Neue', 'Anton',
    ];

    public static function isOverused(string $family): bool
    {
        return in_array(strtolower(trim($family)), self::OVERUSED, true);
    }

    /**
     * A replacement for an overused family, or null when none is needed or
     * none can be found.
     *
     * The replacement keeps the original's generic category, so a serif stays a
     * serif and a mono stays a mono — the point is to leave the monoculture,
     * not to change what the direction asked for. `$seed` varies the pick per
     * site so one overused family does not simply map onto one replacement and
     * recreate the problem under a new name; the same seed always yields the
     * same answer, so builds stay reproducible.
     */
    public static function substitute(
        string $family,
        string $seed,
        FontCatalog $catalog,
        string $slot = 'heading',
    ): ?string {
        if (!self::isOverused($family)) {
            return null;
        }
        $category = self::categoryOf($family, $catalog) ?? 'sans-serif';
        $candidates = self::candidates($category, $catalog, $slot);
        if ($candidates === []) {
            return null;
        }
        // crc32 rather than random: the same site must rebuild identically.
        $index = crc32(strtolower($seed . '|' . $family)) % count($candidates);
        return $candidates[$index];
    }

    /**
     * The pool for `$category`, minus anything the shipped catalog cannot
     * actually serve — a substitution naming a family the build cannot fetch
     * would be worse than the monoculture face it replaced.
     *
     * @return list<string>
     */
    private static function candidates(string $category, FontCatalog $catalog, string $slot): array
    {
        $out = [];
        foreach (self::POOL[$category] ?? [] as $name) {
            if (self::isOverused($name) || $catalog->resolve($name) === null) {
                continue;
            }
            if ($slot !== 'heading' && in_array($name, self::DISPLAY_ONLY, true)) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * The whole curated shelf, for the test that keeps it honest against the
     * catalog.
     *
     * @return array<string,list<string>>
     */
    public static function pool(): array
    {
        return self::POOL;
    }

    private static function categoryOf(string $family, FontCatalog $catalog): ?string
    {
        $entry = $catalog->resolve($family);
        return $entry === null ? null : self::categoryOfStack((string) ($entry['fontFamily'] ?? ''));
    }

    /** The generic fallback a catalog stack ends in ("Chivo, sans-serif" => sans-serif). */
    private static function categoryOfStack(string $stack): ?string
    {
        $parts = array_map(
            static fn (string $p): string => strtolower(trim($p, " \t\"'")),
            explode(',', $stack),
        );
        foreach (array_reverse($parts) as $part) {
            if (in_array($part, self::CATEGORIES, true)) {
                return $part;
            }
        }
        return null;
    }
}
