#!/usr/bin/env bash
# Serve a block theme directory in WordPress Playground and print its URL.
# Mirrors PlaygroundRunner's invocation, without needing a Project.
#
#   tests/tools/serve-theme.sh <theme-dir> <theme-slug> <port>
#
# Runs in the foreground; kill it to stop the site. The URL is printed to
# stdout once the site answers.
set -euo pipefail
theme_dir=$(cd "$1" && pwd)
slug=$2
port=$3
repo=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d -t "playground-$slug")
blueprint="$tmp/blueprint.json"
cat > "$blueprint" <<JSON
{"landingPage":"/","steps":[{"step":"activateTheme","themeFolderName":"$slug"}]}
JSON
log="$tmp/playground.log"
"$repo/node_modules/.bin/wp-playground-cli" server --workers=6 --port="$port" \
  --mount="$theme_dir:/wordpress/wp-content/themes/$slug" --blueprint="$blueprint" >"$log" 2>&1 &
pid=$!
trap 'kill $pid 2>/dev/null; rm -rf "$tmp"' EXIT
for _ in $(seq 1 120); do
  if curl -fsS -o /dev/null "http://127.0.0.1:$port/" 2>/dev/null; then
    echo "http://127.0.0.1:$port/"
    wait $pid
    exit 0
  fi
  if ! kill -0 $pid 2>/dev/null; then
    echo "playground exited:" >&2; tail -20 "$log" >&2; exit 1
  fi
  sleep 1
done
echo "timeout waiting for playground" >&2; tail -20 "$log" >&2; exit 1
