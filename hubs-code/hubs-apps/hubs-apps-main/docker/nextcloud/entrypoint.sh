#!/usr/bin/env bash
#
# entrypoint.sh — nextcloud container PID 1. Runtime-only work;
# build-time steps live in docker/nextcloud/Dockerfile.
#
# NC core (apps/server) is bind-mounted live over /var/www/html, so
# anything patching files there must run here at runtime — the bind
# shadows the image, so image-build patching wouldn't survive.
#
# No chown loop: the container's developer user matches host UID/GID
# (DEVELOPER_UID/DEVELOPER_GID build args), so bind-mounted host files
# arrive already correctly-owned.

set -euo pipefail

NC_ROOT="/var/www/html"
INSTALL_VERSION_MARKER="$NC_ROOT/config/.installed-version"

# NC autoloader patch (nextcloud/server#10613). NC's autoloader throws
# on paths outside its allow-list — our overlay symlinks trip it.
# Replace the throw with `return false` to fall through to the next
# autoloader.
#
# Sed-in-place on apps/server's working tree (shows as modified under
# `git -C apps/server status`), not a vendored full autoloader.php:
# the vendored copy would drift on every NEXTCLOUD_VERSION bump.
#
# Idempotent + drift guard: the grep token gates the sed (skip if
# already patched). The verification grep below crashes loud if
# NEITHER throw nor token is present — upstream moved the matched line
# in this NEXTCLOUD_VERSION and the sed pattern needs updating; better
# than booting a silently-unpatched autoloader.
#
# The `/* hubs-apps NC#10613 ... */` in PATCH_REPLACEMENT is the grep
# token AND an inline rationale for anyone hitting the modified
# lib/autoloader.php. Don't reword it without updating both greps.
AUTOLOADER="$NC_ROOT/lib/autoloader.php"
PATCH_REPLACEMENT="return false; /* hubs-apps NC#10613: overlay symlinks in apps/<X>/.build/ trip NC's autoloader allow-list (validRoots check); return false to fall through to the next registered autoloader. Patched at runtime by docker/nextcloud/entrypoint.sh. */"
if grep -qF 'throw new AutoloadNotAllowedException($fullPath);' "$AUTOLOADER"; then
    sed -i "s|throw new AutoloadNotAllowedException(\$fullPath);|${PATCH_REPLACEMENT}|" \
        "$AUTOLOADER"
fi
grep -qF 'hubs-apps NC#10613' "$AUTOLOADER" || {
    echo "ERROR: NC autoloader patch (#10613) couldn't be applied to $AUTOLOADER." >&2
    echo "       Upstream likely changed the matched line in NEXTCLOUD_VERSION=$NEXTCLOUD_VERSION;" >&2
    echo "       update the sed pattern in $(basename "$0")." >&2
    exit 1
}

# Start cron — sysv services don't auto-start in a container.
# Crontab ships in the image at /etc/cron.d/nextcloud.
sudo service cron start

# First-run install. Needs DB connectivity, so can't be image-time.
# Branch on occ's actual marker, not just "did occ fail": a genuine
# occ/DB failure emits NEITHER marker, and we want it to crash with
# occ's output here rather than trigger a spurious maintenance:install
# that crashes later with a misleading error. `2>&1` captures occ's
# stderr; `|| true` keeps its non-zero exit from tripping set -e before
# we inspect $status_out. `is not installed` arm covers NC versions
# that emit that instead of `installed: false`.
status_out="$(php occ status 2>&1)" || true
case "$status_out" in
    *"installed: true"*)
        : ;;  # already installed — intentional no-op
    *"installed: false"* | *"is not installed"*)
        echo "==> Installing Nextcloud"
        php occ maintenance:install \
            --verbose \
            --database "pgsql" \
            --database-host "postgres" \
            --database-port "5432" \
            --database-name "nextcloud" \
            --database-user "nextcloud" \
            --database-pass "nextcloud" \
            --admin-user "admin" \
            --admin-pass "admin"
        ;;
    *)
        echo "ERROR: 'occ status' could not determine install state (DB not ready or occ broken):" >&2
        printf '%s\n' "$status_out" >&2
        exit 1
        ;;
