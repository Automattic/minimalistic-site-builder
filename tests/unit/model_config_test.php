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
    assert_eq('claude-opus-4-8', ModelConfig::tierModel('anthropic', 'large'));
    assert_eq('claude-haiku-4-5', ModelConfig::tierModel('anthropic', 'small'));
    assert_eq('moonshotai/kimi-k3', ModelConfig::tierModel('openrouter', 'large'));
    assert_eq('moonshotai/kimi-k2.5:nitro', ModelConfig::tierModel('openrouter', 'small'));
    assert_eq('moonshotai/kimi-k2.5:nitro', ModelConfig::stepModel('openrouter', 'sections'));
    assert_eq(null, ModelConfig::stepModel('openrouter', 'design-direction'));

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
    assert_eq('claude-opus-4-8', $models['design-direction']);
    assert_eq('claude-opus-4-8', $models['theme-json']);
    assert_eq('claude-opus-4-8', $models['sections']);
    assert_eq('claude-opus-4-8', $models['page-styles']);
    assert_eq('claude-opus-4-8', $models['fonts-php']);
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

test('StepDefaults applies OpenRouter step models while keeping K3 for creative direction', function () {
    putenv('LLM_PROVIDER=openrouter');
    try {
        $models = StepDefaults::models();
        assert_eq('moonshotai/kimi-k3', $models['design-direction']);
        assert_eq('moonshotai/kimi-k2.5:nitro', $models['theme-json']);
        assert_eq('moonshotai/kimi-k2.5:nitro', $models['sections']);
        assert_eq('moonshotai/kimi-k2.5:nitro', $models['page-styles']);
        assert_eq('moonshotai/kimi-k2.5:nitro', $models['fonts-php']);
    } finally {
        putenv('LLM_PROVIDER');
    }
});

test('global and per-step env models override provider step models', function () {
    putenv('LLM_PROVIDER=openrouter');
    putenv('LLM_MODEL=override-large');
    putenv('LLM_MODEL_SECTIONS=override-sections');
    try {
        $models = StepDefaults::models();
        assert_eq('override-large', $models['theme-json'], 'large-tier env beats provider step model');
        assert_eq('override-sections', $models['sections'], 'per-step env wins over everything');
        assert_eq('moonshotai/kimi-k2.5:nitro', $models['site-spec'], 'unoverridden small tier stays configured');
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL');
        putenv('LLM_MODEL_SECTIONS');
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
