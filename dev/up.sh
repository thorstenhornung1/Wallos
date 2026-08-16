#!/bin/sh
# Starts the development environment, creating the local secret files from the
# committed examples on first run.

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ENGINE=${CONTAINER_ENGINE:-podman}

if [ ! -d "$ROOT/secrets" ]; then
    cp -R "$ROOT/secrets.example" "$ROOT/secrets"
    printf 'Created dev/secrets from dev/secrets.example\n'
fi

exec "$ENGINE" compose -f "$ROOT/compose.yaml" up -d --build "$@"
