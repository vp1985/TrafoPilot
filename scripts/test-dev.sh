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

module_path="/var/www/html/hwos-suite-$$"
test_directory=""
python3 -m unittest discover -s "$repo_root/tests" -p 'test_*.py'
node --test "$repo_root/tests/test_lexware_visual.cjs"

cleanup() {
    "${docker_cmd[@]}" exec "$container" rm -rf -- "$module_path" >/dev/null 2>&1 || true
    if [[ -n "$test_directory" ]]; then
        "${docker_cmd[@]}" exec "$container" rm -rf -- "$test_directory" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
test_directory="$("${docker_cmd[@]}" exec "$container" mktemp -d /tmp/hwos-tests-XXXXXXXXXX)"
"${docker_cmd[@]}" exec "$container" chmod 700 "$test_directory"
"${docker_cmd[@]}" cp "$repo_root/scripts/lexware-ui-fixture.php" "$container:$test_directory/lexware-ui-fixture.php"
"${docker_cmd[@]}" exec "$container" mkdir -p "$module_path"
"${docker_cmd[@]}" cp "$repo_root/modules/." "$container:$module_path"

for test_file in "$repo_root"/tests/php/test_*.php; do
    test_name="$(basename "$test_file")"
    test_path="$test_directory/$test_name"
    "${docker_cmd[@]}" cp "$test_file" "$container:$test_path"
    "${docker_cmd[@]}" exec \
        -e HWOS_MODULE_ROOT="$module_path" \
        -e HWOS_SCRIPT_ROOT="$test_directory" \
        "$container" php "$test_path"
done
