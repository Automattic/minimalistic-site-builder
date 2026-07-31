<?php
declare(strict_types=1);

use Automattic\SiteBuild\PromptRenderer;

/**
 * PromptRenderer::fill — plain {{placeholder}} substitution. Every placeholder
 * must resolve; anything left over is a wiring error and throws.
 */

test('fill substitutes plain placeholders', function () {
    assert_eq('Hello Ada', PromptRenderer::fill('Hello {{name}}', ['name' => 'Ada']));
});

test('fill substitutes a placeholder with an empty value', function () {
    assert_eq('A', PromptRenderer::fill('A{{suffix}}', ['suffix' => '']));
});

test('fill throws on an unknown placeholder', function () {
    assert_throws(fn () => PromptRenderer::fill('Hi {{missing}}', []));
});

test('fill does not reinterpret placeholder-shaped text inside a value', function () {
    assert_eq(
        'Hello Acme {{PLACEHOLDER}}',
        PromptRenderer::fill('Hello {{name}}', ['name' => 'Acme {{PLACEHOLDER}}'])
    );
});

test('fill leaves single-brace JSON untouched', function () {
    $tpl = 'config {"sizeSlug":"large"} for {{name}}';
    assert_eq('config {"sizeSlug":"large"} for hero', PromptRenderer::fill($tpl, ['name' => 'hero']));
});
