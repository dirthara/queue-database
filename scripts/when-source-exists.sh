#!/bin/sh

set -eu

if [ -z "$(find src tests -type f -name '*.php' -print)" ]; then
    echo "Empty package scaffold: tests and coverage are not applicable yet."
    exit 0
fi

exec "$@"
