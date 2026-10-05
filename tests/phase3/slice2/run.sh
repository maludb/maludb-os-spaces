#!/usr/bin/env bash
# Phase 3, slice 2 — pages: the tree, sharing, the wiki, the trash, templates, the public page: tests/phase3/slice2/run.sh
# A fresh SCRATCH database (sp_dev3 — never the installed one), the application on :8401 (php -S, or SP_APP=apache), a fake kernel (:8402), a fake
# MaluDB (:8403), a fake MaluMail (:8406). Needs `sudo -n -u postgres`, php, the kernel's web/node_modules (Playwright + Chromium) for the browser proof.
# Screenshots go to $SHOTS (default /tmp/sp-shots-s2). One line per check; exit 0 only if all passed.   PROOFS="make read" runs a subset.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export SP_DEV_DB=${SP_DEV_DB:-sp_dev3} SP_DEV_ENV=${SP_DEV_ENV:-/tmp/sp-dev3.env} SP_DEV_STATE=${SP_DEV_STATE:-/tmp/sp-dev3-state} SHOTS=${SHOTS:-/tmp/sp-shots-s2}
mkdir -p "$SHOTS" "$SP_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$SP_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$SP_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$SP_DEV_STATE/maludb.log"
cd "$ROOT"
mkdir -p storage/proof
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-make read move trash share publish wiki json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice2/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
rm -rf storage/proof
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
