<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Package defaults for per-step LLM model and temperature. Lives here (not in
 * the CLI bootstrap) so SiteBuilder resolves the same maps for every consumer,
 * including hosts that only load autoload.php. CLI helpers step_models() /
 * step_temperatures() delegate here.
 *
 * Models come from the active provider's large/small tiers in config/models.json
 * (see ModelConfig): the provider is chosen by LLM_PROVIDER (which `--provider`
 * sets), and each step uses its configured tier. Env overrides still win, in
 * order: LLM_MODEL_<STEP> (one step, any model) > LLM_MODEL / LLM_MODEL_SMALL
 * (the run-wide large / small tier) > the provider tier default.
 *
 * Env overrides: LLM_PROVIDER, LLM_MODEL, LLM_MODEL_SMALL, LLM_MODEL_<STEP>,
 * LLM_TEMPERATURE, LLM_TEMPERATURE_<STEP>.
 */
final class StepDefaults
{
    /**
     * Steps that take a model but carry no tier. StepComposition reads them as
     * `$models['<step>'] ?? null`, so they fall through to the client's default
     * model unless something pins them. Listed so an env declaration may name
     * one, and so a misspelled step name can be told from a real one.
     */
    private const TIERLESS_STEPS = ['design-preview', 'inner-pages-design', 'transform-site'];

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
        return self::tierOverride('LLM_MODEL') ?? ModelConfig::tierModel(self::provider(), 'large');
    }

    /** The run-wide "small" model. LLM_MODEL_SMALL overrides the provider's small tier. */
    public static function smallModel(): string
    {
        return self::tierOverride('LLM_MODEL_SMALL') ?? ModelConfig::tierModel(self::provider(), 'small');
    }

    /**
     * A whole-tier override, which must be a bare model id.
     *
     * The `transport:` prefix is per-step only: a tier spans many steps, and
     * moving all of them to another provider is what `--provider` is for.
     * Rejected rather than ignored, because a prefix taken literally here would
     * become a model id no provider has and fail deep in the run.
     */
    private static function tierOverride(string $key): ?string
    {
        $raw = Env::get($key);
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $spec = ModelSpec::parse($raw, $key);
        if ($spec['transport'] !== null) {
            throw new \RuntimeException(
                "{$key} names the transport '{$spec['transport']}', but a transport prefix is only "
                . 'accepted on a single step (LLM_MODEL_<STEP>). Use --provider / LLM_PROVIDER to move '
                . 'the whole run, or set the prefix on the steps you want moved.'
            );
        }
        return $spec['model'];
    }

    /**
     * Per-step model selection. Only LLM steps appear; deterministic steps make
     * no LLM calls. Each step's tier comes from config/models.json; a matching
     * LLM_MODEL_<STEP> env var (e.g. LLM_MODEL_SITE_SPEC) overrides it with any
     * model id, from any provider.
     *
     * Resolution order, strongest first: LLM_MODEL_<STEP>, then LLM_MODEL /
     * LLM_MODEL_SMALL, then the step's tier. An LLM_MODEL_<STEP> value may
     * carry a `transport:` prefix (see ModelSpec) to run that one step on a
     * different provider; only the model half appears here, the transport half
     * reaching RoutingLlm through modelTransports().
     *
     * @return array<string,string> step id => model id
     */
    public static function models(): array
    {
        $large = self::model();
        $small = self::smallModel();

        $out = [];
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            $out[$step] = $tier === 'small' ? $small : $large;
        }
        // Every step, not only the tiered ones: design-preview,
        // inner-pages-design and transform-site have no tier, so an override is
        // the only thing that ever gives them a model.
        foreach (self::knownSteps() as $step) {
            $key = 'LLM_MODEL_' . self::envSuffix($step);
            $raw = Env::get($key);
            if ($raw !== null && trim($raw) !== '') {
                $out[$step] = ModelSpec::parse($raw, $key)['model'];
            }
        }
        return $out;
    }

    /** Every step id that can carry a model. @return list<string> */
    public static function knownSteps(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(ModelConfig::stepTiers()),
            self::TIERLESS_STEPS,
        )));
    }

    /** The env var suffix for a step: page-styles => PAGE_STYLES. */
    private static function envSuffix(string $step): string
    {
        return strtoupper(str_replace('-', '_', $step));
    }

    /**
     * Every step whose LLM_MODEL_<STEP> override also names a transport.
     *
     * @return array<string,array{transport:string,model:string}>
     */
    public static function stepSpecs(): array
    {
        $out = [];
        foreach (self::knownSteps() as $step) {
            $key = 'LLM_MODEL_' . self::envSuffix($step);
            $raw = Env::get($key);
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            $spec = ModelSpec::parse($raw, $key);
            if ($spec['transport'] !== null) {
                $out[$step] = ['transport' => $spec['transport'], 'model' => $spec['model']];
            }
        }
        return $out;
    }

    /**
     * Model id (lowercased) => transport name, for every step whose override
     * sent it somewhere other than the active provider. RoutingLlm's table.
     *
     * Keyed by model rather than by step because that is what a request
     * carries by the time it reaches the transport; no step has to know that
     * more than one provider is in play.
     *
     * @return array<string,string>
     */
    public static function modelTransports(): array
    {
        $out = [];
        foreach (self::stepSpecs() as $spec) {
            $out[strtolower($spec['model'])] = $spec['transport'];
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
