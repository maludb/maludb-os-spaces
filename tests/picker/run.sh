#!/usr/bin/env bash
# The record picker (docs/spaces-record-picker.md) — the endpoint's refusals, search, escaping, paging, the params and the membership walls, every converted form
# rendering its picker, a first render keeping the chosen label, saves through the hidden inputs, the browser pass: tests/picker/run.sh
# A fresh SCRATCH database (sp_picker — never the installed one), the application on :8401 (php -S), a fake kernel (:8402), a fake MaluDB (:8403),
# a fake MaluMail (:8406). Needs `sudo -n -u postgres`, php, the kernel's web/node_modules (Playwright + Chromium) for the browser pass.
# Screenshots go to $SHOTS (default /tmp/sp-shots-picker). One line per check; exit 0 only if all passed.   PROOFS="endpoint forms" runs a subset.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export SP_DEV_DB=${SP_DEV_DB:-sp_picker} SP_DEV_ENV=${SP_DEV_ENV:-/tmp/sp-picker.env} SP_DEV_STATE=${SP_DEV_STATE:-/tmp/sp-picker-state} SHOTS=${SHOTS:-/tmp/sp-shots-picker}
mkdir -p "$SHOTS" "$SP_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$SP_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$SP_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$SP_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$SP_DEV_STATE/malumail.log" FAKE_MALUMAIL_STATE="$SP_DEV_STATE/malumail.json"
cd "$ROOT"
mkdir -p storage/proof
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-endpoint forms saves}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/picker/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; export WORLD=$(php -r 'require "tests/picker/lib.php"; echo json_encode(picker_browser_world());' 2>/dev/null); node tests/picker/browser.mjs || status=1; fi
rm -rf storage/proof
[ $status -eq 0 ] && echo "ALL PICKER PROOFS PASSED" || echo "SOME PICKER PROOFS FAILED"
exit $status
