# check=skip=InvalidDefaultArgInFrom
# cphalcon variant (B3): the base image plus the Phalcon extension, installed
# with PIE and the default compiler flags (D15).
ARG COMPOSER_IMAGE
ARG PIE_IMAGE

FROM ${COMPOSER_IMAGE} AS composer
FROM ${PIE_IMAGE} AS pie

FROM bench/base

ARG PHALCON_VERSION
ENV PHALCON_VERSION=${PHALCON_VERSION}

# PIE is only available during this step. PIE also enables the extension.
RUN --mount=type=bind,from=pie,source=/pie,target=/usr/local/bin/pie \
    set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*; \
    pie install --no-cache "phalcon/cphalcon:${PHALCON_VERSION}"; \
    rm -rf /root/.pie /var/lib/bench/tmp/*

COPY apps/phalcon/ /app/
COPY variants/cphalcon/composer.json variants/cphalcon/composer.lock /app/

# Composer is only available during this step. It makes vendor/autoload.php.
RUN --mount=type=bind,from=composer,source=/usr/bin/composer,target=/usr/local/bin/composer \
    set -eux; \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/var/lib/bench/composer \
        composer install --no-dev --classmap-authoritative --no-interaction; \
    rm -rf /var/lib/bench/composer /var/lib/bench/tmp/*

COPY --chmod=755 variants/cphalcon/check.sh /variant/check.sh
