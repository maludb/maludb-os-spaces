#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the two shares, app_roles, the gate, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (sp_dev10 — never the installed one), the application on :8401 (php -S), a fake kernel (:8402 — run-facts, K6), a fake MaluDB (:8403) and MaluMail (:8406), and the servers under test on :8404 (records),
# :8405 (activity) and :8410 (records with a DEAD kernel url, for the fail-closed proofs). NOTHING REAL IS TOUCHED: no live service, Apache, unit, database or role password.
# PROOFS="gate tools_pages" runs some; SP_KEEP_DB=1 reuses the scratch database and its world (the proofs' own writes are SMOKE rows). Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export SP_DEV_DB=${SP_DEV_DB:-sp_dev10} SP_DEV_ENV=${SP_DEV_ENV:-/tmp/sp-dev10.env} SP_DEV_STATE=${SP_DEV_STATE:-/tmp/sp-dev10-state}
mkdir -p "$SP_DEV_STATE"
if [ -z "${SP_KEEP_DB:-}" ] || [ ! -f "$SP_DEV_ENV" ]; then "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }; fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
trap '"$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$SP_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$SP_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$SP_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$SP_DEV_STATE/malumail.log" FAKE_MALUMAIL_STATE="$SP_DEV_STATE/malumail.json"
cd "$ROOT"
mkdir -p storage/proof
: > "$FAKE_MALUMAIL_LOG"; : > "$SP_DEV_STATE/p4-called.txt"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-gate tools_pages tools_databases tools_channels tools_search_agents shares tools_activity registry coverage}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
rm -rf storage/proof
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
