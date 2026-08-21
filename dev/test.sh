#!/bin/sh
# Runs the test suite in a throwaway PHP container, so no local PHP is needed.
#
#   dev/test.sh              every case
#   dev/test.sh currency     cases matching "currency"
#
# The image is built once and reused. The stock php:8.3-cli has neither zip nor
# pdo_pgsql, and the suite needs both: the backup archive is a zip
# (includes/db/archive.php), and WALLOS_TEST_DRIVER=pgsql needs the driver. A
# missing extension shows up as "Class not found" inside a case, which reads
# like a defect in the code under test rather than a gap in the runner.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENGINE=${CONTAINER_ENGINE:-podman}
IMAGE=${WALLOS_TEST_IMAGE:-localhost/wallos-test-runner}

if ! "$ENGINE" image exists "$IMAGE" 2>/dev/null; then
    printf 'Building the test image once (%s)\n' "$IMAGE" >&2
    "$ENGINE" build -t "$IMAGE" -f - "$ROOT" >/dev/null <<'DOCKERFILE'
FROM docker.io/library/php:8.3-cli-alpine
RUN apk add --no-cache libzip-dev postgresql-dev sqlite-dev $PHPIZE_DEPS \
    && docker-php-ext-install zip pdo pdo_pgsql \
    && docker-php-ext-enable zip pdo_pgsql
DOCKERFILE
fi

exec "$ENGINE" run --rm \
    -v "$ROOT":/var/www/html:Z \
    -w /var/www/html \
    "$IMAGE" \
    php tests/run.php "$@"
