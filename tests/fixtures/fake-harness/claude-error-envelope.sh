#!/bin/sh
# Claude may report an API error in a JSON envelope while exiting successfully.
echo 'diagnostic from error envelope' >&2
printf '%s' '{"is_error":true,"terminal_reason":"api_error","result":"","usage":{}}'
