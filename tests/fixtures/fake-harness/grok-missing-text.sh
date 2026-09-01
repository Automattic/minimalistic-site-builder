#!/bin/sh
exec php "$(dirname "$0")/grok-fixture.php" missing-text "$0" "$@"
