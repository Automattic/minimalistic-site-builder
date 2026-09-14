#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
site_foundry_root=${SITE_FOUNDRY_ROOT:-"$(dirname "$repo_root")/site-foundry"}
keep_running=0
headed=0

for arg in "$@"; do
	case "$arg" in
		--keep-running) keep_running=1 ;;
		--headed) headed=1 ;;
		*) printf 'Usage: %s [--keep-running] [--headed]\n' "$0"; exit 2 ;;
	esac
done

if [[ ! -d "$site_foundry_root/.git" || ! -f "$site_foundry_root/site-foundry.php" ]]; then
	printf 'Authoritative Site Foundry checkout not found: %s\n' "$site_foundry_root"
	printf 'Clone git@github.a8c.com:Automattic/site-foundry.git and set SITE_FOUNDRY_ROOT if needed.\n'
	exit 1
fi
if ! docker info >/dev/null 2>&1; then
	printf 'Docker is not running. Start Docker Desktop and retry.\n'
	exit 1
fi

evidence_dir=${SITE_FOUNDRY_EVIDENCE_DIR:-$(mktemp -d /tmp/site-foundry-real-msb.XXXXXX)}
fixture_dir="$evidence_dir/fixture"
mkdir -p "$fixture_dir"

cat > "$fixture_dir/brands.json" <<'JSON'
[
  {
    "name": "Northstar Brand",
    "context": "Clear, direct and practical. Northstar helps product teams make complex services useful.",
    "config": {
      "settings": {"color":{"palette":[
        {"slug":"base","name":"Base","color":"#fff7ed"},
        {"slug":"contrast","name":"Contrast","color":"#172554"},
        {"slug":"accent-1","name":"Signal coral","color":"#f05a47"},
        {"slug":"accent-6","name":"Soft sky","color":"#dbeafe"}
      ]}},
      "styles": {
        "color":{"background":"var:preset|color|base","text":"var:preset|color|contrast"},
        "elements":{"heading":{"color":{"text":"var:preset|color|contrast"}}}
      }
    }
  },
  {
    "name": "Evergreen Brand",
    "context": "Calm and grounded with an editorial voice.",
    "config": {
      "settings":{"color":{"palette":[
        {"slug":"base","name":"Base","color":"#f4f1e8"},
        {"slug":"contrast","name":"Contrast","color":"#243830"},
        {"slug":"accent-1","name":"Accent","color":"#73956f"}
      ]}},
      "styles":{"color":{"background":"var:preset|color|base","text":"var:preset|color|contrast"}}
    }
  }
]
JSON

cat > "$site_foundry_root/.wp-env.override.json" <<JSON
{
  "plugins": ["."],
  "config": {
    "SITE_FOUNDRY_LOCAL_MSB_PATH": "/var/www/html/wp-content/msb-local",
    "SITE_FOUNDRY_LOCAL_MSB_FIXTURES_PATH": "/var/www/html/wp-content/plugins/site-foundry/tests/e2e/local-pattern/fixtures"
  },
  "mappings": {
    "wp-content/msb-local": "$repo_root",
    "wp-content/site-foundry-e2e": "$fixture_dir"
  }
}
JSON

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

if [[ ! -f "$site_foundry_root/vendor/autoload.php" ]]; then
	composer --working-dir="$site_foundry_root" install --no-interaction
else
	composer --working-dir="$site_foundry_root" dump-autoload
fi
if [[ ! -x "$site_foundry_root/node_modules/.bin/wp-env" ]]; then
	(cd "$site_foundry_root" && pnpm install --frozen-lockfile)
fi

(
	cd "$site_foundry_root"
	pnpm build
	pnpm exec wp-env start
	pnpm exec wp-env run cli wp theme install twentytwentyfour --force
	pnpm exec wp-env run cli wp site-foundry import-brands /var/www/html/wp-content/site-foundry-e2e/brands.json --skip-logos
)
started=1

playwright_args=(test tests/e2e/local-pattern/msb-pattern.spec.js --output "$evidence_dir/playwright")
if [[ $headed -eq 1 ]]; then
	playwright_args+=(--headed)
fi

(
	cd "$site_foundry_root"
	SITE_FOUNDRY_RECORD_VIDEO=1 \
	SITE_FOUNDRY_EVIDENCE_DIR="$evidence_dir" \
		pnpm exec playwright "${playwright_args[@]}"
)

site_url=$(php -r '$r=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $r["site_url"];' "$evidence_dir/result.json")
generation_engine=$(
	cd "$site_foundry_root"
	pnpm exec wp-env run cli wp --url="$site_url" option get site_foundry_generation_engine 2>/dev/null \
		| grep -E '^minimalistic-site-builder$'
)
generation_hash=$(
	cd "$site_foundry_root"
	pnpm exec wp-env run cli wp --url="$site_url" option get site_foundry_generation_input_hash 2>/dev/null \
		| grep -E '^[a-f0-9]{64}$'
)
if [[ "$generation_engine" != "minimalistic-site-builder" || -z "$generation_hash" ]]; then
	printf 'The imported site has no valid MSB generation receipt.\n'
	exit 1
fi
(
	cd "$site_foundry_root"
	pnpm exec wp-env run cli wp --url="$site_url" option get stylesheet
	pnpm exec wp-env run cli wp --url="$site_url" post list --post_type=page --post_status=publish --fields=post_name,post_title --format=json
	pnpm exec wp-env run cli wp --url="$site_url" post list --post_type=wp_global_styles --post_status=publish --fields=post_name,post_title --format=json
)

video=$(find "$evidence_dir/playwright" -type f -name '*.webm' -print -quit)
if command -v ffmpeg >/dev/null 2>&1 && [[ -n "$video" ]]; then
	mp4="$evidence_dir/site-foundry-msb-e2e.mp4"
	ffmpeg -y -loglevel error -i "$video" -c:v libx264 -pix_fmt yuv420p -movflags +faststart "$mp4"
	video="$mp4"
fi

printf 'Real Site Foundry + MSB E2E passed: %s\n' "$site_url"
printf 'MSB input hash: %s\n' "$generation_hash"
printf 'Browser recording: %s\n' "$video"
printf 'Evidence metadata: %s/result.json\n' "$evidence_dir"
if [[ $keep_running -eq 1 ]]; then
	printf 'WordPress remains available at http://localhost:8888\n'
	printf 'Stop it with: cd %s && pnpm exec wp-env stop\n' "$site_foundry_root"
fi
