#!/usr/bin/env sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
test ! -e .env || { echo '.env already exists; nothing changed' >&2; exit 1; }
admin_email=${1:-admin@example.local}
case "$admin_email" in *[!a-zA-Z0-9_@.+-]*|'') echo 'Use a plain email address' >&2; exit 1;; esac
case "$admin_email" in *@*.*) ;; *) echo 'A valid administrator email is required' >&2; exit 1;; esac
command -v openssl >/dev/null
umask 077
# noclobber prevents concurrent initialization from overwriting credentials.
set -C
{
  echo 'APP_URL=http://localhost:8080'
  echo 'LEMMA_SITE=http://'
  echo 'HTTP_PORT=8080'
  echo 'HTTPS_PORT=8443'
  echo 'APP_TIMEZONE=Europe/Moscow'
  printf 'ADMIN_EMAIL=%s\n' "$admin_email"
  printf 'ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)"
  printf 'DB_PASSWORD=%s\n' "$(openssl rand -hex 32)"
  printf 'DB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 32)"
  printf 'APP_DATA_KEY=%s\n' "$(openssl rand -base64 32)"
  echo 'MAIL_ENABLED=0'
  echo 'ATLAS_URL='
} > .env
printf '%s\n' 'Created .env (mode 600). Login: 0001. Read ADMIN_PASSWORD from .env and save it in your password manager.'
