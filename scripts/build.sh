# Image builds. Source this file. Do not run it.

build_base() {
    docker build \
        --file common/base/Dockerfile \
        --build-arg PHP_IMAGE \
        --build-arg PHP_FPM_MAX_CHILDREN \
        --tag bench/base \
        .
}

build_tools() {
    docker build \
        --file common/tools/Dockerfile \
        --build-arg PHP_IMAGE \
        --build-arg COMPOSER_IMAGE \
        --tag bench/tools \
        .
}

build_wrk() {
    docker build \
        --file common/wrk/Dockerfile \
        --build-arg UBUNTU_IMAGE \
        --build-arg WRK_PACKAGE \
        --tag bench/wrk \
        .
}

# Builds the image of a variant. When the variant has a PHALCON_COMMIT, the
# Phalcon tag must point to that commit (D3).
build_variant() {
    local name=$1
    local dir="variants/${name}"

    [ -f "${dir}/variant.env" ] || bench_die "unknown build target: ${name}"

    (
        set -a
        # shellcheck source=/dev/null
        . "${dir}/variant.env"
        set +a

        if [ -n "${PHALCON_COMMIT:-}" ]; then
            local commit
            commit=$(git ls-remote https://github.com/phalcon/cphalcon "refs/tags/v${PHALCON_VERSION}" | cut -f1)
            [ "$commit" = "$PHALCON_COMMIT" ] \
                || bench_die "tag v${PHALCON_VERSION} is not commit ${PHALCON_COMMIT}"
        fi

        docker build \
            --file "${dir}/Dockerfile" \
            --build-arg COMPOSER_IMAGE \
            --build-arg PIE_IMAGE \
            --build-arg PHALCON_VERSION \
            --tag "bench/variant-${name}" \
            .
    )
}

# Builds all images: tools, base, wrk and each variant.
build_all() {
    local dir

    build_tools
    build_base
    build_wrk
    for dir in variants/*/; do
        build_variant "$(basename "$dir")"
    done
}

# Builds the image that the first argument names: all, base, tools, wrk or a
# variant.
build_target() {
    case "${1:-}" in
        all) build_all ;;
        base) build_base ;;
        tools) build_tools ;;
        wrk) build_wrk ;;
        "") bench_die "missing build target" ;;
        *) build_variant "$1" ;;
    esac
}
