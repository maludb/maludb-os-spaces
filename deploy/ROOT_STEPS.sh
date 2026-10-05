#!/usr/bin/env bash
# Spaces — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0 (2026-10-05);
# later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database (tests/setup_dev.sh),
# php -S and fixtures, and never touch an installed application, Apache or systemd.
#   bash deploy/ROOT_STEPS.sh          prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them (0)
set -euo pipefail
APP=/srv/apps/spaces
KERNEL=/var/www
MODE=${1:-plan}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (the owner's D16; Help Desk's lesson of 2026-10-02). A config/.env
#    holding ONLY the three ports: the installer reads them, fills every other required key itself, and sets fresh role
#    passwords because DB_PASSWORD is absent. Never put anything else in this file by hand.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "printf 'APP_INTERNAL_PORT=8186\nMCP_RECORDS_PORT=8833\nMCP_ACTIVITY_PORT=8834\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{…}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K20 seeded, db/169 —, the application with /sso and /sso/logout,
#    scope_kind none), the application token into config/.env, skills imported, approval policies, the installing
#    super-admin's admin grant. --grant-standing-departments gives every standing department the Member role (key user) at
#    write (D3: everyone in the business belongs in Spaces). --hire-agents hires the expert and the Librarian (D10). Run it
#    twice: the roles are read from app_roles only once the records MCP answers (Phase 4). Spaces is a DEFAULT install (K21):
#    bin/install_default_applications.sh runs this step for a fresh installation.
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. The owner's keys and decisions (any time after 2):
echo "== 3. The owner's"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (slice 6 sends the bell's email and the digests)"
echo "   - the K6 text sender, if texts are wanted: php $KERNEL/bin/notify_endpoint_set.php (already set when another application uses it)"
echo "   - DNS and TLS for spaces.subello.com (until then /etc/hosts maps it to this host)"
echo "   - the first spaces beyond General and the standing departments' (Phase 5); the Spaces admin's channel #spaces-admin for the Librarian's Monday note"
echo "   - the Librarian's duty (30 6 * * 1) in Agent HR until the installer reads agents[].duty"
echo "   - the connections a super-admin approves (bin/app_connection.php): Help Desk, Projects and Consultant Tracking reading pages_index and page_markdown;"
echo "     HD1 (ticket_card) and P2 (task_card) for live previews in their own repositories (Extended)"
