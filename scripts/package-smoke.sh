#!/usr/bin/env bash
set -euo pipefail

ARCHIVE="${1:-}"
VERSION="${2:-}"
OUTPUT_DIR="${3:-rishe-release-evidence}"

if [[ -z "${ARCHIVE}" || -z "${VERSION}" ]]; then
  echo "Usage: $0 <archive> <version> [output-dir]" >&2
  exit 2
fi
if [[ ! -r "${ARCHIVE}" ]]; then
  echo "Release archive is not readable: ${ARCHIVE}" >&2
  exit 1
fi

mkdir -p "${OUTPUT_DIR}"
OUTPUT_DIR="$(cd "${OUTPUT_DIR}" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT
ENTRIES="${WORK_DIR}/entries.txt"

unzip -Z1 "${ARCHIVE}" > "${ENTRIES}"
if [[ ! -s "${ENTRIES}" ]]; then
  echo "Release archive is empty." >&2
  exit 1
fi
if grep -Eq '(^/|(^|/)\.\.(/|$)|\\)' "${ENTRIES}"; then
  echo "Release archive contains an unsafe path." >&2
  exit 1
fi
if grep -Ev '^rishe(/|$)' "${ENTRIES}" | grep -q .; then
  echo "Release archive must contain exactly one rishe/ root." >&2
  exit 1
fi

for required in rishe/rishe.php rishe/composer.json rishe/vendor/autoload.php; do
  if ! grep -Fxq "${required}" "${ENTRIES}"; then
    echo "Release archive is missing ${required}." >&2
    exit 1
  fi
done

FORBIDDEN='^rishe/(\.git|\.github|tests|docs|scripts|dist|node_modules)(/|$)|^rishe/(phpunit\.xml\.dist|phpcs\.xml\.dist|composer\.lock|\.DS_Store)$|\.log$'
if grep -E "${FORBIDDEN}" "${ENTRIES}" > "${WORK_DIR}/forbidden.txt"; then
  echo "Release archive contains development-only files:" >&2
  cat "${WORK_DIR}/forbidden.txt" >&2
  exit 1
fi

unzip -q "${ARCHIVE}" -d "${WORK_DIR}/extract"
PLUGIN_DIR="${WORK_DIR}/extract/rishe"
if ! grep -Fq "Version: ${VERSION}" "${PLUGIN_DIR}/rishe.php"; then
  echo "Plugin header does not match release version ${VERSION}." >&2
  exit 1
fi
if ! grep -Fq "define('RISHE_VERSION', '${VERSION}')" "${PLUGIN_DIR}/rishe.php"; then
  echo "RISHE_VERSION does not match release version ${VERSION}." >&2
  exit 1
fi
if [[ ! -s "${PLUGIN_DIR}/vendor/autoload.php" ]]; then
  echo "Production Composer autoloader is missing." >&2
  exit 1
fi

PHP_FILES="$(find "${PLUGIN_DIR}" -type f -name '*.php' | wc -l | tr -d ' ')"
find "${PLUGIN_DIR}" -type f -name '*.php' -print0 | xargs -0 -n1 php -l > "${OUTPUT_DIR}/php-syntax.log"
FILE_COUNT="$(grep -Ev '/$' "${ENTRIES}" | wc -l | tr -d ' ')"
SIZE_BYTES="$(stat -c '%s' "${ARCHIVE}")"
SHA256="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"

cat > "${OUTPUT_DIR}/package.json" <<JSON
{
  "schema": 1,
  "archive": "$(basename "${ARCHIVE}")",
  "version": "${VERSION}",
  "sha256": "${SHA256}",
  "size_bytes": ${SIZE_BYTES},
  "file_count": ${FILE_COUNT},
  "php_file_count": ${PHP_FILES},
  "single_plugin_root": true,
  "development_files_absent": true,
  "production_autoloader_present": true,
  "php_syntax_valid": true,
  "certified_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

cat "${OUTPUT_DIR}/package.json"
