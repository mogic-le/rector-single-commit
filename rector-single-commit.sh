#!/bin/bash
# Create single commits for all php-rector rules
# Needs:
# - git
# - jq
# - rector (installed as a global composer package)
set -e

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "rector-single-commit.sh [commit message prefix]"
    echo "Options:"
    echo " -h, --help: Show help"
    exit
fi

commitMessagePrefix="$1"

messagesDir=$(dirname "$0")/messages
rectorPath="$(composer global config bin-dir --absolute)/rector"
if [ ! -x "$rectorPath" ]; then
    echo "rector executable not found at $rectorPath" >&2
    exit 1
fi

if ! command -v jq > /dev/null 2>&1; then
    echo "jq executable not found" >&2
    exit 1
fi

if ! command -v curl > /dev/null 2>&1; then
    echo "curl executable not found" >&2
    exit 1
fi

if [ ! -f "./rector.php" ]; then
    echo "rector.php configuration file missing" >&2
    exit 1
fi
rectorConfigPath="$(pwd)/rector.php"
echo "Using rector.php at $rectorConfigPath"

if [ ! -z "$(git status --porcelain)" ]; then
    echo "git repository status is not clean. Commit all changes first" >&2
    exit 2
fi

nl="
"
skippedRules=()
pass=0

# Some rules only match after another rule changed a file, so repeat until no
# rule applies anymore.
while true; do
    pass=$((pass + 1))
    echo "Pass $pass: collecting rules"

    # "sh" (dash) breaks here with backslashes in the output, e.g. "TYPO311\v0"
    # "bash" works fine. The dry-run list is preferred because it avoids running
    # rules that have no changes. On a rerun after generated files were deleted,
    # however, Rector has no existing file to report in JSON; use the configured
    # rule list in that case so those files can be generated again.
    rules=$(
        "$rectorPath" process --dry-run --clear-cache --output-format=json\
            | jq -r '.file_diffs[]?.applied_rectors[]'\
            | sort | uniq
         )
    if [ -z "$rules" ] && [ $pass -eq 1 ]; then
        # A deleted generated file is no longer visible to Rector's dry-run JSON.
        # Read explicit configured rule classes as a fallback so each rule is still
        # applied and committed independently on a rerun.
        rules=$(
            php -r '
                $contents = file_get_contents($argv[1]);
                preg_match_all("/\\b(?:withRules|withConfiguredRule)\\s*\\(\\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/", $contents, $matches);
                foreach ($matches[1] as $rule) {
                    echo $rule, PHP_EOL;
                }
            ' "$(pwd)/rector.php" | sort | uniq
        )
    fi

    if [ -z "$rules" ]; then
        if [ $pass -eq 1 ]; then
            echo "Rector has no rules to apply"
        fi
        break
    fi

    committed=0

    numRules=$(echo "$rules"|wc -l)
    echo "Rector wants to apply $numRules rules to the code"
    for rule in $rules; do
        echo Applying rule: $rule

        messageFile=$(echo "$rule"| tr '\\' '.')
        messageFilePath="$messagesDir/${messageFile}.txt"
        ruleShortName=$(echo "$rule" | sed 's/^.*\\//')

        if [ ! -f "$messageFilePath" ]; then
            echo "No commit message file for rule $rule" >&2

            globalAutoload="$(dirname "$rectorPath")/../autoload.php"
            ruleDescription=$(php -r '
                foreach (["vendor/autoload.php", ".Build/vendor/autoload.php", $argv[2]] as $autoload) {
                    if (is_file($autoload)) {
                        require_once $autoload;
                    }
                }
                if (!class_exists($argv[1])) {
                    exit;
                }
                $file = (new ReflectionClass($argv[1]))->getFileName();
                $tokens = token_get_all(file_get_contents($file));
                $found = false;
                foreach ($tokens as $token) {
                    if (is_array($token) && $token[0] === T_STRING && $token[1] === "RuleDefinition") {
                        $found = true;
                        continue;
                    }
                    if ($found && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                        echo stripcslashes(substr($token[1], 1, -1));
                        exit;
                    }
                }
            ' "$rule" "$globalAutoload" 2>/dev/null)

            ruleUrl="https://getrector.com/rule-detail/$(echo "$ruleShortName" | sed -E 's/([a-z0-9])([A-Z])/\1-\2/g' | tr '[:upper:]' '[:lower:]')"

            if [ -n "$ruleDescription" ]; then
                echo "Description found in local source: $ruleDescription" >&2
            else
                findRuleUrl="https://getrector.com/find-rule?query=$ruleShortName"
                echo "No local class found; falling back to $findRuleUrl ..." >&2

                ruleBlock=$(
                    curl -fsSL --max-time 10 "$findRuleUrl" \
                        | grep -B1 -A10 "^[[:space:]]*${ruleShortName}[[:space:]]*\$"
                )

                fetchedUrl=$(echo "$ruleBlock" | grep -m1 -oE 'https://getrector\.com/rule-detail/[a-z0-9-]+')
                ruleUrl=${fetchedUrl:-$ruleUrl}

                ruleDescription=$(
                    echo "$ruleBlock" \
                        | grep -m1 -o '<p>.*</p>' \
                        | sed -e 's/<[^>]*>//g' \
                              -e 's/&amp;/\&/g' -e 's/&quot;/"/g' -e "s/&#039;/'/g" \
                              -e 's/&lt;/</g' -e 's/&gt;/>/g' \
                              -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'
                )
            fi

            if [ -z "$ruleDescription" ]; then
                echo "WARN: Could not determine a description for rule $rule; skipping (create $messageFilePath manually to include it next run)" >&2
                [[ " ${skippedRules[*]} " == *" $rule => "* ]] || skippedRules+=("$rule => $ruleUrl")
                continue
            fi

            printf '%s\n\n%s\n' "$ruleDescription" "$ruleUrl" > "$messageFilePath"
            echo "Description: $ruleDescription" >&2
            echo "Source:      $ruleUrl" >&2
            echo "Created $messageFilePath" >&2
        fi

        messageFileContents=$(<"$messageFilePath")
        commitMessage="$commitMessagePrefix$messageFileContents"
        commitMessage+="${nl}${nl}Applied rule:${nl}$rule"

        "$rectorPath" process --clear-cache --no-diffs --only="$rule"

        if [ -z "$(git status --porcelain)" ]; then
            echo "WARN: No code changes" >&2
            continue
        fi

        git add -A
        printf '%s' "$commitMessage" | git commit\
            --author='Rector <rector@getrector.com>'\
            --file=-
        committed=1
    done

    if [ $committed -eq 0 ]; then
        break
    fi
done

echo "Complete"

if [ ${#skippedRules[@]} -gt 0 ]; then
    echo
    echo "Skipped ${#skippedRules[@]} rule(s) with no description (no message file created, not applied):"
    for entry in "${skippedRules[@]}"; do
        echo " - $entry"
    done
fi
