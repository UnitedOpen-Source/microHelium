#!/usr/bin/env bash
#
# Post-deploy smoke check (issue #54): run after every deploy/rollback to
# confirm the just-started stack is actually healthy, not just "containers
# running". Two layers:
#   1. The PHPUnit Smoke suite (tests/Smoke/*) inside the running app
#      container -- catches a route wired to a missing view, a broken
#      migration, etc. This uses phpunit.xml's in-memory sqlite, so it's
#      safe to run against the live container without touching real data.
#   2. Plain curl checks against the live HTTP endpoints, through the same
#      webserver/nginx path a real client would use -- catches nginx/proxy
#      misconfiguration that step 1 (which talks to Laravel directly) can't.
#
# Exits non-zero on first failure, so `make deploy`/`make rollback` can
# treat this as a gate.

set -euo pipefail

COMPOSE_FILES=(-f docker-compose.yml)
if [ "${SMOKE_PROD:-1}" = "1" ]; then
    COMPOSE_FILES+=(-f docker-compose.prod.yml)
fi

BASE_URL="${SMOKE_BASE_URL:-http://localhost:${APP_PORT:-8000}}"

echo "==> Running PHPUnit smoke suite inside the app container..."
docker compose "${COMPOSE_FILES[@]}" exec -T app php artisan test --testsuite=Smoke

check_endpoint() {
    local path="$1" expect="$2" code
    code=$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 10 "${BASE_URL}${path}" || echo "000")
    if [ "$code" != "$expect" ]; then
        echo "FAIL: ${path} returned HTTP ${code} (expected ${expect})" >&2
        return 1
    fi
    echo "OK: ${path} -> ${code}"
}

echo "==> Checking live HTTP endpoints at ${BASE_URL}..."
check_endpoint "/up" 200
check_endpoint "/api/health" 200
check_endpoint "/login" 200

echo "Smoke checks passed."
