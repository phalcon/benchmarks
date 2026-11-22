# The runner (B7, B8). Source this file. Do not run it.

# Writes one KEY="value" line.
run_value() {
    printf '%s="%s"\n' "$1" "$2"
}

# Writes the metadata of the run that does not need a running server.
run_metadata() {
    local meta=$1 variant image key

    printf '%s\n' "${variants[@]}" > "${meta}/order.txt"
    { uname -a; echo; nproc; echo; lscpu; echo; free -b; } > "${meta}/host.txt"
    for key in net/core/somaxconn net/ipv4/ip_local_port_range net/ipv4/tcp_tw_reuse net/ipv4/tcp_max_syn_backlog; do
        printf '%s = %s\n' "$key" "$(cat "/proc/sys/${key}")"
    done > "${meta}/sysctl.txt"
    docker version > "${meta}/docker.txt" 2>&1
    docker ps > "${meta}/containers.txt"
    { git rev-parse HEAD; git status --porcelain; } > "${meta}/git.txt"
    for image in bench/base bench/tools bench/wrk "${variants[@]/#/bench/variant-}"; do
        printf '%s %s\n' "$image" "$(docker image inspect --format '{{.Id}}' "$image")"
    done > "${meta}/images.txt"
    cp common/versions.env "${meta}/versions.env"
    for variant in "${variants[@]}"; do
        cp "variants/${variant}/variant.env" "${meta}/variant-${variant}.env"
    done
    docker run --rm bench/wrk sh -c 'dpkg-query -W wrk; echo; cat /wrk/run.sh' > "${meta}/wrk.txt"
    {
        if [ "$dom0_confirmed" = yes ]; then echo "confirmed"; else echo "not confirmed"; fi
        echo "Target (checklist I6): vCPU 4 on CPU 20, vCPU 5 on CPU 22, governor performance, 3700 MHz, turbo off"
    } > "${meta}/dom0.txt"
}

# Writes the configuration of the running server of a variant.
run_server_metadata() {
    docker exec bench-server sh -c '
        php -v; echo
        php -i; echo
        php --ri phalcon || true; echo
        nginx -V 2>&1; echo
        cat /etc/nginx/nginx.conf; echo
        cat /usr/local/etc/php-fpm.conf; echo
        echo "PHP_FPM_MAX_CHILDREN=${PHP_FPM_MAX_CHILDREN}"
    ' > "$1" 2>&1
}

# Runs all repetitions of all variants, then the report.
cmd_run() {
    local repetitions=$REPETITIONS duration=$DURATION
    local levels_json="" levels_plaintext="" allow_noise=no dom0_confirmed=no
    local env_args=() variants=() env_list=()
    local dir meta run_id i rep variant test levels others image

    while [ $# -gt 0 ]; do
        case "$1" in
            --allow-noise) allow_noise=yes ;;
            --dom0-confirmed) dom0_confirmed=yes ;;
            --duration) duration=$2; shift ;;
            --env) env_args+=(--env "$2"); env_list+=("$2"); shift ;;
            --levels-json) levels_json=$2; shift ;;
            --levels-plaintext) levels_plaintext=$2; shift ;;
            --repetitions) repetitions=$2; shift ;;
            --variants) read -r -a variants <<< "$2"; shift ;;
            *) bench_die "unknown option: $1" ;;
        esac
        shift
    done

    if [ "${#variants[@]}" -eq 0 ]; then
        for dir in variants/*/; do
            variants+=("$(basename "$dir")")
        done
    fi
    bench_test_env json
    levels_json=${levels_json:-$TEST_LEVELS}
    bench_test_env plaintext
    levels_plaintext=${levels_plaintext:-$TEST_LEVELS}

    # Checks before the run. They run before the run folder exists.
    for image in bench/base bench/tools bench/wrk "${variants[@]/#/bench/variant-}"; do
        docker image inspect "$image" >/dev/null 2>&1 || bench_die "image ${image} is missing. Build it first."
    done
    bench_port_free
    others=$(docker ps --format '{{.Names}}' | grep -v '^bench-' | tr '\n' ' ' || true)
    if [ -n "$others" ] && [ "$allow_noise" = no ]; then
        bench_die "other containers run: ${others}. Stop them or use --allow-noise"
    fi

    run_id=$(date -u +%Y%m%dT%H%M%SZ)
    dir="results/${run_id}"
    meta="${dir}/meta"
    mkdir -p "$meta"
    bench_trap_cleanup

    {
        run_value RUN_ID "$run_id"
        run_value START "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        run_value VARIANTS "${variants[*]}"
        run_value REPETITIONS "$repetitions"
        run_value DURATION "$duration"
        run_value LEVELS_JSON "$levels_json"
        run_value LEVELS_PLAINTEXT "$levels_plaintext"
        run_value SERVER_ENV "${env_list[*]}"
        run_value SERVER_CPU "$SERVER_CPU"
        run_value WRK_CPU "$WRK_CPU"
        run_value PORT "$PORT"
        run_value ALLOW_NOISE "$allow_noise"
        run_value DOM0_CONFIRMED "$dom0_confirmed"
    } > "${meta}/run.env"
    run_metadata "$meta"

    # Interleave the variants in each repetition (A B A B, D13).
    for ((i = 1; i <= repetitions; i++)); do
        rep=$(printf '%02d' "$i")
        for variant in "${variants[@]}"; do
            local out="${dir}/${variant}/rep-${rep}"
            mkdir -p "$out"

            bench_server_start "$variant" "${env_args[@]}"
            bench_server_wait
            bench_server_affinity "${out}/server-affinity.txt" \
                || bench_die "a server process is not on vCPU ${SERVER_CPU} (see ${out})"
            bench_verify_variant "$out" || bench_die "verify failed (see ${out})"
            if [ ! -f "${meta}/server-${variant}.txt" ]; then
                run_server_metadata "${meta}/server-${variant}.txt"
            fi

            for test in json plaintext; do
                levels=$levels_json
                if [ "$test" = plaintext ]; then
                    levels=$levels_plaintext
                fi
                bench_wrk "$test" "${out}/${test}.raw.txt" "$levels" "$duration" \
                    || bench_die "wrk failed or did not run on vCPU ${WRK_CPU} (see ${out})"
            done

            docker rm -f bench-server >/dev/null
            echo "repetition ${rep}/${repetitions} ${variant}: done"
        done
    done

    run_value END "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "${meta}/run.env"
    bench_tools php scripts/report.php "$dir"
    echo "run folder: ${dir}"
}
