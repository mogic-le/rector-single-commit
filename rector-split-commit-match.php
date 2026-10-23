<?php

declare(strict_types=1);

// Matches hunks that are already present in a historical commit's diff (--real-diff)
// against hunks produced by cumulatively re-running individual Rector rules against
// that commit's parent state (--rule-diffs-dir, one file per rule, in --rules-order).
// A hunk is claimed by a rule only when its exact removed/added line content matches
// an unclaimed hunk of the real diff for the same file - no position/context is used
// for matching, since candidate rules were applied cumulatively and may have shifted
// line numbers unrelated to the actual historical edit. Every hunk that ends up
// unclaimed becomes part of the LEFTOVER step. For each step, this script writes the
// exact resulting file content for every file that step changes, so the caller can
// just copy files into place and commit - no patch application involved.

function readArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $args[$key] = $value;
        }
    }
    return $args;
}

function readLines(string $content): array
{
    if ($content === '') {
        return [];
    }
    $lines = explode("\n", $content);
    if (end($lines) === '') {
        array_pop($lines);
    }
    return $lines;
}

function writeLines(array $lines, bool $trailingNewline = true): string
{
    if ($lines === []) {
        return '';
    }
    return implode("\n", $lines) . ($trailingNewline ? "\n" : '');
}

/** @return array<string, array<int, array{oldStart:int,oldLines:int,newStart:int,newLines:int,oldLinesArr:string[],newLinesArr:string[]}>> */
function parseDiff(string $diffText): array
{
    $files = [];
    $currentFile = null;
    $currentHunk = null;

    $flushHunk = function () use (&$files, &$currentFile, &$currentHunk): void {
        if ($currentFile !== null && $currentHunk !== null) {
            $files[$currentFile][] = $currentHunk;
        }
        $currentHunk = null;
    };

    $skipPrefixes = [
        '--- ', '+++ ', 'index ', 'new file mode', 'deleted file mode',
        'similarity index', 'rename from', 'rename to', 'old mode', 'new mode',
        'Binary files',
    ];

    foreach (explode("\n", $diffText) as $line) {
        if (str_starts_with($line, 'diff --git ')) {
            $flushHunk();
            if (preg_match('#^diff --git a/(.+) b/(.+)$#', $line, $m)) {
                $currentFile = $m[2];
                $files[$currentFile] ??= [];
            } else {
                $currentFile = null;
            }
            continue;
        }

        $skip = false;
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($line, $prefix)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        if (str_starts_with($line, '@@ ')) {
            $flushHunk();
            if ($currentFile === null || !preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                continue;
            }
            $currentHunk = [
                'oldStart' => (int) $m[1],
                'oldLines' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1,
                'newStart' => (int) $m[3],
                'newLines' => isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1,
                'oldLinesArr' => [],
                'newLinesArr' => [],
            ];
            continue;
        }

        if ($currentHunk === null) {
            continue;
        }
        if (str_starts_with($line, '-')) {
            $currentHunk['oldLinesArr'][] = substr($line, 1);
        } elseif (str_starts_with($line, '+')) {
            $currentHunk['newLinesArr'][] = substr($line, 1);
        }
    }
    $flushHunk();

    return $files;
}

function hunkKey(array $hunk): string
{
    return implode("\n", $hunk['oldLinesArr']) . "\x00" . implode("\n", $hunk['newLinesArr']);
}

function gitShow(string $repo, string $commit, string $file): ?string
{
    $cmd = sprintf('git -C %s show %s:%s 2>/dev/null', escapeshellarg($repo), escapeshellarg($commit), escapeshellarg($file));
    $handle = popen($cmd, 'r');
    if ($handle === false) {
        return null;
    }
    $content = stream_get_contents($handle);
    $exitCode = pclose($handle);
    return $exitCode === 0 ? $content : null;
}

$args = readArgs($argv);
foreach (['real-diff', 'rule-diffs-dir', 'rules-order', 'touched-files', 'added-files', 'parent-commit', 'target-commit', 'repo', 'out-dir'] as $required) {
    if (!isset($args[$required])) {
        fwrite(STDERR, "missing --$required\n");
        exit(1);
    }
}

