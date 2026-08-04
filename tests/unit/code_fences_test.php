<?php
declare(strict_types=1);

use Automattic\SiteBuild\CodeFences;

test('code fences strips a fence with a language tag', function () {
    assert_eq('body { color: red; }', CodeFences::strip("```css\nbody { color: red; }\n```"));
});

test('code fences leaves unfenced text intact', function () {
    assert_eq('body { color: red; }', CodeFences::strip("  body { color: red; }\n"));
});

test('code fences strips a leading UTF-8 BOM', function () {
    assert_eq('body { color: red; }', CodeFences::strip("\xEF\xBB\xBF```css\nbody { color: red; }\n```"));
});

test('code fences tolerates CRLF line endings around the fence', function () {
    assert_eq('body { color: red; }', CodeFences::strip("```css\r\nbody { color: red; }\r\n```"));
});
