<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\Step;
use Automattic\SiteBuild\StepDeclaration;

/**
 * Step (deterministic): write theme/fonts.php — the module that loads the
 * theme's Google Fonts (telex-style file split: the deterministic
 * functions.php only wires style.css and require_once's this file).
 *
 * Input:  designDirection.json + theme/theme.json + the final generated markup
 *         (theme parts/templates and companion-plugin pages — i.e. AFTER
 *         fix-blocks and assembly).
 * Output: theme/fonts.php, hooked on enqueue_block_assets so the fonts render
 *         in the block editor as well as the front end. Skipped entirely when
 *         theme.json names only system/web-safe families.
 *
 * The step scans the delivered theme and markup, then builds the file from a
 * fixed template. Model-authored family names are URL-encoded and never reach
 * executable PHP source.
 */
final class FontsPhpStep implements Step
{
    /** Families that are system/web-safe or CSS generics — never enqueue these. */
    private const GENERIC = [
        'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui',
        'ui-serif', 'ui-sans-serif', 'ui-monospace', '-apple-system', 'blinkmacsystemfont',
        'helvetica', 'helvetica neue', 'arial', 'georgia', 'times', 'times new roman',
        'courier', 'courier new', 'verdana', 'tahoma', 'trebuchet ms', 'palatino',
        'garamond', 'inherit', 'initial',
    ];

