#!/bin/sh
set -eu

attempt=0
until php -r 'require "/app/app/bootstrap.php"; App\Core\Database::pdo()->query("SELECT 1");' >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo "MariaDB did not become ready in time" >&2
    exit 1
  fi
  sleep 2
done

php /app/scripts/migrate.php
php /app/scripts/work_setup.php

touch /tmp/locia-ready
exec php-fpm -F
