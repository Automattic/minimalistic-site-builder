#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
site_foundry_root=${SITE_FOUNDRY_ROOT:-"$(dirname "$repo_root")/site-foundry"}
proof_dir=${MSB_PATTERN_PROOF_DIR:-/tmp/msb-pattern-proof-initial}
keep_running=0

if [[ ${1:-} == "--keep-running" ]]; then
	keep_running=1
elif [[ $# -gt 0 ]]; then
	printf 'Usage: %s [--keep-running]\n' "$0"
	exit 2
fi

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
if [[ ! -f "$override" ]] || ! php -r '
    $config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $mappings = $config["mappings"] ?? [];
    exit(
        ($mappings["wp-content/msb-pattern-proof"] ?? null) === $argv[2]
        && ($mappings["wp-content/msb-pattern-tests"] ?? null) === $argv[3]
        ? 0 : 1
    );
' "$override" "$proof_dir" "$repo_root/tests"; then
	printf '%s\n' \
		"$override must map:" \
		"  wp-content/msb-pattern-proof => $proof_dir" \
		"  wp-content/msb-pattern-tests => $repo_root/tests"
	exit 1
fi

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
pnpm exec wp-env start
started=1

result=$(pnpm exec wp-env run cli wp eval-file \
	/var/www/html/wp-content/msb-pattern-tests/integration/site-foundry-pattern-proof.php \
	/var/www/html/wp-content/msb-pattern-proof)
printf '%s\n' "$result"

site_url=$(php -r '
    $output = stream_get_contents(STDIN);
    if (preg_match("~https?://localhost:[0-9]+/pattern-proof-[0-9]+~", $output, $match) !== 1) {
        exit(1);
    }
    echo $match[0];
' <<< "$result")

rendered=$(curl -fsS "$site_url/")
for expected in \
	'Make room for good work' \
	'Approved <strong>protected</strong> wording.' \
	'12 Harbor Street'; do
	if [[ "$rendered" != *"$expected"* ]]; then
		printf 'Rendered homepage is missing: %s\n' "$expected"
		exit 1
	fi
done

printf 'Pattern E2E passed: %s/\n' "$site_url"
if [[ $keep_running -eq 1 ]]; then
	printf 'WordPress remains running. Stop it with: cd %s && pnpm exec wp-env stop\n' "$site_foundry_root"
fi
