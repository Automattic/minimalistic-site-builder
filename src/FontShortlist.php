<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * A rotating per-tradition candidate shortlist for the direction expansion.
 *
 * Naming a letterform tradition alone does not diversify type: told "slab",
 * the model reaches for the most famous slab it knows, and the audited result
 * is Zilla Slab + Bitter on six of fourteen demos (BIGR-920). Banning the
 * reflex faces only moves the reflex — FontMonoculture's history shows the
 * next tier takes over. So instead of bans, the expansion prompt now SHOWS a
 * shortlist: a deterministic per-site sample from a curated shelf of real
 * Google families in the committed tradition. The model may pick from it or
 * go beyond it with a reason; either way it stops rediscovering the same
 * famous family, because a concrete alternative is in front of it.
 *
 * Shelves are curated, not computed, for the same reason FontMonoculture's
 * POOL is: the shipped catalog has no script or popularity data, so a blind
 * pick surfaces unusable faces. Every name is asserted to resolve in the
 * vendored catalog by a unit test. The reflex faces themselves (Zilla Slab,
 * Bitter, Oswald…) stay ON their shelves — the goal is rotation, not a new
 * ban list — but anything on FontMonoculture::OVERUSED is filtered out.
 *
 * Pure apart from the catalog it is handed — unit-testable.
 */
final class FontShortlist
{
    /** How many families one build's prompt shows for its tradition. */
    public const SAMPLE = 12;

    /**
     * Curated Latin text and display faces per letterform tradition
     * (ConceptSeeds::TYPE_REGISTERS). A family may sit on two shelves when
     * the traditions genuinely overlap (Literata is humanist and
     * transitional; Abril Fatface is didone and display-serif).
     *
     * @var array<string,list<string>>
     */
    private const SHELVES = [
        'grotesque' => [
            'Schibsted Grotesk', 'Familjen Grotesk', 'Bricolage Grotesque', 'Hanken Grotesk',
            'Darker Grotesque', 'Public Sans', 'Libre Franklin', 'Work Sans',
            'Chivo', 'Karla', 'Rubik', 'Asap',
            'Barlow', 'Gantari', 'Onest', 'Geologica',
            'Anybody', 'Archivo Narrow', 'Roboto Condensed', 'Overpass',
            'Red Hat Text', 'Readex Pro', 'Cabin',
            // frm W5b: the product-homepage grotesks of the reference corpus
            // that are not on the monoculture list (Instrument Sans, Geist
            // and Plus Jakarta Sans are, by measured reflex).
            'Inter Tight', 'Albert Sans', 'Be Vietnam Pro', 'Host Grotesk',
            'Golos Text', 'Reddit Sans',
        ],
        'didone' => [
            'Bodoni Moda', 'Libre Bodoni', 'DM Serif Display', 'DM Serif Text',
            'Prata', 'Abril Fatface', 'Rozha One', 'Gilda Display',
            'Antic Didone', 'Italiana', 'Bellefair', 'Suranna',
            'Old Standard TT', 'Cormorant Infant', 'Judson', 'Rufina',
        ],
        'slab' => [
            'Zilla Slab', 'Bitter', 'Arvo', 'Rokkitt',
            'Aleo', 'Crete Round', 'Alfa Slab One', 'Bevan',
            'Solway', 'Josefin Slab', 'Roboto Slab', 'Hepta Slab',
            'Podkova', 'Besley', 'Kelly Slab', 'Enriqueta',
            'Slabo 27px', 'Ultra',
        ],
        'humanist' => [
            'Alegreya', 'Alegreya Sans', 'Cardo', 'Vollkorn',
            'Literata', 'Petrona', 'Faustina', 'Frank Ruhl Libre',
            'Gelasio', 'Merriweather', 'Source Serif 4', 'Spectral',
            'Lora', 'Newsreader', 'EB Garamond', 'Crimson Pro',
            'Libre Caslon Text', 'Sorts Mill Goudy', 'PT Serif', 'Eczar',
            'Martel', 'Halant', 'Gentium Book Plus', 'Andada Pro',
        ],
        'geometric' => [
            'Poppins', 'Jost', 'Sora', 'Figtree',
            'Epilogue', 'Urbanist', 'Outfit', 'Manrope',
            'Lexend', 'Questrial', 'Josefin Sans', 'DM Sans',
            'Red Hat Display', 'League Spartan', 'Comfortaa', 'Fredoka',
            'Quicksand', 'Exo 2', 'Syne', 'Unbounded',
            // frm W5b: the rounded product geometrics of the reference corpus.
            'Funnel Sans', 'Funnel Display', 'Wix Madefor Display', 'Parkinsans',
        ],
        'transitional' => [
            'Libre Baskerville', 'Baskervville', 'PT Serif', 'Ibarra Real Nova',
            'Lustria', 'Domine', 'Noticia Text', 'Literata',
            'Newsreader', 'Petrona', 'Source Serif 4', 'Spectral',
            'Bree Serif', 'Roboto Serif', 'Georama', 'Brygada 1918',
            'Piazzolla', 'STIX Two Text',
        ],
        'condensed' => [
            'Oswald', 'Barlow Condensed', 'Barlow Semi Condensed', 'Saira Condensed',
            'Archivo Narrow', 'Fjalla One', 'Anton', 'Bebas Neue',
            'Teko', 'Khand', 'Yanone Kaffeesatz', 'PT Sans Narrow',
            'Encode Sans Condensed', 'Saira Semi Condensed', 'News Cycle', 'Pathway Gothic One',
        ],
        'mono' => [
            'JetBrains Mono', 'Space Mono', 'IBM Plex Mono', 'Source Code Pro',
            'Fira Code', 'Inconsolata', 'Cousine', 'DM Mono',
            'Spline Sans Mono', 'Martian Mono', 'Red Hat Mono', 'Overpass Mono',
            'Chivo Mono', 'Fragment Mono', 'Azeret Mono', 'Sometype Mono',
            'Anonymous Pro',
        ],
        'script' => [
            'Caveat', 'Dancing Script', 'Pacifico', 'Kalam',
            'Satisfy', 'Great Vibes', 'Sacramento', 'Amatic SC',
            'Shadows Into Light', 'Courgette', 'Yellowtail', 'Parisienne',
            'Marck Script', 'Gochi Hand', 'Handlee', 'Mr Dafoe',
            'Norican', 'Allura',
        ],
        'display-serif' => [
            'Abril Fatface', 'Yeseva One', 'Ultra', 'Chonburi',
            'Rufina', 'Marcellus', 'Cinzel', 'Forum',
            'Unna', 'IM Fell English', 'Young Serif', 'Grenze',
            'Eczar', 'BioRhyme', 'Bevan', 'Calistoga',
            'Castoro',
        ],
    ];

