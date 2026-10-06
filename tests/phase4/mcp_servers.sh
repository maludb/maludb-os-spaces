#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop — the two MCP servers on SCRATCH loopback ports from the scratch environment ($SP_DEV_ENV):
# records :8404, activity :8405 (never the installed application's ports 8833/8834 or its services). A third records instance with a dead kernel URL
# (:8410) proves the run-facts gate fails closed when the kernel cannot be reached. Pid files live in $SP_DEV_STATE.
# The Python is the application's own mcp/venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt; deploy/ROOT_STEPS.sh step 5 does it for the installed copy);
# otherwise $SP_MCP_VENV, else a sibling application's venv that already holds FastMCP, asyncpg, httpx and uvicorn — nothing is installed here.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${SP_DEV_ENV:-/tmp/sp-dev.env}
STATE=${SP_DEV_STATE:-/tmp/sp-dev-state}
mkdir -p "$STATE"
VENV=
for c in "${SP_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/helpdesk/mcp/venv /srv/apps/consultant_tracking/mcp/venv /srv/apps/projects/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(8404|8405|8410) ' || break; sleep 0.2; done
}
case "${1:-}" in
  stop) stop ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    launch() {  # name, then VAR=value pairs, then the interpreter and script — exec so $! is the python process itself
      local name=$1; shift
      ( cd "$ROOT/mcp" && exec env "$@" >"$STATE/mcp-$name.log" 2>&1 ) &
      echo $! > "$STATE/mcp-$name.pid"
    }
    launch records MCP_RECORDS_PORT=8404 MCP_ACTIVITY_PORT=8405 "$PY" "$ROOT/mcp/records_server.py"
    launch activity MCP_RECORDS_PORT=8404 MCP_ACTIVITY_PORT=8405 "$PY" "$ROOT/mcp/activity_server.py"
    launch nokernel MCP_RECORDS_PORT=8410 OS_INTERNAL_URL=http://127.0.0.1:1 "$PY" "$ROOT/mcp/records_server.py"
    for p in 8404 8405 8410; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8404, activity :8405, records-with-no-kernel :8410; python $VENV)" ;;
  *) echo "usage: $0 start|stop|venv" >&2; exit 2 ;;
esac
