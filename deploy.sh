#!/usr/bin/env bash
# =============================================================================
# Deploy pembaruan dari GitHub ke server (aaPanel).
#
# Pemakaian (di server, dari folder aplikasi):
#     bash deploy.sh                # pull + update + migrate (database di-backup dulu)
#     bash deploy.sh --no-migrate   # pull + update, tabel database tidak disentuh
#
# Aman untuk data:
#   - Tidak pernah menghapus / mengosongkan database (tidak ada migrate:fresh / db:seed).
#   - Sebelum migrate, database di-backup ke storage/backups/ (butuh mysqldump).
#   - .env, file upload (storage/app) dan backup tidak tersentuh oleh git pull.
# =============================================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BRANCH="${BRANCH:-main}"
WEB_USER="${WEB_USER:-www}"          # user web server aaPanel (biasanya "www")
PHP_BIN="${PHP_BIN:-php}"            # mis. PHP_BIN=/www/server/php/83/bin/php
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
MIGRATE=1
[[ "${1:-}" == "--no-migrate" ]] && MIGRATE=0

cd "$APP_DIR"
step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

[[ -f .env ]] || { echo "File .env belum ada. Salin dari .env.example dan isi dulu (lihat DEPLOY.md)."; exit 1; }

step "Mode maintenance"
"$PHP_BIN" artisan down --retry=15 || true
trap '"$PHP_BIN" artisan up >/dev/null 2>&1 || true' EXIT   # selalu hidup lagi, walau ada error

step "Ambil kode terbaru dari GitHub ($BRANCH)"
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
git fetch --prune origin "$BRANCH"
git reset --hard "origin/$BRANCH"     # kode server = kode di GitHub (file yang di-.gitignore tetap aman)

step "Library PHP (composer)"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

step "Build tampilan (CSS/JS)"
if command -v npm >/dev/null 2>&1; then
    npm ci --no-audit --no-fund
    npm run build
else
    echo "npm tidak ditemukan: lewati build. Pasang Node.js (aaPanel > App Store > Node.js) agar tampilan ikut terbarui."
fi

if [[ $MIGRATE -eq 1 ]]; then
    step "Backup database sebelum migrate"
    mkdir -p storage/backups
    env_val() { { grep -E "^$1=" .env || true; } | tail -1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^"//' -e 's/"$//'; }
    DB_PORT="$(env_val DB_PORT)"; DB_HOST="$(env_val DB_HOST)"
    DB_NAME="$(env_val DB_DATABASE)"
    if command -v mysqldump >/dev/null 2>&1 && [[ -n "$DB_NAME" ]]; then
        FILE="storage/backups/${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
        MYSQL_PWD="$(env_val DB_PASSWORD)" mysqldump --single-transaction --quick --no-tablespaces \
            -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$(env_val DB_USERNAME)" "$DB_NAME" | gzip > "$FILE"
        echo "Backup: $FILE"
        ls -1t storage/backups/*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm --   # simpan 10 backup terakhir
    else
        echo "mysqldump tidak ada / DB_DATABASE kosong: backup dilewati. Export manual dari phpMyAdmin dulu bila ragu."
    fi

    step "Migrate (hanya menambah perubahan tabel baru, data tetap)"
    "$PHP_BIN" artisan migrate --force
fi

step "Cache konfigurasi, route, view"
"$PHP_BIN" artisan storage:link 2>/dev/null || true
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan cache:clear        # dashboard dihitung ulang dengan kode baru

step "Hak akses folder"
if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache
fi
chmod -R ug+rwX storage bootstrap/cache

"$PHP_BIN" artisan up
trap - EXIT
step "Selesai: $(git log -1 --format='%h %s')"
