# Based on the Nextcloud DevContainer.
# https://github.com/nextcloud/server/tree/master/.devcontainer

FROM ubuntu:jammy

ARG DEBIAN_FRONTEND=noninteractive
ARG DEVELOPER_GID
ARG DEVELOPER_UID
ARG NEXTCLOUD_VERSION
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
 main" | sudo tee "/etc/apt/sources.list.d/ondrej-apache2.list" && \
    echo "deb\
 [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/ondrej.asc]\
 https://ppa.launchpadcontent.net/ondrej/php/ubuntu\
 jammy\
 main" | sudo tee "/etc/apt/sources.list.d/ondrej-php.list" && \
    apt-get update && \
    apt-get install --no-install-recommends --yes \
        "apache2" \
        "build-essential" \
        "cron" \
        "curl" \
        "git" \
        "gnupg2" \
        "graphviz" \
        "iputils-ping" \
        "libmagickcore-6.q16-3-extra" \
        "lsof" \
        "make" \
        "nano" \
        "openjdk-21-jdk" \
        "openssh-server" \
        "php${PHP_VERSION}-apcu" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-common" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-dev" \
        "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-gmp" \
        "php${PHP_VERSION}-imagick" \
        "php${PHP_VERSION}-imap" \
        "php${PHP_VERSION}-intl" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-opcache" \
        "php${PHP_VERSION}-pgsql" \
        "php${PHP_VERSION}-redis" \
        "php${PHP_VERSION}-soap" \
        "php${PHP_VERSION}-sqlite" \
        "php${PHP_VERSION}-xdebug" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-xmlrpc" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}" \
        "unzip" \
        "vim" && \
    rm -fr /var/lib/apt/lists/*

RUN curl -sS "https://getcomposer.org/installer" -o "/tmp/composer-setup.php" && \
    curl -sS "https://composer.github.io/installer.sig" -o "/tmp/composer-setup.sig" && \
    php -r "\
if (hash_file('sha384', '/tmp/composer-setup.php') !== trim(file_get_contents('/tmp/composer-setup.sig'))) { \
    echo 'Composer installation failed, invalid hash'; \
    exit(1); \
}" && \
    php "/tmp/composer-setup.php" "--filename=composer" "--install-dir=/usr/local/bin" && \
    rm "/tmp/composer-setup.php" "/tmp/composer-setup.sig"

RUN echo "apc.enable_cli=1" >> "/etc/php/${PHP_VERSION}/cli/conf.d/20-apcu.ini"

RUN { \
    echo "xdebug.remote_autostart = 1"; \
    echo "xdebug.remote_enable = 1"; \
} >> "/etc/php/${PHP_VERSION}/cli/conf.d/20-xdebug.ini"

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

RUN a2enmod "headers" "rewrite"
RUN echo "ServerName localhost" >> "/etc/apache2/apache2.conf" && \
    echo "LimitRequestBody 0" > "/etc/apache2/conf-available/limit-request-body.conf" && \
    a2enconf "limit-request-body"

ENV DEVELOPER_GID=$DEVELOPER_GID
ENV DEVELOPER_UID=$DEVELOPER_UID
RUN groupadd --gid "$DEVELOPER_GID" "developer" && \
    useradd --gid "$DEVELOPER_GID" --uid "$DEVELOPER_UID" --create-home --shell "/bin/bash" "developer" && \
    echo "developer:developer" | chpasswd && \
    usermod -a -G "sudo" "developer" && \
    echo "developer ALL=(ALL) NOPASSWD: ALL" >> "/etc/sudoers" && \
    su - "developer" -c 'sudo true'

RUN curl -L "https://github.com/plantuml/plantuml/releases/download/v1.2024.0/plantuml-1.2024.0.jar" \
        -o "/usr/local/plantuml.jar"

ENV APACHE_RUN_GROUP=developer
ENV APACHE_RUN_USER=developer
RUN sed -ri "s/^export APACHE_RUN_GROUP=.*$/export APACHE_RUN_GROUP=${APACHE_RUN_GROUP}/" "/etc/apache2/envvars" && \
    sed -ri "s/^export APACHE_RUN_USER=.*$/export APACHE_RUN_USER=${APACHE_RUN_USER}/" "/etc/apache2/envvars"

ADD "entrypoint.sh" "waitfor.sh" "/usr/local/bin"
RUN chmod +x \
        "/usr/local/bin/entrypoint.sh" \
        "/usr/local/bin/waitfor.sh"

RUN rm -fr /var/www/html/* && \
    chown -R "developer:developer" "/var/www/html"

USER developer

RUN curl -o- "https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh" | bash
RUN bash --login -i -c 'source /home/developer/.bashrc && nvm install 16 && nvm install 20 && nvm alias default 20' && \
    bash --login -i -c 'source /home/developer/.bashrc && npm install -g webpack webpack-cli @openapitools/openapi-generator-cli'

WORKDIR "/var/www/html"
