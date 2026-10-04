#!/bin/sh
# 5Star container entrypoint.
#
#   1. Map Railway's MySQL variables to the app's DB_* names (if not set).
#   2. Default APP_URL to the Railway public domain (if not set).
#   3. Refuse to start without the secrets the API cannot run safely without.
#   4. Point uploads/ and storage/ at the persistent volume.
#   5. Wait for the database, apply pending migrations + idempotent seeds.
#   6. Optionally create/update the admin account from ADMIN_* variables.
#   7. Print the go-live preflight report into the deploy log.
#   8. Run the scheduler every minute in the background.
#   9. Hand over to Apache.
set -eu

APP_DIR=/var/www/app/backend
DATA_DIR="${DATA_DIR:-/data}"

log() { printf '[5star] %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 1. Database connection
# ---------------------------------------------------------------------------
if [ -z "${DB_HOST:-}" ]; then
    if [ -n "${MYSQLHOST:-}" ]; then
        export DB_HOST="$MYSQLHOST"
        export DB_PORT="${MYSQLPORT:-3306}"
        export DB_DATABASE="${MYSQLDATABASE:-railway}"
        export DB_USERNAME="${MYSQLUSER:-root}"
        export DB_PASSWORD="${MYSQLPASSWORD:-}"
        log "Database: using Railway MYSQL* variables (host $DB_HOST)"
    else
        URL="${MYSQL_URL:-${DATABASE_URL:-${MARIADB_URL:-}}}"
        if [ -n "$URL" ]; then
            # Parsed in PHP so URL-encoded passwords and odd characters survive.
            if ! exports="$(DB_URL_TO_PARSE="$URL" php -r '
                $u = parse_url((string) getenv("DB_URL_TO_PARSE"));
                if ($u === false || !isset($u["host"])) { fwrite(STDERR, "[5star] Could not parse database URL\n"); exit(1); }
                $vars = [
                    "DB_HOST" => $u["host"],
                    "DB_PORT" => (string) ($u["port"] ?? 3306),
                    "DB_DATABASE" => ltrim($u["path"] ?? "", "/"),
                    "DB_USERNAME" => rawurldecode($u["user"] ?? ""),
                    "DB_PASSWORD" => rawurldecode($u["pass"] ?? ""),
                ];
                foreach ($vars as $k => $v) { echo "export ", $k, "=", escapeshellarg($v), "\n"; }
            ')"; then
                log "FATAL: the database URL could not be parsed."
                exit 1
            fi
            eval "$exports"
            log "Database: using connection URL (host $DB_HOST)"
        fi
    fi
fi

if [ -z "${DB_HOST:-}" ]; then
    log "FATAL: no database configured. Add a MySQL service and reference it,"
    log "       or set DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD."
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Public URL (used for image links, referral links, QR codes)
# ---------------------------------------------------------------------------
if [ -z "${APP_URL:-}" ] && [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
    export APP_URL="https://${RAILWAY_PUBLIC_DOMAIN}"
    log "APP_URL not set; using $APP_URL"
fi

export APP_ENV="${APP_ENV:-production}"
export APP_DEBUG="${APP_DEBUG:-false}"

# ---------------------------------------------------------------------------
# 3. Secrets
# ---------------------------------------------------------------------------
missing=""
for key in JWT_SECRET OTP_PEPPER; do
    eval "value=\${$key:-}"
    if [ -z "$value" ]; then
        missing="$missing $key"
    elif [ "${#value}" -lt 32 ]; then
        log "FATAL: $key is shorter than 32 characters."
        exit 1
    fi
done
if [ -n "$missing" ]; then
    log "FATAL: required variables not set:$missing"
    log "       Generate each with: openssl rand -hex 32"
    exit 1
fi

# ---------------------------------------------------------------------------
# 4. Persistent data
# ---------------------------------------------------------------------------
mkdir -p "$DATA_DIR/uploads" \
         "$DATA_DIR/storage/logs" \
         "$DATA_DIR/storage/backups" \
         "$DATA_DIR/storage/imports/tmp"

rm -rf "$APP_DIR/public/uploads" "$APP_DIR/storage"
ln -s "$DATA_DIR/uploads" "$APP_DIR/public/uploads"
ln -s "$DATA_DIR/storage" "$APP_DIR/storage"

if ! mountpoint -q "$DATA_DIR" 2>/dev/null; then
    log "WARNING: $DATA_DIR is not a mounted volume. Uploaded images and logs"
    log "         will be lost on the next deploy. Attach a Railway volume at $DATA_DIR."
fi

fix_ownership() {
    # Only touch what is not already www-data, so a large image library does
    # not slow every boot.
    find "$DATA_DIR" \! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
}
fix_ownership

# ---------------------------------------------------------------------------
# 5. Database readiness + migrations
# ---------------------------------------------------------------------------
log "Waiting for database at $DB_HOST:${DB_PORT:-3306} ..."
attempt=0
until php -r '
    try {
        new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")),
            getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]
        );
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
' 2>/tmp/db-error; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 40 ]; then
        log "FATAL: database not reachable after ~2 minutes: $(cat /tmp/db-error)"
        exit 1
    fi
    sleep 3
done
log "Database is reachable."

cd "$APP_DIR"

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # Seeds create roles, permissions, settings and starter catalog data. They
    # run on a brand-new database only: on later boots they would re-assert
    # their demo rows and undo edits made in the admin console.
    # RUN_SEEDS=true forces them (e.g. after adding a new seed file).
    fresh_db="$(php -r '
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE")),
            getenv("DB_USERNAME"), getenv("DB_PASSWORD")
        );
        $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '"'"'schema_migrations'"'"'")->fetchColumn();
        echo $n === 0 ? "yes" : "no";
    ')"

    if [ "$fresh_db" = "yes" ] || [ "${RUN_SEEDS:-false}" = "true" ]; then
        log "Applying migrations and seeds (fresh database: $fresh_db) ..."
        php bin/migrate.php
    else
        log "Applying pending migrations (seeds skipped; set RUN_SEEDS=true to force) ..."
        php bin/migrate.php --no-seed
    fi
