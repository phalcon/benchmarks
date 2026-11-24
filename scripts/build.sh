# Image builds. Source this file. Do not run it.

build_tools() {
    docker build \
        --file common/tools/Dockerfile \
        --build-arg PHP_IMAGE \
        --build-arg COMPOSER_IMAGE \
        --tag bench/tools \
        .
}

# Builds the image that the first argument names.
build_target() {
    case "${1:-}" in
        tools) build_tools ;;
        *) bench_die "unknown build target: ${1:-<none>}" ;;
    esac
}
