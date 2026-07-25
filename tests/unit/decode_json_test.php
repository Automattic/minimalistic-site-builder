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

test('decodeJson escapes unescaped quotes inside string values', function () {
    // The model writes quoted phrases in prose without escaping them. Escaping
    // recovers the value verbatim rather than spending an LLM repair round-trip.
    $data = AnthropicClient::decodeJson('{"content_notes":"Headline "Reserve Now" in gold"}');
    assert_eq('Headline "Reserve Now" in gold', $data['content_notes']);

    $valid = AnthropicClient::decodeJson('{"content_notes":"Headline \\"Reserve Now\\" in gold"}');
    assert_eq('Headline "Reserve Now" in gold', $valid['content_notes']);
});

test('decodeJson recovers the observed page-plan payload with unescaped quotes', function () {
    $raw = file_get_contents(__DIR__ . '/../fixtures/json/page-plan-unescaped-quotes.txt');
    $data = AnthropicClient::decodeJson((string) $raw);

    assert_true(is_array($data), 'payload decodes');
    assert_eq(6, count($data['sections']));
    assert_eq('hero', $data['sections'][0]['slug']);
    assert_eq('closing-cta', $data['sections'][5]['slug']);
    assert_contains(
        '"Professional Grooming Services" or "Specialized Walrus Care."',
        $data['sections'][0]['content_notes'],
    );
});

test('quote escaping leaves valid JSON byte-identical', function () {
    // The pass only runs after a strict decode fails, but a payload that already
    // parses must never be reshaped by it.
    $data = AnthropicClient::decodeJson('{"a":"x, y","b":["p","q"],"c":{"d":"e"},"e":"trailing:"}');
    assert_eq('x, y', $data['a']);
    assert_eq(['p', 'q'], $data['b']);
    assert_eq(['d' => 'e'], $data['c']);
    assert_eq('trailing:', $data['e']);
});

test('quote escaping does not rescue a missing separator', function () {
    // A quote followed by another quote is not a literal, so escaping cannot
    // turn this into valid JSON — it stays a repair-worthy failure.
    $result = AnthropicClient::decodeJsonResult('{"title":"Reservations" "sections":[]}');
    assert_true($result['data'] === null, 'missing comma is still not guessed at locally');
    assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained for repair');
});

test('quote escaping rejects literal-before-comma when no valid member follows', function () {
    // Unlike the lossy sibling below, `then left.` after the comma cannot be
    // read as a valid member, so this payload stays invalid.
    $result = AnthropicClient::decodeJsonResult('{"a":"He said "hi", then left."}');
    assert_true($result['data'] === null, 'ambiguous literal quotes are left to the repair pass');
});

test('quote escaping documents known lossy literal-before-comma boundary', function () {
    // Any future follow-set change must surface here instead of silently changing
    // this known lossy result.
    $data = AnthropicClient::decodeJson('{"a":"quote: "end", "b":"c"}');

    assert_true(is_array($data), 'known lossy boundary decodes');
    assert_eq('quote: "end', $data['a']);
    assert_eq('c', $data['b']);
});

test('decodeJson rejects a top-level scalar with an actionable error', function () {
    $result = AnthropicClient::decodeJsonResult('"valid JSON, wrong shape"');
    assert_true($result['data'] === null, 'JSON envelopes must be objects or arrays');
    assert_contains('top-level JSON value', (string) $result['error']);
});
