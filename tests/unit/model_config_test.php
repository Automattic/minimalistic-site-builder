<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;
use Automattic\SiteBuild\StepDefaults;

/**
 * Provider-aware model resolution: ModelConfig reads config/models.json, and
 * StepDefaults maps each step to the active provider's large/small tier while
 * env overrides (LLM_MODEL_<STEP>, LLM_MODEL, LLM_MODEL_SMALL) still win.
 */

test('ModelConfig reads the packaged provider matrix', function () {
    assert_eq('anthropic', ModelConfig::defaultProvider());
    foreach (['anthropic', 'openai', 'xai', 'openrouter'] as $p) {
        assert_true(ModelConfig::hasProvider($p), "has {$p}");
    }
    assert_true(!ModelConfig::hasProvider('nope'), 'unknown provider is absent');

    assert_eq('gpt-5.5', ModelConfig::tierModel('openai', 'large'));
    assert_eq('gpt-5.4-mini', ModelConfig::tierModel('openai', 'small'));
    assert_eq('claude-opus-5', ModelConfig::tierModel('anthropic', 'large'));
    assert_eq('claude-haiku-4-5', ModelConfig::tierModel('anthropic', 'small'));
    assert_eq('moonshotai/kimi-k3', ModelConfig::tierModel('openrouter', 'large'));
    assert_eq('moonshotai/kimi-k2.5:nitro', ModelConfig::tierModel('openrouter', 'small'));

    $tiers = ModelConfig::stepTiers();
    assert_eq('small', $tiers['site-spec']);
    assert_eq('large', $tiers['sections']);
});

test('ModelConfig tierModel falls back to the other tier when one is omitted', function () {
    ModelConfig::useConfig([
        'default_provider' => 'solo',
        'providers' => ['solo' => ['large' => 'big-model']],
        'step_tiers' => ['sections' => 'large', 'site-spec' => 'small'],
    ]);
    try {
        assert_eq('big-model', ModelConfig::tierModel('solo', 'large'));
        // No 'small' configured → falls back to 'large'.
        assert_eq('big-model', ModelConfig::tierModel('solo', 'small'));
    } finally {
        ModelConfig::useConfig(null);
    }
});

test('StepDefaults default (anthropic) reproduces the historical model mapping', function () {
    // No LLM_PROVIDER / LLM_MODEL set → config default provider.
    putenv('LLM_PROVIDER');
    putenv('LLM_MODEL');
    putenv('LLM_MODEL_SMALL');
    $models = StepDefaults::models();

    assert_eq('claude-haiku-4-5', $models['refine-prompt']);
    assert_eq('claude-haiku-4-5', $models['site-spec']);
    assert_eq('claude-haiku-4-5', $models['design-direction-seeds']);
    assert_eq('claude-haiku-4-5', $models['page-plan']);
    assert_eq('claude-opus-5', $models['design-direction']);
    assert_eq('claude-opus-5', $models['theme-json']);
    assert_eq('claude-opus-5', $models['sections']);
    assert_eq('claude-opus-5', $models['page-styles']);
    assert_true(!isset($models['fonts-php']), 'deterministic fonts-php has no model mapping');
});

test('StepDefaults follows the active provider tiers (openai)', function () {
    putenv('LLM_PROVIDER=openai');
    try {
        assert_eq('openai', StepDefaults::provider());
        $models = StepDefaults::models();
        // small tier → gpt-5.4-mini
        assert_eq('gpt-5.4-mini', $models['site-spec']);
        assert_eq('gpt-5.4-mini', $models['refine-prompt']);
        assert_eq('gpt-5.4-mini', $models['page-plan']);
        // large tier → gpt-5.5
        assert_eq('gpt-5.5', $models['design-direction']);
        assert_eq('gpt-5.5', $models['sections']);
        assert_eq('gpt-5.5', $models['theme-json']);
    } finally {
        putenv('LLM_PROVIDER');
    }
});

test('an LLM_MODEL_<STEP> value may name the transport as well as the model', function () {
    putenv('LLM_PROVIDER=anthropic');
    putenv('LLM_MODEL_THEME_JSON=claude-opus-5');
    putenv('LLM_MODEL_SECTIONS=baseten:zai-org/GLM-5.3-Flash');
    try {
        $models = StepDefaults::models();
        assert_eq('claude-opus-5', $models['theme-json'], 'no prefix: the model alone, as always');
        assert_eq('zai-org/GLM-5.3-Flash', $models['sections'], 'a prefix contributes only the model here');
        assert_eq('claude-haiku-4-5', $models['page-plan'], 'an untouched step keeps its tier');

        assert_eq(
            ['sections' => ['transport' => 'baseten', 'model' => 'zai-org/GLM-5.3-Flash']],
            StepDefaults::stepSpecs(),
            'only the prefixed step is routed away',
        );
        assert_eq(['zai-org/glm-5.3-flash' => 'baseten'], StepDefaults::modelTransports());
    } finally {
        putenv('LLM_MODEL_THEME_JSON');
        putenv('LLM_MODEL_SECTIONS');
        putenv('LLM_PROVIDER');
    }
});

