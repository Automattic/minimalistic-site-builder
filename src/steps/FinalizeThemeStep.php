<?php
declare(strict_types=1);

/**
 * Final step (deterministic): make the theme actually render as designed.
 *
 * Input:  theme/theme.json + the final section markup (theme/parts/*.html,
 *         theme/templates/*.html — i.e. AFTER fix-blocks).
 * Output: theme/functions.php. This step is the SINGLE owner of functions.php —
 *         no other step writes it. It emits:
 *         - the style.css enqueue: WordPress does NOT load a block theme's
 *           style.css automatically (it only reads the header for metadata),
 *           so the utility CSS shipped there — .equal-cards, .header-overlay,
 *           and the page-styles layout appendix — would silently never apply
 *           without an explicit enqueue. Also registered as an editor style so
 *           the editor previews match the front end.
 *         - the Google Fonts enqueue for the heading/body families named in
 *           theme.json, requesting exactly the weights (and italics) the
 *           design actually uses — scanned from theme.json's fontWeight/
 *           fontStyle values and the generated markup's block attributes and
 *           inline styles. Requesting only 400/700 while a direction leans on
 *           a 300 display face makes the browser synthesize the weight and
 *           flattens the design. Hooked on enqueue_block_assets so the fonts
 *           render in the block editor too, not just the front end.
 */
final class FinalizeThemeStep implements Step
{
    /** Weights every build loads: body default + strong. Scanned weights add to these. */
    private const BASE_WEIGHTS = [400, 700];

    /** Families that are system/web-safe or CSS generics — never enqueue these. */
    private const GENERIC = [
        'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui',
        'ui-serif', 'ui-sans-serif', 'ui-monospace', '-apple-system', 'blinkmacsystemfont',
        'helvetica', 'helvetica neue', 'arial', 'georgia', 'times', 'times new roman',
        'courier', 'courier new', 'verdana', 'tahoma', 'trebuchet ms', 'palatino',
        'garamond', 'inherit', 'initial',
    ];

    public function id(): string
    {
        return 'finalize-theme';
    }

    public function label(): string
    {
        return 'Finalize theme (style + font loading)';
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $families = $theme['settings']['typography']['fontFamilies'] ?? [];

        $names = [];
        foreach ($families as $family) {
            $primary = self::primaryFamily((string) ($family['fontFamily'] ?? ''));
            if ($primary !== null && !in_array(strtolower($primary), self::GENERIC, true)) {
                $names[$primary] = true; // dedupe preserving first-seen
            }
        }
        $names = array_keys($names);

        [$weights, $italic] = self::fontVariants($theme, self::themeMarkup($project));

        $project->writeText(
            'theme/functions.php',
            self::functionsPhp($project->slug(), $names, $weights, $italic)
        );
    }

    /**
     * Every font weight the build references, plus whether italics appear.
     * Sources: theme.json fontWeight/fontStyle values anywhere (styles.elements.*,
     * styles.blocks.*, top-level styles) and the generated markup's block
     * attributes ("fontWeight":"300") and inline styles (font-weight:300).
     * 400 and 700 are always included. Pure — unit-testable.
     *
     * @param array<mixed> $theme decoded theme.json
     * @param string $markup concatenated parts/templates HTML
     * @return array{0:int[],1:bool} ascending unique weights, italics used
     */
    public static function fontVariants(array $theme, string $markup): array
    {
        $weights = array_fill_keys(self::BASE_WEIGHTS, true);
        $italic = false;

        self::collectTypography($theme, $weights, $italic);

        if (preg_match_all('/"fontWeight":\s*"?([1-9]00)"?/', $markup, $m) > 0) {
            foreach ($m[1] as $w) {
                $weights[(int) $w] = true;
            }
        }
        if (preg_match_all('/font-weight:\s*([1-9]00)\b/i', $markup, $m) > 0) {
            foreach ($m[1] as $w) {
                $weights[(int) $w] = true;
            }
        }
        if (preg_match('/"fontStyle":\s*"italic"|font-style:\s*italic/i', $markup) === 1) {
            $italic = true;
        }

        $weights = array_keys($weights);
        sort($weights);
        return [$weights, $italic];
    }

