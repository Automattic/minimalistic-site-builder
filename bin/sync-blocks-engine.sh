#!/usr/bin/env bash

set -euo pipefail

readonly upstream_url='https://github.com/Automattic/blocks-engine.git'
readonly upstream_branch='trunk'
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly repo_root
readonly vendor_dir="${repo_root}/lib/blocks-engine-php-transformer"

temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/blocks-engine-sync.XXXXXX")"
cleanup() {
    rm -rf -- "${temp_dir:?}"
}
trap cleanup EXIT INT TERM

git clone \
    --depth 1 \
    --branch "${upstream_branch}" \
    --single-branch \
    "${upstream_url}" \
    "${temp_dir}/blocks-engine"

readonly upstream_transformer="${temp_dir}/blocks-engine/php-transformer"
upstream_commit="$(git -C "${temp_dir}/blocks-engine" rev-parse --verify HEAD)"
readonly upstream_commit

if [[ ! "${upstream_commit}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Invalid upstream commit: ${upstream_commit}" >&2
    exit 1
fi

if [[ ! -d "${upstream_transformer}/src" || ! -f "${upstream_transformer}/VERSION" ]]; then
    echo "Missing upstream php-transformer source or VERSION" >&2
    exit 1
fi

mkdir -p "${vendor_dir}/src"
rsync -a --delete "${upstream_transformer}/src/" "${vendor_dir}/src/"
cp "${upstream_transformer}/VERSION" "${vendor_dir}/VERSION"
printf '%s\n' "${upstream_commit}" > "${vendor_dir}/UPSTREAM_COMMIT"

echo "Vendored php-transformer ${upstream_commit}"
