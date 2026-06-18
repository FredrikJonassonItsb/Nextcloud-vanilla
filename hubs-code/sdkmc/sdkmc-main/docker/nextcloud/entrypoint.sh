#!/bin/bash
# shellcheck disable=SC2012,SC2181,SC2164,SC1091,SC2012,SC1072

shopt -s dotglob
source "/home/developer/.nvm/nvm.sh"

# Apache
APACHE_CONF="/etc/apache2/sites-enabled/000-default.conf"

# Check if the Directory block already exists
if grep -q "<Directory /var/www/html/>" "$APACHE_CONF"; then
    echo "The <Directory> block already exists in $APACHE_CONF."
else
    if grep -q "</VirtualHost>" "$APACHE_CONF"; then
        # Insert lines above the closing VirtualHost tag (in reverse order)
        sudo sed -i \
            -e '/<\/VirtualHost>/i \	<Directory /var/www/html/>' \
            -e '/<\/VirtualHost>/i \		AllowOverride All' \
            -e '/<\/VirtualHost>/i \	</Directory>' \
            "$APACHE_CONF"

        echo "Configuration block added inside the existing <VirtualHost> block in $APACHE_CONF."
    else
        # Append the Directory block at the end of the file
        {
            sudo echo
            sudo echo "	<Directory /var/www/html/>"
            sudo echo "			AllowOverride All"
            sudo echo "	</Directory>"
        } >> "$APACHE_CONF"

        echo "No <VirtualHost> block found. Configuration block added at the end of $APACHE_CONF."
    fi
fi

sudo chown "developer:developer" "/home/developer/.cache"
sudo chown "developer:developer" "/var/www/html/apps-extra"
sudo chown "developer:developer" "/var/www/html/apps"

if [ ! -f "/var/www/html/index.php" ] ; then
    git clone \
        --branch "${NEXTCLOUD_VERSION}" \
        --depth "1" \
        --progress \
        "https://github.com/nextcloud/server.git" \
        "/tmp/nextcloud-server"
    pushd "/tmp/nextcloud-server"
    git submodule update \
        --depth "1" \
        --init \
        --progress \
        --recursive
    popd
    mv /tmp/nextcloud-server/* "/var/www/html"
    cp "/var/www/html/apps-extra/sdkmc/docker/nextcloud/config.php" "/var/www/html/config/config.php"
    rmdir /tmp/nextcloud-server
    sed -i "s/\"php\": \"^\?8.[01]\"/\"php\": \"^$(ls -1 /etc/php | head -n 1 | tr -d " \n")\"/g" "./composer.json"
fi

mkdir -p "/var/www/html/data"
touch "/var/www/html/data/nextcloud.log"
truncate --size "0" "/var/www/html/data/nextcloud.log"

#sudo service apache2 start
sudo service cron start
sudo service ssh start

waitfor.sh -t 30 "mariadb:3306"
if [ $? -ne 0 ] ; then
    exit 1
fi

if [[ ! $(php occ status) =~ installed:[[:space:]]*true ]]; then
    echo "Running NC installation"
    php occ "maintenance:install" \
        --verbose \
        --database "mysql" \
        --database-host "mariadb" \
        --database-port "3306" \
        --database-name "nextcloud" \
        --database-user "nextcloud" \
        --database-pass "nextcloud" \
        --admin-user "admin" \
        --admin-pass "admin"
    # sudo service apache2 restart
fi

if [[ ! $(crontab -l) ]] ; then
    echo "Creating crontab for developer"
    echo "*/1    *    *    *    *    php -f /var/www/html/cron.php >> /home/developer/nextcloud-cron.log 2>&1" | \
        crontab -
fi

sudo chown -R "developer:developer" "/var/www/html/apps-extra/sdkmc"
make -C "/var/www/html/apps-extra/sdkmc"
php occ "app:enable" "sdkmc"

# Add & enable notifications app
latestTag=$(git ls-remote --tags https://github.com/nextcloud/notifications.git \
            | grep -v '\^{}$' \
            | grep -Ev 'rc|beta|alpha' \
            | awk -F'/' '/refs\/tags\/v[0-9]/ {print $NF}' \
            | sort -V \
            | tail -n1)
echo "Latest Release Tag: $latestTag"
git clone --branch "$latestTag" https://github.com/nextcloud/notifications.git /var/www/html/apps/notifications
sed -i 's|min-version="31"|min-version="30"|g' /var/www/html/apps/notifications/appinfo/info.xml
php occ app:enable notifications

# Add & enable logreader app
git clone https://github.com/nextcloud/logreader.git /var/www/html/apps/logreader
sed -i 's|min-version="31"|min-version="30"|g' /var/www/html/apps/logreader/appinfo/info.xml
php occ app:enable logreader

if [ ! -d "/var/www/html/apps/viewer" ]; then
    echo "Viewer app not found. Cloning and setting up."
    git clone https://github.com/nextcloud/viewer.git /var/www/html/apps/viewer
    cd /var/www/html/apps/viewer
    git checkout tags/v30.0.5
    npm ci
    npm run build
    cd /var/www/html
    php occ upgrade
    php occ app:enable viewer
    php occ maintenance:mode --off
else
    echo "Viewer app already exists. Skipping installation."
fi

# Add missing indices
php occ db:add-missing-indices

# Migrate mimetypes
php occ maintenance:repair --include-expensive

# Enable pretty urls
sudo -u developer php /var/www/nextcloud/occ maintenance:update:htaccess

sudo service apache2 start

echo "Nextcloud available at http://localhost:8080"

tail -f "/var/www/html/data/nextcloud.log" &
wait $!

sudo service ssh stop
sudo service cron stop
sudo service apache2 stop

exit 0