esac

# Wire up apps-extra/ symlinks + app:enable. The two-step shape (host
# bind at /srv/apps → in-container symlinks under apps-extra/) is
# load-bearing: it moves the symlink-resolution origin into
# /srv/apps/<app>/.build/, where the relative `../../overlay/<file>`
# links resolve into the sibling overlay/ (visible via the parent bind
# of host apps/ at /srv/apps). Collapse it and overlay resolution
# breaks. Logic in image-baked /usr/local/bin/discover-apps.
# --strict crashes on a quilt app missing .build/ — the failure we
# want surfaced at startup.
/usr/local/bin/discover-apps --strict

# Regenerate .htaccess on every start: a previous container's runtime
# may have left a different one than apps/server's tracked copy.
# Then strip the long-cache headers so HMR-served JS isn't months-
# cached by the browser.
php occ maintenance:update:htaccess
NC_HTACCESS="$NC_ROOT/.htaccess"
if grep -q "max-age=15778463" "$NC_HTACCESS"; then
    sed -i \
        -e '/Header set Cache-Control.*max-age/d' \
        -e '/Header set Cache-Control.*immutable/d' \
        "$NC_HTACCESS"
fi

# Restore HMR proxy block — it's derived from the running HMR-mode
# sidecar-<app> containers, so a nextcloud-only restart mid-session
# would otherwise leave NC with no proxy block until the next
# `make webpack`. Idempotent — no-op when no HMR sidecars run.
/usr/local/bin/regen-htaccess

# Install/upgrade gated block. Marker lives in the nextcloud_config
# named volume, so it survives container recreations.
#
# Gotcha: bumping NEXTCLOUD_VERSION alone does NOT upgrade NC — the
# marker still matches the volume's installed source. A version bump
# requires `make distclean && make setup && make nc-up` to wipe the
# volumes and re-clone at the new version; only then does this gate
# fire.
if [ -f "$INSTALL_VERSION_MARKER" ]; then
    LAST_INSTALL_VERSION="$(cat "$INSTALL_VERSION_MARKER")"
else
    LAST_INSTALL_VERSION=""
fi
if [ "$LAST_INSTALL_VERSION" != "$NEXTCLOUD_VERSION" ]; then
    echo "==> Running install/upgrade tasks (was: ${LAST_INSTALL_VERSION:-none}, now: $NEXTCLOUD_VERSION)"

    # Enable the image-baked apps under apps/ (vendored from upstream
    # NC per Dockerfile). hmr_enabler lives in apps-extra/ and is
    # handled by the discover-apps loop above, not here. Enable-state
    # persists in postgres, so this only runs on install/upgrade.
    php occ app:enable notifications
    php occ app:enable logreader
    php occ app:enable viewer

    php occ db:add-missing-indices
    php occ maintenance:repair --include-expensive

    # MigrateBackgroundImages (nextcloud/server#38114) workaround:
    # upstream migration crashes if appdata_<instanceid>/theming/global
    # is missing, so pre-create it. instanceid exists only post-install.
    INSTANCEID=$(php occ config:system:get instanceid)
    DATADIR=$(php occ config:system:get datadirectory)
    mkdir -p "$DATADIR/appdata_$INSTANCEID/theming/global"

    echo "$NEXTCLOUD_VERSION" > "$INSTALL_VERSION_MARKER"
fi

# exec via sudo: apache must start as root to bind port 80 and open
# root-owned error.log, then drops to APACHE_RUN_USER for workers.
# exec (not a child) keeps the signal chain intact: docker stop →
# SIGTERM to sudo (PID 1) → apache → shutdown.
echo "==> Nextcloud available at http://localhost:8080"
exec sudo apachectl -DFOREGROUND
