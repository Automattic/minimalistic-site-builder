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

test('decodeJsonResult leaves missing separators for model-driven repair', function () {
    $cases = [
        '{"title":"Reservations" "sections":[]}',
        '{"sections":["Hero" "Menu","About"]}',
        '{"sections":["Hero" true,"About"]}',
    ];
    foreach ($cases as $raw) {
        $result = AnthropicClient::decodeJsonResult($raw);
        assert_true($result['data'] === null, 'missing separator is not guessed at locally');
        assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained for repair');
    }
});

test('decodeJson escapes unescaped quotes inside string values', function () {
    // The model writes quoted phrases in prose without escaping them. Escaping
    // recovers the value verbatim rather than spending an LLM repair round-trip.
    $data = AnthropicClient::decodeJson('{"content_notes":"Headline "Reserve Now" in gold"}');
    assert_eq(['content_notes' => 'Headline "Reserve Now" in gold'], $data);

    $valid = AnthropicClient::decodeJson('{"content_notes":"Headline \\"Reserve Now\\" in gold"}');
    assert_eq(['content_notes' => 'Headline "Reserve Now" in gold'], $valid);
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

test('quote escaping preserves quoted prose inside an array string', function () {
    $data = AnthropicClient::decodeJson('{"labels":["Say "hello" warmly","Plain"]}');
    assert_eq(['labels' => ['Say "hello" warmly', 'Plain']], $data);
});

test('quote escaping rejects an inner quote at an ambiguous comma boundary', function () {
    $result = AnthropicClient::decodeJsonResult('{"a":"He said "hi", then left."}');
    assert_true($result['data'] === null, 'ambiguous comma boundary is left to model-driven repair');
    assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained');
});

test('quote escaping never consumes the outer boundary after a literal comma', function () {
    // This differs from valid JSON only by the two missing inner-quote escapes.
    // Local repair must fail closed rather than turn the outer quote into the
    // opening byte of a renamed next key.
    $result = AnthropicClient::decodeJsonResult(
        '{"content_notes":"Use "Reserve Now",","purpose":"Convert"}'
    );
    assert_true($result['data'] === null, 'literal-before-comma is left to model-driven repair');
    assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained');
});

test('quote escaping rejects an ambiguous quote with no distinct outer boundary', function () {
    $result = AnthropicClient::decodeJsonResult('{"a":"quote: "end", "b":"c"}');
    assert_true($result['data'] === null, 'ambiguous quote is left to model-driven repair');
    assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained');
});

test('quote escaping never swallows plausible members into a repaired value', function () {
    $cases = [
        '{"a":"x "y","b":true","safe":1}',
        '{"a":"x "y","b":"z"","safe":true}',
        '{"a":"x "y","b":{"n":1,"m":2}","safe":1}',
        '["x "y",123",false]',
        '["a" 1{} "b"]',
        '{"x":["a" true{} "b"],"safe":1}',
    ];
    foreach ($cases as $raw) {
        $result = AnthropicClient::decodeJsonResult($raw);
        assert_true($result['data'] === null, 'plausible sibling remains structural');
        assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained');
    }
});

test('quote escaping never rewrites object keys', function () {
    $result = AnthropicClient::decodeJsonResult('{"na"me":"Acme","language":"en"}');
    assert_true($result['data'] === null, 'malformed key is left to model-driven repair');
    assert_true(is_string($result['error']) && $result['error'] !== '', 'parser error is retained');
});

test('quote repair establishes string boundaries before stripping trailing commas', function () {
    $data = AnthropicClient::decodeJson(
        '{"note":"Render "items: [one, ]" verbatim","list":[1,2,],}'
    );
    assert_eq([
        'note' => 'Render "items: [one, ]" verbatim',
        'list' => [1, 2],
    ], $data);
});

test('decodeJson rejects a top-level scalar with an actionable error', function () {
    $result = AnthropicClient::decodeJsonResult('"valid JSON, wrong shape"');
    assert_true($result['data'] === null, 'JSON envelopes must be objects or arrays');
    assert_contains('top-level JSON value', (string) $result['error']);
});
