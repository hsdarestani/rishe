#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${1:-/tmp/wordpress}"
OUTPUT_DIR="${2:-rishe-recovery-evidence}"
WP=(wp --path="${WP_PATH}" --allow-root)
mkdir -p "${OUTPUT_DIR}"
OUTPUT_DIR="$(cd "${OUTPUT_DIR}" && pwd)"

ENVIRONMENT="$("${WP[@]}" eval 'echo wp_get_environment_type();')"
if [[ "${ENVIRONMENT}" == "production" ]]; then
  echo "Recovery rehearsal refuses to run against a production environment." >&2
  exit 1
fi

SITE_URL="$("${WP[@]}" option get home)"
PREFIX="$("${WP[@]}" db prefix)"
RUN_ID="$(date +%s)-${RANDOM}"
BEFORE_KEY="recovery-before-${RUN_ID}"
AFTER_KEY="recovery-after-${RUN_ID}"
BEFORE_MARKER="before-${RUN_ID}"
AFTER_MARKER="after-${RUN_ID}"
SOURCE_ARCHIVE="${OUTPUT_DIR}/source-${RUN_ID}.zip"

"${WP[@]}" option update rishe_recovery_rehearsal_marker "${BEFORE_MARKER}" >/dev/null
"${WP[@]}" rishe queue enqueue system.noop \
  --key="${BEFORE_KEY}" \
  --aggregate-type=recovery \
  --aggregate-id="${RUN_ID}" \
  --payload='{"phase":"before_backup"}' \
  --format=json > "${OUTPUT_DIR}/before-enqueue.json"
"${WP[@]}" rishe queue run --limit=100 --format=json > "${OUTPUT_DIR}/before-worker.json"

BEFORE_STATE="$("${WP[@]}" db query "SELECT CONCAT(status, ':', attempts) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${BEFORE_KEY}'" --skip-column-names)"
if [[ "${BEFORE_STATE}" != "completed:1" ]]; then
  echo "Pre-backup health job did not complete exactly once: ${BEFORE_STATE}" >&2
  exit 1
fi

BACKUP_JSON="$("${WP[@]}" rishe backup create --output="${SOURCE_ARCHIVE}" --format=json)"
printf '%s\n' "${BACKUP_JSON}" > "${OUTPUT_DIR}/backup-create.json"
ARCHIVE="$(php -r '$data=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $data["archive"] ?? "";' <<<"${BACKUP_JSON}")"
if [[ -z "${ARCHIVE}" || ! -r "${ARCHIVE}" ]]; then
  echo "Recovery source backup was not created." >&2
  exit 1
fi
"${WP[@]}" rishe backup verify "${ARCHIVE}" --format=json > "${OUTPUT_DIR}/backup-verify-before.json"

"${WP[@]}" option update rishe_recovery_rehearsal_marker "${AFTER_MARKER}" >/dev/null
"${WP[@]}" rishe queue enqueue system.noop \
  --key="${AFTER_KEY}" \
  --aggregate-type=recovery \
  --aggregate-id="${RUN_ID}" \
  --payload='{"phase":"after_backup"}' \
  --format=json > "${OUTPUT_DIR}/after-enqueue.json"
"${WP[@]}" rishe queue run --limit=100 --format=json > "${OUTPUT_DIR}/after-worker.json"

AFTER_COUNT="$("${WP[@]}" db query "SELECT COUNT(*) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${AFTER_KEY}'" --skip-column-names)"
if [[ "${AFTER_COUNT}" != "1" ]]; then
  echo "Post-backup mutation job was not persisted before restore." >&2
  exit 1
fi

RESTORE_JSON="$("${WP[@]}" rishe backup restore "${ARCHIVE}" --confirm="${SITE_URL}" --format=json)"
printf '%s\n' "${RESTORE_JSON}" > "${OUTPUT_DIR}/backup-restore.json"
SAFETY_ARCHIVE="$(php -r '$data=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $data["safety_backup"] ?? "";' <<<"${RESTORE_JSON}")"

RESTORED_MARKER="$("${WP[@]}" option get rishe_recovery_rehearsal_marker)"
RESTORED_BEFORE_STATE="$("${WP[@]}" db query "SELECT CONCAT(status, ':', attempts) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${BEFORE_KEY}'" --skip-column-names)"
RESTORED_AFTER_COUNT="$("${WP[@]}" db query "SELECT COUNT(*) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${AFTER_KEY}'" --skip-column-names)"

if [[ "${RESTORED_MARKER}" != "${BEFORE_MARKER}" ]]; then
  echo "WordPress option was not restored to the pre-backup value." >&2
  exit 1
fi
if [[ "${RESTORED_BEFORE_STATE}" != "completed:1" ]]; then
  echo "Pre-backup ERP job was not restored correctly: ${RESTORED_BEFORE_STATE}" >&2
  exit 1
fi
if [[ "${RESTORED_AFTER_COUNT}" != "0" ]]; then
  echo "Post-backup ERP mutation survived restore unexpectedly." >&2
  exit 1
fi
if [[ -z "${SAFETY_ARCHIVE}" || ! -r "${SAFETY_ARCHIVE}" ]]; then
  echo "Restore did not create a readable safety backup." >&2
  exit 1
fi

"${WP[@]}" rishe backup verify "${SAFETY_ARCHIVE}" --format=json > "${OUTPUT_DIR}/safety-backup-verify.json"
"${WP[@]}" rishe backup verify "${ARCHIVE}" --format=json > "${OUTPUT_DIR}/backup-verify-after.json"
"${WP[@]}" rishe diagnostics --format=json > "${OUTPUT_DIR}/diagnostics-after.json"
"${WP[@]}" rishe certify --environment=staging --format=json > "${OUTPUT_DIR}/certification-after.json"

PLUGIN_VERSION="$("${WP[@]}" eval 'echo RISHE_VERSION;')"
DATABASE_VERSION="$("${WP[@]}" option get rishe_db_version)"
ARCHIVE_SHA256="$(sha256sum "${ARCHIVE}" | awk '{print $1}')"

RUN_ID="${RUN_ID}" \
PLUGIN_VERSION="${PLUGIN_VERSION}" \
DATABASE_VERSION="${DATABASE_VERSION}" \
ARCHIVE="${ARCHIVE}" \
ARCHIVE_SHA256="${ARCHIVE_SHA256}" \
SAFETY_ARCHIVE="${SAFETY_ARCHIVE}" \
ENVIRONMENT="${ENVIRONMENT}" \
php -r '
$data = [
    "schema" => 1,
    "run_id" => getenv("RUN_ID"),
    "environment" => getenv("ENVIRONMENT"),
    "plugin_version" => getenv("PLUGIN_VERSION"),
    "database_version" => getenv("DATABASE_VERSION"),
    "source_archive" => getenv("ARCHIVE"),
    "source_archive_sha256" => getenv("ARCHIVE_SHA256"),
    "safety_archive" => getenv("SAFETY_ARCHIVE"),
    "wordpress_option_restored" => true,
    "pre_backup_job_restored" => true,
    "post_backup_mutation_removed" => true,
    "source_backup_verified_after_restore" => true,
    "safety_backup_verified" => true,
    "certification_passed" => true,
    "completed_at" => gmdate("c"),
];
file_put_contents($argv[1], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
' "${OUTPUT_DIR}/recovery.json"
