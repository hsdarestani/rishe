#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"
OUTPUT_DIR="${2:-dist}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "${VERSION}" ]]; then
  echo "Usage: $0 <version> [output-dir]" >&2
  exit 2
fi
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]]; then
  echo "Invalid semantic version: ${VERSION}" >&2
  exit 2
fi
if ! grep -q "Version: ${VERSION}" "${ROOT}/rishe.php"; then
  echo "rishe.php plugin header does not match ${VERSION}" >&2
  exit 1
fi
if ! grep -q "define('RISHE_VERSION', '${VERSION}')" "${ROOT}/rishe.php"; then
  echo "RISHE_VERSION does not match ${VERSION}" >&2
  exit 1
fi
if [[ ! -r "${ROOT}/composer.lock" ]]; then
  echo "composer.lock is required before building a release. Run composer install first." >&2
  exit 1
fi
LOCK_SHA256="$(sha256sum "${ROOT}/composer.lock" | awk '{print $1}')"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "${BUILD_DIR}"' EXIT
PLUGIN_DIR="${BUILD_DIR}/rishe"
mkdir -p "${PLUGIN_DIR}" "${ROOT}/${OUTPUT_DIR}"

rsync -a --delete --exclude-from="${ROOT}/.distignore" "${ROOT}/" "${PLUGIN_DIR}/"
cp "${ROOT}/composer.lock" "${PLUGIN_DIR}/composer.lock"
(
  cd "${PLUGIN_DIR}"
  composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --classmap-authoritative
  find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
  rm -f composer.lock
)

ARCHIVE="${ROOT}/${OUTPUT_DIR}/rishe-${VERSION}.zip"
rm -f "${ARCHIVE}" "${ARCHIVE}.sha256" "${ARCHIVE}.sig" "${ARCHIVE}.manifest.json"
(
  cd "${BUILD_DIR}"
  TZ=UTC find rishe -exec touch -h -d '2000-01-01 00:00:00 UTC' {} +
  zip -X -q -r "${ARCHIVE}" rishe
)

SHA256="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"
printf '%s  %s\n' "${SHA256}" "$(basename "${ARCHIVE}")" > "${ARCHIVE}.sha256"
SIZE="$(stat -c '%s' "${ARCHIVE}")"
cat > "${ARCHIVE}.manifest.json" <<JSON
{
  "schema": 1,
  "name": "rishe-${VERSION}.zip",
  "version": "${VERSION}",
  "sha256": "${SHA256}",
  "size_bytes": ${SIZE},
  "composer_lock_sha256": "${LOCK_SHA256}",
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "source_commit": "${GITHUB_SHA:-unknown}"
}
JSON

if [[ -n "${RELEASE_SIGNING_KEY_PATH:-}" ]]; then
  openssl dgst -sha256 -sign "${RELEASE_SIGNING_KEY_PATH}" -out "${ARCHIVE}.sig" "${ARCHIVE}.sha256"
fi

echo "${ARCHIVE}"
