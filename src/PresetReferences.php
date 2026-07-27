<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Validates WordPress preset references in generated block-theme markup.
 *
 * Gutenberg uses two representations for the same reference:
 *
 * - `var:preset|spacing|xl` in block-comment attributes
 * - `var(--wp--preset--spacing--xl)` in rendered/inline CSS
 *
 * A missing separator still looks plausible to a model but names a CSS
 * custom property WordPress never creates. This validator checks both the
 * grammar and that every referenced slug is declared by theme.json.
 */
final class PresetReferences
{
    /** @var array<string,list<string>> preset type => path below theme.json */
    private const PRESET_PATHS = [
        'spacing'      => ['settings', 'spacing', 'spacingSizes'],
        'color'        => ['settings', 'color', 'palette'],
        'font-size'    => ['settings', 'typography', 'fontSizes'],
        'font-family'  => ['settings', 'typography', 'fontFamilies'],
        'shadow'       => ['settings', 'shadow', 'presets'],
        'gradient'     => ['settings', 'color', 'gradients'],
        'duotone'      => ['settings', 'color', 'duotone'],
        'aspect-ratio' => ['settings', 'dimensions', 'aspectRatios'],
    ];

    /** Core presets that exist even when a theme does not redeclare them. */
    private const CORE_PRESETS = [
        'shadow' => ['natural', 'deep', 'sharp', 'outlined', 'crisp'],
    ];

    /** Block attributes whose plain value is a preset slug. */
    private const DIRECT_BLOCK_FIELDS = [
        'backgroundColor' => 'color',
        'textColor'       => 'color',
        'borderColor'     => 'color',
        'gradient'        => 'gradient',
        'fontSize'        => 'font-size',
        'fontFamily'      => 'font-family',
        'overlayColor'    => 'color',
        'overlayBackgroundColor' => 'color',
        'overlayTextColor'       => 'color',
        'iconColor'              => 'color',
        'iconBackgroundColor'    => 'color',
    ];

    /** @return string[] human-readable problems; empty means all references are valid */
    public static function problems(Project $project): array
    {
        if (!$project->exists('theme/theme.json')) {
            return ['theme/theme.json: missing; cannot validate preset references'];
        }

        $theme = json_decode($project->readText('theme/theme.json'), true);
        if (!is_array($theme)) {
            return ['theme/theme.json: invalid JSON; cannot validate preset references'];
        }

        $declared = self::declaredSlugs($theme);
        $problems = [];
        foreach (self::markupFiles($project) as $file) {
            $relative = substr($file, strlen($project->themePath()) + 1);
            $markup = (string) file_get_contents($file);
            self::scanMarkup($markup, $relative, $declared, $problems);
        }

        // The page-styles step appends generated CSS after block repair, and
        // theme.json itself contains preset variables in global styles. They
        // are final-theme inputs just as much as block markup is.
        if ($project->exists('theme/style.css')) {
            self::scan($project->readText('theme/style.css'), 'style.css', $declared, $problems);
        }
        $themeStrings = [];
        self::collectStrings($theme, $themeStrings);
        foreach ($themeStrings as $value) {
            self::scan($value, 'theme.json', $declared, $problems);
        }

        // The same logical reference is commonly present in block attributes
        // and rendered HTML. Message-keyed collection keeps the report useful.
        return array_values($problems);
    }

    /**
     * Validate one in-memory markup document against an already-decoded
     * theme.json. This is the project-free intake counterpart to problems():
     * generated units can reject an undeclared preset before any part is
     * written, while the final project-wide gate keeps using the same scanner.
     *
     * @param array<mixed> $theme
     * @return string[] human-readable problems; empty means every reference is valid
     */
    public static function problemsForMarkup(string $markup, array $theme, string $label): array
    {
        $problems = [];
        self::scanMarkup($markup, $label, self::declaredSlugs($theme), $problems);
        return array_values($problems);
    }

    /**
     * @param array<mixed> $theme
     * @return array<string,array<string,true>>
     */
    private static function declaredSlugs(array $theme): array
    {
        $declared = [];
        foreach (self::PRESET_PATHS as $type => $path) {
            $value = $theme;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = [];
                    break;
                }
                $value = $value[$key];
            }