    /** HTML elements that never establish an inherited-family stack frame. */
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
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
            reads: [
                'designDirection.json',
                'theme/theme.json',
                'theme/parts/*',
                'theme/templates/*',
                'plugin/pages/*',
            ],
            writes: ['theme/fonts.php', 'warnings.json'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $direction = $project->readJson('designDirection.json');
        $warnings = [];
        $requirements = self::fontRequirements(
            $theme,
            self::themeMarkup($project),
            $direction,
            $warnings,
        );
        $project->addWarnings($this->id(), $warnings);
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
     * Google-hosted family requirements from the generated theme, keyed by
     * family name in theme.json order. Weights and italics stay attached to the
     * family that uses them so validation/fallback do not accidentally let one
     * family cover another family's variant.
     *
     * @param array<mixed> $theme decoded theme.json
     * @param array<mixed> $direction normalized designDirection.json
     * @param list<string> $warnings
     * @return array<string,array{weights:int[],italic:bool,axes?:array<string,array{min:float,max:float}>}>
     */
    public static function fontRequirements(
        array $theme,
        string $markup,
        array $direction = [],
        array &$warnings = [],
    ): array
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

        self::collectDirectionRequirements($direction, $familiesBySlug, $requirements, $warnings);
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
     * @param array<string,array{weights:int[],italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
     */
    public static function googleFontsUrl(array $requirements): string
    {
        $params = [];
        foreach (self::normalizeRequirements($requirements) as $name => $required) {
            // rawurlencode turns spaces into %20; Google Fonts wants '+'.
            $params[] = 'family=' . str_replace('%20', '+', rawurlencode((string) $name))
                . ':' . self::css2Variant($required);
        }
        return 'https://fonts.googleapis.com/css2?' . implode('&', $params) . '&display=swap';
    }

    /**
     * @param array{weights:list<int>,italic:bool,axes?:array<string,array{min:float,max:float}>} $required
     */
    private static function css2Variant(array $required): string
    {
        $axes = is_array($required['axes'] ?? null) ? $required['axes'] : [];
        $tags = ['wght'];
        if (isset($axes['opsz'])) {
            $tags[] = 'opsz';
        }
        if ($required['italic']) {
            $tags[] = 'ital';
        }
        sort($tags, SORT_STRING);

        $tuples = [];
        $italics = $required['italic'] ? [0, 1] : [null];
        foreach ($italics as $italic) {
            foreach ($required['weights'] as $weight) {
                $values = [];
                foreach ($tags as $tag) {
                    $values[] = match ($tag) {
                        'ital' => (string) $italic,
                        'opsz' => self::formatAxisRange($axes['opsz']),
                        'wght' => (string) $weight,
                    };
                }
                $tuples[] = implode(',', $values);
            }
        }

        return implode(',', $tags) . '@' . implode(';', $tuples);
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
     * @param array<string,array{weights:int[],italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
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
     * The committed direction is a floor, not a hint: final observed usage may
     * add variants, but it cannot remove a family variant the design director
     * explicitly selected.
     *
     * @param array<mixed> $direction
     * @param array<string,string> $familiesBySlug
     * @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
     * @param list<string> $warnings
     */
    private static function collectDirectionRequirements(
        array $direction,
        array $familiesBySlug,
        array &$requirements,
        array &$warnings,
    ): void {
        $type = is_array($direction['type'] ?? null) ? $direction['type'] : [];
        foreach (['heading', 'body'] as $slot) {
            $plan = is_array($type[$slot] ?? null) ? $type[$slot] : [];
            $authoredFamily = is_string($plan['family'] ?? null) ? trim($plan['family']) : '';
            if ($authoredFamily === '') {
                continue;
            }

            $deliveredFamily = isset($familiesBySlug[$slot])
                ? (string) $familiesBySlug[$slot]
                : '';
            if ($deliveredFamily === '') {
                $warnings[] = 'designDirection.json: type.' . $slot . '.family authored value '
                    . self::warningValue($authoredFamily)
                    . '; delivered removed; disposition theme.json has no Google-hosted family for this slot';
                continue;
            }
            if (strcasecmp($authoredFamily, $deliveredFamily) !== 0) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.family authored value '
                    . self::warningValue($authoredFamily) . '; delivered '
                    . self::warningValue($deliveredFamily)
                    . '; disposition theme font family differed from the committed direction';
            }

            $rawWeights = $plan['weights'] ?? [];
            $weights = [];
            $invalidWeights = false;
            if (is_array($rawWeights) && array_is_list($rawWeights)) {
                foreach ($rawWeights as $weight) {
                    if (
                        (is_int($weight) || (is_string($weight) && ctype_digit($weight)))
                        && (int) $weight >= 100
                        && (int) $weight <= 900
                        && (int) $weight % 100 === 0
                    ) {
                        $weights[] = (int) $weight;
                    } else {
                        $invalidWeights = true;
                    }
                }
            } elseif ($rawWeights !== []) {
                $invalidWeights = true;
            }
            $weights = array_values(array_unique($weights));
            sort($weights);
            foreach ($weights as $weight) {
                self::addFamilyWeight($requirements, $deliveredFamily, $weight);
            }
            if ($invalidWeights) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.weights authored value '
                    . self::warningValue($rawWeights) . '; delivered ' . self::warningValue($weights)
                    . '; disposition invalid weights removed';
            }

            if (($plan['italic'] ?? false) === true) {
                self::markFamilyItalic($requirements, $deliveredFamily);
            } elseif (array_key_exists('italic', $plan) && !is_bool($plan['italic'])) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.italic authored value '
                    . self::warningValue($plan['italic'])
                    . '; delivered false; disposition non-boolean value removed';
            }

            $axes = $plan['axes'] ?? [];
            if (!is_array($axes)) {
                $warnings[] = 'designDirection.json: type.' . $slot . '.axes authored value '
                    . self::warningValue($axes)
                    . '; delivered removed; disposition non-object axes removed';
                continue;
            }
            foreach ($axes as $tag => $range) {
                $path = 'designDirection.json: type.' . $slot . '.axes.' . (string) $tag;
                if ($tag !== 'opsz') {
                    $warnings[] = $path . ' authored value ' . self::warningValue($range)
                        . '; delivered removed; disposition axis is not supported by the deterministic CSS2 contract';
                    continue;
                }
                $normalizedRange = self::normalizeAxisRange($range);
                if ($normalizedRange === null) {
                    $warnings[] = $path . ' authored value ' . self::warningValue($range)
                        . '; delivered removed; disposition invalid optical-size range';
                    continue;
                }
                self::addFamilyAxis($requirements, $deliveredFamily, 'opsz', $normalizedRange);
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param array<string,string> $familiesBySlug
     * @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
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
     * @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
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

        /** @var list<array{tag:string,family:?string}> $stack */
        $stack = [];
        if (preg_match_all('/<\/?([a-z0-9-]+)\b([^>]*)>/i', $markup, $tags, PREG_SET_ORDER) > 0) {
            foreach ($tags as $tag) {
                $rawTag = $tag[0];
                $tagName = strtolower($tag[1]);
                if (str_starts_with($rawTag, '</')) {
                    while ($stack !== []) {
                        $closed = array_pop($stack);
                        if ($closed['tag'] === $tagName) {
                            break;
                        }
                    }
                    continue;
                }

                $attrs = $tag[2];
                $parentSlug = $stack === [] ? null : $stack[array_key_last($stack)]['family'];
                $familySlug = self::familySlugFromHtmlAttrs($attrs, $familiesBySlug);
                if ($familySlug === null && preg_match('/^h[1-6]$/', $tagName) === 1) {
                    $familySlug = isset($familiesBySlug['heading']) ? 'heading' : null;
                }
                $familySlug ??= $parentSlug;
                $familySlug ??= isset($familiesBySlug['body']) ? 'body' : null;

                $typography = [];
                if (preg_match('/\bstyle=(["\'])(.*?)\1/is', $attrs, $styleMatch) === 1) {
                    if (preg_match('/font-weight:\s*([1-9]00)\b/i', $styleMatch[2], $m) === 1) {
                        $typography['fontWeight'] = $m[1];
                    }
                    if (preg_match('/font-style:\s*italic\b/i', $styleMatch[2]) === 1) {
                        $typography['fontStyle'] = 'italic';
                    }
                }
                if (in_array($tagName, ['strong', 'b'], true)) {
                    $typography['fontWeight'] = 700;
                }
                if (in_array($tagName, ['em', 'i'], true)) {
                    $typography['fontStyle'] = 'italic';
                }
                if ($familySlug !== null && isset($familiesBySlug[$familySlug])) {
                    self::addTypographyUse($requirements, $familiesBySlug[$familySlug], $typography);
                }

                if (
                    !str_ends_with(rtrim($rawTag), '/>')
                    && !in_array($tagName, self::VOID_TAGS, true)
                ) {
                    $stack[] = ['tag' => $tagName, 'family' => $familySlug];
                }
            }
        }
    }

    /** @param array<string,string> $familiesBySlug */
    private static function familySlugFromHtmlAttrs(string $attrs, array $familiesBySlug): ?string
    {
        if (preg_match('/\bclass=(["\'])(.*?)\1/is', $attrs, $classMatch) !== 1) {
            return null;
        }
        foreach (array_keys($familiesBySlug) as $slugKey) {
            $slug = (string) $slugKey;
            if (
                preg_match(
                    '/(?<![\w-])has-' . preg_quote($slug, '/') . '-font-family(?![\w-])/',
                    $classMatch[2],
                ) === 1
            ) {
                return $slug;
            }
        }
        return null;
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
            foreach ($familiesBySlug as $slugKey => $family) {
                if (strcasecmp($primary, $family) === 0) {
                    return (string) $slugKey;
                }
            }
        }
        return $fallback !== null && isset($familiesBySlug[$fallback]) ? $fallback : null;
    }

    private static function defaultFamilySlugForBlock(string $blockName): ?string
    {
        return in_array($blockName, ['heading', 'site-title'], true) ? 'heading' : 'body';
    }

    /**
     * @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
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

    /** @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements */
    private static function addFamilyWeight(array &$requirements, string $family, int $weight): void
    {
        $requirements[$family] ??= ['weights' => [], 'italic' => false];
        $requirements[$family]['weights'][$weight] = true;
    }

    /** @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements */
    private static function markFamilyItalic(array &$requirements, string $family): void
    {
        $requirements[$family] ??= ['weights' => [400 => true], 'italic' => false];
        $requirements[$family]['italic'] = true;
    }

    /**
     * @param array<string,array{weights:array<int,bool>,italic:bool,axes?:array<string,array{min:float,max:float}>}> $requirements
     * @param array{min:float,max:float} $range
     */
    private static function addFamilyAxis(
        array &$requirements,
        string $family,
        string $tag,
        array $range,
    ): void {
        $requirements[$family] ??= ['weights' => [400 => true], 'italic' => false];
        $current = $requirements[$family]['axes'][$tag] ?? null;
        $requirements[$family]['axes'][$tag] = is_array($current)
            ? [
                'min' => min((float) $current['min'], $range['min']),
                'max' => max((float) $current['max'], $range['max']),
            ]
            : $range;
    }

    /**
     * @param array<string,array{weights:int[]|array<int,bool>,italic:bool,axes?:array<mixed>}> $requirements
     * @return array<string,array{weights:int[],italic:bool,axes?:array<string,array{min:float,max:float}>}>
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
            $entry = [
                'weights' => $weights,
                'italic' => (bool) ($required['italic'] ?? false),
            ];
            $axes = [];
            foreach (is_array($required['axes'] ?? null) ? $required['axes'] : [] as $tag => $range) {
                if ($tag !== 'opsz') {
                    continue;
                }
                $normalizedRange = self::normalizeAxisRange($range);
                if ($normalizedRange !== null) {
                    $axes['opsz'] = $normalizedRange;
                }
            }
            if ($axes !== []) {
                $entry['axes'] = $axes;
            }
            $normalized[$family] = $entry;
        }
        return $normalized;
    }

    /** @return ?array{min:float,max:float} */
    private static function normalizeAxisRange(mixed $range): ?array
    {
        if (!is_array($range)) {
            return null;
        }
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;
        if (
            (!is_int($min) && !is_float($min))
            || (!is_int($max) && !is_float($max))
            || !is_finite((float) $min)
            || !is_finite((float) $max)
            || (float) $min <= 0
            || (float) $max < (float) $min
            || (float) $max > 1000
        ) {
            return null;
        }
        return ['min' => (float) $min, 'max' => (float) $max];
    }

    /** @param array{min:float,max:float} $range */
    private static function formatAxisRange(array $range): string
    {
        $min = self::formatAxisNumber($range['min']);
        $max = self::formatAxisNumber($range['max']);
        return $min === $max ? $min : "{$min}..{$max}";
    }

    private static function formatAxisNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function warningValue(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : get_debug_type($value);
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
