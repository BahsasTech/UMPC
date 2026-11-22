FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext \
        postgresql-dev \
        curl-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql mbstring curl \
    && mkdir -p /var/www/html /run/nginx

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/umpcsl.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY . /var/www/html

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/public/uploads

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