test('a step with no tier gets its only model from an override', function () {
    putenv('LLM_PROVIDER=anthropic');
    putenv('LLM_MODEL_INNER_PAGES_DESIGN=baseten:zai-org/GLM-5.3-Flash');
    try {
        // inner-pages-design carries no tier, so without an override it is not
        // in this map at all and the step falls back to the client's default.
        assert_true(
            !array_key_exists('inner-pages-design', ModelConfig::stepTiers()),
            'inner-pages-design is tier-less',
        );
        assert_eq('zai-org/GLM-5.3-Flash', StepDefaults::models()['inner-pages-design']);
        assert_eq(['zai-org/glm-5.3-flash' => 'baseten'], StepDefaults::modelTransports());
    } finally {
        putenv('LLM_MODEL_INNER_PAGES_DESIGN');
        putenv('LLM_PROVIDER');
    }
});

test('a transport prefix with no model after it is rejected', function () {
    putenv('LLM_PROVIDER=anthropic');
    putenv('LLM_MODEL_SECTIONS=baseten:');
    try {
        $threw = null;
        try {
            StepDefaults::models();
        } catch (RuntimeException $e) {
            $threw = $e->getMessage();
        }
        assert_true($threw !== null, 'half a spec has nothing to send');
        assert_true(str_contains((string) $threw, 'LLM_MODEL_SECTIONS'), "names the var: {$threw}");
    } finally {
        putenv('LLM_MODEL_SECTIONS');
        putenv('LLM_PROVIDER');
    }
});

test('with no prefixed override, nothing is routed and each provider stays one client', function () {
    foreach (ModelConfig::providerNames() as $provider) {
        putenv("LLM_PROVIDER={$provider}");
        try {
            assert_eq([], StepDefaults::stepSpecs(), "{$provider} routes nothing by default");
            assert_eq([], StepDefaults::modelTransports());
            assert_eq(
                array_keys(ModelConfig::stepTiers()),
                array_keys(StepDefaults::models()),
                "{$provider} maps exactly the tiered steps, gaining none",
            );
        } finally {
            putenv('LLM_PROVIDER');
        }
    }
});

test('Baseten runs quality steps on DeepSeek V4 Pro and structural steps on GLM 5.3 Flash', function () {
    putenv('LLM_PROVIDER=baseten');
    try {
        assert_eq('baseten', StepDefaults::provider());
        $models = StepDefaults::models();
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            $expected = $tier === 'large'
                ? 'deepseek-ai/DeepSeek-V4-Pro'
                : 'zai-org/GLM-5.3-Flash';
            assert_eq($expected, $models[$step], "{$step} follows its configured {$tier} tier");
        }
        assert_eq('deepseek-ai/DeepSeek-V4-Pro', ModelConfig::tierModel('baseten', 'large'));
        assert_eq('zai-org/GLM-5.3-Flash', ModelConfig::tierModel('baseten', 'small'));
    } finally {
        putenv('LLM_PROVIDER');
    }
});

test('StepDefaults uses K3 for OpenRouter quality steps and K2.5 for structural steps', function () {
    putenv('LLM_PROVIDER=openrouter');
    try {
        $models = StepDefaults::models();
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            $expected = $tier === 'large'
                ? 'moonshotai/kimi-k3'
                : 'moonshotai/kimi-k2.5:nitro';
            assert_eq(
                $expected,
                $models[$step],
                "{$step} follows its configured {$tier} tier",
            );
        }
    } finally {
        putenv('LLM_PROVIDER');
    }
});

test('LLM_MODEL_<STEP> overrides any provider tier, with any model id', function () {
    putenv('LLM_PROVIDER=openai');
    putenv('LLM_MODEL_SITE_SPEC=claude-haiku-4-5'); // cross-provider id, user's responsibility
    putenv('LLM_MODEL_SECTIONS=gpt-5.5-pro');
    try {
        $models = StepDefaults::models();
        assert_eq('claude-haiku-4-5', $models['site-spec'], 'per-step override wins');
        assert_eq('gpt-5.5-pro', $models['sections'], 'per-step override wins on large tier');
        assert_eq('gpt-5.4-mini', $models['refine-prompt'], 'untouched step keeps provider small tier');
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL_SITE_SPEC');
        putenv('LLM_MODEL_SECTIONS');
    }
});

