FROM php:8.4-fpm-alpine
RUN apk add --no-cache curl libzip libzip-dev oniguruma-dev sqlite-libs sqlite-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip opcache simplexml \
    && apk del libzip-dev oniguruma-dev sqlite-dev libxml2-dev
WORKDIR /app
COPY --chown=www-data:www-data app/ app/
COPY --chown=www-data:www-data config/ config/
COPY --chown=www-data:www-data database/ database/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data scripts/ scripts/
COPY --chown=www-data:www-data deploy/ deploy/
COPY docker/php.ini /usr/local/etc/php/conf.d/lemma.ini
RUN mkdir -p storage/logs storage/uploads storage/throttle /data \
    && chown -R www-data:www-data storage /data \
    && chmod +x deploy/work/entrypoint.sh
USER www-data
EXPOSE 9000
ENTRYPOINT ["/app/deploy/work/entrypoint.sh"]
