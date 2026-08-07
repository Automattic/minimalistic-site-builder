<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure normalizer for the persisted, model-authored hero blueprint.
 *
 * The selected catalog recipe is authoritative. Scalar drift is repaired to
 * bounded recipe defaults, line targets are clamped/ordered, and the result is
 * a fixed point. Repairs and delivered-value loss are collected separately so
 * callers can narrate/report the former without polluting warnings.json.
 */
final class HeroBlueprint
{
    public const VERSION = 1;
    public const MEDIA_MODES = ['none', 'cover-image', 'foreground-image'];
    public const HEADLINE_REGISTERS = ['restrained', 'display', 'poster'];
    public const TEXT_ANCHORS = [
        'top-start', 'center-start', 'bottom-start', 'center',
        'top-end', 'center-end', 'bottom-end',
    ];
    public const REGIONS = ['start', 'center', 'end', 'full'];
    public const FOCAL_REGIONS = ['start', 'center', 'end', 'full', 'none'];
    public const HEIGHT_PROFILES = ['compact', 'standard', 'immersive'];
    public const CTA_TREATMENTS = ['quiet', 'prominent'];
    public const STAGE_BACKDROPS = ['solid', 'texture'];
    public const MOBILE_TRANSFORMATIONS = [
        'retain-media-overlay', 'stack-copy-first', 'stack-media-first',
        'flatten-layers',
    ];

    /**
     * Complete reviewed blueprint for one assigned recipe.
     *
     * The optional constraint argument makes intrinsic incompatibility loud
     * for callers that already have structured requirements; it never mutates
     * a caller constraint to preserve the recipe.
     *
     * @param array<string,mixed> $constraints
     * @return array<string,mixed>
     */
    public static function defaultFor(string $recipe, array $constraints = []): array
    {
        HeroComposition::assertKnown($recipe);
        $constraints = HeroComposition::validateConstraints($constraints);
        if (!HeroComposition::isCompatible($recipe, $constraints)) {
            throw new \InvalidArgumentException("hero recipe '{$recipe}' is incompatible with design_constraints");
        }
        $defaults = HeroComposition::metadata($recipe)['defaults'];
        return [
            'version' => self::VERSION,
            'recipe' => $recipe,
            'media_mode' => $defaults['media_mode'],
            'headline_register' => $defaults['headline_register'],
            'text_anchor' => $defaults['text_anchor'],
            'headline_line_target' => [
                'desktop' => array_values($defaults['headline_line_target']['desktop']),
                'mobile' => array_values($defaults['headline_line_target']['mobile']),
            ],
            'focal_region' => $defaults['focal_region'],
            'text_safe_region' => $defaults['text_safe_region'],
            'height_profile' => $defaults['height_profile'],
            'cta_treatment' => $defaults['cta_treatment'],
            'stage_backdrop' => $defaults['stage_backdrop'],
            'mobile_transformation' => $defaults['mobile_transformation'],
        ];
    }

