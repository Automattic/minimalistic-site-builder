#!/bin/sh
# Fake `codex exec`: record argv/stdin/schema, write the final answer to -o,
# and emit the measured JSONL event shape with deliberately disagreeing item text.
exec php -r '
$binary = $argv[1];
$args = array_slice($argv, 2);
$stdin = stream_get_contents(STDIN);

$valueAfter = static function (string $flag) use ($args): ?string {
    $index = array_search($flag, $args, true);
    return $index !== false && isset($args[$index + 1]) ? $args[$index + 1] : null;
};
$outputPath = $valueAfter("-o");
$schemaPath = $valueAfter("--output-schema");
$schemaBytes = $schemaPath !== null && is_file($schemaPath)
    ? file_get_contents($schemaPath)
    : null;

$injection = "\"; rm -rf ~; echo \"";
if (in_array($injection, $args, true)) {
    touch($binary . ".canary");
}

$record = [
    "argv" => $args,
    "stdin" => $stdin,
    "output_path" => $outputPath,
    "schema_path" => $schemaPath,
    "schema_bytes" => $schemaBytes,
];
file_put_contents(
    $binary . ".calls.jsonl",
    json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX,
);

if ($outputPath === null || file_put_contents($outputPath, $stdin) !== strlen($stdin)) {
    fwrite(STDERR, "could not write fake codex output\n");
    exit(8);
}

$events = [
    ["type" => "thread.started", "thread_id" => "fake-thread"],
    ["type" => "turn.started"],
    [
        "type" => "item.completed",
        "item" => ["type" => "agent_message", "text" => "EVENT_STREAM_MUST_NOT_WIN"],
    ],
    [
        "type" => "turn.completed",
        "usage" => [
            "input_tokens" => 17357,
            "cached_input_tokens" => 11008,
            "cache_write_input_tokens" => 0,
            "output_tokens" => 5,
            "reasoning_output_tokens" => 0,
        ],
    ],
];
foreach ($events as $event) {
    echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
' "$0" "$@"
