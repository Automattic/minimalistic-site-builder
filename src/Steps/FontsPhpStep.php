<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (LLM): write theme/fonts.php — the module that loads the theme's Google
 * Fonts (telex-style file split: the deterministic functions.php only wires
 * style.css and require_once's this file).
 *
 * Input:  theme/theme.json + designDirection.md + the final section markup
 *         (theme/parts/*.html, theme/templates/*.html — i.e. AFTER fix-blocks).
 * Output: theme/fonts.php, hooked on enqueue_block_assets so the fonts render
 *         in the block editor as well as the front end. Skipped entirely when
 *         theme.json names only system/web-safe families.
 *
 * The model writes the file so design intent can reach font loading — a
 * direction built on a light display face can request 300, an editorial serif
 * can request true italics — beyond what a literal scan of the markup finds.
 * But the scan still runs first and acts as the floor: the model's css2 URL
 * MUST request every family and every weight/italic the build actually uses
 * (issue #49's guarantee), touch no URL outside fonts.googleapis.com/
 * fonts.gstatic.com, and lint clean. Any violation is logged and the file is
 * replaced by a deterministic fallback built from the scan — the build never
 * degrades below the scanned minimum.
 */
final class FontsPhpStep implements Step
{
    use LlmOptions;

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
        return 'fonts-php';
    }

    public function label(): string
    {
        return 'Write fonts.php';
    }

    public function declaration(): StepDeclaration
    {
        return new StepDeclaration(
            id: $this->id(),
            label: $this->label(),
            reads: ['theme/theme.json', 'designDirection.json', 'theme/parts/*', 'theme/templates/*', 'plugin/pages/*'],
            writes: ['theme/fonts.php'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $requirements = self::fontRequirements($theme, self::themeMarkup($project));
        // Families BundleFontsStep already shipped as theme assets need no
        // enqueue: their fontFace entries in theme.json are the loading
        // mechanism. Only the families that degraded to the link path remain.
        $bundled = 0;
        foreach (self::googleFamiliesBySlug($theme) as $slug => $name) {
            if (self::hasBundledFaces($theme, $slug)) {
                unset($requirements[$name]);
                ++$bundled;
            }
        }
        if ($requirements === []) {
            $fontsFile = $project->themePath('fonts.php');
            if (is_file($fontsFile) && !unlink($fontsFile)) {
                throw new \RuntimeException("Could not remove stale file: {$fontsFile}");
            }
            echo $bundled > 0
                ? "  all {$bundled} Google family/families bundled as theme assets; fonts.php not needed\n"
                : "  no Google-hosted families; fonts.php not needed\n";
            return;
        }

        $handle = ProjectStore::slugify($project->slug()) . '-fonts';
        $project->writeText('theme/fonts.php', rtrim(self::build($handle, $requirements)) . "\n");
        echo '  fonts-php: ' . count($requirements) . " family/families enqueued\n";
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
     * Google-hosted family requirements from the generated theme, keyed by
     * family name in theme.json order. Unlike fontVariants(), this keeps weights
     * and italics attached to the family that uses them so validation/fallback do
     * not accidentally let one family cover another family's variant.
     *
     * @param array<mixed> $theme decoded theme.json
     * @return array<string,array{weights:int[],italic:bool}>
     */
    public static function fontRequirements(array $theme, string $markup): array
    {
        $familiesBySlug = self::googleFamiliesBySlug($theme);
        if ($familiesBySlug === []) {
            return [];
        }

        $requirements = [];
        foreach ($familiesBySlug as $family) {
            self::addFamilyWeight($requirements, $family, 400);
        }
        if (isset($familiesBySlug['body'])) {
            // Body copy commonly contains <strong>; load the bold body face.
            self::addFamilyWeight($requirements, $familiesBySlug['body'], 700);
        }

        $styles = $theme['styles'] ?? [];
        if (is_array($styles)) {
            self::collectThemeRequirements($styles, 'body', $familiesBySlug, $requirements);
        }
        self::collectMarkupRequirements($markup, $familiesBySlug, $requirements);

        return self::normalizeRequirements($requirements);
    }

    /**
     * The Google Fonts CSS2 URL requesting each family at exactly the given
     * weights — `ital,wght` tuples for families whose scanned use includes
     * italics, plain `wght` otherwise. Used by the deterministic fallback.
     * Pure — unit-testable.
     *
     * @param array<string,array{weights:int[],italic:bool}> $requirements
     */
    public static function googleFontsUrl(array $requirements): string
    {
        $params = [];
        foreach (self::normalizeRequirements($requirements) as $name => $required) {
            // css2 requires axis tuples in ascending order: all 0,(upright)
            // before 1,(italic).
            $axis = $required['italic']
                ? 'ital,wght@' . implode(';', array_merge(
                    array_map(static fn (int $w): string => "0,{$w}", $required['weights']),
                    array_map(static fn (int $w): string => "1,{$w}", $required['weights']),
                ))
                : 'wght@' . implode(';', $required['weights']);

            // rawurlencode turns spaces into %20; Google Fonts wants '+'.
            $params[] = 'family=' . str_replace('%20', '+', rawurlencode($name)) . ':' . $axis;
        }
        return 'https://fonts.googleapis.com/css2?' . implode('&', $params) . '&display=swap';
    }

    /**
     * The theme's fonts.php, built straight from the scanned requirements.
     *
     * This is the only writer of the file, and every value it interpolates is
     * program-controlled: the handle is slugified to [a-z0-9-], and the URL is
     * assembled by googleFontsUrl() from rawurlencode()d family names and
     * integer weights. Model-authored strings — family names above all — are
     * never emitted into this template. They used to be, in the docblock
     * below, where a name carrying a comment terminator closed the comment
     * early and made the rest of itself executable PHP (BIGR-750). The family
     * list is legible in the URL a line further down; the comment does not
     * need to repeat it.
     *
     * Pure — unit-testable.
     *
     * @param array<string,array{weights:int[],italic:bool}> $requirements
     */
    public static function build(string $handle, array $requirements): string
    {
        $requirements = self::normalizeRequirements($requirements);
        $url = self::googleFontsUrl($requirements);

        return <<<PHP
            <?php
            /**
             * Webfonts from Google Fonts at the weights the design uses, on
             * enqueue_block_assets so they render in the block editor as well
             * as the front end.
             */
            add_action('enqueue_block_assets', function () {
                wp_enqueue_style('preconnect-gfonts', 'https://fonts.gstatic.com', array(), null);
                wp_enqueue_style(
                    '{$handle}',
                    '{$url}',
                    array(),
                    null
                );
            });
            PHP;
    }

    /**
     * Unique Google-hostable family names from theme.json, first-seen order.
     *
     * @param array<mixed> $theme
     * @return string[]
     */
    public static function googleFamilies(array $theme): array
    {
        return array_values(self::googleFamiliesBySlug($theme));
    }

    /** True when BundleFontsStep already shipped fontFace entries for this slug. */
    private static function hasBundledFaces(array $theme, string $slug): bool
    {
        $i = self::familyIndexBySlug($theme, $slug);
        return $i !== null
            && ($theme['settings']['typography']['fontFamilies'][$i]['fontFace'] ?? []) !== [];
    }

    /**
     * Index of the fontFamilies entry carrying this slug, or null.
     *
     * @param array<mixed> $theme
     */
    public static function familyIndexBySlug(array $theme, string $slug): ?int
    {
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $i => $family) {
            if (is_array($family) && (string) ($family['slug'] ?? '') === $slug) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Google-hostable families keyed by their theme.json slug, first-seen order.
     *
     * @param array<mixed> $theme
     * @return array<string,string> slug => family name
     */
    public static function googleFamiliesBySlug(array $theme): array
    {
        $families = [];
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $family) {
            if (!is_array($family)) {
                continue;
            }
            $slug = (string) ($family['slug'] ?? '');
            $primary = self::primaryFamily((string) ($family['fontFamily'] ?? ''));
            if (
                $slug !== ''
                && $primary !== null
                && !in_array(strtolower($primary), self::GENERIC, true)
                && !in_array($primary, $families, true)
            ) {
                $families[$slug] = $primary;
            }
        }
        return $families;
    }

    /**
     * @param array<mixed> $node
     * @param array<string,string> $familiesBySlug
     * @param array<string,array{weights:array<int,bool>,italic:bool}> $requirements
     */
    private static function collectThemeRequirements(
        array $node,
        ?string $currentSlug,
        array $familiesBySlug,
        array &$requirements,
    ): void {
        $familySlug = $currentSlug;
        $typography = $node['typography'] ?? null;
        if (is_array($typography) && isset($typography['fontFamily'])) {
            $familySlug = self::resolveFamilySlug((string) $typography['fontFamily'], $familiesBySlug, $familySlug);
        }

        if ($familySlug !== null && isset($familiesBySlug[$familySlug]) && is_array($typography)) {
            self::addTypographyUse($requirements, $familiesBySlug[$familySlug], $typography);
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $nextSlug = $familySlug;
                if (is_string($key) && in_array($key, ['heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                    $nextSlug = 'heading';
                }
                self::collectThemeRequirements($value, $nextSlug, $familiesBySlug, $requirements);
            }
        }
    }

    /**
     * @param array<string,string> $familiesBySlug
     * @param array<string,array{weights:array<int,bool>,italic:bool}> $requirements
     */
    private static function collectMarkupRequirements(string $markup, array $familiesBySlug, array &$requirements): void
    {
        if (preg_match_all('/<!--\s*wp:([^\s{\/]+)[\s\S]*?-->/', $markup, $comments, PREG_SET_ORDER) > 0) {
            foreach ($comments as $comment) {
                $attrs = self::blockAttrs($comment[0]);
                if ($attrs === null) {
                    continue;
                }
                $blockName = $comment[1];
                $familySlug = is_string($attrs['fontFamily'] ?? null)
                    ? self::resolveFamilySlug($attrs['fontFamily'], $familiesBySlug, null)
                    : self::defaultFamilySlugForBlock($blockName);
                if ($familySlug === null || !isset($familiesBySlug[$familySlug])) {
                    continue;
                }

                $typography = [];
                if (is_array($attrs['style']['typography'] ?? null)) {
                    $typography = $attrs['style']['typography'];
                }
                foreach (['fontWeight', 'fontStyle'] as $key) {
                    if (array_key_exists($key, $attrs)) {
                        $typography[$key] = $attrs[$key];
                    }
                }
                self::addTypographyUse($requirements, $familiesBySlug[$familySlug], $typography);
            }
        }

        if (preg_match_all('/<([a-z0-9-]+)\b([^>]*)>/i', $markup, $tags, PREG_SET_ORDER) > 0) {
            foreach ($tags as $tag) {
                $attrs = $tag[2];
                $familySlug = null;
                if (preg_match('/\bclass=(["\'])(.*?)\1/is', $attrs, $classMatch) === 1) {
                    foreach (array_keys($familiesBySlug) as $slug) {
                        if (preg_match('/(?<![\w-])has-' . preg_quote($slug, '/') . '-font-family(?![\w-])/', $classMatch[2]) === 1) {
                            $familySlug = $slug;
                            break;
                        }
                    }
                }
                $familySlug ??= self::defaultFamilySlugForTag($tag[1]);
                if ($familySlug === null || !isset($familiesBySlug[$familySlug])) {
                    continue;
                }
                if (preg_match('/\bstyle=(["\'])(.*?)\1/is', $attrs, $styleMatch) !== 1) {
                    continue;
                }
                $typography = [];
                if (preg_match('/font-weight:\s*([1-9]00)\b/i', $styleMatch[2], $m) === 1) {
                    $typography['fontWeight'] = $m[1];
                }
                if (preg_match('/font-style:\s*italic\b/i', $styleMatch[2]) === 1) {
                    $typography['fontStyle'] = 'italic';
                }
                self::addTypographyUse($requirements, $familiesBySlug[$familySlug], $typography);
            }
        }
    }

    /** @return array<mixed>|null */
    private static function blockAttrs(string $comment): ?array
    {
        $start = strpos($comment, '{');
        $end = strrpos($comment, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $attrs = json_decode(substr($comment, $start, $end - $start + 1), true);
        return is_array($attrs) ? $attrs : null;
    }

    /** @param array<string,string> $familiesBySlug */
    private static function resolveFamilySlug(string $value, array $familiesBySlug, ?string $fallback): ?string
    {
        $value = trim($value);
        if (isset($familiesBySlug[$value])) {
            return $value;
        }
        if (preg_match('/var:preset\|font-family\|([a-z0-9-]+)|--wp--preset--font-family--([a-z0-9-]+)/i', $value, $m) === 1) {
            $slug = $m[1] !== '' ? $m[1] : $m[2];
            return isset($familiesBySlug[$slug]) ? $slug : $fallback;
        }
        $primary = self::primaryFamily($value);
        if ($primary !== null) {
            foreach ($familiesBySlug as $slug => $family) {
                if (strcasecmp($primary, $family) === 0) {
                    return $slug;
                }
            }
        }
        return $fallback !== null && isset($familiesBySlug[$fallback]) ? $fallback : null;
    }

    private static function defaultFamilySlugForBlock(string $blockName): ?string
    {
        return in_array($blockName, ['heading', 'site-title'], true) ? 'heading' : 'body';
    }

    private static function defaultFamilySlugForTag(string $tag): ?string
    {
        return preg_match('/^h[1-6]$/i', $tag) === 1 ? 'heading' : 'body';
    }

    /**
     * @param array<string,array{weights:array<int,bool>,italic:bool}> $requirements
     * @param array<mixed> $typography
     */
    private static function addTypographyUse(array &$requirements, string $family, array $typography): void
    {
        if (isset($typography['fontWeight']) && preg_match('/^[1-9]00$/', (string) $typography['fontWeight']) === 1) {
            self::addFamilyWeight($requirements, $family, (int) $typography['fontWeight']);
        }
        if (isset($typography['fontStyle']) && is_string($typography['fontStyle']) && strtolower($typography['fontStyle']) === 'italic') {
            self::markFamilyItalic($requirements, $family);
        }
    }

    /** @param array<string,array{weights:array<int,bool>,italic:bool}> $requirements */
    private static function addFamilyWeight(array &$requirements, string $family, int $weight): void
    {
        $requirements[$family] ??= ['weights' => [], 'italic' => false];
        $requirements[$family]['weights'][$weight] = true;
    }

    /** @param array<string,array{weights:array<int,bool>,italic:bool}> $requirements */
    private static function markFamilyItalic(array &$requirements, string $family): void
    {
        $requirements[$family] ??= ['weights' => [400 => true], 'italic' => false];
        $requirements[$family]['italic'] = true;
    }

    /**
     * @param array<string,array{weights:int[]|array<int,bool>,italic:bool}> $requirements
     * @return array<string,array{weights:int[],italic:bool}>
     */
    private static function normalizeRequirements(array $requirements): array
    {
        $normalized = [];
        foreach ($requirements as $family => $required) {
            $weights = $required['weights'] ?? [400];
            $weights = array_map('intval', array_is_list($weights) ? $weights : array_keys($weights));
            $weights = array_values(array_unique(array_filter(
                $weights,
                static fn (int $weight): bool => $weight >= 100 && $weight <= 900 && $weight % 100 === 0
            )));
            if ($weights === []) {
                $weights = [400];
            }
            sort($weights);
            $normalized[$family] = [
                'weights' => $weights,
                'italic' => (bool) ($required['italic'] ?? false),
            ];
        }
        return $normalized;
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
     * All generated markup concatenated for the usage scan: theme parts +
     * templates + the content plugin's pages (a font style used only in
     * seeded content must still be loaded by the theme).
     */
    public static function themeMarkup(Project $project): string
    {
        $markup = '';
        foreach ($project->markupFiles() as $file) {
            $markup .= "\n" . (string) file_get_contents($file);
        }
        return $markup;
    }

    /** Extract the first font name from a CSS font-family stack, unquoted. */
    private static function primaryFamily(string $stack): ?string
    {
        return \Automattic\SiteBuild\FontCatalog::primaryFamily($stack);
    }



}
