# ITSL Hubs-Apps — Nextcloud container (PHP + Apache on Ubuntu).

FROM ubuntu:jammy

# Run every RUN under bash with pipefail, so a pipe whose left side fails
# fails the build instead of being masked by the right side's status.
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

ARG DEBIAN_FRONTEND=noninteractive
ARG DEVELOPER_GID
ARG DEVELOPER_UID
ARG PHP_MEMORY_LIMIT
ARG PHP_POST_MAX_SIZE
ARG PHP_UPLOAD_MAX_FILESIZE
ARG PHP_VERSION

ENV PHP_VERSION=$PHP_VERSION

RUN apt-get update && \
    apt-get dist-upgrade --yes && \
    apt-get install --no-install-recommends --yes \
        "curl" \
        "gnupg" \
        "openssl" \
        "software-properties-common" \
        "sudo" && \
    curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c" \
        -o "/etc/apt/keyrings/ondrej.asc" && \
    chmod a+r "/etc/apt/keyrings/ondrej.asc" && \
    echo "deb\
 [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/ondrej.asc]\
 https://ppa.launchpadcontent.net/ondrej/apache2/ubuntu\
 jammy\
 main" > "/etc/apt/sources.list.d/ondrej-apache2.list" && \
    echo "deb\
 [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/ondrej.asc]\
 https://ppa.launchpadcontent.net/ondrej/php/ubuntu\
 jammy\
 main" > "/etc/apt/sources.list.d/ondrej-php.list" && \
    apt-get update && \
    apt-get install --no-install-recommends --yes \
        "apache2" \
        "cron" \
        "git" \
        "iputils-ping" \
        "libmagickcore-6.q16-3-extra" \
        "lsof" \
        "nano" \
        "php${PHP_VERSION}-apcu" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-gmp" \
        "php${PHP_VERSION}-imagick" \
        "php${PHP_VERSION}-imap" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-opcache" \
        "php${PHP_VERSION}-pgsql" \
        "php${PHP_VERSION}-redis" \
        "php${PHP_VERSION}-soap" \
        "php${PHP_VERSION}-xdebug" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-xmlrpc" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}" \
        "unzip" && \
    rm -fr /var/lib/apt/lists/*

RUN echo "apc.enable_cli=1" >> "/etc/php/${PHP_VERSION}/cli/conf.d/20-apcu.ini"

RUN { \
    echo "memory_limit=$PHP_MEMORY_LIMIT"; \
    echo "post_max_size=$PHP_POST_MAX_SIZE"; \
    echo "upload_max_filesize=$PHP_UPLOAD_MAX_FILESIZE"; \
} >> "/etc/php/${PHP_VERSION}/apache2/conf.d/20-limit.ini"

RUN { \
    echo "xdebug.client_host=127.0.0.1"; \
    echo "xdebug.client_port=9003"; \
    echo "xdebug.mode=debug"; \
    echo "xdebug.start_with_request=trigger"; \
} >> "/etc/php/${PHP_VERSION}/apache2/conf.d/20-xdebug.ini"

RUN a2enmod "headers" "rewrite" "proxy" "proxy_http" "proxy_wstunnel"

# Rewrites `sidecar-<app>:3000` into NC's request path via .htaccess,
# regenerated from the running sidecar-* containers (compose service-name
# DNS disambiguates per app). Run from Makefile HMR recipes.
COPY "docker/nextcloud/regen-htaccess" "/usr/local/bin/regen-htaccess"

# apps-extra discovery — symlinks + app:enable. entrypoint.sh runs it
# --strict (crash on missing .build/); `make new-app` and operators run
# it default (skip + warn) to add apps without an nc-down/nc-up cycle.
COPY "docker/nextcloud/discover-apps" "/usr/local/bin/discover-apps"

# Container entrypoint — runtime bootstrap: DB install on first run,
# apps-extra symlinks, apache start.
COPY "docker/nextcloud/entrypoint.sh" "/usr/local/bin/entrypoint.sh"
# Set the exec bit in a RUN, not via `COPY --chmod`: --chmod needs BuildKit,
# which isn't guaranteed for the compose-up auto-build (a legacy-builder
# fallback fails on it), and two of these sources are 0644 in git. Plain
# COPY + chmod builds on either builder.
RUN chmod 0755 /usr/local/bin/regen-htaccess /usr/local/bin/discover-apps /usr/local/bin/entrypoint.sh
RUN echo "ServerName localhost" >> "/etc/apache2/apache2.conf" && \
    echo "LimitRequestBody 0" > "/etc/apache2/conf-available/limit-request-body.conf" && \
    a2enconf "limit-request-body"

