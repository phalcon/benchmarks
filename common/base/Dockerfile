# check=skip=InvalidDefaultArgInFrom
# Base image of all variants (B2, D14): PHP 8.4 FPM with OPcache, and nginx.
ARG PHP_IMAGE

FROM ${PHP_IMAGE}

ARG PHP_FPM_MAX_CHILDREN

# Temporary files go here, not to /tmp.
ENV PHP_FPM_MAX_CHILDREN=${PHP_FPM_MAX_CHILDREN} \
    TMPDIR=/var/lib/bench/tmp

RUN set -eux; \
    install -d -o www-data -g www-data /var/lib/bench/tmp /run/php /app/public; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx; \
    rm -rf /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    rm -f /usr/local/etc/php-fpm.d/*.conf

COPY common/config/bench.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY common/config/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY common/config/nginx.conf /etc/nginx/nginx.conf

WORKDIR /app

CMD ["sh", "-c", "php-fpm -D && exec nginx"]
