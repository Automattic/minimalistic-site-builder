<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Package defaults for per-step LLM model and temperature. Lives here (not in
 * the CLI bootstrap) so SiteBuilder resolves the same maps for every consumer,
 * including hosts that only load autoload.php. CLI helpers step_models() /
 * step_temperatures() delegate here.
 *
 * Models come from the active provider's design/code/small tiers in
 * config/models.json (see ModelConfig): the provider is chosen by LLM_PROVIDER
 * (which `--provider` sets), and each step uses its configured tier. Env
 * overrides still win, in order: LLM_MODEL_<STEP> (one step, any model) >
 * LLM_MODEL_DESIGN / LLM_MODEL_CODE / LLM_MODEL_SMALL (one tier) > LLM_MODEL
 * (both non-small tiers) > the provider tier default.
 *
 * Env overrides: LLM_PROVIDER, LLM_MODEL, LLM_MODEL_DESIGN, LLM_MODEL_CODE,
 * LLM_MODEL_SMALL, LLM_MODEL_<STEP>, LLM_TEMPERATURE, LLM_TEMPERATURE_<STEP>.
 */
final class StepDefaults
{
    /** Active provider: LLM_PROVIDER, else the config default. */
    public static function provider(): string
    {
        return strtolower((string) Env::get('LLM_PROVIDER', ModelConfig::defaultProvider()));
    }

    /**
     * The fallback model for any request that carries no model of its own (the
     * transport's default). Every step passes its own, so this only matters to
     * ad-hoc callers — it uses the design tier, the safe end of the range.
     */
    public static function model(): string
    {
        return self::tierModel('design');
    }

    /** The design tier: the creative steps that decide what the site looks like. */
    public static function designModel(): string
    {
        return self::tierModel('design');
    }

    /** The code tier: the steps that emit block markup, CSS and PHP. */
    public static function codeModel(): string
    {
        return self::tierModel('code');
    }

    /** The small tier: the fast/cheap structural steps. */
    public static function smallModel(): string
    {
        return self::tierModel('small');
    }

    /**
     * One tier's model. LLM_MODEL_<TIER> wins, then LLM_MODEL — which covers
     * design and code together, the two tiers the single "large" tier used to
     * name, so an existing LLM_MODEL keeps moving the whole expensive half of
     * the pipeline rather than half of it.
     */
    public static function tierModel(string $tier): string
    {
        $perTier = Env::get('LLM_MODEL_' . strtoupper($tier));
        if ($perTier !== null && $perTier !== '') {
            return $perTier;
        }
        $runWide = $tier === 'small' ? null : Env::get('LLM_MODEL');
        if ($runWide !== null && $runWide !== '') {
            return $runWide;
        }
        return ModelConfig::tierModel(self::provider(), $tier);
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
        $byTier = [];
        $out = [];
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            $envKey = 'LLM_MODEL_' . strtoupper(str_replace('-', '_', $step));
            $byTier[$tier] ??= self::tierModel($tier);
            $out[$step] = Env::get($envKey, $byTier[$tier]);
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
