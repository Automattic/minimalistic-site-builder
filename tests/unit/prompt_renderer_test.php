<?php
declare(strict_types=1);

/**
 * PromptRenderer::fill — plain {{placeholder}} substitution plus optional
 * {{#section}}...{{/section}} blocks that are kept only when their var is
 * present and non-empty.
 */

test('fill substitutes plain placeholders', function () {
    assert_eq('Hello Ada', PromptRenderer::fill('Hello {{name}}', ['name' => 'Ada']));
});

test('fill throws on an unknown placeholder', function () {
    assert_throws(fn () => PromptRenderer::fill('Hi {{missing}}', []));
});

test('fill keeps a section when its var is non-empty', function () {
    assert_eq('A. Style: bold', PromptRenderer::fill('A{{#style}}. Style: {{style}}{{/style}}', ['style' => 'bold']));
});

test('fill drops a section (body included) when its var is empty or absent', function () {
    assert_eq('A', PromptRenderer::fill('A{{#style}}. Style: {{style}}{{/style}}', ['style' => '']));
    assert_eq('A', PromptRenderer::fill('A{{#style}}. Style: {{style}}{{/style}}', []));
});

test('fill resolves nested sections', function () {
    $tpl = '{{#outer}}[{{#inner}}{{inner}}{{/inner}}]{{/outer}}';
    assert_eq('[x]', PromptRenderer::fill($tpl, ['outer' => 'y', 'inner' => 'x']));
    // Outer kept, inner dropped -> brackets remain but inner body is gone.
    assert_eq('[]', PromptRenderer::fill($tpl, ['outer' => 'y', 'inner' => '']));
    // Outer dropped -> whole block (including inner) gone.
    assert_eq('', PromptRenderer::fill($tpl, ['outer' => '', 'inner' => 'x']));
});

test('fill throws on an unbalanced section tag', function () {
    assert_throws(fn () => PromptRenderer::fill('A{{#style}}. Style: x', ['style' => 'bold']));
});

test('fill leaves single-brace JSON untouched', function () {
    $tpl = 'config {"sizeSlug":"large"} for {{name}}';
    assert_eq('config {"sizeSlug":"large"} for hero', PromptRenderer::fill($tpl, ['name' => 'hero']));
});