$realFiles = parseDiff((string) file_get_contents($args['real-diff']));

// unclaimed real-hunk indices per file, queued by content key (fifo per key, so
// duplicate identical hunks in the same file are matched in original order)
$unclaimedQueue = [];
$hunkOwner = [];
foreach ($realFiles as $file => $hunks) {
    foreach ($hunks as $i => $hunk) {
        $unclaimedQueue[$file][hunkKey($hunk)][] = $i;
        $hunkOwner[$file][$i] = null;
    }
}

$rules = array_filter(array_map('trim', (array) file($args['rules-order'])));
$stepOrder = [];

foreach ($rules as $rule) {
    $ruleDiffFile = rtrim($args['rule-diffs-dir'], '/') . '/' . str_replace('\\', '.', $rule) . '.diff';
    if (!is_file($ruleDiffFile)) {
        continue;
    }
    $ruleFiles = parseDiff((string) file_get_contents($ruleDiffFile));
    $claimedByThisRule = 0;

    foreach ($ruleFiles as $file => $hunks) {
        if (!isset($realFiles[$file])) {
            continue;
        }
        foreach ($hunks as $hunk) {
            $key = hunkKey($hunk);
            if (!empty($unclaimedQueue[$file][$key])) {
                $hunkIndex = array_shift($unclaimedQueue[$file][$key]);
                $hunkOwner[$file][$hunkIndex] = $rule;
                $claimedByThisRule++;
            }
        }
    }

    if ($claimedByThisRule > 0) {
        $stepOrder[] = $rule;
    }
}

foreach ($hunkOwner as $file => $owners) {
    foreach ($owners as $i => $owner) {
        if ($owner === null) {
            $hunkOwner[$file][$i] = 'LEFTOVER';
        }
    }
}

$leftoverHunkCount = 0;
foreach ($hunkOwner as $owners) {
    $leftoverHunkCount += count(array_filter($owners, static fn ($o) => $o === 'LEFTOVER'));
}

// touched files that produced no parseable hunks (binary, mode-only, etc.), and files
// that only exist at the target commit (pure additions - a rule can't invent a whole
// file from nothing, including the "new" side of a rename) still need to end up
// somewhere; their whole content goes straight into LEFTOVER
$touchedFiles = array_filter(array_map('trim', (array) file($args['touched-files'])));
$addedFiles = array_filter(array_map('trim', (array) file($args['added-files'])));
$wholeFileLeftovers = [];
foreach ($touchedFiles as $file) {
    if (empty($realFiles[$file])) {
        $wholeFileLeftovers[] = $file;
    }
}
foreach ($addedFiles as $file) {
    $wholeFileLeftovers[] = $file;
}

if (($leftoverHunkCount > 0 || $wholeFileLeftovers !== []) && !in_array('LEFTOVER', $stepOrder, true)) {
    $stepOrder[] = 'LEFTOVER';
}

$stepIndex = array_map(static fn ($i) => $i + 1, array_flip($stepOrder));
$totalSteps = count($stepOrder);

$outDir = rtrim($args['out-dir'], '/');
@mkdir($outDir, 0777, true);

$changedFilesPerStep = array_fill(1, max($totalSteps, 1), []);
$errors = [];
$deletions = [];

