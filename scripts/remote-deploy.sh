#!/usr/bin/env bash
set -euo pipefail

ARCHIVE=""
EXPECTED_SHA=""
WP_PATH=""
ENVIRONMENT="staging"
PLUGIN_SLUG="rishe"
DRY_RUN="0"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --archive) ARCHIVE="$2"; shift 2 ;;
    --sha256) EXPECTED_SHA="$2"; shift 2 ;;
    --wp-path) WP_PATH="$2"; shift 2 ;;
    --environment) ENVIRONMENT="$2"; shift 2 ;;
    --plugin-slug) PLUGIN_SLUG="$2"; shift 2 ;;
    --dry-run) DRY_RUN="1"; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

[[ -f "${ARCHIVE}" ]] || { echo "Archive not found." >&2; exit 1; }
[[ -d "${WP_PATH}" ]] || { echo "WordPress path not found." >&2; exit 1; }
ACTUAL_SHA="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"
[[ "${ACTUAL_SHA}" == "${EXPECTED_SHA}" ]] || { echo "Artifact checksum mismatch." >&2; exit 1; }

WP=(wp --path="${WP_PATH}" --allow-root)
if "${WP[@]}" help rishe certify >/dev/null 2>&1; then
  "${WP[@]}" rishe certify --environment="${ENVIRONMENT}" --format=json || {
    echo "Pre-deployment certification failed." >&2
    exit 1
  }
else
  echo "Current release has no Rishe certification command; continuing with package verification and rollback protection."
fi

if [[ "${DRY_RUN}" == "1" ]]; then
  echo "Dry-run preflight succeeded."
  exit 0
fi

STAMP="$(date -u +%Y%m%d-%H%M%S)"
DB_BACKUP="/tmp/rishe-predeploy-${STAMP}.sql"
PLUGIN_DIR="${WP_PATH}/wp-content/plugins/${PLUGIN_SLUG}"
ROLLBACK_DIR="${PLUGIN_DIR}.rollback-${STAMP}"
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGE_DIR}"' EXIT

"${WP[@]}" db export "${DB_BACKUP}" --add-drop-table
unzip -q "${ARCHIVE}" -d "${STAGE_DIR}"
[[ -f "${STAGE_DIR}/${PLUGIN_SLUG}/rishe.php" ]] || { echo "Invalid plugin archive." >&2; exit 1; }

if [[ -d "${PLUGIN_DIR}" ]]; then
  mv "${PLUGIN_DIR}" "${ROLLBACK_DIR}"
fi
mv "${STAGE_DIR}/${PLUGIN_SLUG}" "${PLUGIN_DIR}"

rollback() {
  set +e
  rm -rf "${PLUGIN_DIR}"
  if [[ -d "${ROLLBACK_DIR}" ]]; then
    mv "${ROLLBACK_DIR}" "${PLUGIN_DIR}"
  fi
  "${WP[@]}" db import "${DB_BACKUP}"
  "${WP[@]}" plugin activate "${PLUGIN_SLUG}"
  "${WP[@]}" rishe deploy record --environment="${ENVIRONMENT}" --version="rollback" --sha256="${EXPECTED_SHA}" --status=rolled_back --format=json
}
trap rollback ERR

"${WP[@]}" plugin activate "${PLUGIN_SLUG}"
"${WP[@]}" rishe diagnostics --strict --format=json
"${WP[@]}" rishe certify --environment="${ENVIRONMENT}" --format=json
VERSION="$("${WP[@]}" eval 'echo RISHE_VERSION;')"
"${WP[@]}" rishe deploy record --environment="${ENVIRONMENT}" --version="${VERSION}" --sha256="${EXPECTED_SHA}" --status=succeeded --format=json

trap - ERR
rm -rf "${ROLLBACK_DIR}"
rm -f "${DB_BACKUP}"
echo "Deployment succeeded: ${VERSION} (${ENVIRONMENT})"
