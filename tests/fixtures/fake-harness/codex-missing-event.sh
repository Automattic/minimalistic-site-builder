#!/bin/sh
# Write the -o answer but omit the required turn.completed usage event.
exec php -r '
$args = array_slice($argv, 1);
$index = array_search("-o", $args, true);
$path = $index !== false && isset($args[$index + 1]) ? $args[$index + 1] : null;
if ($path !== null) {
    file_put_contents($path, stream_get_contents(STDIN));
}
echo json_encode([
    "type" => "item.completed",
    "item" => ["type" => "agent_message", "text" => "event only"],
], JSON_THROW_ON_ERROR), "\n";
' -- "$@"
