<?php
declare(strict_types=1);

use Automattic\SiteBuild\LlmLogger;
use Automattic\SiteBuild\Step;

/**
 * Unit tests for LlmLogger: filename uniqueness (the section-hero -> section-hero-02
 * rule), filename sanitising, the formatted header/request/response layout, and
 * that an actual log file is written to the configured directory.
 */

test('slug lowercases and strips unsafe characters', function () {
    assert_eq('section-hero', LlmLogger::slug('Section Hero'));
    assert_eq('theme-json', LlmLogger::slug('theme-json'));
    assert_eq('a-b', LlmLogger::slug('  a/b  '));
    assert_eq('request', LlmLogger::slug('!!!'), 'empty after sanitising falls back to "request"');
});

test('uniquePath uses the plain name first, then -02, -03', function () {
    with_temp_dir('builder_ll_', function (string $dir) {
        $p1 = LlmLogger::uniquePath($dir, 'section-hero');
        assert_eq("{$dir}/section-hero.log", $p1);
        file_put_contents($p1, 'x');

        $p2 = LlmLogger::uniquePath($dir, 'section-hero');
        assert_eq("{$dir}/section-hero-02.log", $p2);
        file_put_contents($p2, 'x');

        $p3 = LlmLogger::uniquePath($dir, 'section-hero');
        assert_eq("{$dir}/section-hero-03.log", $p3);
    });
});

test('format renders summary header, request, and response', function () {
    $request = [
        'model'    => 'claude-opus-4-8',
        'messages' => [['role' => 'user', 'content' => 'Build a hero section']],
    ];
    $response = [
        'text' => '<!-- wp:group --><!-- /wp:group -->',
        'input' => 120,
        'output' => 340,
        'stop_reason' => 'end_turn',
    ];

    $out = LlmLogger::format('section-hero', $request, $response, 12.5);

    assert_contains('Step / label : section-hero', $out);
    assert_contains('Model        : claude-opus-4-8', $out);
    assert_contains('Time         : 12.50s', $out);
    assert_contains('Tokens       : 120 in + 340 out = 460 total', $out);
    assert_contains('Stop reason  : end_turn', $out);
    assert_contains('REQUEST', $out);
    assert_contains('Build a hero section', $out, 'full request body is included');
    assert_contains('RESPONSE', $out);
    assert_contains('<!-- wp:group -->', $out, 'full response text is included');
});

test('format shows cache-read tokens with thousands separators', function () {
    $response = [
        'text' => 'ok',
        'input' => 19339,
        'output' => 16000,
        'cache_read_input_tokens' => 18102,
        'cache_creation_input_tokens' => 0,
    ];

    $out = LlmLogger::format('section-hero', ['model' => 'm'], $response, 1.0);

    assert_contains('Tokens       : 19,339 in (18,102 cache-read) + 16,000 out = 35,339 total', $out);
});

test('format shows cache-write tokens and orders both cache components deterministically', function () {
    $writeOnly = LlmLogger::format('section-hero', ['model' => 'm'], [
        'text' => 'ok',
        'input' => 2345,
        'output' => 6789,
        'cache_creation_input_tokens' => 1234,
    ], 1.0);
    assert_contains('Tokens       : 2,345 in (1,234 cache-write) + 6,789 out = 9,134 total', $writeOnly);

    $both = LlmLogger::format('section-hero', ['model' => 'm'], [
        'text' => 'ok',
        'input' => 25000,
        'output' => 5000,
        'cache_read_input_tokens' => 18000,
        'cache_creation_input_tokens' => 2048,
    ], 1.0);
    assert_contains('Tokens       : 25,000 in (18,000 cache-read, 2,048 cache-write) + 5,000 out = 30,000 total', $both);
});

test('format uses thousands separators when cache tokens are explicitly zero', function () {
    $response = [
        'text' => 'ok',
        'input' => 19339,
        'output' => 16000,
        'cache_read_input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
    ];

    $out = LlmLogger::format('section-hero', ['model' => 'm'], $response, 1.0);

    assert_contains('Tokens       : 19,339 in + 16,000 out = 35,339 total', $out);
    assert_true(strpos($out, 'cache-read') === false, 'zero cache-read tokens are omitted');
    assert_true(strpos($out, 'cache-write') === false, 'zero cache-write tokens are omitted');
});

test('format renders message content as readable multi-line text, not escaped JSON', function () {
    $request = [
        'model'    => 'claude-opus-4-8',
        'system'   => "You are the design lead.\nBe tasteful.",
        'messages' => [['role' => 'user', 'content' => "Line one\nLine two"]],
    ];
    $out = LlmLogger::format('section-hero', $request, ['text' => 'ok', 'input' => 1, 'output' => 2], 1.0);

    assert_contains("### SYSTEM\nYou are the design lead.\nBe tasteful.", $out, 'system prompt keeps real newlines');
    assert_contains("### MESSAGE 1 [USER]\nLine one\nLine two", $out, 'user content keeps real newlines');
    assert_true(strpos($out, 'Line one\nLine two') === false, 'no escaped \\n in the rendered prompt');
    assert_contains('"model": "claude-opus-4-8"', $out, 'scalar params still shown as JSON');
});

test('renderContent marks every content block boundary and cached prefixes', function () {
    $content = [
        ['type' => 'text', 'text' => 'hello', 'cache_control' => ['type' => 'ephemeral']],
        ['type' => 'tool_use', 'name' => 'get_theme', 'input' => ['slug' => 'x']],
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png']],
    ];
    $out = LlmLogger::renderContent($content);

    assert_contains("--- CONTENT BLOCK 1 [TEXT] ---\n[cached prefix]\nhello", $out);
    assert_contains('--- CONTENT BLOCK 2 [TOOL_USE] ---', $out);
    assert_contains('--- CONTENT BLOCK 3 [IMAGE] ---', $out);
    assert_contains('hello', $out);
    assert_contains('[tool_use: get_theme]', $out);
    assert_contains('"slug": "x"', $out);
    assert_contains('[image: image/png]', $out);
});