test('LLM_MODEL and LLM_MODEL_SMALL override the run-wide large / small tiers', function () {
    putenv('LLM_PROVIDER=openai');
    putenv('LLM_MODEL=gpt-5.5-pro');
    putenv('LLM_MODEL_SMALL=gpt-5.4-nano');
    try {
        assert_eq('gpt-5.5-pro', StepDefaults::model());
        assert_eq('gpt-5.4-nano', StepDefaults::smallModel());
        $models = StepDefaults::models();
        assert_eq('gpt-5.5-pro', $models['sections'], 'large tier follows LLM_MODEL');
        assert_eq('gpt-5.4-nano', $models['site-spec'], 'small tier follows LLM_MODEL_SMALL');
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL');
        putenv('LLM_MODEL_SMALL');
    }
});

test('a pin whose model id another step already resolves to is refused, not silently followed', function () {
    // Routes are keyed by model id, not by step. Pinning `sections` to a model
    // the large tier already uses would take design-direction, theme-json,
    // page-styles and custom-motion along with it — four steps nobody named.
    putenv('LLM_PROVIDER=baseten');
    putenv('LLM_MODEL_SECTIONS=openai:deepseek-ai/DeepSeek-V4-Pro');
    try {
        $threw = null;
        try {
            StepDefaults::modelTransports();
        } catch (RuntimeException $e) {
            $threw = $e->getMessage();
        }
        assert_true($threw !== null, 'a pin must not silently move steps it did not name');
        assert_true(str_contains($threw, 'LLM_MODEL_SECTIONS'), "names the pin: {$threw}");
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL_SECTIONS');
    }
});

test('ids that differ only in case collide too, because routes are matched case-insensitively', function () {
    // OpenRouter spells it moonshotai/kimi-k3 and Baseten moonshotai/Kimi-K3.
    // The route key is lowercased, so pinning the Baseten spelling would drag
    // every OpenRouter-tier step to Baseten carrying the wrong casing — an id
    // Baseten, which is case-sensitive, does not have.
    putenv('LLM_PROVIDER=openrouter');
    putenv('LLM_MODEL_SECTIONS=baseten:moonshotai/Kimi-K3');
    try {
        $threw = null;
        try {
            StepDefaults::modelTransports();
        } catch (RuntimeException $e) {
            $threw = $e->getMessage();
        }
        assert_true($threw !== null, 'a case-folded collision must not route silently');
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL_SECTIONS');
    }
});

test('two steps cannot send one model id to two different transports', function () {
    putenv('LLM_PROVIDER=anthropic');
    putenv('LLM_MODEL_SECTIONS=baseten:zai-org/GLM-5.3-Flash');
    putenv('LLM_MODEL_PAGE_PLAN=openrouter:zai-org/GLM-5.3-Flash');
    try {
        $threw = null;
        try {
            StepDefaults::modelTransports();
        } catch (RuntimeException $e) {
            $threw = $e->getMessage();
        }
        assert_true($threw !== null, 'the table cannot express this, so it must not guess');
        assert_true(str_contains($threw, 'LLM_MODEL_PAGE_PLAN'), "names the losing pin: {$threw}");
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL_SECTIONS');
        putenv('LLM_MODEL_PAGE_PLAN');
    }
});

test('distinct model ids on distinct transports remain the supported shape', function () {
    // The guards above must not cost the feature its documented use.
    putenv('LLM_PROVIDER=anthropic');
    putenv('LLM_MODEL_SECTIONS=baseten:zai-org/GLM-5.3-Flash');
    putenv('LLM_MODEL_PAGE_PLAN=openai:gpt-5.4-mini');
    try {
        // Insertion order follows step_tiers, where page-plan precedes sections.
        assert_eq(
            ['gpt-5.4-mini' => 'openai', 'zai-org/glm-5.3-flash' => 'baseten'],
            StepDefaults::modelTransports(),
        );
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL_SECTIONS');
        putenv('LLM_MODEL_PAGE_PLAN');
    }
});

test('the seed judge runs on the large tier by default', function () {
    putenv('LLM_PROVIDER');
    putenv('LLM_MODEL');
    putenv('LLM_MODEL_SMALL');
    assert_eq('large', ModelConfig::stepTiers()['design-direction-judge'] ?? null, 'a taste call earns the quality tier');
    assert_eq('claude-opus-5', StepDefaults::models()['design-direction-judge']);
});
