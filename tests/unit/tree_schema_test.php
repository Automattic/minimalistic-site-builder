<?php
declare(strict_types=1);

use Automattic\SiteBuild\TreeGraph\Schema;

/** @return array<string,mixed> a minimal brief valid against schemas/tree/brief.schema.json */
function tree_schema_minimal_brief(): array
{
    return [
        'version'       => 1,
        'identity'      => ['site_title' => 'Golden Crumb', 'tagline' => 'Bread worth waking for'],
        'art_direction' => 'Warm flour-dusted light over dark rye tones; a bakery with editorial calm and one amber accent.',
        'style'         => [
            'artistic'  => 'Bauhaus',
            'ui'        => 'Flat Design',
            'rationale' => 'Geometry and clarity serve a bakery of precision.',
        ],
        'axis'          => ['anchor' => 'left', 'argument' => 'Editorial attitude reads ranged-left.'],
        'language'      => 'English',
        'palette'       => [
            ['name' => 'Cream', 'color' => '#F5F0E6', 'role' => 'background'],
            ['name' => 'Rye', 'color' => '#2B1D12', 'role' => 'text'],
        ],
        'pages'         => [[
            'slug'       => 'home',
            'title'      => 'Home',
            'front_page' => true,
            'sections'   => [[
                'id'         => 'hero',
                'role'       => 'hero',
                'copy_notes' => 'A statement about bread and the morning it belongs to.',
                'design'     => ['band' => 'base', 'layout' => 'stack'],
            ]],
        ]],
        'custom_blocks'   => [],
        'schema_packages' => [],
        'navigation'      => ['items' => []],
        'footer'          => ['intent' => 'A quiet closing band.', 'items' => []],
    ];
}

test('Schema: a type mismatch reports once and stops deeper checks', function () {
    $issues = Schema::validate(['type' => 'object', 'required' => ['a']], 'not an object');
    assert_eq(1, count($issues));
    assert_eq('', $issues[0]['path']);
    assert_contains('expected object, got string', $issues[0]['message']);
});

test('Schema: missing required properties are reported with the parent path', function () {
    $issues = Schema::validate(
        ['type' => 'object', 'required' => ['name', 'size'], 'properties' => []],
        ['name' => 'x'],
        '/steps/0',
    );
    assert_eq(1, count($issues));
    assert_eq('/steps/0', $issues[0]['path']);
    assert_contains('missing required property "size"', $issues[0]['message']);
});

test('Schema: additionalProperties false rejects unknown keys with a pointer', function () {
    $issues = Schema::validate(
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false],
        ['a' => 'x', 'extra' => 1],
    );
    assert_eq(1, count($issues));
    assert_eq('/extra', $issues[0]['path']);
    assert_eq('unexpected property', $issues[0]['message']);
});

test('Schema: enum membership, with int/float treated as one number type', function () {
    assert_eq([], Schema::validate(['enum' => ['a', 'b']], 'b'));
    assert_eq([], Schema::validate(['enum' => [1, 2]], 1.0), 'JSON has one number type');
    $issues = Schema::validate(['enum' => ['a', 'b']], 'c');
    assert_eq(1, count($issues));
    assert_contains('expected one of a, b', $issues[0]['message']);
});

test('Schema: pattern and minLength apply to strings', function () {
    assert_eq([], Schema::validate(['type' => 'string', 'pattern' => '^[a-z0-9-]+$'], 'hero-band'));
    $issues = Schema::validate(['type' => 'string', 'pattern' => '^[a-z0-9-]+$'], 'Hero Band');
    assert_contains('does not match', $issues[0]['message']);
    $issues = Schema::validate(['type' => 'string', 'minLength' => 10], 'short');
    assert_contains('shorter than minLength 10', $issues[0]['message']);
});

test('Schema: oneOf requires exactly one matching alternative', function () {
    $schema = ['oneOf' => [['type' => 'string'], ['type' => 'number']]];
    assert_eq([], Schema::validate($schema, 'x'));
    assert_eq([], Schema::validate($schema, 3));
    $issues = Schema::validate($schema, true);
    assert_eq(1, count($issues));
    assert_contains('must match exactly one of 2 alternatives (matched 0)', $issues[0]['message']);

    $both = ['oneOf' => [['type' => 'string'], ['type' => 'string', 'minLength' => 1]]];
    $issues = Schema::validate($both, 'ab');
    assert_contains('(matched 2)', $issues[0]['message']);
});

test('Schema: minItems and per-item validation on arrays', function () {
    $schema = ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string']];
    $issues = Schema::validate($schema, ['only']);
    assert_contains('fewer than minItems 2', $issues[0]['message']);
    $issues = Schema::validate($schema, ['a', 3]);
    assert_eq('/1', $issues[0]['path']);
    assert_contains('expected string, got number', $issues[0]['message']);
});

test('Schema: an empty PHP array counts as an empty object and an empty array', function () {
    assert_eq([], Schema::validate(['type' => 'object'], []));
    assert_eq([], Schema::validate(['type' => 'array'], []));
});

test('Schema: the real brief schema accepts a minimal brief and reports a dropped style', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/tree/brief.schema.json'), true);
    assert_true(is_array($schema), 'brief schema parses');

    assert_eq([], Schema::validate($schema, tree_schema_minimal_brief()));

    $broken = tree_schema_minimal_brief();
    unset($broken['style']);
    $issues = Schema::validate($schema, $broken);
    assert_eq(1, count($issues));
    assert_contains('missing required property "style"', $issues[0]['message']);
});

test('Schema: the brief schema rejects a bad palette hex and a bad band enum', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/tree/brief.schema.json'), true);

    $brief = tree_schema_minimal_brief();
    $brief['palette'][0]['color'] = 'F5F0E6';
    $issues = Schema::validate($schema, $brief);
    assert_eq(1, count($issues));
    assert_eq('/palette/0/color', $issues[0]['path']);

    $brief = tree_schema_minimal_brief();
    $brief['pages'][0]['sections'][0]['design']['band'] = 'loud';
    $issues = Schema::validate($schema, $brief);
    assert_eq(1, count($issues));
    assert_eq('/pages/0/sections/0/design/band', $issues[0]['path']);
});