    /**
     * Normalize generated data against one authoritative assigned recipe.
     *
     * @param mixed        $raw
     * @param list<string> $repairs successful deterministic repairs
     * @param list<string> $warnings delivered-value losses/fallbacks
     * @return array<string,mixed>
     */
    public static function normalize(
        mixed $raw,
        string $assignedRecipe,
        array &$repairs = [],
        array &$warnings = [],
    ): array {
        HeroComposition::assertKnown($assignedRecipe);
        $defaults = self::defaultFor($assignedRecipe);
        $meta = HeroComposition::metadata($assignedRecipe);

        if (!is_array($raw)) {
            $warnings[] = self::warning(
                'hero_blueprint',
                $raw,
                $defaults,
                'synthesized complete assigned-recipe defaults because generated blueprint was unusable',
            );
            return $defaults;
        }

        $out = $defaults;

        if (($raw['version'] ?? null) !== self::VERSION) {
            self::repair($repairs, 'version', $raw['version'] ?? null, self::VERSION);
        }

        $authoredRecipe = is_string($raw['recipe'] ?? null) ? trim($raw['recipe']) : '';
        if ($authoredRecipe !== $assignedRecipe) {
            self::repair($repairs, 'recipe', $raw['recipe'] ?? null, $assignedRecipe);
        }

        $out['media_mode'] = self::enum(
            $raw['media_mode'] ?? null,
            self::MEDIA_MODES,
            $defaults['media_mode'],
            'media_mode',
            $repairs,
        );
        if (!in_array($out['media_mode'], $meta['media_modes'], true)) {
            $authored = $out['media_mode'];
            $out['media_mode'] = $defaults['media_mode'];
            self::repair($repairs, 'media_mode', $authored, $out['media_mode'], 'recipe compatibility');
        }

        $out['headline_register'] = self::enum(
            $raw['headline_register'] ?? null,
            self::HEADLINE_REGISTERS,
            $defaults['headline_register'],
            'headline_register',
            $repairs,
        );
        if (!in_array($out['headline_register'], $meta['headline_registers'], true)) {
            $authored = $out['headline_register'];
            $out['headline_register'] = $defaults['headline_register'];
            self::repair(
                $repairs,
                'headline_register',
                $authored,
                $out['headline_register'],
                'recipe compatibility',
            );
        }

        $out['text_anchor'] = self::enum(
            $raw['text_anchor'] ?? null,
            self::TEXT_ANCHORS,
            $defaults['text_anchor'],
            'text_anchor',
            $repairs,
        );
        $out['focal_region'] = self::enum(
            $raw['focal_region'] ?? null,
            self::FOCAL_REGIONS,
            $defaults['focal_region'],
            'focal_region',
            $repairs,
        );
        $out['text_safe_region'] = self::enum(
            $raw['text_safe_region'] ?? null,
            self::REGIONS,
            $defaults['text_safe_region'],
            'text_safe_region',
            $repairs,
        );

        $out['height_profile'] = self::enum(
            $raw['height_profile'] ?? null,
            self::HEIGHT_PROFILES,
            $defaults['height_profile'],
            'height_profile',
            $repairs,
        );
        if (!in_array($out['height_profile'], $meta['height_profiles'], true)) {
            $authored = $out['height_profile'];
            $out['height_profile'] = $defaults['height_profile'];
            self::repair($repairs, 'height_profile', $authored, $out['height_profile'], 'recipe compatibility');
        }

        $out['cta_treatment'] = self::enum(
            $raw['cta_treatment'] ?? null,
            self::CTA_TREATMENTS,
            $defaults['cta_treatment'],
            'cta_treatment',
            $repairs,
        );
        $out['stage_backdrop'] = self::enum(
            $raw['stage_backdrop'] ?? null,
            self::STAGE_BACKDROPS,
            $defaults['stage_backdrop'],
            'stage_backdrop',
            $repairs,
        );
        if (!in_array($out['stage_backdrop'], (array) ($meta['backdrops'] ?? ['solid']), true)) {
            $authored = $out['stage_backdrop'];
            $out['stage_backdrop'] = $defaults['stage_backdrop'];
            self::repair($repairs, 'stage_backdrop', $authored, $out['stage_backdrop'], 'recipe compatibility');
        }
        $out['mobile_transformation'] = self::enum(
            $raw['mobile_transformation'] ?? null,
            self::MOBILE_TRANSFORMATIONS,
            $defaults['mobile_transformation'],
            'mobile_transformation',
            $repairs,
        );
        if (!in_array($out['mobile_transformation'], $meta['mobile_transformations'], true)) {
            $authored = $out['mobile_transformation'];
            $out['mobile_transformation'] = $defaults['mobile_transformation'];
            self::repair(
                $repairs,
                'mobile_transformation',
                $authored,
                $out['mobile_transformation'],
                'recipe compatibility',
            );
        }

        $targets = is_array($raw['headline_line_target'] ?? null)
            ? $raw['headline_line_target']
            : [];
        if (!is_array($raw['headline_line_target'] ?? null)) {
            self::repair(
                $repairs,
                'headline_line_target',
                $raw['headline_line_target'] ?? null,
                $defaults['headline_line_target'],
            );
        }
        foreach (['desktop', 'mobile'] as $viewport) {
            $out['headline_line_target'][$viewport] = self::lineRange(
                $targets[$viewport] ?? null,
                $defaults['headline_line_target'][$viewport],
                "headline_line_target.{$viewport}",
                $repairs,
            );
        }

        self::repairSpatialCompatibility($out, $defaults, $repairs);

        return $out;
    }

    /** @param array<string,mixed> $blueprint */
    public static function recipe(array $blueprint): string
    {
        $recipe = trim((string) ($blueprint['recipe'] ?? ''));
        HeroComposition::assertKnown($recipe);
        return $recipe;
    }

