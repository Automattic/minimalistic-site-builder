<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Json\JsJsonEncoder;
use Automattic\SiteBuild\BlockSerializer\Json\JsonArray;
use Automattic\SiteBuild\BlockSerializer\Json\JsonBoolean;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNull;
use Automattic\SiteBuild\BlockSerializer\Json\JsonNumber;
use Automattic\SiteBuild\BlockSerializer\Json\JsonObject;
use Automattic\SiteBuild\BlockSerializer\Json\JsonString;
use Automattic\SiteBuild\BlockSerializer\Json\JsonUndefined;
use Automattic\SiteBuild\BlockSerializer\Json\JsonValue;

test('typed JSON preserves empty object and array identity recursively', function () {
    $root = JsonValue::parse('{"object":{},"array":[],"nested":[{},[]]}');
    assert_true($root instanceof JsonObject);
    assert_true($root->get('object') instanceof JsonObject);
    assert_true($root->get('array') instanceof JsonArray);
    $nested = $root->get('nested');
    assert_true($nested instanceof JsonArray);
    assert_true($nested->get(0) instanceof JsonObject);
    assert_true($nested->get(1) instanceof JsonArray);
    assert_true($root->toNative() instanceof stdClass);
    assert_true(is_array($root->get('array')->toNative()));
    assert_eq('{"object":{},"array":[],"nested":[{},[]]}', JsJsonEncoder::stringify($root));
});

test('typed JSON decoder follows JSON.parse number, duplicate-key, and negative-zero semantics', function () {
    $root = JsonValue::parse('{"a":1,"b":2,"a":3,"large":9007199254740993,"minus":-0}');
    assert_true($root instanceof JsonObject);
    assert_eq(['a', 'b', 'large', 'minus'], array_column($root->entries(), 'key'));
    assert_eq(3.0, $root->get('a')->toNative());
    assert_eq(9007199254740992.0, $root->get('large')->toNative(), 'JavaScript rounds integers to IEEE-754');
    $minus = $root->get('minus');
    assert_true($minus instanceof JsonNumber && $minus->isNegativeZero());
    assert_eq('{"a":3,"b":2,"large":9007199254740992,"minus":0}', JsJsonEncoder::stringify($root));
});

test('typed JSON rejects invalid syntax instead of silently coercing it', function () {
    foreach (['', '{', '{"a":}', '[1,]', '01', 'true false', '"unterminated'] as $invalid) {
        assert_eq(null, JsonValue::tryParse($invalid), "invalid vector: {$invalid}");
    }
    assert_throws(fn () => JsonValue::parse('{"a":}'));
});

test('JSON.stringify orders JavaScript array-index keys before insertion-ordered keys', function () {
    $value = JsonValue::parse(
        '{"b":1,"10":"ten","2":"two","01":"leading",'
        . '"4294967294":"max-index","4294967295":"ordinary","0":"zero","a":2}'
    );
    assert_true($value instanceof JsonObject);
    assert_eq(
        '{"0":"zero","2":"two","10":"ten","4294967294":"max-index",'
        . '"b":1,"01":"leading","4294967295":"ordinary","a":2}',
        JsJsonEncoder::stringify($value)
    );
});

test('Gutenberg comment encoding matches JSON.stringify replacement order', function () {
    $attributes = new JsonObject();
    $attributes->set('a', new JsonString('-- <tag> & "quoted"'));
    $attributes->set('bs', new JsonString('\\'));
    $attributes->set('bsQuote', new JsonString('\\"'));
    $attributes->set('unicode', new JsonString("café 'tea' 😀"));

    $encoded = JsJsonEncoder::serializeAttributes($attributes);
    assert_eq(
        '{"a":"\\u002d\\u002d \\u003ctag\\u003e \\u0026 \\u0022quoted\\u0022",'
        . '"bs":"\\u005c","bsQuote":"\\u005c\\u0022","unicode":"café \'tea\' 😀"}',
        $encoded
    );
    $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    assert_eq('-- <tag> & "quoted"', $decoded['a']);
    assert_eq('\\', $decoded['bs']);
    assert_eq('\\"', $decoded['bsQuote']);
});

test('JSON.stringify preserves controls and line separators like the pinned Node runtime', function () {
    $value = new JsonString(chr(8) . "\f\n\r\t\x00\x1f/\u{2028}\u{2029}");
    assert_eq(
        '"\\b\\f\\n\\r\\t\\u0000\\u001f/' . "\u{2028}\u{2029}" . '"',
        JsJsonEncoder::stringify($value)
    );
});