    /**
     * The deterministic per-site sample for one tradition: SAMPLE consecutive
     * names (wrapping) from the shelf, starting at a crc32 offset of the
     * stable identifier. The same site always sees the same names; two sites
     * see different windows, so no single family is in front of every build.
     *
     * Empty when the tradition is unknown or its shelf loses too many names
     * to the catalog — a wrong list is worse than none.
     *
     * @return list<string>
     */
    public static function candidates(string $typeRegister, string $identifier, FontCatalog $catalog): array
    {
        $shelf = [];
        foreach (self::SHELVES[$typeRegister] ?? [] as $name) {
            if (!FontMonoculture::isOverused($name) && $catalog->resolve($name) !== null) {
                $shelf[] = $name;
            }
        }
        $count = count($shelf);
        if ($count < self::SAMPLE) {
            return [];
        }
        // crc32 rather than random: the same site must rebuild identically.
        $start = crc32($identifier . '|' . $typeRegister) % $count;
        $out = [];
        for ($i = 0; $i < self::SAMPLE; $i++) {
            $out[] = $shelf[($start + $i) % $count];
        }
        return $out;
    }

    /**
     * The prompt paragraph the expansion template injects, or '' when there
     * is nothing worth saying (degraded seed, unknown tradition).
     */
    public static function promptParagraph(
        string $typeRegister,
        string $identifier,
        FontCatalog $catalog,
        string $register = '',
    ): string {
        $candidates = self::candidates($typeRegister, $identifier, $catalog);
        if ($candidates === []) {
            return '';
        }
        return 'Candidate families inside this tradition, all on Google Fonts: '
            . implode(', ', $candidates) . '. '
            . 'Treat the list as a starting shelf, not a fence: pick from it, or go beyond it '
            . 'when you can say what makes another real Google Fonts family in this tradition '
            . 'righter for THIS site. Do not default to the one famous family the tradition is '
            . 'known by; that reflex is how every generated site ends up set in the same face.'
            . self::productWeightSentence($typeRegister, $register);
    }

    /**
     * The traditions whose display type on a product or portfolio homepage
     * is medium weight and tight, not bold (frm W5b). Every reference site
     * of the corpus sets its grotesque or geometric display at 500-600 with
     * -0.04em tracking; generated sites in the same traditions defaulted to
     * 700-800, which reads as a brochure.
     */
    public const PRODUCT_REGISTERS = ['modernist', 'technical', 'pop', 'playful', 'utilitarian'];
    public const PRODUCT_TYPE_REGISTERS = ['grotesque', 'geometric'];

    public static function productWeightSentence(string $typeRegister, string $register): string
    {
        if (!in_array($register, self::PRODUCT_REGISTERS, true)
            || !in_array($typeRegister, self::PRODUCT_TYPE_REGISTERS, true)) {
            return '';
        }
        return ' This is a product or portfolio tradition in a sans letterform: set the display '
            . 'heading at a MEDIUM weight (commit 500 and 600 in `weights`, and lead with them; never 800), '
            . 'pair it with `type_treatment: "tight"`, and keep the body at 400. Bold display sans '
            . 'is the brochure reflex the reference corpus never shows.';
    }

    /**
     * The whole curated shelf map, for the test that keeps it honest against
     * the catalog.
     *
     * @return array<string,list<string>>
     */
    public static function shelves(): array
    {
        return self::SHELVES;
    }
}
