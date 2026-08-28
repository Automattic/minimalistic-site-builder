<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Loads config/models.json — the per-provider default model matrix.
 *
 * Each provider defines a `large` and `small` model id; each pipeline step is
 * tagged with a tier in `step_tiers`. StepDefaults resolves a step's model by
 * looking up the active provider's model for that step's tier, while env
 * overrides (LLM_MODEL, LLM_MODEL_SMALL, LLM_MODEL_<STEP>) still win. Keeping the
 * matrix in data (not code) lets `--provider` swap the whole model set in one
 * flag without touching per-step wiring.
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
     * Transport a provider's unpinned steps use — the client make_llm() builds
     * for it. Defaults to the provider's own name, so every single-transport
     * provider needs no `transport` key at all; `hybrid` sets it to `baseten`.
     */
    public static function transport(string $provider): string
    {
        $entry = self::data()['providers'][$provider] ?? [];
        $transport = is_array($entry) ? ($entry['transport'] ?? null) : null;
        return is_string($transport) && $transport !== '' ? $transport : $provider;
    }

    /**
     * Steps this provider pins to a specific transport and model, overriding
     * the tier default. Absent for every single-transport provider.
     *
     * Validated strictly rather than skipped: a typo here would silently send a
     * step to the wrong provider, and the whole point of the entry is that the
     * step goes somewhere other than where you would otherwise assume.
     *
     * @return array<string,array{transport:string,model:string}>
     */
    public static function stepAssignments(string $provider): array
    {
        $entry = self::data()['providers'][$provider] ?? [];
        $steps = is_array($entry) ? ($entry['steps'] ?? []) : [];
        if (!is_array($steps)) {
            throw new \RuntimeException("Provider '{$provider}' has a non-array 'steps' map in the model config.");
        }

        $out = [];
        foreach ($steps as $step => $spec) {
            $transport = is_array($spec) ? ($spec['transport'] ?? null) : null;
            $model = is_array($spec) ? ($spec['model'] ?? null) : null;
            if (!is_string($transport) || $transport === '' || !is_string($model) || $model === '') {
                throw new \RuntimeException(
                    "Provider '{$provider}' step '{$step}' needs both a non-empty 'transport' and 'model'."
                );
            }
            $out[(string) $step] = ['transport' => $transport, 'model' => $model];
        }
        return $out;
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
