<?php

declare(strict_types=1);

// Tracks which (file, line-range) regions a target commit actually touched, and decides,
// for each real commit a rector rule subsequently produces (scoped to the same files, run
// for real against the reverted-to-parent working tree), whether that commit's changes
// belong there:
//   - no overlap with target's original regions at all  -> revert (drop) entirely
//   - fully inside target's regions                     -> keep as-is
//   - partially inside, same old/new line count          -> trim line-by-line: lines that
//     fall inside target's regions keep rector's version, the rest revert to original
//   - partially inside, line count changed (structural)  -> keep whole hunk (can't trim a
//     replacement without a well-defined old-line-to-new-line mapping)
//
// Two modes:
//   init  --target= --parent= --touched-files= --repo= --state=
//     Parses the target commit's own diff into per-file hunk ranges and writes them to
//     --state as the initial (translated) scope, since nothing has shifted yet.
//   trim  --state= --rule-diff= --repo= --out-dir=
//     Given one rule-commit's own isolated diff (HEAD~1..HEAD), applies the above decision
//     per hunk, writes each affected file's corrected content under --out-dir, updates
//     --state with the new translated positions, and prints one line to stdout:
//     "KEPT <file>,<file>,..." or "DROPPED" (nothing in this commit was in scope).

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

function hunkRange(array $hunk): array
{
    // accepts either a diff-parsed hunk (oldStart/oldLines) or a stored scope entry
    // (start/lines). A pure insertion (lines === 0) is treated as a 1-wide point at its
    // position, purely so overlap checks have something non-empty to intersect against.
    $start = $hunk['start'] ?? $hunk['oldStart'];
    $lines = $hunk['lines'] ?? $hunk['oldLines'];
    return [$start, $start + max($lines, 1)];
}

function rangesOverlap(array $a, array $b): bool
{
    return $a[0] < $b[1] && $b[0] < $a[1];
}

function splice(array &$lines, int $splicePos, int $removeCount, array $insert): void
{
    array_splice($lines, $splicePos, $removeCount, $insert);
}

$args = readArgs($argv);
$mode = $argv[1] ?? '';

if ($mode === 'init') {
    foreach (['target', 'parent', 'touched-files', 'repo', 'state'] as $required) {
        if (!isset($args[$required])) {
            fwrite(STDERR, "missing --$required\n");
            exit(1);
        }
    }
    $touchedFiles = array_filter(array_map('trim', (array) file($args['touched-files'])));
    $state = ['files' => []];
    foreach ($touchedFiles as $file) {
        $diffText = shell_exec(sprintf(
            'git -C %s diff -U0 %s %s -- %s 2>/dev/null',
            escapeshellarg($args['repo']),
            escapeshellarg($args['parent']),
            escapeshellarg($args['target']),
            escapeshellarg($file)
        )) ?? '';
        $parsed = parseDiff($diffText);
        $hunks = $parsed[$file] ?? [];
        $state['files'][$file] = array_map(
            static fn ($h) => ['start' => $h['oldStart'], 'lines' => $h['oldLines']],
            $hunks
        );
    }
    file_put_contents($args['state'], json_encode($state, JSON_PRETTY_PRINT));
    exit(0);
}

if ($mode === 'trim') {
    foreach (['state', 'rule-diff', 'repo', 'out-dir'] as $required) {
        if (!isset($args[$required])) {
            fwrite(STDERR, "missing --$required\n");
            exit(1);
        }
    }
    $state = json_decode((string) file_get_contents($args['state']), true) ?? ['files' => []];
    $ruleFiles = parseDiff((string) file_get_contents($args['rule-diff']));

    $outDir = rtrim($args['out-dir'], '/');
    @mkdir($outDir, 0777, true);

    $anyKept = false;
    $affectedFiles = [];

    foreach ($ruleFiles as $file => $hunks) {
        if ($hunks === []) {
            continue;
        }
        $targetHunks = $state['files'][$file] ?? [];
        usort($hunks, static fn ($a, $b) => $a['oldStart'] <=> $b['oldStart']);

        $preContent = gitShow($args['repo'], 'HEAD~1', $file);
        if ($preContent === null) {
            fwrite(STDERR, "could not read pre-commit content of $file\n");
            exit(1);
        }
        $lines = readLines($preContent);
        $hasTrailingNewline = $preContent === '' || str_ends_with($preContent, "\n");

        $appliedSoFar = [];
        $fileChanged = false;
        $newTargetHunks = $targetHunks;

        foreach ($hunks as $hunk) {
            $range = hunkRange($hunk);
            $overlapping = array_filter($targetHunks, static fn ($t) => rangesOverlap($range, hunkRange($t)));

            $fullyCovered = $overlapping !== [] && array_reduce(
                range($hunk['oldStart'], $hunk['oldStart'] + max($hunk['oldLines'], 1) - 1),
                static function (bool $carry, int $pos) use ($overlapping) {
                    foreach ($overlapping as $t) {
                        [$s, $e] = hunkRange($t);
                        if ($pos >= $s && $pos < $e) {
                            return $carry;
                        }
                    }
                    return false;
                },
                true
            );

            if ($overlapping === []) {
                $decision = 'drop';
            } elseif ($fullyCovered) {
                $decision = 'keep';
            } elseif (count($hunk['oldLinesArr']) === count($hunk['newLinesArr'])) {
                $decision = 'trim';
            } else {
                $decision = 'keep';
            }

            if ($decision === 'drop') {
                $insert = $hunk['oldLinesArr'];
            } elseif ($decision === 'keep') {
                $insert = $hunk['newLinesArr'];
            } else {
                $insert = [];
                foreach ($hunk['oldLinesArr'] as $i => $oldLine) {
                    $pos = $hunk['oldStart'] + $i;
                    $inScope = false;
                    foreach ($overlapping as $t) {
                        [$s, $e] = hunkRange($t);
                        if ($pos >= $s && $pos < $e) {
                            $inScope = true;
                            break;
                        }
                    }
                    $insert[] = $inScope ? $hunk['newLinesArr'][$i] : $oldLine;
                }
            }

            if ($insert !== $hunk['oldLinesArr']) {
                $fileChanged = true;
            }

            $offset = 0;
            foreach ($appliedSoFar as [$appliedStart, $delta]) {
                if ($appliedStart < $hunk['oldStart']) {
                    $offset += $delta;
                }
            }
            $splicePos = $hunk['oldStart'] - ($hunk['oldLines'] === 0 ? 0 : 1) + $offset;
            splice($lines, $splicePos, $hunk['oldLines'], $insert);
            $delta = count($insert) - $hunk['oldLines'];
            $appliedSoFar[] = [$hunk['oldStart'], $delta];

            if ($delta !== 0) {
                foreach ($newTargetHunks as &$t) {
                    if ($t['start'] > $hunk['oldStart']) {
                        $t['start'] += $delta;
                    }
                }
                unset($t);
            }
        }

        $state['files'][$file] = $newTargetHunks;

        if ($fileChanged) {
            $anyKept = true;
            $affectedFiles[] = $file;
        }

        $target = "$outDir/$file";
        @mkdir(dirname($target), 0777, true);
        file_put_contents($target, writeLines($lines, $hasTrailingNewline));
    }

    file_put_contents($args['state'], json_encode($state, JSON_PRETTY_PRINT));

    if ($anyKept) {
        echo 'KEPT ' . implode(',', $affectedFiles) . "\n";
    } else {
        echo "DROPPED\n";
    }
    exit(0);
}

fwrite(STDERR, "unknown mode '$mode' (expected 'init' or 'trim')\n");
exit(1);
