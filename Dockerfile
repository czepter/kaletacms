# Kaleta in a container: PHP 8.4 with Apache (the shipped .htaccess does the rewriting, WebP/AVIF negotiation and caching).
# The site lives in the volume /var/www/html – the first start copies Kaleta there, and later updates come from Kaleta's own
# signed updates (Settings → Backups and updates), exactly as on shared hosting. See compose.yaml.
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libavif-dev libfreetype6-dev libzip-dev libicu-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql zip intl exif opcache \
    && a2enmod rewrite headers expires deflate mime \
    && printf '<Directory /var/www/html>\n\tAllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/kaleta.conf \
    && a2enconf kaleta \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/kaleta.ini
COPY docker/entrypoint.sh /usr/local/bin/kaleta-entrypoint
COPY --chown=www-data:www-data . /usr/src/kaleta
RUN rm -rf /usr/src/kaleta/docker /usr/src/kaleta/Dockerfile

VOLUME /var/www/html
# The entrypoint and Apache start as root (port 80, copying the site into the volume); Apache serves every request as www-data
# nosemgrep: dockerfile.security.missing-user-entrypoint.missing-user-entrypoint
ENTRYPOINT ["kaleta-entrypoint"]
# nosemgrep: dockerfile.security.missing-user.missing-user
CMD ["apache2-foreground"]
