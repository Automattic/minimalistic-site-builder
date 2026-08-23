#!/bin/sh
exec php "$(dirname "$0")/grok-fixture.php" malformed "$0" "$@"
