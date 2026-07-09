<?php
declare(strict_types=1);

use Automattic\SiteBuild\AnthropicClient;

test('decodeJson parses plain JSON', function () {
    $data = AnthropicClient::decodeJson('{"a": 1, "b": [2, 3]}');
    assert_eq(1, $data['a']);
    assert_eq([2, 3], $data['b']);
});

test('decodeJson strips ```json fences', function () {
    $data = AnthropicClient::decodeJson("```json\n{\"a\": 1}\n```");
    assert_eq(1, $data['a']);
});

test('decodeJson tolerates trailing commas before } and ]', function () {
    // The exact failure mode seen from the model: a comma after the last object
    // property and after the last array element.
    $raw = "```json\n{\n  \"directions\": [\n    {\n      \"title\": \"One\",\n      \"description\": \"first\",\n    },\n    {\n      \"title\": \"Two\",\n      \"description\": \"second\",\n    }\n  ]\n}\n```";
    $data = AnthropicClient::decodeJson($raw);
    assert_true(is_array($data['directions']), 'directions decoded');
    assert_eq(2, count($data['directions']));
    assert_eq('One', $data['directions'][0]['title']);
});

test('decodeJson does not corrupt commas inside string values', function () {
    // A comma followed by a brace INSIDE a string must survive the repair pass,
    // even when there is a real trailing comma elsewhere in the payload.
    $data = AnthropicClient::decodeJson('{"note": "warm, }earthy tones", "list": [1, 2,],}');
    assert_eq('warm, }earthy tones', $data['note']);
    assert_eq([1, 2], $data['list']);
});

test('decodeJson returns null for unrecoverable text', function () {
    assert_true(AnthropicClient::decodeJson('not json at all') === null, 'null on garbage');
});
