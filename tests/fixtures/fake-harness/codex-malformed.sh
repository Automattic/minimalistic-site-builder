#!/bin/sh
# Write the -o answer, then emit malformed JSONL so parse failure exercises cleanup.
exec php -r '
$args = array_slice($argv, 1);
$index = array_search("-o", $args, true);
$path = $index !== false && isset($args[$index + 1]) ? $args[$index + 1] : null;
if ($path !== null) {
    file_put_contents($path, stream_get_contents(STDIN));
}
echo "this is not a JSONL event\n";
' -- "$@"
