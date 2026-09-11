#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
site_foundry_root=${SITE_FOUNDRY_ROOT:-"$(dirname "$repo_root")/site-foundry"}
proof_dir=${MSB_PATTERN_PROOF_DIR:-/tmp/msb-pattern-proof-initial}
keep_running=0
headed=0

for arg in "$@"; do
	case "$arg" in
		--keep-running) keep_running=1 ;;
		--headed) headed=1 ;;
		*) printf 'Usage: %s [--keep-running] [--headed]\n' "$0"; exit 2 ;;
	esac
done

evidence_dir=${SITE_FOUNDRY_EVIDENCE_DIR:-$(mktemp -d /tmp/msb-site-foundry-e2e.XXXXXX)}
mkdir -p "$evidence_dir"

if [[ ! -f "$site_foundry_root/site-foundry.php" ]]; then
	printf 'Site Foundry checkout not found: %s\nSet SITE_FOUNDRY_ROOT to its checkout.\n' "$site_foundry_root"
	exit 1
fi
if [[ ! -x "$site_foundry_root/node_modules/.bin/wp-env" ]]; then
	printf 'Site Foundry dependencies are missing. Run: cd %s && pnpm install\n' "$site_foundry_root"
	exit 1
fi
if [[ ! -f "$site_foundry_root/vendor/autoload.php" ]]; then
	printf 'Site Foundry PHP dependencies are missing. Run: cd %s && composer install\n' "$site_foundry_root"
	exit 1
fi

override="$site_foundry_root/.wp-env.override.json"
php -r '
    $path = $argv[1];
    $config = is_file($path)
        ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        : [];
    $config["plugins"] = ["."];
    $config["config"]["SITE_FOUNDRY_LOCAL_PATTERN_BUNDLE"] = "/var/www/html/wp-content/msb-pattern-proof";
    $config["mappings"]["wp-content/msb-pattern-proof"] = $argv[2];
    $config["mappings"]["wp-content/msb-pattern-tests"] = $argv[3];
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $tmp = $path . ".tmp";
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
        throw new RuntimeException("Could not update " . $path);
    }
' "$override" "$proof_dir" "$repo_root/tests"

started=0
cleanup() {
	if [[ $started -eq 1 && $keep_running -eq 0 ]]; then
		(
			cd "$site_foundry_root"
			pnpm exec wp-env stop >/dev/null
		)
	fi
}
trap cleanup EXIT

php "$repo_root/tests/pattern-proof.php" "$proof_dir"
node "$repo_root/tests/integration/pattern-content-oracle.js" "$proof_dir/pattern-output.json"

cd "$site_foundry_root"
composer dump-autoload
pnpm build
pnpm exec wp-env stop >/dev/null 2>&1 || true
pnpm exec wp-env start
started=1

playwright_args=(test tests/e2e/local-pattern/msb-pattern.spec.js --output "$evidence_dir/playwright")
if [[ $headed -eq 1 ]]; then
	playwright_args+=(--headed)
fi
SITE_FOUNDRY_RECORD_VIDEO=1 \
SITE_FOUNDRY_EVIDENCE_DIR="$evidence_dir" \
	pnpm exec playwright "${playwright_args[@]}"

site_url=$(php -r '$r=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $r["site_url"];' "$evidence_dir/result.json")
video=$(find "$evidence_dir/playwright" -type f -name '*.webm' -print -quit)
if command -v ffmpeg >/dev/null 2>&1 && [[ -n "$video" ]]; then
	mp4="$evidence_dir/site-foundry-pattern-e2e.mp4"
	ffmpeg -y -loglevel error -i "$video" -c:v libx264 -pix_fmt yuv420p -movflags +faststart "$mp4"
	video="$mp4"
fi

printf 'Pattern E2E passed through the Site Foundry plugin: %s\n' "$site_url"
printf 'Browser recording: %s\n' "$video"
printf 'Evidence metadata: %s/result.json\n' "$evidence_dir"
if [[ $keep_running -eq 1 ]]; then
	printf 'WordPress remains running. Stop it with: cd %s && pnpm exec wp-env stop\n' "$site_foundry_root"
fi
