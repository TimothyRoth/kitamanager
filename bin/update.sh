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

has_app_secret() {
    as_app_user "$PHP" -r '
        require "vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env");
        exit("" === ($_SERVER["APP_SECRET"] ?? "") ? 1 : 0);
    '
}

# The login needs a secret and the repository ships none. A machine without
# one gets its own, once, in .env.local (which git ignores).
ensure_app_secret() {
    if has_app_secret; then
        return
    fi

    printf 'APP_SECRET=%s\n' "$("$PHP" -r 'echo bin2hex(random_bytes(32));')" >> .env.local
    chown "$APP_USER:$APP_USER" .env.local
    chmod 640 .env.local
    echo "Created an APP_SECRET in .env.local."
}

backup_databases
chown -R "$APP_USER:$APP_USER" .

as_app_user "$PHP" "$(command -v composer)" install --no-interaction
ensure_app_secret
as_app_user "$PHP" bin/console doctrine:migrations:migrate --no-interaction
as_app_user "$PHP" bin/console cache:clear
as_app_user "$PHP" bin/health-check.php

echo "Update finished."