test('renderContent keeps boundaries after cache markers are stripped and leaves strings plain', function () {
    $stripped = LlmLogger::renderContent([
        ['type' => 'text', 'text' => 'shared'],
        ['type' => 'text', 'text' => 'varying'],
    ]);

    assert_contains("--- CONTENT BLOCK 1 [TEXT] ---\nshared", $stripped);
    assert_contains("--- CONTENT BLOCK 2 [TEXT] ---\nvarying", $stripped);
    assert_true(!str_contains($stripped, '[cached prefix]'), 'stripped content has no cache marker');
    assert_eq("plain\nstring", LlmLogger::renderContent("plain\nstring"));
});

test('log writes a file to the configured directory and is reversible', function () {
    with_temp_dir('builder_ll_', function (string $tmp) {
        $dir = "{$tmp}/llms";
        LlmLogger::setDir($dir);

        try {
            $request = ['model' => 'claude-haiku-4-5', 'messages' => [['role' => 'user', 'content' => 'hi']]];
            $written = LlmLogger::log('theme-json', $request, ['text' => 'OK', 'input' => 1, 'output' => 2], 0.5);

            $path = "{$dir}/01-theme-json.log";
            assert_eq($path, $written, 'log returns the evidence path for failure diagnostics');
            assert_true(file_exists($path), 'first log file is prefixed 01-');
            assert_contains('Step / label : theme-json', (string) file_get_contents($path));
        } finally {
            LlmLogger::setDir(null); // restore default for other tests
        }
    });
});

test('log prefixes files with the call order, restarting per run', function () {
    with_temp_dir('builder_ll_', function (string $tmp) {
        $dir = "{$tmp}/ordered";
        LlmLogger::setDir($dir);

        try {
            $resp = ['text' => 'x', 'input' => 0, 'output' => 0];
            LlmLogger::log('site-spec', ['model' => 'm'], $resp, 0.0);
            LlmLogger::log('theme-json', ['model' => 'm'], $resp, 0.0);
            LlmLogger::log('section-hero', ['model' => 'm'], $resp, 0.0);

            assert_true(file_exists("{$dir}/01-site-spec.log"), 'first call is 01-');
            assert_true(file_exists("{$dir}/02-theme-json.log"), 'second call is 02-');
            assert_true(file_exists("{$dir}/03-section-hero.log"), 'third call is 03-');

            // setDir starts a fresh run, so numbering restarts at 01.
            LlmLogger::setDir($dir);
            LlmLogger::log('site-spec', ['model' => 'm'], $resp, 0.0);
            assert_true(file_exists("{$dir}/01-site-spec-02.log"), 'new run restarts at 01 (collision-suffixed)');
        } finally {
            LlmLogger::setDir(null);
        }
    });
});

test('format renders the error instead of a response for a failed call', function () {
    $out = LlmLogger::format('section-hero', ['model' => 'claude-opus-4-8'], ['text' => '', 'input' => 0, 'output' => 0], 19.0, 'HTTP 400: invalid_request');

    assert_contains('Status       : FAILED', $out);
    assert_contains('ERROR', $out);
    assert_contains('HTTP 400: invalid_request', $out, 'the failure message is included');
    assert_true(strpos($out, 'RESPONSE') === false, 'a failed call has no RESPONSE section');
});

test('format marks a successful call OK', function () {
    $out = LlmLogger::format('header', ['model' => 'm'], ['text' => 'ok', 'input' => 1, 'output' => 2], 0.5);
    assert_contains('Status       : OK', $out);
    assert_contains('RESPONSE', $out);
});

test('log writes a -failed file for a failed call', function () {
    with_temp_dir('builder_ll_', function (string $tmp) {
        $dir = "{$tmp}/failed";
        LlmLogger::setDir($dir);

        try {
            LlmLogger::log('section-hero', ['model' => 'm'], ['text' => '', 'input' => 0, 'output' => 0], 19.0, 'HTTP 400: invalid_request');

            $path = "{$dir}/01-section-hero-failed.log";
            assert_true(file_exists($path), 'a failed call is logged as <label>-failed.log');
            assert_contains('Status       : FAILED', (string) file_get_contents($path));
        } finally {
            LlmLogger::setDir(null);
        }
    });
});

test('log is a no-op when no project directory is set', function () {
    LlmLogger::setDir(null);
    // Must not throw and must not write anywhere (no repo-root fallback).
    $written = LlmLogger::log('orphan', ['model' => 'm'], ['text' => 't', 'input' => 0, 'output' => 0], 0.0);
    assert_eq(null, $written, 'logging without a dir is a silent no-op');
});

test('log is a no-op when disabled', function () {
    with_temp_dir('builder_ll_', function (string $tmp) {
        $dir = "{$tmp}/disabled";
        LlmLogger::setDir($dir);
        LlmLogger::setEnabled(false);

        try {
            $written = LlmLogger::log('nope', ['model' => 'm'], ['text' => 't', 'input' => 0, 'output' => 0], 0.0);

            assert_true(!file_exists("{$dir}/nope.log"), 'nothing written while disabled');
            assert_eq(null, $written, 'disabled logging has no evidence path');
        } finally {
            LlmLogger::setEnabled(true);
            LlmLogger::setDir(null);
        }
    });
});
