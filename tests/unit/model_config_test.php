<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;
use Automattic\SiteBuild\StepDefaults;

/**
 * Provider-aware model resolution: ModelConfig reads config/models.json, and
 * StepDefaults maps each step to the active provider's design/code/small tier
 * while env overrides (LLM_MODEL_<STEP>, LLM_MODEL_<TIER>, LLM_MODEL) still win.
 */

test('ModelConfig reads the packaged provider matrix', function () {
    assert_eq('anthropic', ModelConfig::defaultProvider());
    foreach (['anthropic', 'openai', 'xai'] as $p) {
        assert_true(ModelConfig::hasProvider($p), "has {$p}");
    }
    assert_true(!ModelConfig::hasProvider('nope'), 'unknown provider is absent');

    assert_eq('gpt-5.5', ModelConfig::tierModel('openai', 'design'));
    assert_eq('gpt-5.5', ModelConfig::tierModel('openai', 'code'));
    assert_eq('gpt-5.4-mini', ModelConfig::tierModel('openai', 'small'));
    assert_eq('claude-opus-5', ModelConfig::tierModel('anthropic', 'design'));
    assert_eq('claude-sonnet-5', ModelConfig::tierModel('anthropic', 'code'));
    assert_eq('claude-haiku-4-5', ModelConfig::tierModel('anthropic', 'small'));

    $tiers = ModelConfig::stepTiers();
    assert_eq('small', $tiers['site-spec']);
    assert_eq('design', $tiers['design-direction']);
    assert_eq('code', $tiers['sections']);
});

test('ModelConfig tierModel falls back to a configured tier when one is omitted', function () {
    ModelConfig::useConfig([
        'default_provider' => 'solo',
        'providers' => ['solo' => ['design' => 'big-model']],
        'step_tiers' => ['sections' => 'code', 'site-spec' => 'small'],
    ]);
    try {
        assert_eq('big-model', ModelConfig::tierModel('solo', 'design'));
        // Neither 'code' nor 'small' configured → both fall back to 'design'.
        assert_eq('big-model', ModelConfig::tierModel('solo', 'code'));
        assert_eq('big-model', ModelConfig::tierModel('solo', 'small'));
    } finally {
        ModelConfig::useConfig(null);
    }
});

test('ModelConfig tierModel prefers the nearest configured tier', function () {
    ModelConfig::useConfig([
        'default_provider' => 'pair',
        'providers' => ['pair' => ['design' => 'big-model', 'small' => 'tiny-model']],
        'step_tiers' => ['sections' => 'code'],
    ]);
    try {
        // No 'code' model: the code steps borrow the design model, not the small
        // one — a missing tier must never silently downgrade quality.
        assert_eq('big-model', ModelConfig::tierModel('pair', 'code'));
    } finally {
        ModelConfig::useConfig(null);
    }
});

test('ModelConfig tierModel rejects an unknown tier name', function () {
    assert_throws(function () {
        ModelConfig::tierModel('anthropic', 'large');
    });
});

test('StepDefaults default (anthropic) splits design, code and small tiers', function () {
    // No LLM_PROVIDER / LLM_MODEL set → config default provider.
    putenv('LLM_PROVIDER');
    putenv('LLM_MODEL');
    putenv('LLM_MODEL_DESIGN');
    putenv('LLM_MODEL_CODE');
    putenv('LLM_MODEL_SMALL');
    $models = StepDefaults::models();

    assert_eq('claude-haiku-4-5', $models['refine-prompt']);
    assert_eq('claude-haiku-4-5', $models['site-spec']);
    assert_eq('claude-haiku-4-5', $models['design-direction-seeds']);
    assert_eq('claude-haiku-4-5', $models['page-plan']);
    // Design: the steps that decide what the site looks like.
    assert_eq('claude-opus-5', $models['design-direction-judge']);
    assert_eq('claude-opus-5', $models['design-direction']);
    assert_eq('claude-opus-5', $models['theme-json']);
    // Code: the steps that emit block markup, CSS and PHP.
    assert_eq('claude-sonnet-5', $models['sections']);
    assert_eq('claude-sonnet-5', $models['page-styles']);
    assert_eq('claude-sonnet-5', $models['custom-motion']);
    assert_eq('claude-sonnet-5', $models['fonts-php']);
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
        // design + code tiers → gpt-5.5 (one provider, one big model)
        assert_eq('gpt-5.5', $models['design-direction']);
        assert_eq('gpt-5.5', $models['sections']);
        assert_eq('gpt-5.5', $models['theme-json']);
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
        assert_eq('gpt-5.5-pro', $models['sections'], 'per-step override wins on the code tier');
        assert_eq('gpt-5.4-mini', $models['refine-prompt'], 'untouched step keeps provider small tier');
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL_SITE_SPEC');
        putenv('LLM_MODEL_SECTIONS');
    }
});

test('LLM_MODEL covers design + code, LLM_MODEL_SMALL the small tier', function () {
    putenv('LLM_PROVIDER=openai');
    putenv('LLM_MODEL=gpt-5.5-pro');
    putenv('LLM_MODEL_SMALL=gpt-5.4-nano');
    try {
        assert_eq('gpt-5.5-pro', StepDefaults::model());
        assert_eq('gpt-5.4-nano', StepDefaults::smallModel());
        $models = StepDefaults::models();
        assert_eq('gpt-5.5-pro', $models['design-direction'], 'design tier follows LLM_MODEL');
        assert_eq('gpt-5.5-pro', $models['sections'], 'code tier follows LLM_MODEL');
        assert_eq('gpt-5.4-nano', $models['site-spec'], 'small tier follows LLM_MODEL_SMALL');
    } finally {
        putenv('LLM_PROVIDER');
        putenv('LLM_MODEL');
        putenv('LLM_MODEL_SMALL');
    }
});

test('LLM_MODEL_DESIGN and LLM_MODEL_CODE split the two big tiers apart', function () {
    putenv('LLM_MODEL=gpt-5.5-pro');
    putenv('LLM_MODEL_CODE=gpt-5.4-codex');
    try {
        $models = StepDefaults::models();
        assert_eq('gpt-5.5-pro', $models['design-direction'], 'design falls through to LLM_MODEL');
        assert_eq('gpt-5.4-codex', $models['sections'], 'the tier override beats LLM_MODEL');
        assert_eq('gpt-5.4-codex', $models['fonts-php']);
    } finally {
        putenv('LLM_MODEL');
        putenv('LLM_MODEL_CODE');
    }

    putenv('LLM_MODEL_DESIGN=claude-opus-5-preview');
    try {
        $models = StepDefaults::models();
        assert_eq('claude-opus-5-preview', $models['theme-json']);
        assert_eq('claude-sonnet-5', $models['sections'], 'the code tier keeps its provider default');
    } finally {
        putenv('LLM_MODEL_DESIGN');
    }
});
