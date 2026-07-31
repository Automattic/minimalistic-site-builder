#!/usr/bin/env bash

set -euo pipefail

readonly upstream_url='https://github.com/Automattic/blocks-engine.git'
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly repo_root
readonly vendor_dir="${repo_root}/lib/blocks-engine-php-transformer"

if [[ "$#" -gt 1 ]]; then
    echo "Usage: $0 [upstream-commit]" >&2
    exit 1
fi

if [[ "$#" -eq 1 ]]; then
    requested_commit="$1"
else
    requested_commit="$(<"${vendor_dir}/UPSTREAM_COMMIT")"
fi
readonly requested_commit

if [[ ! "${requested_commit}" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "Invalid upstream commit: ${requested_commit}" >&2
    exit 1
fi

requested_commit_normalized="$(printf '%s' "${requested_commit}" | tr '[:upper:]' '[:lower:]')"
readonly requested_commit_normalized

temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/blocks-engine-sync.XXXXXX")"
cleanup() {
    rm -rf -- "${temp_dir:?}"
}
trap cleanup EXIT INT TERM

readonly checkout_dir="${temp_dir}/blocks-engine"
git init "${checkout_dir}"
git -C "${checkout_dir}" remote add origin "${upstream_url}"
git -C "${checkout_dir}" fetch --depth 1 origin "${requested_commit}"
git -C "${checkout_dir}" checkout --detach FETCH_HEAD

readonly upstream_transformer="${checkout_dir}/php-transformer"
upstream_commit="$(git -C "${checkout_dir}" rev-parse --verify HEAD)"
readonly upstream_commit

if [[ ! "${upstream_commit}" =~ ^[0-9a-fA-F]{40}$ ]]; then
    echo "Invalid upstream commit: ${upstream_commit}" >&2
    exit 1
fi

upstream_commit_normalized="$(printf '%s' "${upstream_commit}" | tr '[:upper:]' '[:lower:]')"
readonly upstream_commit_normalized

if [[ "${upstream_commit_normalized}" != "${requested_commit_normalized}" ]]; then
    echo "Resolved upstream commit ${upstream_commit} does not match requested ${requested_commit}" >&2
    exit 1
fi

if [[ ! -d "${upstream_transformer}/src" || ! -f "${upstream_transformer}/VERSION" ]]; then
    echo "Missing upstream php-transformer source or VERSION" >&2
    exit 1
fi

mkdir -p "${vendor_dir}/src"
rsync -a --delete "${upstream_transformer}/src/" "${vendor_dir}/src/"
cp "${upstream_transformer}/VERSION" "${vendor_dir}/VERSION"
printf '%s\n' "${upstream_commit_normalized}" > "${vendor_dir}/UPSTREAM_COMMIT"

echo "Vendored php-transformer ${upstream_commit_normalized}"
