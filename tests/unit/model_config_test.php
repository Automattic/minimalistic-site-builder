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

test('hybrid pins the markup steps away from Baseten and leaves the rest on it', function () {
    putenv('LLM_PROVIDER=hybrid');
    try {
        $models = StepDefaults::models();

        assert_eq('gpt-5.6-sol', $models['sections'], 'section generation goes to GPT');
        assert_eq('gpt-5.6-sol', $models['page-styles'], 'page styles go to GPT');
        assert_eq('gpt-5.6-sol', $models['inner-pages-design'], 'inner page sections go to GPT');

        // The pinned set is stated here, not derived, so adding or dropping a
        // pin has to be a deliberate edit to this list as well as to the config.
        assert_eq(
            ['inner-pages-design', 'sections', 'page-styles'],
            array_keys(ModelConfig::stepAssignments('hybrid')),
            'exactly these steps leave Baseten',
        );

        // inner-pages-design has no tier at all, so no other provider puts it
        // in this map. The pin is what gives that step a model.
        assert_true(
            !array_key_exists('inner-pages-design', ModelConfig::stepTiers()),
            'inner-pages-design is tier-less; the pin is its only source of a model',
        );

        // Everything else keeps the Baseten tier it would have had.
        $pinned = ModelConfig::stepAssignments('hybrid');
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            if (isset($pinned[$step])) {
                continue;
            }
            $expected = $tier === 'large' ? 'moonshotai/Kimi-K3' : 'zai-org/GLM-5.2-Fast';
            assert_eq($expected, $models[$step], "{$step} stays on its Baseten {$tier} tier");
        }

        // Three steps, one routed model, so exactly one extra transport.
        assert_eq(
            ['gpt-5.6-sol' => 'openai'],
            StepDefaults::modelTransports(),
            'each pinned model names the transport that can actually serve it',
        );
    } finally {
        putenv('LLM_PROVIDER');
    }
});

test('a per-step env override still beats a hybrid pin', function () {
    putenv('LLM_PROVIDER=hybrid');
    putenv('LLM_MODEL_SECTIONS=zai-org/GLM-5.2');
    try {
        $models = StepDefaults::models();
        assert_eq('zai-org/GLM-5.2', $models['sections'], 'LLM_MODEL_<STEP> outranks the pin');
        assert_eq('gpt-5.6-sol', $models['inner-pages-design'], 'the untouched pin is unaffected');
    } finally {
        putenv('LLM_MODEL_SECTIONS');
        putenv('LLM_PROVIDER');
    }
});

test('single-transport providers pin nothing and resolve exactly as before', function () {
    foreach (['anthropic', 'openai', 'xai', 'openrouter', 'baseten'] as $provider) {
        assert_eq([], ModelConfig::stepAssignments($provider), "{$provider} pins no step");
        assert_eq($provider, ModelConfig::transport($provider), "{$provider} is its own transport");

        putenv("LLM_PROVIDER={$provider}");
        try {
            $models = StepDefaults::models();
            assert_eq(
                array_keys(ModelConfig::stepTiers()),
                array_keys($models),
                "{$provider} maps exactly the tiered steps, gaining none",
            );
        } finally {
            putenv('LLM_PROVIDER');
        }
    }

    assert_eq('baseten', ModelConfig::transport('hybrid'), 'hybrid runs on the Baseten client by default');
});

test('a malformed step pin fails loudly rather than routing somewhere surprising', function () {
    ModelConfig::useConfig([
        'providers' => ['broken' => ['large' => 'm', 'steps' => ['sections' => ['model' => 'x']]]],
        'step_tiers' => [],
    ]);
    try {
        $threw = null;
        try {
            ModelConfig::stepAssignments('broken');
        } catch (RuntimeException $e) {
            $threw = $e->getMessage();
        }
        assert_true($threw !== null, 'a pin missing its transport is rejected');
        assert_true(str_contains($threw, 'sections'), "the message names the step: {$threw}");
    } finally {
        ModelConfig::useConfig(null);
    }
});

test('StepDefaults uses K3 for Baseten quality steps and GLM 5.2 Fast for structural steps', function () {
    putenv('LLM_PROVIDER=baseten');
    try {
        assert_eq('baseten', StepDefaults::provider());
        $models = StepDefaults::models();
        foreach (ModelConfig::stepTiers() as $step => $tier) {
            $expected = $tier === 'large'
                ? 'moonshotai/Kimi-K3'
                : 'zai-org/GLM-5.2-Fast';
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