else
    log "RUN_MIGRATIONS=false, skipping migrations."
fi

# ---------------------------------------------------------------------------
# 6. Admin account (first deploy only — remove the variables afterwards)
# ---------------------------------------------------------------------------
if [ -n "${ADMIN_PASSWORD:-}" ]; then
    log "ADMIN_PASSWORD is set: creating/updating the administrator account."
    php bin/seed_admin.php \
        --name="${ADMIN_NAME:-Administrator}" \
        --mobile="${ADMIN_MOBILE:-}" \
        --email="${ADMIN_EMAIL:-}" \
        --password="$ADMIN_PASSWORD"
    log "Done. Delete ADMIN_PASSWORD from the Railway variables now, or every"
    log "redeploy will reset the admin password to it."
fi

# ---------------------------------------------------------------------------
# 7. Go-live report (informational; never blocks the boot)
# ---------------------------------------------------------------------------
if [ "${RUN_PREFLIGHT:-true}" = "true" ]; then
    php bin/preflight.php || log "Preflight reported blockers (see above)."
fi

fix_ownership

# ---------------------------------------------------------------------------
# 8. Scheduler (one runner per container; tasks are claimed atomically in
#    the database, so extra replicas are safe)
# ---------------------------------------------------------------------------
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
    (
        while true; do
            runuser -u www-data -- php "$APP_DIR/bin/scheduler.php" \
                >> "$DATA_DIR/storage/logs/scheduler.log" 2>&1 || true
            sleep 60
        done
    ) &
    log "Scheduler started (every 60s, log: storage/logs/scheduler.log)."
fi

# ---------------------------------------------------------------------------
# 9. Apache on Railway's port
# ---------------------------------------------------------------------------
export PORT="${PORT:-8080}"
echo "Listen ${PORT}" > /etc/apache2/ports.conf

# mod_php needs the prefork MPM, and Apache refuses to start with more than
# one MPM enabled ("AH00534: More than one MPM loaded"). Enforce exactly one
# at runtime as well, whatever the base image or platform left enabled.
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
    ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
fi
log "Apache MPM: $(ls /etc/apache2/mods-enabled | grep '^mpm_.*\.load$' | tr '\n' ' ')"

if ! apache2ctl -t 2>/tmp/apache-configtest; then
    log "FATAL: Apache configuration test failed:"
    cat /tmp/apache-configtest
    exit 1
fi
log "Starting Apache on port $PORT"

exec "$@"
