#!/bin/bash
# Create single commits for all fractor rules
# Needs:
# - git
# - jq
# - fractor (installed as a global composer package)
set -e

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "fractor-single-commit.sh [commit message prefix]"
    echo "Options:"
    echo " -h, --help: Show help"
    exit
fi

commitMessagePrefix="$1"

messagesDir=$(dirname "$0")/messages
fractorPath="$(composer global config bin-dir --absolute)/fractor"
if [ ! -x "$fractorPath" ]; then
    echo "fractor executable not found at $fractorPath" >&2
    exit 1
fi

if ! command -v jq > /dev/null 2>&1; then
    echo "jq executable not found" >&2
    exit 1
fi

if [ ! -f "./fractor.php" ]; then
    echo "fractor.php configuration file missing" >&2
    exit 1
fi
fractorConfigPath="$(pwd)/fractor.php"
echo "Using fractor.php at $fractorConfigPath"

if [ ! -z "$(git status --porcelain)" ]; then
    echo "git repository status is not clean. Commit all changes first" >&2
    exit 2
fi

# Run a fractor dry-run and store its JSON in $dryRunJson; abort on fatal errors
fractorDryRun() {
    dryRunJson=$("$fractorPath" process --dry-run --clear-cache --no-progress-bar --output-format=json || true)
    fatalErrors=$(echo "$dryRunJson" | jq -r '.fatal_errors[]?' 2>/dev/null || true)
    if [ -n "$fatalErrors" ]; then
        echo "fractor failed:" >&2
        echo "$fatalErrors" >&2
        echo "If a fractor extension service is missing, run \"composer global install\"" >&2
        echo "so a9f/fractor-extension-installer regenerates its package list." >&2
        exit 1
    fi
}

nl="
"
skippedRules=()
pass=0

