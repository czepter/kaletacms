#!/bin/sh
# First start: an empty volume gets Kaleta; a volume with a site stays as it is (Kaleta updates itself).
set -e
if [ ! -e /var/www/html/index.php ]; then
    echo "Kaleta: copying the files into /var/www/html"
    tar -C /usr/src/kaleta -cf - . | tar -C /var/www/html -xf -
    chown -R www-data:www-data /var/www/html
fi
# Installation without the browser (2.5): with KALETA_URL and KALETA_ADMIN_PASSWORD set, a site that is not installed yet
# installs itself; the database comes from KALETA_DB_*. Without them the web installer asks (and still reads KALETA_DB_*).
if [ ! -e /var/www/html/config.php ] && [ -e /var/www/html/install.php ] && [ -n "$KALETA_URL" ] && [ -n "$KALETA_ADMIN_PASSWORD" ]; then
    tries=0
    until php /var/www/html/install.php --url="$KALETA_URL" --admin-user="${KALETA_ADMIN_USER:-admin}" \
        --admin-email="${KALETA_ADMIN_EMAIL:-}" --site-name="${KALETA_SITE_NAME:-}" --language="${KALETA_LANGUAGE:-en}" \
        --site-language="${KALETA_SITE_LANGUAGE:-${KALETA_LANGUAGE:-en}}" --starter="${KALETA_STARTER:-firemni}"; do
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            echo "Kaleta: the installation did not finish – open the site in the browser to install it"
            break
        fi
        echo "Kaleta: trying again in 3 s (the database may still be starting)"
        sleep 3
    done
    chown -R www-data:www-data /var/www/html
fi
exec docker-php-entrypoint "$@"
