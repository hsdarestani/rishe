#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${1:-/tmp/wordpress}"
WP=(wp --path="${WP_PATH}" --allow-root)
KEY="concurrency-$(date +%s)-${RANDOM}"
LOG_DIR="${GITHUB_WORKSPACE:-/tmp}/rishe-concurrency"
rm -rf "${LOG_DIR}"
mkdir -p "${LOG_DIR}"

report() {
  {
    echo "key=${KEY}"
    echo "--- enqueue results ---"
    for file in "${LOG_DIR}"/enqueue-*.log; do
      [[ -e "${file}" ]] || continue
      echo "### $(basename "${file}") rc=$(cat "${file%.log}.rc" 2>/dev/null || echo missing)"
      cat "${file}"
    done
    echo "--- worker results ---"
    for file in "${LOG_DIR}"/worker-*.log; do
      [[ -e "${file}" ]] || continue
      echo "### $(basename "${file}") rc=$(cat "${file%.log}.rc" 2>/dev/null || echo missing)"
      cat "${file}"
    done
    echo "--- database snapshot ---"
    prefix="$("${WP[@]}" db prefix 2>/dev/null || true)"
    if [[ -n "${prefix}" ]]; then
      "${WP[@]}" db query "SELECT id,idempotency_key,status,attempts,max_attempts,scheduled_at,locked_at,last_error FROM ${prefix}rishe_operation_jobs WHERE idempotency_key='${KEY}'" 2>&1 || true
      "${WP[@]}" db query "SELECT event_type,status_from,status_to,message FROM ${prefix}rishe_operation_job_events WHERE job_id IN (SELECT id FROM ${prefix}rishe_operation_jobs WHERE idempotency_key='${KEY}') ORDER BY id" 2>&1 || true
    fi
  } | tee "${LOG_DIR}/summary.log"
}
trap 'status=$?; if [[ ${status} -ne 0 ]]; then report; fi; exit ${status}' EXIT

for index in $(seq 1 8); do
  (
    set +e
    "${WP[@]}" rishe queue enqueue system.noop \
      --key="${KEY}" \
      --aggregate-type=integration \
      --aggregate-id=shared \
      --payload='{"delay_ms":200}' \
      --format=json >"${LOG_DIR}/enqueue-${index}.log" 2>&1
    echo "$?" >"${LOG_DIR}/enqueue-${index}.rc"
  ) &
done
wait

for rc_file in "${LOG_DIR}"/enqueue-*.rc; do
  [[ "$(cat "${rc_file}")" == "0" ]] || { echo "A concurrent enqueue command failed." >&2; false; }
done

PREFIX="$("${WP[@]}" db prefix)"
COUNT="$("${WP[@]}" db query "SELECT COUNT(*) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${KEY}'" --skip-column-names)"
[[ "${COUNT}" == "1" ]] || { echo "Expected one idempotent job, found ${COUNT}." >&2; false; }

for index in $(seq 1 4); do
  (
    set +e
    "${WP[@]}" rishe queue run --limit=10 --format=json >"${LOG_DIR}/worker-${index}.log" 2>&1
    echo "$?" >"${LOG_DIR}/worker-${index}.rc"
  ) &
done
wait

for rc_file in "${LOG_DIR}"/worker-*.rc; do
  [[ "$(cat "${rc_file}")" == "0" ]] || { echo "A concurrent worker command failed." >&2; false; }
done

ROW="$("${WP[@]}" db query "SELECT CONCAT(status,':',attempts) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${KEY}'" --skip-column-names)"
[[ "${ROW}" == "completed:1" ]] || { echo "Unexpected concurrent job result: ${ROW}." >&2; false; }

trap - EXIT
report
echo "Concurrency smoke test passed: ${ROW}"
