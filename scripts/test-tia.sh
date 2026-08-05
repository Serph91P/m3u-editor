#!/usr/bin/env bash
# Force-rebuilds the local Pest TIA dependency graph from scratch (--tia --fresh),
# which requires PCOV or Xdebug. Day-to-day, you don't need this: `composer test:fast`
# (plain `pest --tia --parallel`) fetches the shared graph that
# .github/workflows/tia-baseline.yml records on every push to main, and replaying
# that graph needs no coverage driver at all — this is only for rebuilding the
# graph locally yourself (e.g. no network access to fetch the CI baseline, or
# debugging the graph itself).
#
# On Laravel Herd (macOS), Herd's PHP builds don't load Xdebug/PCOV by default.
# This script loads Herd's bundled Xdebug for a single invocation via -d flags,
# without touching the shared php.ini used by other Herd sites.
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="php"
XDEBUG_FLAGS=()

if command -v herd >/dev/null 2>&1; then
    if php -m | grep -qiE '^(xdebug|pcov)$'; then
        : # already available, no extra flags needed
    else
        HERD_PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;' 2>/dev/null || echo "")
        ARCH=$(uname -m)
        case "$ARCH" in
            arm64) HERD_ARCH="arm64" ;;
            x86_64) HERD_ARCH="x86" ;;
            *) HERD_ARCH="" ;;
        esac

        if [ -n "$HERD_PHP_VERSION" ] && [ -n "$HERD_ARCH" ]; then
            XDEBUG_SO="/Applications/Herd.app/Contents/Resources/xdebug/xdebug-${HERD_PHP_VERSION}-${HERD_ARCH}.so"
            if [ -f "$XDEBUG_SO" ]; then
                XDEBUG_FLAGS=(-d "zend_extension=${XDEBUG_SO}" -d "xdebug.mode=coverage")
            fi
        fi
    fi
fi

if [ ${#XDEBUG_FLAGS[@]} -eq 0 ] && ! php -m | grep -qiE '^(xdebug|pcov)$'; then
    echo "Warning: no PCOV/Xdebug detected and no Herd Xdebug build found for this PHP version/arch." >&2
    echo "--tia will no-op. Falling back to a normal test run." >&2
fi


# Deliberately not --parallel: paratest spawns worker processes that don't
# inherit these -d flags, silently dropping coverage from every worker and
# producing an incomplete graph. Full rebuilds are infrequent, so correctness
# here matters more than speed.
exec "$PHP_BIN" "${XDEBUG_FLAGS[@]}" vendor/bin/pest --tia --fresh "$@"