    /**
     * Walk decoded theme.json collecting fontWeight / fontStyle:italic values
     * wherever they appear.
     *
     * @param array<mixed> $node
     * @param array<int,bool> $weights
     */
    private static function collectTypography(array $node, array &$weights, bool &$italic): void
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::collectTypography($value, $weights, $italic);
                continue;
            }
            if ($key === 'fontWeight' && preg_match('/^[1-9]00$/', (string) $value) === 1) {
                $weights[(int) $value] = true;
            }
            if ($key === 'fontStyle' && is_string($value) && strtolower($value) === 'italic') {
                $italic = true;
            }
        }
    }

    /**
     * The Google Fonts CSS2 URL requesting each family at exactly the given
     * weights — `ital,wght` tuples when italics are used, plain `wght` axis
     * otherwise. Pure — unit-testable.
     *
     * @param string[] $names
     * @param int[] $weights ascending
     */
    public static function googleFontsUrl(array $names, array $weights, bool $italic): string
    {
        // css2 requires axis tuples in ascending order: all 0,(upright) before 1,(italic).
        $axis = $italic
            ? 'ital,wght@' . implode(';', array_merge(
                array_map(static fn (int $w): string => "0,{$w}", $weights),
                array_map(static fn (int $w): string => "1,{$w}", $weights),
            ))
            : 'wght@' . implode(';', $weights);

        $params = array_map(
            // rawurlencode turns spaces into %20; Google Fonts wants '+'.
            static fn (string $n): string => 'family=' . str_replace('%20', '+', rawurlencode($n)) . ':' . $axis,
            $names
        );
        return 'https://fonts.googleapis.com/css2?' . implode('&', $params) . '&display=swap';
    }

    /** All generated parts + templates concatenated, for the usage scan. */
    private static function themeMarkup(Project $project): string
    {
        $markup = '';
        foreach (['parts', 'templates'] as $dir) {
            foreach (glob($project->themePath($dir) . '/*.html') ?: [] as $file) {
                $markup .= "\n" . (string) file_get_contents($file);
            }
        }
        return $markup;
    }

    /** Extract the first font name from a CSS font-family stack, unquoted. */
    private static function primaryFamily(string $stack): ?string
    {
        $first = trim(explode(',', $stack)[0]);
        $first = trim($first, "\"'");
        return $first === '' ? null : $first;
    }

    /**
     * @param string[] $names
     * @param int[] $weights
     */
    private static function functionsPhp(string $slug, array $names, array $weights, bool $italic): string
    {
        $slug = ProjectStore::slugify($slug);
        $fonts = '';

        if ($names !== []) {
            $url = self::googleFontsUrl($names, $weights, $italic);
            $list = implode(', ', $names);
            $variants = implode('/', $weights) . ($italic ? ' + italics' : '');

            $fonts = <<<PHP

                // Webfonts ({$list}) from Google Fonts at the weights the design
                // actually uses ({$variants}), on enqueue_block_assets so they render
                // in the block editor as well as the front end.
                add_action('enqueue_block_assets', function () {
                    wp_enqueue_style('preconnect-gfonts', 'https://fonts.gstatic.com', array(), null);
                    wp_enqueue_style(
                        '{$slug}-fonts',
                        '{$url}',
                        array(),
                        null
                    );
                });
                PHP;
        }

        return <<<PHP
            <?php
            /**
             * Front-end wiring the block theme needs beyond theme.json.
             */
            add_action('wp_enqueue_scripts', function () {
                // Block themes do not load style.css automatically — without this
                // enqueue its utility CSS (card layouts, layout utilities) never applies.
                wp_enqueue_style('{$slug}-style', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
            });
            {$fonts}

            // Mirror style.css into the editor so previews match the front end.
            add_action('after_setup_theme', function () {
                add_editor_style('style.css');
            });

            PHP;
    }
}
