#!/bin/sh
# Emit a Claude Code JSON envelope while recording argv, stdin, and key presence.
exec php -r '
$stdin = stream_get_contents(STDIN);
$binary = $argv[1];
$args = array_slice($argv, 2);
$injection = "\"; rm -rf ~; echo \"";
if (in_array($injection, $args, true)) {
    touch($binary . ".canary");
}
$record = [
    "argv" => $args,
    "stdin" => $stdin,
    "anthropic_api_key" => getenv("ANTHROPIC_API_KEY"),
    "anthropic_auth_token" => getenv("ANTHROPIC_AUTH_TOKEN"),
];
$result = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
echo json_encode([
    "is_error" => false,
    "stop_reason" => "end_turn",
    "terminal_reason" => "completed",
    "result" => $result,
    "usage" => [
        "input_tokens" => 11,
        "output_tokens" => 5,
        "cache_creation_input_tokens" => 3,
        "cache_read_input_tokens" => 7,
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
' "$0" "$@"
