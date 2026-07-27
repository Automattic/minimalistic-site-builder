<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Loads config/models.json — the per-provider default model matrix.
 *
 * Each provider defines a `large` and `small` model id and may override costly
 * outlier steps with `step_models`; every pipeline step is tagged with a tier
 * in `step_tiers`. Environment overrides (LLM_MODEL, LLM_MODEL_SMALL,
 * LLM_MODEL_<STEP>) still win. Keeping the matrix in data (not code) lets
 * `--provider` swap the whole model set in one flag without hard-coded step
 * wiring.
 */
final class ModelConfig
{
    /** @var array<string,mixed>|null Decoded config, cached for the process. */
    private static ?array $cache = null;

    /** Default config file, relative to this package's src/ directory. */
    private static function defaultPath(): string
    {
        return dirname(__DIR__) . '/config/models.json';
    }

    /** @return array<string,mixed> */
    private static function data(): array
    {
        if (self::$cache === null) {
            self::$cache = self::load(self::defaultPath());
        }
        return self::$cache;
    }

    /**
     * Read and validate the config file.
     *
     * @return array<string,mixed>
     */
    private static function load(string $path): array
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException("Model config not found or unreadable: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['providers']) || !is_array($data['providers'])) {
            throw new \RuntimeException("Invalid model config (missing 'providers'): {$path}");
        }
        return $data;
    }

    /**
     * Override the loaded config (tests). Pass null to reset to the packaged file
     * on the next read.
     *
     * @param array<string,mixed>|null $data
     */
    public static function useConfig(?array $data): void
    {
        self::$cache = $data;
    }

    /** Provider used when neither --provider nor LLM_PROVIDER is set. */
    public static function defaultProvider(): string
    {
        return (string) (self::data()['default_provider'] ?? 'anthropic');
    }

    /** @return list<string> */
    public static function providerNames(): array
    {
        return array_keys(self::data()['providers']);
    }

    public static function hasProvider(string $provider): bool
    {
        return isset(self::data()['providers'][$provider]);
    }

    /**
     * Model id for a provider's tier ('large' | 'small'). Falls back to the other
     * tier if one is omitted, so a provider may configure a single model.
     */
    public static function tierModel(string $provider, string $tier): string
    {
        $providers = self::data()['providers'];
        if (!isset($providers[$provider]) || !is_array($providers[$provider])) {
            throw new \RuntimeException(
                "Unknown provider '{$provider}' in model config. Known: " . implode(', ', self::providerNames())
            );
        }
        $models = $providers[$provider];
        $other = $tier === 'small' ? 'large' : 'small';
        $model = $models[$tier] ?? $models[$other] ?? null;
        if (!is_string($model) || $model === '') {
            throw new \RuntimeException("No '{$tier}' model configured for provider '{$provider}'.");
        }
        return $model;
    }

    /**
     * Optional provider-specific model for one step. This lets a provider keep
     * an expensive reasoning model as its creative large tier while routing
     * high-volume code/markup steps to a faster model. Environment overrides
     * are applied later by StepDefaults and always take precedence.
     */
    public static function stepModel(string $provider, string $step): ?string
    {
        $providers = self::data()['providers'];
        $models = $providers[$provider] ?? null;
        if (!is_array($models)) {
            return null;
        }
        $stepModels = $models['step_models'] ?? null;
        if (!is_array($stepModels)) {
            return null;
        }
        $model = $stepModels[$step] ?? null;
        return is_string($model) && trim($model) !== '' ? $model : null;
    }

    /**
     * Step id => tier ('large' | 'small').
     *
     * @return array<string,string>
     */
    public static function stepTiers(): array
    {
        $tiers = self::data()['step_tiers'] ?? [];
        return is_array($tiers) ? $tiers : [];
    }
}
