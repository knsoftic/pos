#!/usr/bin/env bash
#
# Deploy the current origin/master onto this server.
#
#   bash deploy.sh
#
# ─────────────────────────────────────────────────────────────────────────────
# THE ORDER IS THE WHOLE POINT. Every step here is placed where it is because
# putting it elsewhere has already broken this site once:
#
#   reset --hard, not pull   The server should be exactly origin. `pull` stops
#                            on a conflict if anyone edited a file here, and a
#                            half-applied deploy is worse than a refused one.
#
#   vendor:publish           Livewire serves its JS from a ROUTE by default, at
#                            a path that depends on app.debug. With cached
#                            routes that path and the rendered <script> tag can
#                            disagree — the asset 404s, Livewire never loads,
#                            Alpine (which ships inside it) never loads, and
#                            every x-model form silently submits nothing.
#                            Publishing makes it a real static file instead.
#
#   optimize AFTER .env      A config or route cache older than .env is worse
#                            than no cache. `pos:preflight` checks for exactly
#                            that at the end.
#
#   chown LAST               artisan runs as root and writes root-owned files
#                            into bootstrap/cache and storage. nginx and
#                            PHP-FPM run as `www`. Chown before artisan and the
#                            site goes back to 500.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/pos.knbazaar.com}"
PHP="${PHP:-/www/server/php/82/bin/php}"
COMPOSER="${COMPOSER:-/usr/bin/composer}"
WEB_USER="${WEB_USER:-www}"

step() { printf '\n\033[1;36m▸ %s\033[0m\n' "$1"; }

cd "$APP_DIR"

step "Fetching origin/master"
git fetch origin
git reset --hard origin/master
git --no-pager log --oneline -1

step "Installing dependencies (no dev)"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader

step "Running migrations"
"$PHP" artisan migrate --force

# Re-published every deploy on purpose: a composer update that bumps Livewire
# leaves the previously published files behind, and Livewire then warns in the
# browser console that its assets are out of date.
step "Publishing Livewire assets as static files"
"$PHP" artisan vendor:publish --force --tag=livewire:assets

step "Rebuilding config, route, view and event caches"
"$PHP" artisan optimize

step "Handing the files back to $WEB_USER"
chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache public/vendor
chmod 640 .env

step "Preflight"
"$PHP" artisan pos:preflight
