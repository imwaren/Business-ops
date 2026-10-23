#!/usr/bin/env bash
# Copy Xero SOA CSVs and migration SQL to vps6, then import into production mytemr_master.
set -euo pipefail

SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
SSH_PORT="${SSH_PORT:-1969}"
SSH_HOST="${SSH_HOST:-root@vps6.znvme.mytdedi01.filipinowebservices.com}"
REMOTE_DIR="${REMOTE_DIR:-/tmp/soa_xero_prod}"
CSV_DIR="${CSV_DIR:-/Users/myt_waren/Downloads/SOA Xero Generated}"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STEP="${1:-all}"

ssh_run() {
  ssh -i "$SSH_KEY" -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new "$SSH_HOST" "$@"
}

echo "==> mkdir ${REMOTE_DIR}"
ssh_run "mkdir -p '${REMOTE_DIR}/csv' '${REMOTE_DIR}/sql/migrations/soa_xero_2026'"

ENC_LOCAL="$(mktemp)"
python3 "${REPO_ROOT}/scripts/write_soa_enc_env.py" "${REPO_ROOT}/.env" "${ENC_LOCAL}"
COPYFILE_DISABLE=1 tar -C "$(dirname "${ENC_LOCAL}")" -cf - "$(basename "${ENC_LOCAL}")" \
  | ssh_run "tar -C /tmp -xf - && mv '/tmp/$(basename "${ENC_LOCAL}")' '${REMOTE_DIR}/enc.env' && chmod 600 '${REMOTE_DIR}/enc.env'"
rm -f "${ENC_LOCAL}"

echo "==> copy CSVs"
COPYFILE_DISABLE=1 tar -C "${CSV_DIR}" -cf - . \
  | ssh_run "tar -C '${REMOTE_DIR}/csv' -xf -"

echo "==> copy SQL"
COPYFILE_DISABLE=1 tar -C "${REPO_ROOT}/sql/migrations/soa_xero_2026" -cf - . \
  | ssh_run "tar -C '${REMOTE_DIR}/sql/migrations/soa_xero_2026' -xf -"

COPYFILE_DISABLE=1 tar -C "${REPO_ROOT}/sql" -cf - general_ledger_triggers_master.sql \
  | ssh_run "tar -C '${REMOTE_DIR}/sql' -xf -"

echo "==> run on prod (${STEP})"
ssh_run "chmod +x '${REMOTE_DIR}/sql/migrations/soa_xero_2026/run_on_prod.sh' && sed -i 's/\r$//' '${REMOTE_DIR}/sql/migrations/soa_xero_2026/run_on_prod.sh' && REMOTE_DIR='${REMOTE_DIR}' '${REMOTE_DIR}/sql/migrations/soa_xero_2026/run_on_prod.sh' '${STEP}'"
