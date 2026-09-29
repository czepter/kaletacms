#!/bin/sh
# First start: an empty volume gets Kaleta; a volume with a site stays as it is (Kaleta updates itself).
set -e
if [ ! -e /var/www/html/index.php ]; then
    echo "Kaleta: copying the files into /var/www/html"
    tar -C /usr/src/kaleta -cf - . | tar -C /var/www/html -xf -
    chown -R www-data:www-data /var/www/html
fi
exec docker-php-entrypoint "$@"
