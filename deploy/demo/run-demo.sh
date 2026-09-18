#!/bin/sh
# Simple public demo on a plain VPS - no Docker, no database server.
# Builds a fresh, fully-anonymized SQLite demo and serves it with PHP's built-in
# server. A restart rebuilds the data, so the demo self-heals.
#
# Usage:
#   sh deploy/demo/run-demo.sh                 # serves on 0.0.0.0:8080
#   PORT=80 APP_URL=http://203.0.113.10 sh deploy/demo/run-demo.sh
#
# Requirements on the VPS:  php-cli with pdo_sqlite  (e.g. apt install php-cli php-sqlite3)
set -e

# repo root = two levels up from this script
cd "$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"

PHP="${PHP:-php}"
PORT="${PORT:-8080}"
# Bind to all interfaces by default; behind a reverse proxy set BIND_HOST=127.0.0.1.
BIND_HOST="${BIND_HOST:-0.0.0.0}"

# All config via env (load_env() yields to real env vars, and a fresh clone has no .env).
export APP_ENV="${APP_ENV:-demo}"
export APP_DEBUG="${APP_DEBUG:-0}"
export APP_URL="${APP_URL:-http://localhost:${PORT}}"
export APP_TIMEZONE="${APP_TIMEZONE:-Europe/Moscow}"
export DB_CONNECTION=sqlite
export DB_SQLITE_PATH="${DB_SQLITE_PATH:-storage/demo.sqlite}"
export MSP_SYNC_ENABLED=0
# Point the "Просмотр ТИМ" link at an external standalone viewer (the bundled
# /locia-atlas/ SPA can't run under a sub-path). Empty ⇒ use bundled viewer.
export ATLAS_URL="${ATLAS_URL:-}"
# Passwordless "Демо доступ" button on the login page. Set DEMO_MODE=1 for demos.
export DEMO_MODE="${DEMO_MODE:-1}"
DEMO_SESSION_PATH="${DEMO_SESSION_PATH:-storage/sessions}"

echo "[locia-demo] PHP: $("$PHP" -v | head -1)"
echo "[locia-demo] building fresh demo database at ${DB_SQLITE_PATH} ..."
mkdir -p "$(dirname "${DB_SQLITE_PATH}")" "$DEMO_SESSION_PATH"
case "$DEMO_SESSION_PATH" in
    storage/sessions|/data/sessions|/tmp/locia-*) rm -f -- "$DEMO_SESSION_PATH"/sess_* ;;
    *) echo "[locia-demo] refusing unsafe DEMO_SESSION_PATH: $DEMO_SESSION_PATH" >&2; exit 1 ;;
esac
rm -f "${DB_SQLITE_PATH}"
"$PHP" -d "session.save_path=${DEMO_SESSION_PATH}" scripts/sqlite_setup.php >/dev/null
"$PHP" -d "session.save_path=${DEMO_SESSION_PATH}" scripts/seed_demo.php
"$PHP" -d "session.save_path=${DEMO_SESSION_PATH}" scripts/demo_privacy_audit.php

echo "[locia-demo] serving on http://${BIND_HOST}:${PORT}  (APP_URL=${APP_URL})"
echo "[locia-demo] demo roles are available from /login"
exec "$PHP" -d display_errors=0 -d error_reporting=0 -d expose_php=0 \
  -d "session.save_path=${DEMO_SESSION_PATH}" \
  -S "${BIND_HOST}:${PORT}" -t public public/router.php
