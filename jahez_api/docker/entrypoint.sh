#!/bin/sh
set -e

# Wait for MySQL to accept connections (max ~60s), then migrate.
# Uses DB_* env vars injected by docker-compose / Coolify.
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
    } catch (Throwable \$e) { exit(1); }
  "; do
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
