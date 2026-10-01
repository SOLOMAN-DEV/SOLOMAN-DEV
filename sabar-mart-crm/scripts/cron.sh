#!/bin/sh
# Scheduled maintenance for the Sabar Mart CRM (cPanel "Cron Jobs" or crontab).
#
#   scripts/cron.sh backup   # consistent database snapshot, keeps the newest $SABAR_CRM_BACKUP_KEEP
#   scripts/cron.sh purge    # remove expired idempotency keys
#
# Cron jobs do not see the environment variables set in cPanel's Python App screen, so the
# settings are read from an env file (default ~/.sabar-crm.env, which must be chmod 600):
#
#   SABAR_CRM_VENV=/home/cpuser/virtualenv/sabar-mart-crm/3.11   # from the Python App page
#   DATABASE_URL=mysql+pymysql://cpuser_crmapp:PASSWORD@localhost/cpuser_crm?charset=utf8mb4
#   SABAR_CRM_BACKUP_DIR=/home/cpuser/crm-backups
#   SABAR_CRM_BACKUP_KEEP=14
#   SABAR_CRM_ALERT_EMAIL=ops@sabarmart.com          # plus the SMTP settings, see monitoring.py
set -eu

APP_DIR=$(cd "$(dirname "$0")/.." && pwd)
ENV_FILE=${SABAR_CRM_ENV_FILE:-$HOME/.sabar-crm.env}

if [ ! -f "$ENV_FILE" ]; then
  echo "missing $ENV_FILE (see the comments in $0)" >&2
  exit 2
fi
if [ -n "$(find "$ENV_FILE" -perm /077 2>/dev/null)" ]; then
  echo "$ENV_FILE is readable by other users; run: chmod 600 $ENV_FILE" >&2
  exit 2
fi
set -a
. "$ENV_FILE"
set +a

if [ -n "${SABAR_CRM_VENV:-}" ]; then
  . "$SABAR_CRM_VENV/bin/activate"
fi
cd "$APP_DIR"

case "${1:-}" in
  backup) exec python -m sabar_mart_crm.db backup "${SABAR_CRM_BACKUP_DIR:-$HOME/crm-backups}" --keep "${SABAR_CRM_BACKUP_KEEP:-14}" ;;
  purge)  exec python -m sabar_mart_crm.db purge ;;
  *)      echo "usage: $0 backup|purge" >&2; exit 2 ;;
esac