# Some rules only match after another rule changed a file (e.g. FlexForm items
# after the TCEforms wrapper is gone), so repeat until no rule applies anymore.
while true; do
    pass=$((pass + 1))
    echo "Pass $pass: collecting rules"

    # "sh" (dash) breaks here with backslashes in the output, e.g. "TYPO3v12\..."
    # "bash" works fine. The dry-run list is preferred because it avoids running
    # rules that have no changes. On a rerun after generated files were deleted,
    # however, Fractor has no existing file to report in JSON; use the configured
    # rule list in that case so those files can be generated again.
    fractorDryRun
    rules=$(
        echo "$dryRunJson"\
            | jq -r '.file_diffs[]?.applied_rules[]'\
            | sort | uniq
         )
    if [ -z "$rules" ] && [ $pass -eq 1 ]; then
        # A deleted generated file is no longer visible to Fractor's dry-run JSON.
        # Read explicit configured rule classes as a fallback so each rule is still
        # applied and committed independently on a rerun.
        rules=$(
            php -r '
                $contents = file_get_contents($argv[1]);
                preg_match_all("/\\b(?:withRules|withConfiguredRule)\\s*\\(\\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/", $contents, $matches);
                foreach ($matches[1] as $rule) {
                    echo $rule, PHP_EOL;
                }
            ' "$(pwd)/fractor.php" | sort | uniq
        )
    fi

    if [ -z "$rules" ]; then
        break
    fi

    numRules=$(echo "$rules"|wc -l)
    echo "Fractor wants to apply $numRules rules to the code"
    committed=0

    for rule in $rules; do
        echo Applying rule: $rule

        messageFile=$(echo "$rule"| tr '\\' '.')
        messageFilePath="$messagesDir/${messageFile}.txt"

        if [ ! -f "$messageFilePath" ]; then
            echo "No commit message file for rule $rule" >&2

            # First line: RuleDefinition description, second line: @changelog URL
            globalAutoload="$(dirname "$fractorPath")/../autoload.php"
            ruleInfo=$(php -r '
                foreach (["vendor/autoload.php", ".Build/vendor/autoload.php", $argv[2]] as $autoload) {
                    if (is_file($autoload)) {
                        require_once $autoload;
                    }
                }
                if (!class_exists($argv[1])) {
                    exit;
                }
                $reflection = new ReflectionClass($argv[1]);
                $description = "";
                $tokens = token_get_all(file_get_contents($reflection->getFileName()));
                $found = false;
                foreach ($tokens as $token) {
                    if (is_array($token) && $token[0] === T_STRING && $token[1] === "RuleDefinition") {
                        $found = true;
                        continue;
                    }
                    if ($found && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $description = stripcslashes(substr($token[1], 1, -1));
                        break;
                    }
                }
                $changelog = "";
                if (preg_match("/@changelog\\s+(\\S+)/", (string) $reflection->getDocComment(), $match)) {
                    $changelog = $match[1];
                }
                echo $description, PHP_EOL, $changelog;
            ' "$rule" "$globalAutoload" 2>/dev/null)

            ruleDescription=$(echo "$ruleInfo" | sed -n 1p)
            ruleChangelog=$(echo "$ruleInfo" | sed -n 2p)
            ruleUrl=${ruleChangelog:-https://github.com/andreaswolf/fractor}

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

        "$fractorPath" process --clear-cache --no-progress-bar --no-diffs --only="$rule"

        if [ -z "$(git status --porcelain)" ]; then
            echo "WARN: No code changes" >&2
            continue
        fi

        # Fractor re-prints every parsed file, so formatting-only rewrites of
        # unrelated files would end up in this commit. Stage only the files the
        # dry-run reported for this rule and discard everything else.
        ruleFiles=$(echo "$dryRunJson"\
            | jq -r --arg rule "$rule" '.file_diffs[]? | select(.applied_rules | index($rule)) | .file')
        if [ -n "$ruleFiles" ]; then
            echo "$ruleFiles" | while read -r file; do git add -- "$file"; done
            git checkout -- .
        else
            git add -A
        fi
        if git diff --cached --quiet; then
            echo "WARN: No code changes" >&2
            continue
        fi
        printf '%s' "$commitMessage" | git commit\
            --author='Fractor <fractor@localhost>'\
            --file=-
        committed=1
    done

    if [ $committed -eq 0 ]; then
        break
    fi
done

if [ ${#skippedRules[@]} -gt 0 ]; then
    echo
    echo "Skipped ${#skippedRules[@]} rule(s) with no description (no message file created, not applied):"
    for entry in "${skippedRules[@]}"; do
        echo " - $entry"
    done
    echo "Not applying the remaining fractor changes; create the message files and rerun" >&2
    exit 3
fi

# Fractor's printers also rewrite files no rule touched (quotes, [end],
# XML indentation). Commit them separately so the result equals a normal run.
echo "Applying fractor formatting"
"$fractorPath" process --clear-cache --no-progress-bar --no-diffs
git add -A
# Run once more: files changing again are not stable under fractor (e.g. its
# XML printer adds a blank line on every run). Drop such files if all they
# got is whitespace, so reruns do not create a new commit each time.
"$fractorPath" process --clear-cache --no-progress-bar --no-diffs > /dev/null
for file in $(git diff --name-only --relative); do
    if git diff --cached --quiet -w --ignore-blank-lines HEAD -- "$file"; then
        echo "WARN: fractor keeps adding whitespace to $file; not committing it" >&2
        git restore --staged --worktree --source=HEAD -- "$file"
    else
        git checkout -- "$file"
    fi
done
if ! git diff --cached --quiet; then
    printf '%s' "${commitMessagePrefix}Apply fractor formatting${nl}${nl}Formatting-only changes by the fractor file printers, no rule applied." | git commit\
        --author='Fractor <fractor@localhost>'\
        --file=-
fi

fractorDryRun
remaining=$(echo "$dryRunJson" | jq -r '.changed_files[]?')
if [ -n "$remaining" ]; then
    echo "ERROR: fractor dry-run still reports changes:" >&2
    echo "$remaining" >&2
    exit 4
fi

echo "Complete"
