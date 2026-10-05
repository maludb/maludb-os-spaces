#!/usr/bin/env bash
# Phase 3, slice 1 — spaces, membership and sections (THE CRUD EXEMPLAR): tests/phase3/slice1/run.sh
# A fresh SCRATCH database (sp_dev1 — never the installed one), the application on :8401 (php -S, or SP_APP=apache for a real Apache with the
# rendered deploy vhost), a fake kernel (:8402), a fake MaluDB (:8403), a fake MaluMail (:8406). Needs `sudo -n -u postgres`, php, the kernel's
# web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/sp-shots-s1). One line per check; exit 0 only if all passed.
#   PROOFS="seeds spaces" tests/phase3/slice1/run.sh     runs a subset
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export SP_DEV_DB=${SP_DEV_DB:-sp_dev1} SP_DEV_ENV=${SP_DEV_ENV:-/tmp/sp-dev1.env} SP_DEV_STATE=${SP_DEV_STATE:-/tmp/sp-dev1-state} SHOTS=${SHOTS:-/tmp/sp-shots-s1}
mkdir -p "$SHOTS" "$SP_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$SP_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$SP_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$SP_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-seeds spaces membership sections visibility}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice1/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
