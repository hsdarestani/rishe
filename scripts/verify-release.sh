#!/usr/bin/env bash
set -euo pipefail

ARCHIVE="${1:-}"
CHECKSUM_FILE="${2:-${ARCHIVE}.sha256}"
SIGNATURE_FILE="${3:-}"
PUBLIC_KEY_FILE="${4:-}"

if [[ -z "${ARCHIVE}" || ! -f "${ARCHIVE}" ]]; then
  echo "Release archive is required." >&2
  exit 2
fi
if [[ ! -f "${CHECKSUM_FILE}" ]]; then
  echo "Checksum file is missing." >&2
  exit 1
fi

(
  cd "$(dirname "${ARCHIVE}")"
  sha256sum -c "$(basename "${CHECKSUM_FILE}")"
)

if [[ -n "${SIGNATURE_FILE}" || -n "${PUBLIC_KEY_FILE}" ]]; then
  if [[ ! -f "${SIGNATURE_FILE}" || ! -f "${PUBLIC_KEY_FILE}" ]]; then
    echo "Both signature and public key are required for signature verification." >&2
    exit 1
  fi
  openssl dgst -sha256 -verify "${PUBLIC_KEY_FILE}" -signature "${SIGNATURE_FILE}" "${CHECKSUM_FILE}"
fi

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT
unzip -q "${ARCHIVE}" -d "${WORK_DIR}"
PLUGIN_DIR="${WORK_DIR}/rishe"

[[ -f "${PLUGIN_DIR}/rishe.php" ]] || { echo "Plugin bootstrap is missing." >&2; exit 1; }
[[ -f "${PLUGIN_DIR}/vendor/autoload.php" ]] || { echo "Production autoloader is missing." >&2; exit 1; }
[[ ! -d "${PLUGIN_DIR}/tests" ]] || { echo "Tests must not be included in the production package." >&2; exit 1; }
[[ ! -d "${PLUGIN_DIR}/.git" ]] || { echo "Git metadata must not be included." >&2; exit 1; }
find "${PLUGIN_DIR}" -path '*/vendor/*' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "Release package verified: ${ARCHIVE}"
