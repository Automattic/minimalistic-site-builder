<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Loads config/models.json — the per-provider default model matrix.
 *
 * Each provider defines a model id per tier — `design` (the creative steps that
 * decide what the site looks like), `code` (the steps that emit block markup,
 * CSS and PHP from a direction already decided) and `small` (fast/cheap
 * structural steps) — and each pipeline step is tagged with a tier in
 * `step_tiers`. StepDefaults resolves a step's model by looking up the active
 * provider's model for that step's tier, while env overrides (LLM_MODEL_<STEP>,
 * LLM_MODEL_DESIGN / LLM_MODEL_CODE / LLM_MODEL_SMALL, LLM_MODEL) still win.
 * Keeping the matrix in data (not code) lets `--provider` swap the whole model
 * set in one flag without touching per-step wiring.
 */
final class ModelConfig
{
    /**
     * Tier lookup order. A provider may configure a single model and still serve
     * every tier: each tier falls through to the next configured one, nearest
     * neighbour first (a missing `code` model borrows `design`, not `small`).
     */
    private const TIER_FALLBACKS = [
        'design' => ['design', 'code', 'small'],
        'code'   => ['code', 'design', 'small'],
        'small'  => ['small', 'code', 'design'],
    ];
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

    /** The tier names a step may be tagged with. @return list<string> */
    public static function tierNames(): array
    {
        return array_keys(self::TIER_FALLBACKS);
    }

    /**
     * Model id for a provider's tier ('design' | 'code' | 'small'). Falls back
     * through TIER_FALLBACKS if that tier is omitted, so a provider may
     * configure a single model. An unknown tier name fails loud — it can only
     * come from a typo in step_tiers, and guessing a model there would silently
     * bill the wrong tier.
     */
    public static function tierModel(string $provider, string $tier): string
    {
        $providers = self::data()['providers'];
        if (!isset($providers[$provider]) || !is_array($providers[$provider])) {
            throw new \RuntimeException(
                "Unknown provider '{$provider}' in model config. Known: " . implode(', ', self::providerNames())
            );
        }
        if (!isset(self::TIER_FALLBACKS[$tier])) {
            throw new \RuntimeException(
                "Unknown model tier '{$tier}'. Known: " . implode(', ', self::tierNames())
            );
        }
        $models = $providers[$provider];
        foreach (self::TIER_FALLBACKS[$tier] as $candidate) {
            $model = $models[$candidate] ?? null;
            if (is_string($model) && $model !== '') {
                return $model;
            }
        }
        throw new \RuntimeException("No '{$tier}' model configured for provider '{$provider}'.");
    }

    /**
     * Step id => tier ('design' | 'code' | 'small').
     *
     * @return array<string,string>
     */
    public static function stepTiers(): array
    {
        $tiers = self::data()['step_tiers'] ?? [];
        return is_array($tiers) ? $tiers : [];
    }
}
