# Shared functions for the bench scripts. Source this file. Do not run it.

# Writes the message to stderr and stops with exit code 1.
bench_die() {
    printf 'bench: %s\n' "$*" >&2
    exit 1
}

# Loads the pinned versions and the bench settings, and exports them.
bench_load_env() {
    set -a
    # shellcheck source=../common/versions.env
    . common/versions.env
    if [ -f common/bench.env ]; then
        # shellcheck source=../common/bench.env
        . common/bench.env
    fi
    set +a
}

# Runs a command in the tools image, with the repository at /srv.
bench_tools() {
    local tty=()
    if [ -t 0 ] && [ -t 1 ]; then
        tty=(--tty)
    fi

    mkdir -p .local/tmp .local/composer
    docker run --rm "${tty[@]}" \
        --network host \
        --user "$(id -u):$(id -g)" \
        --volume "$PWD:/srv" \
        --workdir /srv \
        --env TMPDIR=/srv/.local/tmp \
        --env COMPOSER_HOME=/srv/.local/composer \
        bench/tools "$@"
}
