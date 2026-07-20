#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${1:-/tmp/wordpress}"
WP=(wp --path="${WP_PATH}" --allow-root)
LOG_DIR="${GITHUB_WORKSPACE:-/tmp}/rishe-runtime"
rm -rf "${LOG_DIR}"
mkdir -p "${LOG_DIR}"

run_logged() {
  local name="$1"
  shift
  set +e
  "$@" >"${LOG_DIR}/${name}.log" 2>&1
  local status=$?
  set -e
  echo "${status}" >"${LOG_DIR}/${name}.rc"
  if [[ ${status} -ne 0 ]]; then
    echo "Command ${name} failed with exit code ${status}." >&2
    cat "${LOG_DIR}/${name}.log" >&2
    return "${status}"
  fi
}

run_logged diagnostics "${WP[@]}" rishe diagnostics --format=json
run_logged certify-before "${WP[@]}" rishe certify --environment=staging --format=json
run_logged backup-create "${WP[@]}" rishe backup create --format=json

archive="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); if (!is_array($d) || empty($d["archive"])) { exit(2); } echo $d["archive"];' "${LOG_DIR}/backup-create.log")"
echo "${archive}" >"${LOG_DIR}/archive.path"
run_logged backup-verify "${WP[@]}" rishe backup verify "${archive}" --format=json
run_logged certify-after "${WP[@]}" rishe certify --environment=staging --format=json

{
  echo "Runtime smoke test passed."
  echo "archive=${archive}"
  for rc_file in "${LOG_DIR}"/*.rc; do
    echo "$(basename "${rc_file}" .rc)=$(cat "${rc_file}")"
  done
} | tee "${LOG_DIR}/summary.log"