            $declared[$type] = [];
            if (is_array($value)) {
                foreach ($value as $preset) {
                    if (is_array($preset) && isset($preset['slug']) && is_string($preset['slug'])) {
                        $declared[$type][$preset['slug']] = true;
                    }
                }
            }
            $coreEnabled = !($type === 'shadow'
                && ($theme['settings']['shadow']['defaultPresets'] ?? null) === false);
            if ($coreEnabled) {
                foreach (self::CORE_PRESETS[$type] ?? [] as $slug) {
                    $declared[$type][$slug] = true;
                }
            }
        }
        return $declared;
    }

    /** @return string[] absolute paths, sorted for deterministic reports */
    private static function markupFiles(Project $project): array
    {
        $files = array_merge(
            glob($project->themePath('parts') . '/*.html') ?: [],
            glob($project->themePath('templates') . '/*.html') ?: [],
        );
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return list<array<mixed>> valid block-comment attribute objects */
    private static function blockAttributes(string $markup): array
    {
        $attributes = [];
        $blocks = BlockMarkup::parse($markup);
        foreach ($blocks->indices() as $index) {
            $attrs = $blocks->attrs($index);
            if ($attrs !== null) {
                $attributes[] = $attrs;
            }
        }
        return $attributes;
    }

    /**
     * @param array<string,array<string,true>> $declared
     * @param array<string,string> $problems message => message, for de-duplication
     */
    private static function scanMarkup(
        string $markup,
        string $label,
        array $declared,
        array &$problems,
    ): void {
        $values = [];
        foreach (self::blockAttributes($markup) as $attrs) {
            self::collectStrings($attrs, $values);
            self::scanDirectBlockFields($attrs, $label, $declared, $problems);
        }
        array_push($values, ...self::cssSegments($markup));
        foreach ($values as $value) {
            self::scan($value, $label, $declared, $problems);
        }
    }

    /**
     * @param array<mixed> $value
     * @param string[] $strings
     */
    private static function collectStrings(array $value, array &$strings): void
    {
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            } elseif (is_array($item)) {
                self::collectStrings($item, $strings);
            }
        }
    }

    /**
     * Validate block fields that use a bare preset slug rather than var:preset.
     *
     * @param array<mixed> $value
     * @param array<string,array<string,true>> $declared
     * @param array<string,string> $problems
     */
    private static function scanDirectBlockFields(
        array $value,
        string $file,
        array $declared,
        array &$problems,
    ): void {
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item) && isset(self::DIRECT_BLOCK_FIELDS[$key])) {
                $type = self::DIRECT_BLOCK_FIELDS[$key];
                if (!isset($declared[$type][$item])) {
                    $message = "{$file}: preset {$type} slug \"{$item}\" from {$key} is not declared in theme.json";
                    $problems[$message] = $message;
                }
            }
        }
    }

    /** @return string[] contents of inline style attributes and raw style elements */
    private static function cssSegments(string $markup): array
    {
        $segments = [];
        if (preg_match_all('~\bstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~is', $markup, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $segments[] = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
            }
        }
        if (preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~is', $markup, $matches)) {
            array_push($segments, ...$matches[1]);
        }
        return $segments;
    }

    /**
     * @param array<string,array<string,true>> $declared
     * @param array<string,string> $problems message => message, for de-duplication
     */
    private static function scan(string $value, string $file, array $declared, array &$problems): void
    {
        if (preg_match_all('/var:preset[^\s"\'();,<>{}\[\]]*/i', $value, $matches)) {
            foreach ($matches[0] as $token) {
                self::checkToken($token, $file, 'block', $declared, $problems);
            }
        }
        if (preg_match_all('/--wp--preset-+[a-z0-9_|.\-]*/i', $value, $matches)) {
            foreach ($matches[0] as $token) {
                self::checkToken($token, $file, 'css', $declared, $problems);
            }
        }
    }

    /**
     * @param array<string,array<string,true>> $declared
     * @param array<string,string> $problems
     */
    private static function checkToken(
        string $token,
        string $file,
        string $syntax,
        array $declared,
        array &$problems,
    ): void {
        $types = implode('|', array_map(
            static fn (string $type): string => preg_quote($type, '/'),
            array_keys(self::PRESET_PATHS),
        ));
        $pattern = $syntax === 'block'
            ? '/^var:preset\|(' . $types . ')\|([a-z0-9][a-z0-9_-]*)$/'
            : '/^--wp--preset--(' . $types . ')--([a-z0-9][a-z0-9_-]*)$/';

        if (preg_match($pattern, $token, $match) !== 1) {
            $message = "{$file}: malformed preset reference \"{$token}\"";
            $problems[$message] = $message;
            return;
        }

        [, $type, $slug] = $match;
        if (!isset($declared[$type][$slug])) {
            $message = "{$file}: preset {$type} slug \"{$slug}\" is not declared in theme.json";
            $problems[$message] = $message;
        }
    }
}
