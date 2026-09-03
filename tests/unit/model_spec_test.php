<?php
declare(strict_types=1);

use Automattic\SiteBuild\ModelConfig;
use Automattic\SiteBuild\ModelSpec;
use Automattic\SiteBuild\StepDefaults;

/**
 * Unit tests for the optional `transport:` prefix on an LLM_MODEL_<STEP> value.
 */

test('a value with no transport prefix is the model, exactly as before', function () {
    foreach (['claude-opus-5', 'gpt-5.6-sol', 'zai-org/GLM-5.3-Flash', 'grok-4.6'] as $model) {
        assert_eq(['transport' => null, 'model' => $model], ModelSpec::parse($model));
    }
});

test('a known transport before the first colon splits; anything else is model text', function () {
    assert_eq(
        ['transport' => 'baseten', 'model' => 'zai-org/GLM-5.3-Flash'],
        ModelSpec::parse('baseten:zai-org/GLM-5.3-Flash'),
    );

    // THE case this rule exists for. `moonshotai/kimi-k2.5:nitro` is an
    // ordinary OpenRouter model id and is already the openrouter small tier.
    // Treating any colon as a transport separator would read the transport as
    // "moonshotai/kimi-k2.5" and silently route to the model "nitro".
    assert_eq(
        ['transport' => null, 'model' => 'moonshotai/kimi-k2.5:nitro'],
        ModelSpec::parse('moonshotai/kimi-k2.5:nitro'),
        'a colon inside a model id is not a transport separator',
    );

    // A prefix AND a colon-bearing model resolve together.
    assert_eq(
        ['transport' => 'openrouter', 'model' => 'moonshotai/kimi-k2.5:nitro'],
        ModelSpec::parse('openrouter:moonshotai/kimi-k2.5:nitro'),
    );

    // A leading segment that merely looks like a vendor is left alone: this is
    // a real Baseten model id, not the OpenAI transport.
    assert_eq(
        ['transport' => null, 'model' => 'openai/gpt-oss-120b'],
        ModelSpec::parse('openai/gpt-oss-120b'),
    );
});

test('the transport is matched case-insensitively and the model is passed through verbatim', function () {
    $spec = ModelSpec::parse('  BASETEN : zai-org/GLM-5.3-Flash  ');
    assert_eq('baseten', $spec['transport'], 'transport names normalize');
    // Baseten ids are case-sensitive, so the model must never be case-folded.
    assert_eq('zai-org/GLM-5.3-Flash', $spec['model'], 'the model keeps its exact casing');
});

test('a transport prefix with nothing after it is rejected, naming the variable', function () {
    foreach (['baseten:', 'openai:   '] as $bad) {
        $threw = null;
        try {
            ModelSpec::parse($bad, 'LLM_MODEL_SECTIONS');
        } catch (RuntimeException $e) {
            $threw = $e->getMessage();
        }
        assert_true($threw !== null, "rejected: '{$bad}'");
        assert_true(str_contains((string) $threw, 'LLM_MODEL_SECTIONS'), 'the message names the variable');
    }
});

test('every configured provider is also a transport that can be built', function () {
    // A provider is a whole model set; a transport is a wire client. They are
    // one-to-one now, and a provider added without a matching transport would
    // fail only at make_llm() time, deep in a run.
    foreach (ModelConfig::providerNames() as $provider) {
        assert_true(
            in_array($provider, ModelSpec::TRANSPORTS, true),
            "provider '{$provider}' has a transport make_llm() can build",
        );
    }
});

test('a transport prefix on a whole-tier override is refused, not taken literally', function () {
    // A tier spans many steps; moving all of them is what --provider is for.
    // Taken literally the value becomes a model id no provider has, and fails
    // deep in the run instead of here.
    foreach (['LLM_MODEL', 'LLM_MODEL_SMALL'] as $key) {
        putenv("{$key}=baseten:zai-org/GLM-5.3-Flash");
        try {
            $threw = null;
            try {
                StepDefaults::models();
            } catch (RuntimeException $e) {
                $threw = $e->getMessage();
            }
            assert_true($threw !== null, "{$key} rejects a transport prefix");
            assert_true(str_contains((string) $threw, 'LLM_MODEL_<STEP>'), 'the message points at the per-step form');
        } finally {
            putenv($key);
        }
    }

    // A bare model id is still the ordinary whole-tier override.
    putenv('LLM_PROVIDER=anthropic');
    putenv('LLM_MODEL=claude-haiku-4-5');
    try {
        assert_eq('claude-haiku-4-5', StepDefaults::models()['sections'], 'a bare tier override still works');
    } finally {
        putenv('LLM_MODEL');
        putenv('LLM_PROVIDER');
    }
});
