<?php
declare(strict_types=1);

/**
 * Step (LLM): generate the block theme's theme.json.
 *
 * Input:  meta.json (user prompt) + siteSpec.json (factual info). The model
 *         makes the design decisions (palette, typography, spacing) inline —
 *         there is no separate design document.
 * Output: theme/theme.json — palette, typography, spacing, layout, element styles.
 *
 * Validates the structure the templates depend on (version 3, the five required
 * color slugs, the two required font slugs) and fails loud if the model drifts
 * from it. The required slugs are a MINIMUM contract, not an exact one: the
 * model may add expressive extras (a surface/muted color, a third display/mono
 * font) for variety. The required brand colors also have to be meaningfully
 * chromatic and distinct, so the model cannot satisfy the contract with paper,
 * ink, gray, and one small red accent.
 */
final class ThemeJsonStep implements ConcurrentStep
{
    use ModelOption;

    // The guaranteed-present slugs downstream parts/header/footer reference. The
    // model may add more (extra palette tints, a third font); these are just the
    // floor every theme must clear.
    private const REQUIRED_COLORS = ['base', 'contrast', 'primary', 'secondary', 'accent'];
    private const BRAND_COLORS = ['primary', 'secondary', 'accent'];
    private const REQUIRED_FONTS = ['heading', 'body'];
    private const MIN_CHROMATIC_BRAND_COLORS = 2;
    private const MIN_BRAND_SATURATION = 0.18;
    private const MIN_BRAND_HUE_SEPARATION = 24.0;
    private const REQ = 'theme-json';

    public function __construct(
        private Llm $llm,
        private PromptRenderer $renderer,
        private ?string $model = null,
        private ?float $temperature = null,
    ) {}

    public function id(): string
    {
        return 'theme-json';
    }

    public function label(): string
    {
        return 'Generate theme.json';
    }

    public function requests(Project $project): array
    {
        $meta = $project->readJson('meta.json');
        $rendered = $this->renderer->render('theme-json.md', [
            'user_prompt'      => (string) ($meta['prompt'] ?? ''),
            'site_spec'        => $project->readText('siteSpec.json'),
            'design_direction' => DesignDirectionStep::readFor($project),
        ]);

        return [self::REQ => $this->withModel(['prompt' => $rendered])];
    }

    public function consume(Project $project, array $results): void
    {
        $theme = $results[self::REQ] ?? null;
        if (!is_array($theme)) {
            throw new RuntimeException('theme-json: missing model output');
        }

        // Force the schema fields and validate the contract templates rely on.
        $theme['$schema'] = 'https://schemas.wp.org/trunk/theme.json';
        $theme['version'] = 3;

        self::assertColors($theme, self::allowsNeutralBrandPalette($project));
        self::assertFonts($theme);

        $project->writeJson('theme/theme.json', $theme);
    }

    public function run(Project $project): void
    {
        $this->consume($project, $this->llm->completeJsonBatch($this->requests($project)));
    }

