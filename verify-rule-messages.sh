#!/bin/bash
# Compare messages/*.txt against the description in the currently installed
# rector rule classes (dry run only, nothing is changed or committed).
# Needs:
# - php
# - composer
# - rector (installed as a global composer package)
set -e

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "verify-rule-messages.sh [target project dir]"
    echo "Options:"
    echo " -h, --help: Show help"
    echo "Compares messages/*.txt against the installed rector rule classes."
    echo "Pass a target project dir to also resolve rule packages that are only"
    echo "required locally there (e.g. rector-laravel), not globally."
    exit
fi

scriptDir=$(dirname "$0")
messagesDir="$scriptDir/messages"
projectDir="${1:-.}"

if ! command -v php > /dev/null 2>&1; then
    echo "php executable not found" >&2
    exit 1
fi

if ! command -v composer > /dev/null 2>&1; then
    echo "composer executable not found" >&2
    exit 1
fi

globalAutoload="$(composer global config bin-dir --absolute)/../autoload.php"
if [ ! -f "$globalAutoload" ]; then
    echo "global composer autoload not found at $globalAutoload" >&2
    exit 1
fi

php "$scriptDir/verify-rule-messages.php" "$globalAutoload" "$messagesDir" "$projectDir"
