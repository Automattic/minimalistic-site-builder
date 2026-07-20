<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\Steps;

use Automattic\SiteBuild\Llm;
use Automattic\SiteBuild\LlmOptions;
use Automattic\SiteBuild\Project;
use Automattic\SiteBuild\ProjectStore;
use Automattic\SiteBuild\PromptRenderer;
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

    private const LOG_FILE = 'fonts-php.log';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

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
            reads: ['theme/theme.json', 'designDirection.json', 'theme/parts/*', 'theme/templates/*'],
            writes: ['theme/fonts.php'],
            concurrent: false,
        );
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $requirements = self::fontRequirements($theme, self::themeMarkup($project));
        if ($requirements === []) {
            $fontsFile = $project->themePath('fonts.php');
            if (is_file($fontsFile) && !unlink($fontsFile)) {
                throw new \RuntimeException("Could not remove stale file: {$fontsFile}");
            }
            echo "  no Google-hosted families; fonts.php not needed\n";
            return;
        }

        $handle = ProjectStore::slugify($project->slug()) . '-fonts';

        $rendered = $this->renderer->render('fonts-php.md', [
            'design_direction' => DesignDirectionStep::readFor($project),
            'families'         => implode(', ', array_keys($requirements)),
            'usage'            => self::usageText($requirements),
            'handle'           => $handle,
        ]);
        $php = self::stripFences(trim(
            $this->llm->complete($rendered, $this->withOptions(['log_label' => $this->id()]))
        ));

        $problems = self::validate($php, $requirements);
        if ($problems !== []) {
            file_put_contents(
                $project->logPath(self::LOG_FILE),
                "REJECTED fonts.php:\n{$php}\n\nPROBLEMS:\n- " . implode("\n- ", $problems) . "\n"
            );
            echo '  fonts-php: model output rejected (' . count($problems)
                . ' problem(s)); using the deterministic fallback — see logs/' . self::LOG_FILE . "\n";
            $php = self::fallback($handle, $requirements);
        }

        $project->writeText('theme/fonts.php', rtrim($php) . "\n");
    }

    /**
     * Validate the model's fonts.php against the constraints that make it safe
     * to execute and complete for the design: required hook and enqueues, only
     * Google Fonts URLs, every scanned family/weight/italic requested per
     * family, and clean `php -l`. Returns problems; empty = valid.
     *
     * @param array<string,array{weights:int[],italic:bool}> $requirements
     * @return string[]
     */
    public static function validate(string $php, array $requirements): array
    {
        $problems = [];
        if (!str_starts_with(trim($php), '<?php')) {
            $problems[] = 'must start with <?php';
        }
        if (!str_contains($php, 'wp_enqueue_style')) {
            $problems[] = 'missing wp_enqueue_style';
        }

        // The only hook allowed is enqueue_block_assets (front end + editor).
        if (preg_match_all('/add_action\s*\(\s*[\'"]([^\'"]+)[\'"]/', $php, $m) > 0) {
            foreach (array_unique($m[1]) as $hook) {
                if ($hook !== 'enqueue_block_assets') {
                    $problems[] = "disallowed hook: {$hook}";
                }
            }
        } else {
            $problems[] = 'missing add_action(\'enqueue_block_assets\', …)';
        }

        // Only Google Fonts hosts may appear.
        preg_match_all('~https?://[^\s\'"<>)]+~i', $php, $m);
        $coverage = [];
        $hasGoogleCss = false;
        foreach ($m[0] as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!in_array($host, ['fonts.googleapis.com', 'fonts.gstatic.com'], true)) {
                $problems[] = "URL outside Google Fonts: {$url}";
            }
            if ($host === 'fonts.googleapis.com') {
                $hasGoogleCss = true;
                self::mergeCoverage($coverage, self::coverageFromGoogleUrl($url));
            }
        }

        // The scan is the floor: every family must cover its own required
        // weights and italics. The model may request more than the scan.
        if (!$hasGoogleCss) {
            $problems[] = 'no fonts.googleapis.com URL';
        } else {
            foreach ($requirements as $name => $required) {
                $key = self::familyKey($name);
                if (!isset($coverage[$key])) {
                    $problems[] = "family not requested: {$name}";
                    continue;
                }
                foreach ($required['weights'] as $weight) {
                    if (!self::coverageHasWeight($coverage[$key]['upright'], $weight)) {
                        $problems[] = "family {$name} missing scanned weight: {$weight}";
                    }
                }
                if ($required['italic']) {
                    foreach ($required['weights'] as $weight) {
                        if (!self::coverageHasWeight($coverage[$key]['italic'], $weight)) {
                            $problems[] = "family {$name} missing scanned italic weight: {$weight}";
                        }
                    }
                }
            }
        }

        // Lint last: only worth the subprocess when the structure is right.
        if ($problems === []) {
            $problems = self::lint($php);
        }
        return $problems;
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
     * The deterministic fonts.php built straight from the scan — the guaranteed
     * floor the model output is measured against. Pure — unit-testable.
     *
     * @param array<string,array{weights:int[],italic:bool}> $requirements
     */
    public static function fallback(string $handle, array $requirements): string
    {
        $requirements = self::normalizeRequirements($requirements);
        $url = self::googleFontsUrl($requirements);
        $list = implode(', ', array_keys($requirements));

        return <<<PHP
            <?php
            /**
             * Webfonts ({$list}) from Google Fonts at the weights the design uses,
             * on enqueue_block_assets so they render in the block editor as well
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

    /**
     * Google-hostable families keyed by their theme.json slug, first-seen order.
     *
     * @param array<mixed> $theme
     * @return array<string,string> slug => family name
     */
    private static function googleFamiliesBySlug(array $theme): array
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
     * @return array<string,array{
     *   upright:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>},
     *   italic:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>}
     * }>
     */
    private static function coverageFromGoogleUrl(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }

        $coverage = [];
        foreach (explode('&', $query) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if (urldecode($key) !== 'family') {
                continue;
            }
            $spec = urldecode($value);
            [$family, $variant] = array_pad(explode(':', $spec, 2), 2, '');
            $family = trim($family);
            if ($family === '') {
                continue;
            }
            $key = self::familyKey($family);
            $coverage[$key] ??= self::emptyCoverage();
            self::mergeOneCoverage($coverage[$key], self::coverageFromVariant($variant));
        }
        return $coverage;
    }

    /** @return array{upright:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>},italic:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>}} */
    private static function coverageFromVariant(string $variant): array
    {
        $coverage = self::emptyCoverage();
        if ($variant === '') {
            self::addCoverageValue($coverage['upright'], '400');
            return $coverage;
        }

        [$axisText, $tupleText] = array_pad(explode('@', $variant, 2), 2, '');
        if ($tupleText === '') {
            return $coverage;
        }

        $axes = array_map('strtolower', array_map('trim', explode(',', $axisText)));
        $weightIndex = array_search('wght', $axes, true);
        $italicIndex = array_search('ital', $axes, true);

        foreach (explode(';', $tupleText) as $tuple) {
            $parts = array_map('trim', explode(',', $tuple));
            $weight = $weightIndex === false ? '400' : ($parts[$weightIndex] ?? '');
            if ($weight === '') {
                continue;
            }
            $slot = 'upright';
            if ($italicIndex !== false && ($parts[$italicIndex] ?? '') === '1') {
                $slot = 'italic';
            }
            self::addCoverageValue($coverage[$slot], $weight);
        }

        return $coverage;
    }

    /** @return array{upright:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>},italic:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>}} */
    private static function emptyCoverage(): array
    {
        return [
            'upright' => ['exact' => [], 'ranges' => []],
            'italic'  => ['exact' => [], 'ranges' => []],
        ];
    }

    /**
     * @param array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>} $slot
     */
    private static function addCoverageValue(array &$slot, string $weight): void
    {
        if (preg_match('/^([1-9]00)$/', $weight, $m) === 1) {
            $slot['exact'][(int) $m[1]] = true;
            return;
        }
        if (preg_match('/^([1-9]00)\.\.([1-9]00)$/', $weight, $m) === 1) {
            $slot['ranges'][] = [(int) $m[1], (int) $m[2]];
        }
    }

    /**
     * @param array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>} $slot
     */
    private static function coverageHasWeight(array $slot, int $weight): bool
    {
        if (isset($slot['exact'][$weight])) {
            return true;
        }
        foreach ($slot['ranges'] as [$min, $max]) {
            if ($weight >= min($min, $max) && $weight <= max($min, $max)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,array{upright:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>},italic:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>}}> $into */
    private static function mergeCoverage(array &$into, array $from): void
    {
        foreach ($from as $family => $coverage) {
            $into[$family] ??= self::emptyCoverage();
            self::mergeOneCoverage($into[$family], $coverage);
        }
    }

    /** @param array{upright:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>},italic:array{exact:array<int,bool>,ranges:array<int,array{0:int,1:int}>}} $into */
    private static function mergeOneCoverage(array &$into, array $from): void
    {
        foreach (['upright', 'italic'] as $slot) {
            foreach ($from[$slot]['exact'] as $weight => $_) {
                $into[$slot]['exact'][(int) $weight] = true;
            }
            foreach ($from[$slot]['ranges'] as $range) {
                $into[$slot]['ranges'][] = $range;
            }
        }
    }

    private static function familyKey(string $family): string
    {
        return strtolower(trim($family));
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
    private static function themeMarkup(Project $project): string
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
        $first = trim(explode(',', $stack)[0]);
        $first = trim($first, "\"'");
        return $first === '' ? null : $first;
    }

    /** @param array<string,array{weights:int[],italic:bool}> $requirements */
    private static function usageText(array $requirements): string
    {
        $lines = [];
        foreach (self::normalizeRequirements($requirements) as $family => $required) {
            $lines[] = $family . ': weights ' . implode(', ', $required['weights'])
                . '; italics ' . ($required['italic'] ? 'yes' : 'no');
        }
        return implode("\n", $lines);
    }

    /** @return string[] problems from `php -l`, empty when the file parses */
    private static function lint(string $php): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fontsphp-');
        if ($tmp === false) {
            return []; // can't lint here; the structural checks above still hold
        }
        file_put_contents($tmp, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        @unlink($tmp);
        return $rc === 0 ? [] : ['php -l failed: ' . implode(' ', $out)];
    }

    /** Strip a leading/trailing markdown code fence if the model added one. */
    private static function stripFences(string $text): string
    {
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n/', '', $text);
            $text = preg_replace('/\n```$/', '', (string) $text);
        }
        return trim((string) $text);
    }
}