    /** @param array<mixed> $theme */
    private static function assertColors(array $theme, bool $allowNeutralBrandPalette = false): void
    {
        $palette = $theme['settings']['color']['palette'] ?? null;
        if (!is_array($palette)) {
            throw new RuntimeException('theme.json missing settings.color.palette');
        }
        $bySlug = [];
        foreach ($palette as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = (string) ($entry['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $hex = (string) ($entry['color'] ?? '');
            if (self::hexToRgb($hex) === null) {
                throw new RuntimeException("theme.json palette slug '{$slug}' has invalid hex color: {$hex}");
            }
            $bySlug[$slug] = $hex;
        }
        foreach (self::REQUIRED_COLORS as $needed) {
            if (!array_key_exists($needed, $bySlug)) {
                throw new RuntimeException("theme.json palette missing slug: {$needed}");
            }
        }
        if (!$allowNeutralBrandPalette) {
            self::assertBrandColorDiversity($bySlug);
        }
    }

    /** @param array<mixed> $theme */
    private static function assertFonts(array $theme): void
    {
        $families = $theme['settings']['typography']['fontFamilies'] ?? null;
        if (!is_array($families)) {
            throw new RuntimeException('theme.json missing settings.typography.fontFamilies');
        }
        $slugs = array_column($families, 'slug');
        foreach (self::REQUIRED_FONTS as $needed) {
            if (!in_array($needed, $slugs, true)) {
                throw new RuntimeException("theme.json fontFamilies missing slug: {$needed}");
            }
        }
    }

    private static function allowsNeutralBrandPalette(Project $project): bool
    {
        $meta = $project->readJson('meta.json');
        $prompt = strtolower((string) ($meta['prompt'] ?? ''));
        return preg_match('/\b(monochrome|achromatic|gr[ae]yscale|black[- ]and[- ]white|duotone)\b/', $prompt) === 1;
    }

    /** @param array<string,string> $colors */
    private static function assertBrandColorDiversity(array $colors): void
    {
        $chromatic = [];
        foreach (self::BRAND_COLORS as $slug) {
            $rgb = self::hexToRgb($colors[$slug]);
            if ($rgb === null) {
                continue;
            }
            $hsl = self::rgbToHsl($rgb);
            if ($hsl['s'] >= self::MIN_BRAND_SATURATION) {
                $chromatic[] = ['slug' => $slug, 'h' => $hsl['h'], 's' => $hsl['s']];
            }
        }

        if (count($chromatic) < self::MIN_CHROMATIC_BRAND_COLORS) {
            throw new RuntimeException(
                'theme.json palette must include at least two chromatic brand colors among primary, secondary, and accent'
            );
        }

        $maxHueDistance = 0.0;
        foreach ($chromatic as $a) {
            foreach ($chromatic as $b) {
                if ($a['slug'] === $b['slug']) {
                    continue;
                }
                $maxHueDistance = max($maxHueDistance, self::hueDistance($a['h'], $b['h']));
            }
        }
        if ($maxHueDistance < self::MIN_BRAND_HUE_SEPARATION) {
            throw new RuntimeException(
                'theme.json palette brand colors are too close in hue; primary, secondary, and accent need clearer color separation'
            );
        }
    }

    /**
     * @return null|array{0:int,1:int,2:int}
     */
    private static function hexToRgb(string $hex): ?array
    {
        $hex = trim($hex);
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $hex, $m) === 1) {
            return [
                hexdec($m[1][0] . $m[1][0]),
                hexdec($m[1][1] . $m[1][1]),
                hexdec($m[1][2] . $m[1][2]),
            ];
        }
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m) !== 1) {
            return null;
        }
        return [
            hexdec(substr($m[1], 0, 2)),
            hexdec(substr($m[1], 2, 2)),
            hexdec(substr($m[1], 4, 2)),
        ];
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{h:float,s:float,l:float}
     */
    private static function rgbToHsl(array $rgb): array
    {
        $r = $rgb[0] / 255;
        $g = $rgb[1] / 255;
        $b = $rgb[2] / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        $lightness = ($max + $min) / 2;

        if ($delta === 0.0) {
            return ['h' => 0.0, 's' => 0.0, 'l' => $lightness];
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));
        if ($max === $r) {
            $hue = 60 * fmod((($g - $b) / $delta), 6);
        } elseif ($max === $g) {
            $hue = 60 * ((($b - $r) / $delta) + 2);
        } else {
            $hue = 60 * ((($r - $g) / $delta) + 4);
        }
        if ($hue < 0) {
            $hue += 360;
        }

        return ['h' => $hue, 's' => $saturation, 'l' => $lightness];
    }

    private static function hueDistance(float $a, float $b): float
    {
        $diff = abs($a - $b);
        return min($diff, 360 - $diff);
    }
}
