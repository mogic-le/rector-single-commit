#!/bin/bash
# Split a single historical commit into real, individually-scoped Rector rule commits
# plus one leftover commit (reusing the original message/author/date). Net code change
# across the whole operation is zero.
#
# How it works: the target commit's own changes are physically reverted (working tree
# reset to its parent), then Rector is run for real, scoped to just the files the target
# commit touched - the same one-rule-per-commit process as rector-single-commit.sh, just
# restricted to those files. Since Rector runs on real (reverted) code, it can produce a
# rule match even when its output isn't byte-identical to what the target commit did by
# hand (different formatting, added type declarations, etc.) - unlike a static exact-diff
# comparison. Whatever a rule produces that falls outside the region the target commit
# actually touched is trimmed back out (line-by-line where the hunk's old/new line count
# matches, otherwise the whole hunk is reverted) so no rule commit ever contains code the
# target commit didn't already have. Whatever's left over is reconciled at the end via a
# real git checkout against the target commit's own tree, so the final state always ends
# up byte-identical to the target commit - verified before finishing.
#
# Needs:
# - git, jq, php, curl
# - rector (installed as a global composer package)
set -e

scriptDir="$(cd "$(dirname "$0")" && pwd)"
messagesDir="$scriptDir/messages"
scopePath="$scriptDir/rector-split-commit-scope.php"

mode="apply"
targetCommit=""
commitMessagePrefix=""

usage() {
    echo "rector-split-commit.sh [--dry-run|--apply] <target-commit-sha> [commit message prefix]"
    echo "Options:"
    echo " --dry-run:   only print what would happen, don't touch git history"
    echo " --apply:     perform the split (default)"
    echo " -h, --help:  show this help"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) mode="dry-run"; shift ;;
        --apply) mode="apply"; shift ;;
        -h|--help) usage; exit 0 ;;
        *)
            if [ -z "$targetCommit" ]; then
                targetCommit="$1"
            else
                commitMessagePrefix="$1"
            fi
            shift
            ;;
    esac
done

if [ -z "$targetCommit" ]; then
    usage
    exit 1
fi

# --- tool checks ---
rectorPath="$(composer global config bin-dir --absolute)/rector"
if [ ! -x "$rectorPath" ]; then
    echo "rector executable not found at $rectorPath" >&2
    exit 1
fi
for tool in jq curl php git; do
    if ! command -v "$tool" > /dev/null 2>&1; then
        echo "$tool executable not found" >&2
        exit 1
    fi
done
if [ ! -f "$scopePath" ]; then
    echo "scope helper not found at $scopePath" >&2
    exit 1
fi
if [ ! -f "./rector.php" ]; then
    echo "rector.php configuration file missing" >&2
    exit 1
fi

# --- git state checks ---
if [ ! -z "$(git status --porcelain)" ]; then
    echo "git repository status is not clean. Commit all changes first" >&2
    exit 2
fi

targetSha="$(git rev-parse "$targetCommit")" || { echo "cannot resolve $targetCommit" >&2; exit 1; }
parentCount="$(git cat-file -p "$targetSha" | grep -c '^parent ' || true)"
if [ "$parentCount" != "1" ]; then
    echo "target commit $targetSha must have exactly one parent (got $parentCount); merge commits are not supported" >&2
    exit 1
fi
parentSha="$(git rev-parse "${targetSha}~1")"
originalHead="$(git rev-parse HEAD)"

if ! git merge-base --is-ancestor "$targetSha" HEAD; then
    echo "$targetSha is not an ancestor of HEAD; nothing to rebase onto" >&2
    exit 1
fi

echo "Target commit: $targetSha (parent $parentSha)"
echo "Current HEAD:  $originalHead"

# --- touched files: only ones that already existed at the parent commit, since a rule
# can only be credited with a change to content it could have started from. Files that
# only exist at the target (pure additions, including the "new" side of a rename - a
# rule can't invent a whole file) go straight to LEFTOVER via the final checkout step.
# --no-renames is required: git's default rename detection otherwise collapses a
# delete+add pair into a single "new path only" entry, hiding the old (deleted) path. ---
repoRoot="$(pwd)"
touchedFilesList="$(mktemp)"
addedFilesList="$(mktemp)"
while IFS= read -r f; do
    [ -z "$f" ] && continue
    if git cat-file -e "${parentSha}:${f}" 2>/dev/null; then
        echo "$f" >> "$touchedFilesList"
    else
        echo "$f" >> "$addedFilesList"
    fi
done < <(git show --no-renames --name-only --pretty=format: "$targetSha")

if [ ! -s "$touchedFilesList" ] && [ ! -s "$addedFilesList" ]; then
    echo "no files touched by $targetSha; nothing to split" >&2
    rm -f "$touchedFilesList" "$addedFilesList"
    exit 1
