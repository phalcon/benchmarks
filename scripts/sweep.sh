# The php-fpm worker sweep (D6). Source this file. Do not run it.

# Runs JSON at 256 and plaintext at 1024 connections for each worker count,
# then shows the medians for each count. The selection of the count is done
# by hand (plan, assumption 7).
cmd_sweep() {
    local values="1 2 4 8 16 32 64" repetitions=3 value
    local pass=() lines=()

    while [ $# -gt 0 ]; do
        case "$1" in
            --allow-noise | --dom0-confirmed) pass+=("$1") ;;
            --duration) pass+=(--duration "$2"); shift ;;
            --repetitions) repetitions=$2; shift ;;
            --values) values=$2; shift ;;
            *) bench_die "unknown option: $1" ;;
        esac
        shift
    done

    for value in $values; do
        echo "== pm.max_children=${value}"
        cmd_run --variants cphalcon --repetitions "$repetitions" \
            --levels-json 256 --levels-plaintext 1024 \
            --env "PHP_FPM_MAX_CHILDREN=${value}" "${pass[@]}"
        lines+=("$(printf '%-6s %-27s ' "$value" "$RUN_DIR")$(bench_tools php -r '
            $result = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
            foreach ($result["rows"] as $row) {
                $rps = $row["medianRps"] === null ? "-" : number_format($row["medianRps"], 1, ".", "");
                printf("%s %s (%s)  ", $row["test"], $rps, $row["status"]);
            }
        ' "${RUN_DIR}/results.json")")
    done

    echo "== sweep"
    printf '%-6s %-27s %s\n' "value" "run folder" "median requests per second"
    printf '%s\n' "${lines[@]}"
}
