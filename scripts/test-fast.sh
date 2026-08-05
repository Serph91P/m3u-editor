#!/usr/bin/env bash
# Runs Pest with Test Impact Analysis (--tia --parallel). Pest's baselined()
# config (see tests/Pest.php) fetches the shared dependency graph recorded by
# .github/workflows/tia-baseline.yml via the GitHub CLI, so this needs no
# PCOV/Xdebug on this machine for day-to-day use.
#
# Safety net: if no baseline has been published yet (e.g. the workflow hasn't
# run successfully on GitHub yet, or `gh` isn't installed/authenticated), Pest
# hard-errors instead of falling back on its own. Rather than block testing
# entirely on that, we detect that one specific failure and retry as a normal
# full test run.
set -uo pipefail

cd "$(dirname "$0")/.."

output=$(vendor/bin/pest --tia --parallel "$@" 2>&1)
status=$?

echo "$output"

if [ $status -ne 0 ] && echo "$output" | grep -q "Failed to query baseline runs"; then
    echo "" >&2
    echo "No TIA baseline available yet (see message above) — running the full suite instead." >&2
    exec vendor/bin/pest --no-tia --parallel "$@"
fi

exit $status
