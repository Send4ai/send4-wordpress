#!/usr/bin/env bash
# Build the zip that gets uploaded to a site or to wordpress.org.
set -euo pipefail

cd "$(dirname "$0")/.."

# Refuses a zip whose three version strings disagree; see the script.
VERSION="$(bin/check-version.sh)"

BUILD_DIR="build"
STAGE="${BUILD_DIR}/send4"

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE}"

# rsync honours .distignore the way the wordpress.org deploy actions do.
rsync -a --exclude-from=.distignore ./ "${STAGE}/"

# A copy of each of our own assets with the version in its name, which
# Send4_Assets::url() prefers. `?ver=` does not reach a CDN that ignores the
# query string; a new file name does. The vendored library is left alone: it is
# byte for byte upstream's, and changes only when ITI_VERSION does.
for asset in admin/css/*.css admin/js/*.js public/css/*.css public/js/*.js; do
	[ -f "${STAGE}/${asset}" ] || continue
	cp "${STAGE}/${asset}" "${STAGE}/${asset%.*}.${VERSION}.${asset##*.}"
done

( cd "${BUILD_DIR}" && zip -qr "send4.zip" "send4" )

echo "Built ${BUILD_DIR}/send4.zip (version ${VERSION})"
