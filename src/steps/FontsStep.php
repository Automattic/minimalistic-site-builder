<?php
declare(strict_types=1);

/**
 * Step (deterministic): make the theme's chosen fonts actually load.
 *
 * Input:  theme/theme.json (settings.typography.fontFamilies with heading/body).
 * Output: theme/functions.php that enqueues the heading + body families from
 *         Google Fonts via `enqueue_block_assets`, so the webfonts render on
 *         both the front end and in the block editor.
 *
 * theme.json only NAMES font families; nothing loads them. Rather than trust the
 * model to emit valid (hashed) `fontFace` src URLs — which it can't do reliably —
 * we read the family names it chose and build a stable Google Fonts CSS2 request
 * from them here. Families that aren't Google-hosted (web-safe / system stacks)
 * are skipped; if no families need loading, no functions.php is written.
 */
final class FontsStep implements Step
{
    /** Weights requested for every family (Google CSS2). 400 + 700 cover body + bold. */
    private const WEIGHTS = '400;700';

    /** Primary families we must NOT request from Google (web-safe / system stacks). */
    private const SKIP = [
        'serif', 'sans-serif', 'monospace', 'system-ui', 'ui-serif', 'ui-sans-serif',
        'ui-monospace', 'cursive', 'fantasy', 'arial', 'helvetica', 'helvetica neue',
        'times', 'times new roman', 'georgia', 'garamond', 'courier', 'courier new',
        'verdana', 'tahoma', 'trebuchet ms', 'inherit', 'initial',
    ];

    public function id(): string
    {
        return 'enqueue-fonts';
    }

    public function label(): string
    {
        return 'Enqueue fonts';
    }

    public function run(Project $project): void
    {
        if (!$project->exists('theme/theme.json')) {
            return;
        }
        $theme = json_decode($project->readText('theme/theme.json'), true);
        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            return;
        }

        // Unique Google families in heading-then-body order, de-duplicated.
        $names = [];
        foreach ($families as $family) {
            $name = self::primaryFamily((string) ($family['fontFamily'] ?? ''));
            if ($name === '' || in_array(strtolower($name), self::SKIP, true)) {
                continue;
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return;
        }

        $prefix = self::prefix($project);
        $project->writeText('theme/functions.php', self::functionsPhp($prefix, $names));
    }

    /** First real family in a CSS font stack, without quotes: `"Cormorant Garamond", serif` -> `Cormorant Garamond`. */
    private static function primaryFamily(string $stack): string
    {
        $first = explode(',', $stack)[0] ?? '';
        return trim($first, " \t\"'");
    }

    /** A function-name-safe prefix from the project slug. */
    private static function prefix(Project $project): string
    {
        $slug = '';
        if ($project->exists('siteSpec.json')) {
            $slug = (string) ($project->readJson('siteSpec.json')['slug'] ?? '');
        }
        $prefix = preg_replace('/[^a-z0-9_]+/', '_', strtolower($slug)) ?? '';
        $prefix = trim($prefix, '_');
        return $prefix === '' ? 'theme' : $prefix;
    }

    /**
     * @param string[] $names family names, already filtered to Google-hostable ones
     */
    private static function functionsPhp(string $prefix, array $names): string
    {
        // One combined CSS2 request: family=A:wght@400;700&family=B:wght@400;700.
        $params = [];
        foreach ($names as $name) {
            $params[] = 'family=' . str_replace('%20', '+', rawurlencode($name)) . ':wght@' . self::WEIGHTS;
        }
        $url = 'https://fonts.googleapis.com/css2?' . implode('&', $params) . '&display=swap';
        $fn = $prefix . '_enqueue_fonts';

        return <<<PHP
        <?php
        /**
         * Load the theme's Google Fonts on the front end and in the block editor.
         * Generated from the heading/body families in theme.json.
         */
        if ( ! function_exists( '{$fn}' ) ) {
        	function {$fn}() {
        		wp_enqueue_style(
        			'{$prefix}-fonts',
        			'{$url}',
        			array(),
        			null
        		);
        	}
        }
        add_action( 'enqueue_block_assets', '{$fn}' );

        PHP;
    }
}
