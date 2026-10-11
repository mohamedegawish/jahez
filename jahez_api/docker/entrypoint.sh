#!/bin/sh
set -e

# Wait for MySQL to accept connections (max ~60s), then migrate.
# Uses DB_* env vars injected by docker-compose / Coolify.
# NOTE: a wrong password (MySQL error 1045) fails FAST with a clear message —
# retrying would never help because the db volume keeps the credentials that
# were set on the very first deploy.
if [ -n "$DB_HOST" ]; then
  echo "Waiting for database $DB_HOST:${DB_PORT:-3306}..."
  ATTEMPTS=0
  until php -r "
    try {
      new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306'),
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: ''
      );
      exit(0);
    } catch (PDOException \$e) {
      \$driverCode = is_array(\$e->errorInfo) ? (\$e->errorInfo[1] ?? null) : null;
      if ((int) \$driverCode === 1045 || str_contains(\$e->getMessage(), 'Access denied')) {
        fwrite(STDERR, 'DB_AUTH_FAILED');
        exit(2);
      }
      exit(1);
    } catch (Throwable \$e) { exit(1); }
  "; do
    if [ "$?" -eq 2 ]; then
      echo "ERROR: Access denied for user '${DB_USERNAME}' on host '${DB_HOST}'."
      echo "The db volume keeps the MySQL password from the FIRST deploy; changing DB_PASSWORD afterwards does not update it."
      echo "Fix: stop the stack, remove the db volume (docker volume rm <project>_db_data), then redeploy."
      exit 1
    fi
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge 60 ]; then
      echo "Database not reachable after 60s, continuing anyway..."
      break
    fi
    sleep 2
  done
  echo "Database is reachable."
fi

# Laravel writable dirs (named volumes start empty on first deploy).
mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# If this container was started as the queue worker, run it instead of Apache.
if [ "$1" = "queue" ]; then
  echo "Starting queue worker..."
  exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --verbose
fi

# Scheduler (sanctum:prune-expired, payments:reconcile). Needs no web server.
if [ "$1" = "scheduler" ]; then
  echo "Starting scheduler..."
  exec php artisan schedule:work --verbose
fi

# Normal web container: link storage, migrate, seed, cache config, start Apache.
php artisan storage:link --force 2>/dev/null || true
php artisan migrate --force
# DatabaseSeeder is safe to run repeatedly (reference data only; demo users only on local/testing).
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Go-live gate from docs/deployment.md: prints PASS/WARN/FAIL. Warn-only here so a
# misconfigured staging env can't crash the container; check the logs before going live.
php artisan app:check-production || true

exec "$@"
