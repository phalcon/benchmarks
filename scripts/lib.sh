# Shared functions for the bench scripts. Source this file. Do not run it.

# Removes the benchmark containers. It always succeeds.
bench_cleanup() {
    docker rm -f bench-server bench-wrk >/dev/null 2>&1 || true
}

# Writes the message to stderr and stops with exit code 1.
bench_die() {
    printf 'bench: %s\n' "$*" >&2
    exit 1
}

# Writes the HTTP status code of "GET <path>" on the bench port. Returns 1
# when there is no answer.
bench_http_status() {
    (
        exec 3<>"/dev/tcp/127.0.0.1/${PORT}" || exit 1
        printf 'GET %s HTTP/1.0\r\nHost: localhost\r\n\r\n' "$1" >&3
        IFS=' ' read -r -t 5 _ status _ <&3 || exit 1
        printf '%s' "$status"
    ) 2>/dev/null
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

# Stops when a process listens on the bench port.
bench_port_free() {
    if (exec 3<>"/dev/tcp/127.0.0.1/${PORT}") 2>/dev/null; then
        bench_die "port ${PORT} is in use"
    fi
}

# Writes "<pid> <command> <Cpus_allowed_list>" for each process of the server
# container, then OK or FAIL. Returns 1 when a process is not on the server
# vCPU.
bench_server_affinity() {
    local out=$1 pid comm list ok=1

    : > "$out"
    while read -r pid comm; do
        list=$(awk '/^Cpus_allowed_list/ { print $2 }' "/proc/${pid}/status" 2>/dev/null || true)
        # The process stopped after the list was made.
        if [ -z "$list" ]; then
            continue
        fi
        printf '%s %s %s\n' "$pid" "$comm" "$list" >> "$out"
        if [ "$list" != "$SERVER_CPU" ]; then
            ok=0
        fi
    done < <(docker top bench-server -eo pid,comm | tail -n +2)

    if [ "$ok" -eq 1 ]; then
        echo OK >> "$out"
        return 0
    fi
    echo FAIL >> "$out"
    return 1
}

# Starts the server container of a variant on the server vCPU. More
# arguments go to "docker run".
bench_server_start() {
    local variant=$1
    shift

    docker run --detach --name bench-server \
        --network host \
        --cpuset-cpus "$SERVER_CPU" \
        --init \
        --ulimit nofile=200000:200000 \
        "$@" \
        "bench/variant-${variant}" >/dev/null
}

# Waits up to 60 seconds until the server answers. Stops when the container
# stops or the time is over, and shows the container log.
bench_server_wait() {
    local _
    for _ in $(seq 1 60); do
        if [ "$(docker inspect --format '{{.State.Running}}' bench-server 2>/dev/null)" != "true" ]; then
            break
        fi
        if [ "$(bench_http_status /plaintext || true)" = "200" ]; then
            return 0
        fi
        sleep 1
    done

    docker logs bench-server 2>&1 | tail -n 50 >&2 || true
    bench_die "server did not start"
}

# Loads the settings of a test: TEST_URL, TEST_LEVELS, TEST_PIPELINE and
# TEST_ACCEPT.
bench_test_env() {
    [ -f "common/tests/$1.env" ] || bench_die "unknown test: $1"
    # shellcheck source=/dev/null
    . "common/tests/$1.env"
}

# Removes the benchmark containers when the script stops for any reason.
bench_trap_cleanup() {
    trap bench_cleanup EXIT
    trap 'exit 130' INT TERM
}

# Runs the variant check and the response checks of both tests against the
# running server. Writes the results to the folder. Returns 1 on a failure.
bench_verify_variant() {
    local out=$1 test failed=0

    docker exec bench-server /variant/check.sh > "${out}/check.txt" || failed=1
    for test in json plaintext; do
        bench_test_env "$test"
        bench_tools php scripts/verify.php "$test" "http://127.0.0.1:${PORT}${TEST_URL}" "$TEST_ACCEPT" \
            > "${out}/verify-${test}.txt" || failed=1
    done

    return "$failed"
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
