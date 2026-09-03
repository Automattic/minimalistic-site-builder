#!/bin/sh
# Return controllable JSON content so batch recovery retries are observable.
exec php -r '
function increment_counter(string $path): int {
    $handle = fopen($path, "c+");
    if ($handle === false || !flock($handle, LOCK_EX)) {
        fwrite(STDERR, "counter lock failed\n");
        exit(9);
    }
    $raw = stream_get_contents($handle);
    $count = (int) ($raw === false ? "0" : $raw) + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) $count);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $count;
}

$stdin = stream_get_contents(STDIN);
$binary = $argv[1];
if (str_contains($stdin, "T14_REPAIR")) {
    $attempt = increment_counter($binary . ".repair-count");
    $result = $attempt === 1 ? "not json" : json_encode(["repaired" => true], JSON_THROW_ON_ERROR);
} elseif (str_contains($stdin, "T14_ALWAYS_BAD")) {
    increment_counter($binary . ".persistent-count");
    $result = "still not json";
} else {
    $result = json_encode(["good" => true], JSON_THROW_ON_ERROR);
}

echo json_encode([
    "is_error" => false,
    "stop_reason" => "end_turn",
    "result" => $result,
    "usage" => ["input_tokens" => 2, "output_tokens" => 1],
], JSON_THROW_ON_ERROR);
' "$0" "$@"
