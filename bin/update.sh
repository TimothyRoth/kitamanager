#!/usr/bin/env bash
# Puts freshly pulled code into service: run it right after "git pull".
# Usage, as root from anywhere:  bin/update.sh [php-binary]
set -euo pipefail
cd "$(dirname "$0")/.."

APP_USER=www-data
PHP=${1:-php}

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo bin/update.sh" >&2
    exit 1
fi

as_app_user() {
    sudo -H -u "$APP_USER" -- "$@"
}

backup_databases() {
    local target database
    target="var/backup/$(date +%Y%m%d-%H%M%S)"

    for database in var/data_*.db; do
        [[ -e $database ]] || continue
        mkdir -p "$target"
        cp "$database" "$target/"
        echo "Backed up $database to $target/"
    done
}

backup_databases
chown -R "$APP_USER:$APP_USER" .

as_app_user "$PHP" "$(command -v composer)" install --no-interaction
as_app_user "$PHP" bin/console doctrine:migrations:migrate --no-interaction
as_app_user "$PHP" bin/console cache:clear

echo "Update finished."