test('typed strings round-trip JavaScript lone UTF-16 surrogates', function () {
    foreach (['\\ud800', '\\udc00'] as $escape) {
        $value = JsonValue::parse('"' . $escape . '"');
        assert_true($value instanceof JsonString);
        assert_eq('"' . $escape . '"', JsJsonEncoder::stringify($value));
    }
    assert_eq(
        '"😀"',
        JsJsonEncoder::stringify(JsonValue::parse('"\\ud83d\\ude00"')),
        'a valid surrogate pair canonicalizes to its Unicode scalar'
    );
});

test('JSON.stringify applies undefined, non-finite, and cycle rules', function () {
    $object = new JsonObject();
    $object->set('keep', new JsonBoolean(true));
    $object->set('omit', new JsonUndefined());
    $object->set('nan', new JsonNumber(NAN));
    $object->set('infinity', new JsonNumber(INF));
    $object->set('array', new JsonArray([new JsonUndefined(), new JsonNumber(-INF), new JsonNull()]));
    assert_eq(
        '{"keep":true,"nan":null,"infinity":null,"array":[null,null,null]}',
        JsJsonEncoder::stringify($object)
    );
    assert_eq(null, JsJsonEncoder::stringify(new JsonUndefined()));

    $cycle = new JsonObject();
    $cycle->set('self', $cycle);
    assert_throws(fn () => JsJsonEncoder::stringify($cycle));
});

test('JavaScript number formatting matches committed exponent and rounding boundaries', function () {
    $vectors = [
        [0.0, '0'],
        [-0.0, '0'],
        [1.0, '1'],
        [0.000001, '0.000001'],
        [0.0000001, '1e-7'],
        [1.0e20, '100000000000000000000'],
        [1.0e21, '1e+21'],
        [1.2345678901234567, '1.2345678901234567'],
        [9007199254740992.0, '9007199254740992'],
        [1.7976931348623157e308, '1.7976931348623157e+308'],
        [5.0e-324, '5e-324'],
    ];
    foreach ($vectors as [$number, $expected]) {
        assert_eq($expected, JsJsonEncoder::stringify(new JsonNumber($number)), "number {$expected}");
        assert_eq($expected, JsJsonEncoder::stringifyNumber($number), "public number formatter {$expected}");
    }
});

test('JavaScript number formatting is independent of host serialize_precision', function () {
    $previous = ini_get('serialize_precision');
    ini_set('serialize_precision', '7');
    try {
        assert_eq(
            '1.2345678901234567',
            JsJsonEncoder::stringify(new JsonNumber(1.2345678901234567))
        );
        assert_eq('7', ini_get('serialize_precision'), 'encoder restores the host setting');
    } finally {
        if ($previous !== false) {
            ini_set('serialize_precision', $previous);
        }
    }
});

test('seeded finite-double formatting differential matches Node', function () {
    $node = [];
    $nodeExit = 1;
    exec('command -v node 2>/dev/null', $node, $nodeExit);
    if ($nodeExit !== 0 || ($node[0] ?? '') === '') {
        skip_test('Node is unavailable; committed number-boundary vectors still ran');
    }

    $helper = repo_path('bin/block-fixer/test/js-json-vectors.js');
    $command = escapeshellarg($node[0]) . ' ' . escapeshellarg($helper)
        . ' ' . escapeshellarg('0x243f6a8885a308d3') . ' 1024 2>&1';
    $lines = [];
    $exit = 1;
    exec($command, $lines, $exit);
    assert_eq(0, $exit, implode("\n", $lines));
    $payload = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    assert_eq(1024, $payload['count']);

    foreach ($payload['vectors'] as $index => $vector) {
        $bytes = hex2bin($vector['bits']);
        assert_true($bytes !== false);
        $native = unpack('Evalue', $bytes);
        $number = new JsonNumber($native['value']);
        assert_eq($vector['json'], JsJsonEncoder::stringify($number), "random vector {$index}: {$vector['bits']}");

        $object = new JsonObject();
        $object->set('n', $number);
        assert_eq(
            $vector['comment'],
            JsJsonEncoder::serializeAttributes($object),
            "comment vector {$index}: {$vector['bits']}"
        );
    }
});
