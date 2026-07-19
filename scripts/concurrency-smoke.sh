#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${1:-/tmp/wordpress}"
WP=(wp --path="${WP_PATH}" --allow-root)
KEY="concurrency-$(date +%s)-${RANDOM}"

for _ in $(seq 1 8); do
  "${WP[@]}" rishe queue enqueue system.noop --key="${KEY}" --aggregate-type=integration --aggregate-id=shared --payload='{"delay_ms":200}' --format=json >/tmp/rishe-enqueue-$RANDOM.json &
done
wait

PREFIX="$("${WP[@]}" db prefix)"
COUNT="$("${WP[@]}" db query "SELECT COUNT(*) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${KEY}'" --skip-column-names)"
[[ "${COUNT}" == "1" ]] || { echo "Expected one idempotent job, found ${COUNT}." >&2; exit 1; }

for _ in $(seq 1 4); do
  "${WP[@]}" rishe queue run --limit=10 --format=json >/tmp/rishe-worker-$RANDOM.json &
done
wait

ROW="$("${WP[@]}" db query "SELECT CONCAT(status,':',attempts) FROM ${PREFIX}rishe_operation_jobs WHERE idempotency_key='${KEY}'" --skip-column-names)"
[[ "${ROW}" == "completed:1" ]] || { echo "Unexpected concurrent job result: ${ROW}." >&2; exit 1; }

echo "Concurrency smoke test passed: ${ROW}"
