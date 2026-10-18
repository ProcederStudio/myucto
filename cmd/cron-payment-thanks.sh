#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-payment-thanks.php" "$@" >> "$LOG_DIR/payment-thanks.log" 2>&1
