#!/bin/sh
set -eu

cd /var/www/html

# 1. Ensure writable Laravel runtime & storage directories exist with www-data ownership
mkdir -p \
    storage/app/public/products/main \
    storage/app/public/products/gallery \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# 2. Ensure APP_KEY is available (fail fast when REQUIRE_APP_KEY=true; otherwise persist outside public web root)
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE="/var/www/html/storage/framework/.generated_app_key"
    if [ "${REQUIRE_APP_KEY:-false}" = "true" ]; then
        echo "[entrypoint] ERROR: APP_KEY is empty while REQUIRE_APP_KEY=true." >&2
        exit 1
    fi
    if [ ! -s "$KEY_FILE" ]; then
        GENERATED_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        printf '%s' "$GENERATED_KEY" > "$KEY_FILE"
        chown www-data:www-data "$KEY_FILE"
        chmod 600 "$KEY_FILE"
        echo "[entrypoint] Generated and persisted fallback APP_KEY in $KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# 3. Wait for database readiness when using networked SQL drivers
if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ] || [ "${DB_CONNECTION:-}" = "mysql" ]; then
    MAX_RETRIES="${DB_WAIT_RETRIES:-30}"
    ATTEMPT=1
    echo "[entrypoint] Waiting for ${DB_CONNECTION} database at ${DB_HOST:-db}:${DB_PORT:-5432}..."
    until php -r '
        $driver = getenv("DB_CONNECTION") ?: "pgsql";
        $host   = getenv("DB_HOST") ?: "db";
        $port   = getenv("DB_PORT") ?: ($driver === "mysql" ? "3306" : "5432");
        $db     = getenv("DB_DATABASE") ?: "ascending_pc";
        $user   = getenv("DB_USERNAME") ?: "ascending";
        $pass   = getenv("DB_PASSWORD") ?: "";
        try {
            new PDO("{$driver}:host={$host};port={$port};dbname={$db}", $user, $pass, [
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    '; do
        if [ "$ATTEMPT" -ge "$MAX_RETRIES" ]; then
            echo "[entrypoint] ERROR: Database not reachable after ${MAX_RETRIES} attempts." >&2
            exit 1
        fi
        ATTEMPT=$((ATTEMPT + 1))
        sleep 2
    done
    echo "[entrypoint] Database connection established."
fi

# 4. Link public storage (idempotent)
su-exec www-data php artisan storage:link --force >/dev/null 2>&1 || true

# 5. Run database migrations & optional seeders
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[entrypoint] Running database migrations..."
    su-exec www-data php artisan migrate --force --no-interaction
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "[entrypoint] Seeding marketplace database..."
    su-exec www-data php artisan db:seed --force --no-interaction
fi

# 6. Cache configuration, routes, and views in production
if [ "${APP_ENV:-production}" = "production" ]; then
    su-exec www-data php artisan config:cache
    su-exec www-data php artisan route:cache
    su-exec www-data php artisan view:cache
else
    su-exec www-data php artisan config:clear >/dev/null 2>&1 || true
fi

exec "$@"
