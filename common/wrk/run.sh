#!/bin/sh
# Derived from TechEmpower FrameworkBenchmarks (BSD-3-Clause). See NOTICE.
# Runs the primer, the warmup and each level of one test (I3, D10).
# Writes the AFFINITY line, then for each phase: PHASE, the /proc/stat lines
# of the two CPUs, the wrk output, the /proc/stat lines again, and END.
set -eu
: "${URL:?}" "${ACCEPT:?}" "${LEVELS:?}" "${PIPELINE:?}" "${DURATION:?}" "${SERVER_CPU:?}" "${WRK_CPU:?}"
max_threads=$(nproc)

cpu_lines() {
    grep -E "^cpu(${SERVER_CPU}|${WRK_CPU}) " /proc/stat
}

phase() {
    name=$1
    connections=$2
    threads=$3
    duration=$4
    shift 4
    echo "PHASE ${name} ${connections} ${threads}"
    cpu_lines
    wrk -H "Host: localhost" -H "Accept: ${ACCEPT}" -H "Connection: keep-alive" \
        --latency -d "${duration}" -c "${connections}" --timeout 8 -t "${threads}" "${URL}" "$@"
    cpu_lines
    echo "END"
}

echo "AFFINITY $(awk '/^Cpus_allowed_list/ { print $2 }' /proc/self/status)"
phase primer 8 8 5
sleep 5
phase warmup 512 "${max_threads}" "${DURATION}"
sleep 5
for connections in ${LEVELS}; do
    threads=$(( connections > max_threads ? max_threads : connections ))
    if [ "${PIPELINE}" -gt 1 ]; then
        phase level "${connections}" "${threads}" "${DURATION}" -s /wrk/pipeline.lua -- "${PIPELINE}"
    else
        phase level "${connections}" "${threads}" "${DURATION}"
    fi
    sleep 2
done
