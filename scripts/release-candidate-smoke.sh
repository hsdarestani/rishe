#!/usr/bin/env bash
set -euo pipefail

OUTPUT_DIR="${1:-dist-release-candidate}"
EVIDENCE_DIR="${2:-rishe-release-evidence}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION="$(php -r '$source=file_get_contents($argv[1]); if (!preg_match("/define\\(\x27RISHE_VERSION\x27, \x27([^\x27]+)\x27\\)/", $source, $matches)) { fwrite(STDERR, "Unable to resolve RISHE_VERSION.\n"); exit(1); } echo $matches[1];' "${ROOT}/rishe.php")"
mkdir -p "${ROOT}/${OUTPUT_DIR}" "${ROOT}/${EVIDENCE_DIR}"
KEY_DIR="$(mktemp -d)"
trap 'rm -rf "${KEY_DIR}"' EXIT

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "${KEY_DIR}/private.pem" >/dev/null 2>&1
openssl pkey -in "${KEY_DIR}/private.pem" -pubout -out "${KEY_DIR}/public.pem" >/dev/null 2>&1

RELEASE_SIGNING_KEY_PATH="${KEY_DIR}/private.pem" \
  bash "${ROOT}/scripts/build-release.sh" "${VERSION}" "${OUTPUT_DIR}"
ARCHIVE="${ROOT}/${OUTPUT_DIR}/rishe-${VERSION}.zip"
cp "${KEY_DIR}/public.pem" "${ARCHIVE%.zip}.pub.pem"

bash "${ROOT}/scripts/verify-release.sh" \
  "${ARCHIVE}" \
  "${ARCHIVE}.sha256" \
  "${ARCHIVE}.sig" \
  "${ARCHIVE%.zip}.pub.pem"

bash "${ROOT}/scripts/package-smoke.sh" \
  "${ARCHIVE}" \
  "${VERSION}" \
  "${ROOT}/${EVIDENCE_DIR}"

cat > "${ROOT}/${EVIDENCE_DIR}/candidate.json" <<JSON
{
  "schema": 1,
  "version": "${VERSION}",
  "archive": "${ARCHIVE}",
  "checksum": "${ARCHIVE}.sha256",
  "signature": "${ARCHIVE}.sig",
  "public_key": "${ARCHIVE%.zip}.pub.pem",
  "signing_key_scope": "ephemeral_release_candidate",
  "completed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

cat "${ROOT}/${EVIDENCE_DIR}/candidate.json"
