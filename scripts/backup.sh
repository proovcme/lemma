#!/usr/bin/env sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
test -f .env
umask 077
backup_root=${1:-backups}
stamp=$(date -u '+%Y%m%dT%H%M%SZ')
mkdir -p "$backup_root"
target="$backup_root/$stamp"
mkdir "$target"
# Stop all application writes for a consistent database + file snapshot.
docker compose stop web lemma
trap 'docker compose start lemma web >/dev/null' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' > "$target/database.sql"
docker compose run --rm -T --no-deps --entrypoint tar lemma -C /app/storage -czf - . > "$target/storage.tar.gz"
# Encryption keys are needed to restore saved SMTP settings. Keep backups private.
cp .env "$target/environment.env"
git rev-parse HEAD > "$target/commit.txt" 2>/dev/null || printf 'Source archive; see release.json\n' > "$target/commit.txt"
cp manifest/release.json "$target/release.json"
(cd "$target" && if command -v sha256sum >/dev/null 2>&1; then sha256sum database.sql storage.tar.gz environment.env commit.txt release.json > SHA256SUMS; else shasum -a 256 database.sql storage.tar.gz environment.env commit.txt release.json > SHA256SUMS; fi)
echo "Backup created: $target"
