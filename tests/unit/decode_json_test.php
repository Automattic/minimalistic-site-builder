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

test('decodeJson strips a BOM and CRLF fenced envelope', function () {
    $data = AnthropicClient::decodeJson("\xEF\xBB\xBF```json  \r\n{\r\n  \"a\": 1\r\n}\r\n```  ");
    assert_eq(1, $data['a']);
});

test('decodeJson accepts a complete bare fence but not surrounding prose', function () {
    assert_eq(['ok' => true], AnthropicClient::decodeJson("```\n{\"ok\":true}\n```"));
    assert_true(
        AnthropicClient::decodeJson("Here is the result:\n```json\n{\"ok\":true}\n```") === null,
        'prose around a JSON fragment is not silently discarded',
    );
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

test('decodeJsonResult reports the observed missing-separator failure', function () {
    $result = AnthropicClient::decodeJsonResult('{"title":"Reservations" "sections":[]}');

    assert_true($result['data'] === null, 'missing comma is not guessed at locally');
    assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained for repair');
});

test('decodeJsonResult reports unescaped quotes without corrupting valid strings', function () {
    $invalid = AnthropicClient::decodeJsonResult('{"content_notes":"Headline "Reserve Now" in gold"}');
    assert_true($invalid['data'] === null, 'unescaped inner quotes remain invalid');
    assert_true(is_string($invalid['error']) && $invalid['error'] !== '', 'parser error is retained for repair');

    $valid = AnthropicClient::decodeJson('{"content_notes":"Headline \\"Reserve Now\\" in gold"}');
    assert_eq('Headline "Reserve Now" in gold', $valid['content_notes']);
});

test('decodeJson rejects a top-level scalar with an actionable error', function () {
    $result = AnthropicClient::decodeJsonResult('"valid JSON, wrong shape"');
    assert_true($result['data'] === null, 'JSON envelopes must be objects or arrays');
    assert_contains('top-level JSON value', (string) $result['error']);
});