# AllowOverride All — NC's URL rewrites live in .htaccess; without it
# every NC URL 404s. Trailing `grep -q` asserts the insert landed:
# `sed -i` exits 0 on zero matches, so a future base image lacking the
# `</VirtualHost>` anchor would silently ship a 404-on-every-URL image.
# Crash at build time instead (as the autoloader + LaxifyCSP seds do).
RUN sed -i \
    -e '/<\/VirtualHost>/i \	<Directory /var/www/html/>' \
    -e '/<\/VirtualHost>/i \		AllowOverride All' \
    -e '/<\/VirtualHost>/i \	</Directory>' \
    "/etc/apache2/sites-enabled/000-default.conf" \
 && grep -q 'AllowOverride All' "/etc/apache2/sites-enabled/000-default.conf"

# Dev mode: opcache ON, but with per-request timestamp revalidation
# (validate_timestamps=1, revalidate_freq=0) so live edits to the bind-mounted
# apps/server NC core still surface immediately — a changed file's mtime forces
# a recompile on the next request — while unchanged files run from cached
# bytecode. A blanket `phpdismod opcache` recompiled all of NC on EVERY request
# (e.g. the /status.php healthcheck), burning CPU on an otherwise-idle server.
# enable_cli stays off so occ / cron.php always run fresh source.
RUN phpenmod opcache && \
    { \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=0"; \
        echo "opcache.validate_timestamps=1"; \
        echo "opcache.revalidate_freq=0"; \
    } >> "/etc/php/${PHP_VERSION}/apache2/conf.d/20-opcache-dev.ini"

# NC scheduled tasks (cron daemon started by entrypoint). /etc/cron.d/
# format takes a user field, so cron.php runs as `developer`.
RUN echo '*/1 * * * * developer php -f /var/www/html/cron.php >> /home/developer/nextcloud-cron.log 2>&1' \
        > "/etc/cron.d/nextcloud" && \
    chmod 0644 "/etc/cron.d/nextcloud"

ENV DEVELOPER_GID=$DEVELOPER_GID
ENV DEVELOPER_UID=$DEVELOPER_UID
# `adm` group: read access to /var/log/apache2 (root:adm 0750) so
# `docker exec nextcloud tail /var/log/apache2/access.log` works as the
# default `developer` user without needing -u root.
# A host GID can already be taken in the base image (macOS GID 20 is
# `dialout` in Ubuntu), so `groupadd --gid` would exit 4. Rename the
# group already holding the GID to `developer` instead of creating one.
RUN if existing="$(getent group "$DEVELOPER_GID")"; then \
        groupmod --new-name "developer" "${existing%%:*}"; \
    else \
        groupadd --gid "$DEVELOPER_GID" "developer"; \
    fi && \
    useradd --gid "$DEVELOPER_GID" --uid "$DEVELOPER_UID" --create-home --shell "/bin/bash" "developer" && \
    echo "developer:developer" | chpasswd && \
    usermod -a -G "sudo,adm" "developer" && \
    echo "developer ALL=(ALL) NOPASSWD: ALL" >> "/etc/sudoers"

ENV APACHE_RUN_GROUP=developer
ENV APACHE_RUN_USER=developer
# grep -q after each sed: `sed -ri` exits 0 on zero matches, so an
# envvars format not matching `^export APACHE_RUN_*=` would silently
# leave apache as the default user, and bind-mounted writes would land
# as the wrong UID. Assert the substitution landed; else crash at build.
RUN sed -ri "s/^export APACHE_RUN_GROUP=.*$/export APACHE_RUN_GROUP=${APACHE_RUN_GROUP}/" "/etc/apache2/envvars" && \
    sed -ri "s/^export APACHE_RUN_USER=.*$/export APACHE_RUN_USER=${APACHE_RUN_USER}/" "/etc/apache2/envvars" && \
    grep -q "^export APACHE_RUN_GROUP=${APACHE_RUN_GROUP}$" "/etc/apache2/envvars" && \
    grep -q "^export APACHE_RUN_USER=${APACHE_RUN_USER}$" "/etc/apache2/envvars"

RUN rm -fr /var/www/html/* && \
    chown -R "developer:developer" "/var/www/html"

USER developer

# At runtime compose binds apps/server over /var/www/html, so lib/, core/,
# etc. stay live-editable. We bake ONLY the paths the named volumes shadow
# — apps/, apps-extra/, config/, data/ — so those volumes seed from the
# image on first mount instead of the bind. This COPY bakes the bundled NC
# apps (activity, dav, files, …); the vendored clones below add to the same
# apps/ dir before the volume captures it. Host edits to a baked app under
# apps/server/apps/ don't reach the running NC (the volume shadows the
# bind) — by design: vendored-app state is volume-managed, not host-edited.
COPY --chown=developer:developer "apps/server/apps/" "/var/www/html/apps/"
COPY --chown=developer:developer "docker/nextcloud/config.php" "/var/www/html/config/config.php"

# The nextcloud/server#10613 autoloader patch (replace
# `throw new AutoloadNotAllowedException(...)` with `return false;`) is
# applied at runtime in entrypoint.sh, not here: the apps/server bind
# masks the image's lib/, so the patch must hit the bind. Idempotent,
# with a verification grep that crashes if a future NEXTCLOUD_VERSION
# changes the line.

# Vendored NC apps required by the mail/calendar UI. They clone into apps/
# alongside the bundled apps. notifications/logreader/viewer are first-party
# nextcloud/* repos that tag in lockstep with each NC server release, so they
# derive from NEXTCLOUD_VERSION (the single source of truth, passed as a build
# arg from compose) rather than carrying independent hardcoded tags — a skew
# here would mix companion apps from one NC release with a server from another.
ARG NEXTCLOUD_VERSION
RUN git clone --depth 1 --branch "$NEXTCLOUD_VERSION" \
        "https://github.com/nextcloud/notifications.git" \
        "/var/www/html/apps/notifications" && \
    git clone --depth 1 --branch "$NEXTCLOUD_VERSION" \
        "https://github.com/nextcloud/logreader.git" \
        "/var/www/html/apps/logreader" && \
    git clone --depth 1 --branch "$NEXTCLOUD_VERSION" \
        "https://github.com/nextcloud/viewer.git" \
        "/var/www/html/apps/viewer"

# HMR support (#43). hmr_enabler lives in apps-extra/, not apps/, so
# runtime symlinks for other apps land alongside without touching it.
# LaxifyCSP sed adds allowEvalScript(true) so webpack.hmr.js's
# `devtool: 'eval'` runs (vue-loader#1795 requires it); drop the sed when
# upstream lands the call. A second sed allows the `webpack:` scheme in
# connect-src: `eval` stamps each module with `//# sourceURL=webpack://`,
# and dev tooling fetches those URLs at runtime. LaxifyCSP's `connect-src
# *` cannot cover them — per the CSP spec `*` matches only network schemes
# (http/https/ws/wss), so non-network schemes must be listed explicitly.
#
# Pinned to a commit (upstream has no tags) for deterministic rebuilds —
# a moving ref would push NC into upgrade-required mode on every build.
# Bump the SHA when upstream ships a relevant fix.
#
# Trailing `grep -q` asserts the sed landed: `sed -i` exits 0 on zero
# replacements, so a SHA bump onto refactored upstream would silently
# build an unpatched image and break HMR. Crash at build time instead.
ARG HMR_ENABLER_REF=054ab860e547aa69938508338ae8eccfdf0213e5
RUN mkdir -p "/var/www/html/apps-extra/hmr_enabler" && \
    cd "/var/www/html/apps-extra/hmr_enabler" && \
    git init -q && \
    git fetch --depth 1 "https://github.com/nextcloud/hmr_enabler.git" "$HMR_ENABLER_REF" && \
    git checkout -q FETCH_HEAD && \
    sed -i 's|\$csp->addAllowedScriptDomain(.*);|&\n\t\t\$csp->allowEvalScript(true);|' \
        "/var/www/html/apps-extra/hmr_enabler/lib/Listener/LaxifyCSP.php" && \
    grep -q 'allowEvalScript(true)' \
        "/var/www/html/apps-extra/hmr_enabler/lib/Listener/LaxifyCSP.php" && \
    sed -i 's|\$csp->addAllowedConnectDomain(.*);|&\n\t\t\$csp->addAllowedConnectDomain("webpack:");|' \
        "/var/www/html/apps-extra/hmr_enabler/lib/Listener/LaxifyCSP.php" && \
    grep -q 'addAllowedConnectDomain("webpack:")' \
        "/var/www/html/apps-extra/hmr_enabler/lib/Listener/LaxifyCSP.php"

# Create data/ as developer so the nextcloud_data volume inherits its
# ownership on first init. Else docker makes the mount point root-owned
# and NC can't write inside (uploads, profiler subdir).
RUN mkdir -p "/var/www/html/data"

WORKDIR "/var/www/html"

CMD ["/usr/local/bin/entrypoint.sh"]
