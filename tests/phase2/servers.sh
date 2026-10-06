#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the proofs' servers on loopback, all from the scratch environment ($SP_DEV_ENV):
#   the application on :8401, a fake kernel on :8402, a fake MaluDB on :8403, a fake MaluMail on :8406. The application is `php -S`
#   with tests/dev_router.php by default; with SP_APP=apache it is a REAL Apache (as your user, mod_php) serving deploy/apache-spaces.conf
#   rendered the way the kernel's installer renders it — the proofs then exercise the vhost's own rewrites (/sso, the canonical URLs,
#   the public door, /api blocked). State: $SP_DEV_STATE (default /tmp/sp-dev2-state) holds the pid files, the fake kernel's state file and the MaluDB log.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${SP_DEV_ENV:-/tmp/sp-dev2.env}
STATE=${SP_DEV_STATE:-/tmp/sp-dev2-state}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  pkill -f 'php .*-S 127.0.0.1:840[1236]' 2>/dev/null || true       # the built-in server's forked workers outlive their parent
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_STATE="$STATE/malumail.json" FAKE_MALUMAIL_LOG="$STATE/malumail.log"
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"; echo '{"mailboxes":{}}' > "$FAKE_MALUMAIL_STATE"
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:8402 "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:8403 "$ROOT/tests/fake_maludb.php" >"$STATE/maludb.log.srv" 2>&1 & echo $! > "$STATE/maludb.pid"
    php -S 127.0.0.1:8406 "$ROOT/tests/fake_malumail.php" >"$STATE/malumail.log.srv" 2>&1 & echo $! > "$STATE/malumail.pid"
    if [ "${SP_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-spaces.conf').read()
for k, val in {'APP_FQDN': 'spaces.subello.com', 'APP_DIR': root, 'APP_INTERNAL_PORT': '8407', 'MCP_RECORDS_PORT': '8404', 'MCP_ACTIVITY_PORT': '8405'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:8401>')
open(out + '/site.conf', 'w').write(v)
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\nDefine APACHE_LOG_DIR %s\nListen 8401\nInclude %s/site.conf\n' % ((out,) * 5))
PY
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=64M -d post_max_size=72M -S 127.0.0.1:8401 -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8401/api/v1/health && break; sleep 0.2; done
    echo "servers up (app :8401 ${SP_APP:-php}, fake kernel :8402, fake MaluDB :8403, fake MaluMail :8406)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
