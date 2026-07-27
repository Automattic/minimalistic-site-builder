<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Package defaults for per-step LLM model and temperature. Lives here (not in
 * the CLI bootstrap) so SiteBuilder resolves the same maps for every consumer,
 * including hosts that only load autoload.php. CLI helpers step_models() /
 * step_temperatures() delegate here.
 *
 * Models come from the active provider's large/small tiers and optional
 * per-step model map in config/models.json (see ModelConfig). Env overrides
 * still win, in order: LLM_MODEL_<STEP> (one step, any model) > LLM_MODEL /
 * LLM_MODEL_SMALL (the run-wide large / small tier) > provider step model >
 * provider tier default.
 *
 * Env overrides: LLM_PROVIDER, LLM_MODEL, LLM_MODEL_SMALL, LLM_MODEL_<STEP>,
 * LLM_TEMPERATURE, LLM_TEMPERATURE_<STEP>.
 */
final class StepDefaults
{
    /** Active provider: LLM_PROVIDER, else the config default. */
    public static function provider(): string
    {
        return strtolower((string) Env::get('LLM_PROVIDER', ModelConfig::defaultProvider()));
    }

    /**
     * The run-wide "large" model: any LLM step without a more specific one uses
     * this. LLM_MODEL overrides the active provider's large tier.
     */
    public static function model(): string
    {
        return Env::get('LLM_MODEL') ?? ModelConfig::tierModel(self::provider(), 'large');
    }

    /** The run-wide "small" model. LLM_MODEL_SMALL overrides the provider's small tier. */
    public static function smallModel(): string
    {
        return Env::get('LLM_MODEL_SMALL') ?? ModelConfig::tierModel(self::provider(), 'small');
    }

    /**
     * Per-step model selection. Only LLM steps appear; deterministic steps make
     * no LLM calls. Each step's tier comes from config/models.json; a matching
     * LLM_MODEL_<STEP> env var (e.g. LLM_MODEL_SITE_SPEC) overrides it with any
     * model id, from any provider.
     *
     * @return array<string,string> step id => model id
     */
    public static function models(): array
    {
        $provider = self::provider();
        $largeOverride = Env::get('LLM_MODEL');
        $smallOverride = Env::get('LLM_MODEL_SMALL');

        $out = [];
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            $envKey = 'LLM_MODEL_' . strtoupper(str_replace('-', '_', $step));
            $tierOverride = $tier === 'small' ? $smallOverride : $largeOverride;
            $configured = ModelConfig::stepModel($provider, $step)
                ?? ModelConfig::tierModel($provider, $tier);
            $out[$step] = Env::get($envKey) ?? $tierOverride ?? $configured;
        }
        return $out;
    }

    /**
     * Per-step sampling temperature. Null = don't send (API default). Values
     * only apply on models that still support sampling — see
     * AnthropicClient::supportsSampling().
     *
     * @return array<string,?float> step id => temperature
     */
    public static function temperatures(): array
    {
        return [
            'refine-prompt'    => self::temperature('REFINE_PROMPT', null),
            'site-spec'        => self::temperature('SITE_SPEC', null),
            'design-direction' => self::temperature('DESIGN_DIRECTION', 1.0),
            'theme-json'       => self::temperature('THEME_JSON', null),
            'page-plan'        => self::temperature('PAGE_PLAN', null),
            'sections'         => self::temperature('SECTIONS', 0.9),
            'page-styles'      => self::temperature('PAGE_STYLES', null),
            'custom-motion'    => self::temperature('CUSTOM_MOTION', null),
            'fonts-php'        => self::temperature('FONTS_PHP', null),
        ];
    }

    /**
     * LLM_TEMPERATURE_<STEP> wins, then LLM_TEMPERATURE, then $default.
     * Non-numeric env values are ignored.
     */
    public static function temperature(string $envSuffix, ?float $default): ?float
    {
        $raw = Env::get('LLM_TEMPERATURE_' . $envSuffix) ?? Env::get('LLM_TEMPERATURE');
        return is_numeric($raw) ? (float) $raw : $default;
    }
}
