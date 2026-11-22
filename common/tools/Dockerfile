# check=skip=InvalidDefaultArgInFrom
# Tools image. Composer, PHPUnit, PHP_CodeSniffer and PHPStan run here.
# This image does not load Phalcon.
ARG PHP_IMAGE
ARG COMPOSER_IMAGE

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE}

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV TMPDIR=/srv/.local/tmp \
    COMPOSER_HOME=/srv/.local/composer

WORKDIR /srv