    /**
     * Repair region/anchor fields as a single compatible tuple.
     *
     * @param array<string,mixed> $out
     * @param array<string,mixed> $defaults
     * @param list<string>        $repairs
     */
    private static function repairSpatialCompatibility(array &$out, array $defaults, array &$repairs): void
    {
        if ($out['media_mode'] !== 'cover-image') {
            foreach (['focal_region' => 'none', 'text_safe_region' => 'full'] as $field => $delivered) {
                if ($out[$field] !== $delivered) {
                    $authored = $out[$field];
                    $out[$field] = $delivered;
                    self::repair($repairs, $field, $authored, $delivered, 'cover-only field compatibility');
                }
            }
            return;
        }

        if (!self::anchorFitsSafeRegion($out['text_anchor'], $out['text_safe_region'])) {
            $authored = $out['text_anchor'];
            $out['text_anchor'] = $defaults['text_anchor'];
            self::repair($repairs, 'text_anchor', $authored, $out['text_anchor'], 'text-safe region compatibility');
            if (!self::anchorFitsSafeRegion($out['text_anchor'], $out['text_safe_region'])) {
                $authoredSafe = $out['text_safe_region'];
                $out['text_safe_region'] = $defaults['text_safe_region'];
                self::repair(
                    $repairs,
                    'text_safe_region',
                    $authoredSafe,
                    $out['text_safe_region'],
                    'text-anchor compatibility',
                );
            }
        }

        if ($out['focal_region'] !== 'none'
            && $out['focal_region'] !== 'full'
            && $out['focal_region'] === $out['text_safe_region']) {
            $authored = $out['focal_region'];
            $out['focal_region'] = $defaults['focal_region'];
            self::repair($repairs, 'focal_region', $authored, $out['focal_region'], 'cover safe/focal separation');
            if ($out['focal_region'] === $out['text_safe_region']) {
                $authoredSafe = $out['text_safe_region'];
                $out['text_safe_region'] = $defaults['text_safe_region'];
                self::repair(
                    $repairs,
                    'text_safe_region',
                    $authoredSafe,
                    $out['text_safe_region'],
                    'cover safe/focal separation',
                );
            }
        }
    }

    private static function anchorFitsSafeRegion(string $anchor, string $safe): bool
    {
        return match ($safe) {
            'full' => true,
            'center' => $anchor === 'center',
            'start' => str_ends_with($anchor, '-start'),
            'end' => str_ends_with($anchor, '-end'),
            default => false,
        };
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $repairs
     */
    private static function enum(
        mixed $raw,
        array $allowed,
        string $default,
        string $field,
        array &$repairs,
    ): string {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';
        if (!in_array($value, $allowed, true)) {
            self::repair($repairs, $field, $raw, $default);
            return $default;
        }
        if ($raw !== $value) {
            self::repair($repairs, $field, $raw, $value, 'canonicalized');
        }
        return $value;
    }

    /**
     * @param mixed        $raw
     * @param array{0:int,1:int} $default
     * @param list<string> $repairs
     * @return array{0:int,1:int}
     */
    private static function lineRange(mixed $raw, array $default, string $field, array &$repairs): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) !== 2) {
            self::repair($repairs, $field, $raw, $default);
            return $default;
        }
        $values = array_values($raw);
        $range = [];
        foreach ($values as $value) {
            if (!is_int($value)) {
                self::repair($repairs, $field, $raw, $default);
                return $default;
            }
            $range[] = max(1, min(6, $value));
        }
        if ($range[0] > $range[1]) {
            $range = [$range[1], $range[0]];
        }
        if ($range !== $values) {
            self::repair($repairs, $field, $raw, $range, 'clamped and ordered');
        }
        return [$range[0], $range[1]];
    }

    /** @param list<string> $repairs */
    private static function repair(
        array &$repairs,
        string $field,
        mixed $authored,
        mixed $delivered,
        string $reason = 'invalid or missing generated value',
    ): void {
        $repairs[] = 'designDirection.json: hero_blueprint.' . $field
            . ' authored ' . self::describe($authored)
            . ' delivered ' . self::describe($delivered)
            . "; disposition repaired ({$reason})";
    }

    private static function warning(string $field, mixed $authored, mixed $delivered, string $disposition): string
    {
        return 'designDirection.json: field ' . $field
            . ' authored ' . self::describe($authored)
            . ' delivered ' . self::describe($delivered)
            . "; disposition {$disposition}";
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
