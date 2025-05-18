#!/bin/bash
# Create single commits for all php-rector rules
# Needs:
# - git
# - jq
# - rector (in vendor/bin or RECTOR_PATH)
set -e

if [ "$1" = "-h" -o "$1" = "--help" ]; then
    echo "rector-single-commit.sh [commit message prefix]"
    echo "Options:"
    echo " -h, --help: Show help"
    exit
fi

commitMessagePrefix="$1"

messagesDir=$(dirname "$0")/messages
rectorPath=${RECTOR_PATH:-./vendor/bin/rector}
if [ ! -x "$rectorPath" ]; then
    echo "rector executable not found at $rectorPath" >&2
    exit 1
fi

if ! command -v jq > /dev/null 2>&1; then
    echo "jq executable not found" >&2
    exit 1
fi

if [ ! -f "./rector.php" ]; then
    echo "rector.php configuration file missing" >&2
    exit 1
fi

if [ ! -z "$(git status --porcelain)" ]; then
    echo "git repository status is not clean. Commit all changes first" >&2
    exit 2
fi

# "sh" (dash) breaks here with backslashes in the output, e.g. "TYPO311\v0"
# "bash" works fine
rules=$(
    "$rectorPath" process --dry-run --clear-cache --output-format=json\
        | jq -r '.file_diffs[]?.applied_rectors[]'\
        | sort | uniq
     )
if [ -z "$rules" ]; then
    echo "Nothing to do; the code is clean"
    exit
fi

numRules=$(echo "$rules"|wc -l)
echo "Rector wants to apply $numRules rules to the code"
nl="
"
for rule in $rules; do
    echo Applying rule: $rule

    messageFile=$(echo "$rule"| tr '\\' '.')
    messageFilePath="$messagesDir/${messageFile}.txt"
    if [ ! -f "$messageFilePath" ]; then
        echo "No commit message file for rule" >&2
        echo " $rule" >&2
        echo -n " https://getrector.com/find-rule?query="
        echo "$rule" | sed 's/^.*\\//'
        echo " $messageFilePath" >&2
        exit 3
    fi

    messageFileContents=$(<"$messageFilePath")
    commitMessage="$commitMessagePrefix$messageFileContents"
    commitMessage+="${nl}${nl}Applied rule:${nl}$rule"

    "$rectorPath" process --clear-cache --no-diffs --only="$rule"

    if [ -z "$(git status --porcelain)" ]; then
        echo "WARN: No code changes" >&2
        continue
    fi

    git commit\
        --all\
        --author='Rector <rector@getrector.com>'\
        --message="$commitMessage"
done

echo "Complete"
