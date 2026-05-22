#!/usr/bin/env bash
#
# Build a distributable caw-plugin-builder.zip.
#
# The repository keeps vendor/ out of version control, but a WordPress admin
# installing the plugin from a zip cannot run Composer. This script produces an
# installable artifact: the caw-plugin-builder/ folder with production-only
# dependencies bundled and development-only files (listed in
# caw-plugin-builder/.distignore) removed.
#
# Used both for local builds and by .github/workflows/release.yml, so a release
# artifact is byte-reproducible from a clean checkout.
#
# Usage: bin/build-zip.sh
# Output: build/caw-plugin-builder.zip

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="caw-plugin-builder"
SRC="${REPO_ROOT}/${PLUGIN_SLUG}"
BUILD_DIR="${REPO_ROOT}/build"
STAGE="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP="${BUILD_DIR}/${PLUGIN_SLUG}.zip"

if [ ! -d "${SRC}" ]; then
	echo "error: plugin directory not found at ${SRC}" >&2
	exit 1
fi

echo "==> Staging ${PLUGIN_SLUG}"
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE}"
# Copy everything (including dotfiles); vendor/ and any VCS metadata are
# dropped — vendor/ is rebuilt below with production-only dependencies.
cp -a "${SRC}/." "${STAGE}/"
rm -rf "${STAGE}/vendor" "${STAGE}/.git"

echo "==> Installing production dependencies"
composer install \
	--working-dir="${STAGE}" \
	--no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Removing development-only files"
# .distignore entries must be literal paths relative to the plugin root.
# Anything with a traversal segment or an absolute path is rejected so a
# careless (or hostile) entry cannot delete outside the staging tree.
if [ -f "${STAGE}/.distignore" ]; then
	while IFS= read -r pattern || [ -n "${pattern}" ]; do
		pattern="${pattern%$'\r'}"
		[ -z "${pattern}" ] && continue
		case "${pattern}" in \#*) continue ;; esac
		rel="${pattern#/}"
		[ -z "${rel}" ] && continue
		case "${rel}" in
			*..*|/*)
				echo "    skipping unsafe .distignore entry: ${pattern}" >&2
				continue
				;;
		esac
		rm -rf -- "${STAGE:?}/${rel}"
	done < "${STAGE}/.distignore"
fi

echo "==> Zipping"
( cd "${BUILD_DIR}" && zip -qr "${ZIP}" "${PLUGIN_SLUG}" )

echo "==> Built ${ZIP}"
