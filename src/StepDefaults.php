<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Package defaults for per-step LLM model and temperature. Lives here (not in
 * the CLI bootstrap) so SiteBuilder resolves the same maps for every consumer,
 * including hosts that only load autoload.php. CLI helpers step_models() /
 * step_temperatures() delegate here.
 *
 * Env overrides: LLM_MODEL, LLM_MODEL_<STEP>, LLM_TEMPERATURE,
 * LLM_TEMPERATURE_<STEP>.
 */
final class StepDefaults
{
    /** The model used by any LLM step that isn't given a more specific one. */
    public static function model(): string
    {
        return Env::get('LLM_MODEL', 'claude-opus-4-8');
    }

    /**
     * Per-step model selection. Only LLM steps appear; deterministic steps make
     * no LLM calls.
     *
     * @return array<string,string> step id => model id
     */
    public static function models(): array
    {
        $default = self::model();
        return [
            // Fast, cheap prompt clean-up at the very start — small model by default.
            'refine-prompt' => Env::get('LLM_MODEL_REFINE_PROMPT', 'claude-haiku-4-5'),
            'site-spec'    => Env::get('LLM_MODEL_SITE_SPEC',    'claude-haiku-4-5'),
            // Design direction is the creative seed every later step builds on, so
            // it runs on the best model by default; override to trade cost/quality.
            'design-direction' => Env::get('LLM_MODEL_DESIGN_DIRECTION', $default),
            // Brainstorming concept seeds is cheap divergence work — small model.
            'design-direction-seeds' => Env::get('LLM_MODEL_DESIGN_DIRECTION_SEEDS', 'claude-haiku-4-5'),
            'theme-json'   => Env::get('LLM_MODEL_THEME_JSON',   $default),
            // Planning is light and structural — cheap/fast model by default.
            'page-plan'    => Env::get('LLM_MODEL_PAGE_PLAN',     'claude-haiku-4-5'),
            // Section markup is the quality-critical work — best model by default.
            'sections'     => Env::get('LLM_MODEL_SECTIONS',     $default),
            // One small CSS appendix with a strict validator — best model by default.
            'page-styles'  => Env::get('LLM_MODEL_PAGE_STYLES',  $default),
            // fonts.php behind a strict validator; scan-built fallback otherwise.
            'fonts-php'    => Env::get('LLM_MODEL_FONTS_PHP',    $default),
        ];
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
