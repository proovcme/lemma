#!/usr/bin/env sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
backup_dir=${1:-}
if [ -z "$backup_dir" ] || [ "${2:-}" != '--yes' ]; then
  echo 'Usage: scripts/restore.sh BACKUP_DIRECTORY --yes' >&2
  echo 'Replaces all current application data. Restore with the same application release and APP_DATA_KEY.' >&2
  exit 1
fi
test -f .env
test -f "$backup_dir/database.sql"
test -f "$backup_dir/storage.tar.gz"
(cd "$backup_dir" && if command -v sha256sum >/dev/null 2>&1; then sha256sum -c SHA256SUMS; else shasum -a 256 -c SHA256SUMS; fi)
# Refuse to restore encrypted settings with an unrelated installation key.
test "$(sed -n '/^APP_DATA_KEY=/p' .env)" = "$(sed -n '/^APP_DATA_KEY=/p' "$backup_dir/environment.env")" || { echo 'APP_DATA_KEY differs; restore the key from environment.env first' >&2; exit 1; }
docker compose stop web lemma
# On failure keep the application stopped: a partial restore must not accept writes.
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS lemma; CREATE DATABASE lemma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" lemma' < "$backup_dir/database.sql"
docker compose run --rm -T --no-deps --entrypoint sh lemma -c 'find /app/storage -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; tar -C /app/storage -xzf -' < "$backup_dir/storage.tar.gz"
docker compose up -d --wait lemma web
echo "Restore completed: $backup_dir"
