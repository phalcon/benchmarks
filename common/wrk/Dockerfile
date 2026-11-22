# check=skip=InvalidDefaultArgInFrom
# Load generator image (B6, D17): the Ubuntu 24.04 wrk package, the same
# binary as TechEmpower.
ARG UBUNTU_IMAGE

FROM ${UBUNTU_IMAGE}

ARG WRK_PACKAGE

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends "${WRK_PACKAGE}"; \
    rm -rf /var/lib/apt/lists/*

COPY common/wrk/run.sh common/wrk/pipeline.lua /wrk/
