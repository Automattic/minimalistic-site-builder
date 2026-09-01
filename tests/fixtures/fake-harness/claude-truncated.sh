#!/bin/sh
# Always report output-limit termination so zero-retry behavior is observable.
exec php -r '
$stdin = stream_get_contents(STDIN);
echo json_encode([
    "is_error" => false,
    "stop_reason" => "max_tokens",
    "result" => $stdin,
    "usage" => ["input_tokens" => 2, "output_tokens" => 5],
], JSON_THROW_ON_ERROR);
' -- "$@"