fi
mapfile -t touchedFiles < "$touchedFilesList"
addedFileCount=$(wc -l < "$addedFilesList" | tr -d ' ')
echo "${#touchedFiles[@]} pre-existing file(s) touched by $targetSha ($addedFileCount added file(s) go straight to LEFTOVER)"

workDir="$(mktemp -d)"
cleanup() {
    if [ -n "${RECTOR_SPLIT_KEEP_WORKDIR:-}" ]; then
        echo "RECTOR_SPLIT_KEEP_WORKDIR set: kept $workDir" >&2
    else
        rm -rf "$workDir"
    fi
    rm -f "$touchedFilesList" "$addedFilesList"
}
trap cleanup EXIT

if [ "$mode" = "dry-run" ]; then
    if [ "${#touchedFiles[@]}" -eq 0 ]; then
        echo "This commit only adds files - nothing for Rector to run against; it would become a pure LEFTOVER commit."
        exit 0
    fi
    echo "Discovering candidate rector rules (scoped to the touched files, against the current tree)..." >&2
    discoveryJson=""
    discoveryExit=0
    discoveryJson="$("$rectorPath" process --dry-run --clear-cache --output-format=json "${touchedFiles[@]}" 2>"$workDir/discovery-err.log")" || discoveryExit=$?
    if [ "$discoveryExit" != "0" ] && [ "$discoveryExit" != "2" ]; then
        echo "rector discovery failed with exit code $discoveryExit:" >&2
        cat "$workDir/discovery-err.log" >&2
        exit 1
    fi
    candidateRules="$(printf '%s' "$discoveryJson" | jq -r '.file_diffs[]?.applied_rectors[]' 2>/dev/null | sort -u)"
    echo "Candidate rule(s) against the CURRENT tree (informational only - the real run reverts to the parent commit first, so this dry-run cannot show what would actually match):"
    if [ -z "$candidateRules" ]; then
        echo "(none)"
    else
        echo "$candidateRules"
    fi
    echo
    echo "Dry run complete. Re-run with --apply to perform the split for real."
    exit 0
fi

# --- backup, then physically remove the target commit and its changes ---
backupTag="pre-rector-split-$(echo "$targetSha" | cut -c1-8)-$(date +%s)"
git tag "$backupTag" "$originalHead"
echo "Backup tag created: $backupTag (recover with: git reset --hard $backupTag)"

sequenceEditor="$workDir/sequence-editor.sh"
cat > "$sequenceEditor" <<EOF
#!/bin/bash
awk -v target="$targetSha" '
{
    if (\$1 == "pick" && (index(target, \$2) == 1)) {
        print "edit", \$2, substr(\$0, length(\$1) + length(\$2) + 3)
    } else {
        print
    }
}
' "\$1" > "\$1.new"
mv "\$1.new" "\$1"
EOF
chmod +x "$sequenceEditor"

GIT_SEQUENCE_EDITOR="$sequenceEditor" git rebase -i "$parentSha"

# working tree currently holds the target commit's full content; --hard reset it (and the
# index) back to the parent, i.e. physically undo the commit, per the backup tag above
git reset --hard "$parentSha" > /dev/null

# --- build a commit message for a matched rule, reusing rector-single-commit.sh's
# messages/<rule>.txt lookup/generation logic. Unlike that script, a rule with no
# discoverable description falls back to a generic message instead of being skipped:
# the code the rule touched is already real history here either way. ---
build_rule_commit_message() {
    local rule="$1"
    local messageFile messageFilePath ruleShortName ruleDescription globalAutoload ruleUrl findRuleUrl ruleBlock fetchedUrl

    messageFile=$(echo "$rule" | tr '\\' '.')
    messageFilePath="$messagesDir/${messageFile}.txt"
    ruleShortName=$(echo "$rule" | sed 's/^.*\\//')

    if [ ! -f "$messageFilePath" ]; then
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

        if [ -z "$ruleDescription" ]; then
            findRuleUrl="https://getrector.com/find-rule?query=$ruleShortName"
            ruleBlock=$(curl -fsSL --max-time 10 "$findRuleUrl" | grep -B1 -A10 "^[[:space:]]*${ruleShortName}[[:space:]]*\$" || true)
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
            ruleDescription="Apply $ruleShortName"
        fi

        printf '%s\n\n%s\n' "$ruleDescription" "$ruleUrl" > "$messageFilePath"
    fi

    messageFileContents=$(<"$messageFilePath")
    printf '%s%s\n\nApplied rule:\n%s' "$commitMessagePrefix" "$messageFileContents" "$rule"
}

