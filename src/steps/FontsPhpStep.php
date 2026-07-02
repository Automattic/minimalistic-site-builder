<?php
declare(strict_types=1);

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
 * (issue #49's guarantee), call nothing but wp_enqueue_style/add_action, touch
 * no URL outside fonts.googleapis.com/fonts.gstatic.com, and lint clean. Any
 * violation is logged and the file is replaced by a deterministic fallback
 * built from the scan — the build never degrades below the scanned minimum.
 */
final class FontsPhpStep implements Step
{
    use ModelOption;

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
    ) {}

    public function id(): string
    {
        return 'fonts-php';
    }

    public function label(): string
    {
        return 'Write fonts.php';
    }

    public function run(Project $project): void
    {
        $theme = $project->readJson('theme/theme.json');
        $names = self::googleFamilies($theme);
        if ($names === []) {
            echo "  no Google-hosted families; fonts.php not needed\n";
            return;
        }

        [$weights, $italic] = self::fontVariants($theme, self::themeMarkup($project));
        $handle = ProjectStore::slugify($project->slug()) . '-fonts';

        $rendered = $this->renderer->render('fonts-php.md', [
            'design_direction' => DesignDirectionStep::readFor($project),
            'families'         => implode(', ', $names),
            'usage'            => self::usageText($weights, $italic),
            'handle'           => $handle,
        ]);
        $php = self::stripFences(trim(
            $this->llm->complete($rendered, $this->withModel(['log_label' => $this->id()]))
        ));

        $problems = self::validate($php, $names, $weights, $italic);
        if ($problems !== []) {
            file_put_contents(
                $project->logPath(self::LOG_FILE),
                "REJECTED fonts.php:\n{$php}\n\nPROBLEMS:\n- " . implode("\n- ", $problems) . "\n"
            );
            echo '  fonts-php: model output rejected (' . count($problems)
                . ' problem(s)); using the deterministic fallback — see logs/' . self::LOG_FILE . "\n";
            $php = self::fallback($handle, $names, $weights, $italic);
        }

        $project->writeText('theme/fonts.php', rtrim($php) . "\n");
    }

    /**
     * Validate the model's fonts.php against the constraints that make it safe
     * to execute and complete for the design: required hook and enqueues, only
     * Google Fonts URLs, no side-effecting PHP, every scanned family/weight/
     * italic requested, and clean `php -l`. Returns problems; empty = valid.
     *
     * @param string[] $names
     * @param int[] $weights
     * @return string[]
     */
    public static function validate(string $php, array $names, array $weights, bool $italic): array
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

        // No side-effecting PHP: this file runs inside WordPress.
        if (preg_match('/\b(?:eval|exec|system|shell_exec|passthru|proc_open|popen|assert|create_function|call_user_func|call_user_func_array|file_get_contents|file_put_contents|fopen|unlink|base64_decode|hex2bin|gzinflate|wp_remote_\w+)\s*\(/i', $php) === 1) {
            $problems[] = 'forbidden function call';
        }
        if (preg_match('/\b(?:include|require)(?:_once)?\b/i', $php) === 1) {
            $problems[] = 'include/require is not allowed';
        }
        if (preg_match('/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV|SESSION)\b/', $php) === 1) {
            $problems[] = 'superglobal access is not allowed';
        }
        if (str_contains($php, '`')) {
            $problems[] = 'backticks are not allowed';
        }

        // Only Google Fonts hosts may appear.
        preg_match_all('~https?://[^\s\'"<>)]+~i', $php, $m);
        $googleUrls = '';
        foreach ($m[0] as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!in_array($host, ['fonts.googleapis.com', 'fonts.gstatic.com'], true)) {
                $problems[] = "URL outside Google Fonts: {$url}";
            }
            if ($host === 'fonts.googleapis.com') {
                $googleUrls .= ' ' . $url;
            }
        }

        // The scan is the floor: every family, weight, and italic the build
        // uses must be requested (the model may add more).
        if ($googleUrls === '') {
            $problems[] = 'no fonts.googleapis.com URL';
        } else {
            foreach ($names as $name) {
                if (!str_contains($googleUrls, 'family=' . str_replace('%20', '+', rawurlencode($name)))) {
                    $problems[] = "family not requested: {$name}";
                }
            }
            foreach ($weights as $weight) {
                if (preg_match('/\b' . $weight . '\b/', $googleUrls) !== 1) {
                    $problems[] = "scanned weight not requested: {$weight}";
                }
            }
            if ($italic && !str_contains($googleUrls, 'ital')) {
                $problems[] = 'italics are used but not requested';
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
     * The Google Fonts CSS2 URL requesting each family at exactly the given
     * weights — `ital,wght` tuples when italics are used, plain `wght` axis
     * otherwise. Used by the deterministic fallback. Pure — unit-testable.
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

    /**
     * The deterministic fonts.php built straight from the scan — the guaranteed
     * floor the model output is measured against. Pure — unit-testable.
     *
     * @param string[] $names
     * @param int[] $weights
     */
    public static function fallback(string $handle, array $names, array $weights, bool $italic): string
    {
        $url = self::googleFontsUrl($names, $weights, $italic);
        $list = implode(', ', $names);

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
        $names = [];
        foreach ($theme['settings']['typography']['fontFamilies'] ?? [] as $family) {
            $primary = self::primaryFamily((string) ($family['fontFamily'] ?? ''));
            if ($primary !== null && !in_array(strtolower($primary), self::GENERIC, true)) {
                $names[$primary] = true; // dedupe preserving first-seen
            }
        }
        return array_keys($names);
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
     * The scanned-usage block for the prompt.
     *
     * @param int[] $weights
     */
    private static function usageText(array $weights, bool $italic): string
    {
        return 'Weights: ' . implode(', ', $weights) . "\nItalics: " . ($italic ? 'yes' : 'no');
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