foreach ($realFiles as $file => $hunks) {
    if ($hunks === []) {
        continue;
    }
    $parentContent = gitShow($args['repo'], $args['parent-commit'], $file);
    if ($parentContent === null) {
        $errors[] = "could not read $file at parent commit {$args['parent-commit']}";
        continue;
    }
    $parentLines = readLines($parentContent);
    usort($hunks, static fn ($a, $b) => $a['oldStart'] <=> $b['oldStart']);

    // reconstruct using whatever trailing-newline convention the target actually has
    // (falls back to parent's for a deletion, where it's moot either way)
    $targetContent = gitShow($args['repo'], $args['target-commit'], $file);
    $referenceForNewline = $targetContent ?? $parentContent;
    $hasTrailingNewline = $referenceForNewline === '' || str_ends_with($referenceForNewline, "\n");

    $prevSnapshot = writeLines($parentLines, $hasTrailingNewline);
    for ($k = 1; $k <= $totalSteps; $k++) {
        $lines = $parentLines;
        $appliedSoFar = [];
        foreach ($hunks as $i => $hunk) {
            $owner = $hunkOwner[$file][$i];
            if (($stepIndex[$owner] ?? PHP_INT_MAX) > $k) {
                continue;
            }
            $offset = 0;
            foreach ($appliedSoFar as [$appliedStart, $delta]) {
                if ($appliedStart < $hunk['oldStart']) {
                    $offset += $delta;
                }
            }
            // a pure insertion (oldLines === 0) declares its position as "after old line
            // oldStart", one past where a same-numbered replacement/deletion hunk would start
            $splicePos = $hunk['oldStart'] - ($hunk['oldLines'] === 0 ? 0 : 1) + $offset;
            array_splice($lines, $splicePos, $hunk['oldLines'], $hunk['newLinesArr']);
            $appliedSoFar[] = [$hunk['oldStart'], count($hunk['newLinesArr']) - $hunk['oldLines']];
        }

        $snapshot = writeLines($lines, $hasTrailingNewline);
        if ($snapshot !== $prevSnapshot) {
            $target = "$outDir/step-$k/$file";
            @mkdir(dirname($target), 0777, true);
            file_put_contents($target, $snapshot);
            $changedFilesPerStep[$k][] = $file;
        }
        $prevSnapshot = $snapshot;
    }

    if ($targetContent === null) {
        // file doesn't exist at the target commit at all - it was deleted, not emptied
        if ($prevSnapshot !== '') {
            $errors[] = "integrity check failed for $file: expected deletion but reconstruction is not empty ({$args['target-commit']})";
        } else {
            $deletions[] = $file;
        }
    } elseif ($targetContent !== $prevSnapshot) {
        $errors[] = "integrity check failed for $file: reconstructed content does not match {$args['target-commit']}";
    }
}

if ($wholeFileLeftovers !== []) {
    $leftoverStep = $stepIndex['LEFTOVER'] ?? null;
    foreach ($wholeFileLeftovers as $file) {
        $content = gitShow($args['repo'], $args['target-commit'], $file);
        if ($content === null) {
            // no parseable hunks (binary) and absent at target - a binary deletion
            $deletions[] = $file;
            $changedFilesPerStep[$leftoverStep][] = $file;
            continue;
        }
        $target = "$outDir/step-$leftoverStep/$file";
        @mkdir(dirname($target), 0777, true);
        file_put_contents($target, $content);
        $changedFilesPerStep[$leftoverStep][] = $file;
    }
}

if ($deletions !== []) {
    $deletionsFile = fopen("$outDir/deletions.txt", 'w');
    foreach ($deletions as $file) {
        $lastStep = null;
        foreach ($changedFilesPerStep as $k => $files) {
            if (in_array($file, $files, true)) {
                $lastStep = $k;
            }
        }
        if ($lastStep !== null) {
            fwrite($deletionsFile, $lastStep . "\t" . $file . "\n");
        }
    }
    fclose($deletionsFile);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: $error\n");
    }
    exit(1);
}

$stepsFile = fopen("$outDir/steps.txt", 'w');
foreach ($stepOrder as $i => $rule) {
    $k = $i + 1;
    if (empty($changedFilesPerStep[$k])) {
        continue;
    }
    fwrite($stepsFile, $k . "\t" . $rule . "\t" . implode(',', $changedFilesPerStep[$k]) . "\n");
}
fclose($stepsFile);

$plan = fopen("$outDir/plan.txt", 'w');
$totalRealHunks = array_sum(array_map('count', $realFiles));
fwrite($plan, "Split plan for {$args['target-commit']} (" . count($realFiles) . " file(s), $totalRealHunks hunk(s)):\n\n");
foreach ($stepOrder as $rule) {
    $count = 0;
    foreach ($hunkOwner as $owners) {
        $count += count(array_filter($owners, static fn ($o) => $o === $rule));
    }
    $fileCount = count($changedFilesPerStep[$stepIndex[$rule]] ?? []);
    $label = $rule === 'LEFTOVER' ? 'LEFTOVER (original WIP commit)' : $rule;
    fwrite($plan, " - $label: $count hunk(s), $fileCount file(s)\n");
}
fclose($plan);