if [ "${#touchedFiles[@]}" -gt 0 ]; then
    stateFile="$workDir/state.json"
    php "$scopePath" init \
        --target="$targetSha" --parent="$parentSha" \
        --touched-files="$touchedFilesList" --repo="$repoRoot" --state="$stateFile"

    echo "Discovering candidate rector rules (scoped to the touched files)..." >&2
    discoveryJson=""
    discoveryExit=0
    discoveryJson="$("$rectorPath" process --dry-run --clear-cache --output-format=json "${touchedFiles[@]}" 2>"$workDir/discovery-err.log")" || discoveryExit=$?
    if [ "$discoveryExit" != "0" ] && [ "$discoveryExit" != "2" ]; then
        echo "rector discovery failed with exit code $discoveryExit:" >&2
        cat "$workDir/discovery-err.log" >&2
        discoveryJson=""
    fi
    candidateRules="$(printf '%s' "$discoveryJson" | jq -r '.file_diffs[]?.applied_rectors[]' 2>/dev/null | sort -u)"

    if [ -z "$candidateRules" ]; then
        candidateRules="$(
            php -r '
                $contents = file_get_contents($argv[1]);
                preg_match_all("/\\b(?:withRules|withConfiguredRule)\\s*\\(\\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/", $contents, $matches);
                foreach ($matches[1] as $rule) {
                    echo $rule, PHP_EOL;
                }
            ' "$(pwd)/rector.php" | sort -u
        )"
    fi

    if [ -n "$candidateRules" ]; then
        echo "Testing $(echo "$candidateRules" | wc -l | tr -d ' ') candidate rule(s)"
        kept=0
        while IFS= read -r rule; do
            [ -z "$rule" ] && continue
            "$rectorPath" process --clear-cache --no-diffs --only="$rule" "${touchedFiles[@]}" > /dev/null 2>&1 || true

            if [ -z "$(git status --porcelain)" ]; then
                continue
            fi

            git add -A
            git commit -q -m "rule: $rule (untrimmed)"

            ruleDiffFile="$workDir/rule.diff"
            git diff -U0 HEAD~1 HEAD > "$ruleDiffFile"
            trimOutDir="$workDir/trim-out"
            rm -rf "$trimOutDir"

            result="$(php "$scopePath" trim --state="$stateFile" --rule-diff="$ruleDiffFile" --repo="$repoRoot" --out-dir="$trimOutDir")"

            if [[ "$result" == DROPPED* ]]; then
                echo "  - $rule: nothing inside the target commit's changes; reverted"
                git reset --hard HEAD~1 > /dev/null
                continue
            fi

            filesStr="${result#KEPT }"
            IFS=',' read -ra keptFiles <<< "$filesStr"
            for f in "${keptFiles[@]}"; do
                cp "$trimOutDir/$f" "$f"
            done

            if [ -z "$(git status --porcelain)" ]; then
                # trimming reverted every line this rule touched
                echo "  - $rule: nothing inside the target commit's changes; reverted"
                git reset --hard HEAD~1 > /dev/null
                continue
            fi

            commitMessage="$(build_rule_commit_message "$rule")"
            git add -A
            printf '%s' "$commitMessage" | git commit -q --amend \
                --author='Rector <rector@getrector.com>' \
                --file=-
            echo "  - $rule: kept (${keptFiles[*]})"
            kept=$((kept + 1))
        done <<< "$candidateRules"
        echo "$kept rule commit(s) kept"
    else
        echo "no candidate rector rules found for the touched files"
    fi
fi

# --- leftover: reconcile whatever's left directly against the target commit's own tree.
# Real git checkout/rm, not a synthetic patch - guarantees byte-identical final content. ---
mapfile -t addedFiles < "$addedFilesList"
leftoverStaged=0
for f in "${touchedFiles[@]}" "${addedFiles[@]}"; do
    [ -z "$f" ] && continue
    if git cat-file -e "${targetSha}:${f}" 2>/dev/null; then
        mkdir -p "$(dirname "$f")"
        git show "${targetSha}:${f}" > "$f"
        git add "$f"
    else
        git rm -q -f --ignore-unmatch "$f" > /dev/null
    fi
done
if [ -n "$(git status --porcelain)" ]; then
    origMessage="$(git show -s --format=%B "$targetSha")"
    origAuthorName="$(git show -s --format=%an "$targetSha")"
    origAuthorEmail="$(git show -s --format=%ae "$targetSha")"
    origDate="$(git show -s --format=%aI "$targetSha")"
    printf '%s' "$origMessage" | git commit -q \
        --author="$origAuthorName <$origAuthorEmail>" \
        --date="$origDate" \
        --file=-
    leftoverStaged=1
    echo "Leftover commit created (reused original message/author/date)"
else
    echo "Nothing left over - rule commits fully reproduced the target commit"
fi

if ! git rebase --continue; then
    echo "ERROR: rebase --continue failed. Backup tag: $backupTag (recover with: git reset --hard $backupTag, or git rebase --abort if still mid-rebase)" >&2
    exit 1
fi

newHead="$(git rev-parse HEAD)"
if [ -n "$(git diff "$originalHead" "$newHead")" ]; then
    echo "ERROR: net diff vs original HEAD is not empty after split! Backup tag: $backupTag" >&2
    exit 1
fi

echo
echo "Split complete. Net diff vs original HEAD ($originalHead) is empty, as expected."
echo "New commits:"
git log --oneline "${parentSha}..${newHead}"
