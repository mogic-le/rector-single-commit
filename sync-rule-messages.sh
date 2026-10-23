#!/bin/bash
# Create missing, update changed and remove obsolete messages/*.txt files from
# the descriptions of the installed rector rules. Dry run by default.
# Needs:
# - php
# - composer
# - rector (installed as a global composer package)
set -e

mode="dry-run"
projectDir=""

usage() {
    echo "sync-rule-messages.sh [--dry-run|--apply] [target project dir]"
    echo "Options:"
    echo " --dry-run:   only report NEW/UPDATED/REVIEW/REMOVED/SKIPPED/FAILED rules (default)"
    echo " --apply:     write the message files and messages.lock.json"
    echo " -h, --help:  show this help"
    echo " [target project dir]: also cover rule packages only required locally"
    echo "                       there (e.g. rector-laravel), not globally"
}

while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage; exit ;;
        --dry-run) mode="dry-run" ;;
        --apply) mode="apply" ;;
        -*) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
        *) projectDir="$1" ;;
    esac
    shift
done

scriptDir="$(cd "$(dirname "$0")" && pwd)"

if ! command -v php > /dev/null 2>&1; then
    echo "php executable not found" >&2
    exit 1
fi

if ! command -v composer > /dev/null 2>&1; then
    echo "composer executable not found" >&2
    exit 1
fi

globalVendor="$(composer global config vendor-dir --absolute 2>/dev/null)"
if [ ! -f "$globalVendor/autoload.php" ]; then
    echo "global composer autoload not found at $globalVendor/autoload.php" >&2
    exit 1
fi

php "$scriptDir/sync-rule-messages.php" \
    --mode="$mode" \
    --global-vendor="$globalVendor" \
    --messages-dir="$scriptDir/messages" \
    --lock="$scriptDir/messages.lock.json" \
    ${projectDir:+--project-dir="$projectDir"}
