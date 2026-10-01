#!/usr/bin/env bash
set -euo pipefail

readonly container="dolibarr-dev-dolibarr-1"
readonly expected_project="dolibarr-dev"
readonly expected_service="dolibarr"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
docker_cmd=(sudo docker)

if [[ "${HWOS_DEV_CONTAINER:-$container}" != "$container" ]]; then
    printf 'Refusing non-DEV container: %s\n' "${HWOS_DEV_CONTAINER}" >&2
    exit 1
fi

labels="$("${docker_cmd[@]}" inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}|{{ index .Config.Labels "com.docker.compose.service" }}' "$container")"
if [[ "$labels" != "$expected_project|$expected_service" ]]; then
    printf 'Refusing container with unexpected Compose labels: %s\n' "$labels" >&2
    exit 1
fi

copied_tests=()
cleanup() {
    local test_path
    for test_path in "${copied_tests[@]}"; do
        "${docker_cmd[@]}" exec "$container" rm -f -- "$test_path" >/dev/null 2>&1 || true
    done
}
trap cleanup EXIT

for test_file in "$repo_root"/tests/php/test_*.php; do
    test_name="$(basename "$test_file")"
    test_path="/tmp/$test_name"
    copied_tests+=("$test_path")
    "${docker_cmd[@]}" cp "$test_file" "$container:$test_path"
    "${docker_cmd[@]}" exec \
        -e HWOS_MODULE_ROOT=/var/www/html/custom \
        "$container" php "$test_path"
done
