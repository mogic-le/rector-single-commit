<?php
// Creates, updates and removes messages/*.txt files so there is one for every
// installed rector rule. Called by sync-rule-messages.sh.
//
// messages.lock.json remembers the content generated from each rule's own
// description at the last sync. That separates "upstream changed" from
// "message was reworded by hand":
//   NEW      rule has no message file                  -> create it
//   UPDATED  upstream changed, file not reworded       -> overwrite it
//   REVIEW   upstream changed, file reworded by hand   -> keep the file,
//            only the lock moves on (so it is reported once per change)
//   REMOVED  rule is gone, its package is installed    -> delete the file
//   SKIPPED  rule's package is not installed at all    -> keep the file
//   FAILED   rule has no extractable description       -> nothing written
// Files without a lock entry yet (first run, or created by
// rector-single-commit.sh) just get their baseline recorded.
//
// Usage: sync-rule-messages.php --mode=dry-run|apply --global-vendor=<dir>
//        --messages-dir=<dir> --lock=<file> [--project-dir=<dir>]

require_once __DIR__ . '/rule-messages-lib.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}
$apply = ($args['mode'] ?? 'dry-run') === 'apply';
$globalVendor = $args['global-vendor'];
$messagesDir = rtrim($args['messages-dir'], '/');
$lockFile = $args['lock'];
$projectDir = $args['project-dir'] ?? '';

// Autoloaders and rule dirs: global composer first, then the project's
// own vendor dir for rule packages only installed there (e.g. rector-laravel).
$vendorDirs = [[$globalVendor, true]];
if ($projectDir !== '') {
    foreach (['vendor', '.Build/vendor'] as $sub) {
        $vendorDirs[] = [rtrim($projectDir, '/') . '/' . $sub, false];
    }
}
$ruleDirs = [];
foreach ($vendorDirs as [$vendorDir, $includeCore]) {
    if (is_file("$vendorDir/autoload.php")) {
        require_once "$vendorDir/autoload.php";
        $ruleDirs = array_merge($ruleDirs, rectorRuleDirs($vendorDir, $includeCore));
    }
}

$rules = discoverRules($ruleDirs);
if ($rules === []) {
    fwrite(STDERR, "No rector rules found; is rector installed as a global composer package?\n");
    exit(1);
}
$installedRoots = [];
foreach (array_keys($rules) as $fqcn) {
    $installedRoots[strtok($fqcn, '\\')] = true;
}

$lock = is_file($lockFile) ? json_decode(file_get_contents($lockFile), true) : [];
$newLock = [];

$report = ['NEW' => [], 'UPDATED' => [], 'REVIEW' => [], 'REMOVED' => [], 'SKIPPED' => [], 'FAILED' => []];
$baselined = 0;
$unchanged = 0;
$writes = [];
$deletes = [];

foreach ($rules as $fqcn => $ref) {
    $path = $messagesDir . '/' . ruleMessageName($fqcn) . '.txt';
    $generated = ruleMessageContent($ref);
    $current = is_file($path) ? file_get_contents($path) : null;

    if ($generated === null) {
        $report['FAILED'][] = "$fqcn (no description in " . $ref->getFileName() . ')';
        if (isset($lock[$fqcn])) {
            $newLock[$fqcn] = $lock[$fqcn];
        }
        continue;
    }

    if ($current === null) {
        $report['NEW'][] = $fqcn . "\n      " . strtok($generated, "\n");
        $writes[$path] = $generated;
        $newLock[$fqcn] = $generated;
        continue;
    }

    $newLock[$fqcn] = $generated;
    $locked = $lock[$fqcn] ?? null;
    if ($locked === null) {
        $baselined++;
    } elseif ($locked === $generated || $current === $generated) {
        $unchanged++;
    } elseif ($current === $locked) {
        $report['UPDATED'][] = $fqcn . "\n      old: " . strtok($locked, "\n") . "\n      new: " . strtok($generated, "\n");
        $writes[$path] = $generated;
    } else {
        $report['REVIEW'][] = $fqcn
            . "\n      file:         " . strtok($current, "\n")
            . "\n      upstream was: " . strtok($locked, "\n")
            . "\n      upstream now: " . strtok($generated, "\n");
    }
}

foreach (glob($messagesDir . '/*.txt') as $path) {
    $fqcn = ruleClassFromMessageName(basename($path, '.txt'));
    if (isset($rules[$fqcn])) {
        continue;
    }
    $root = strtok($fqcn, '\\');
    if (isset($installedRoots[$root])) {
        $report['REMOVED'][] = $fqcn;
        $deletes[] = $path;
    } else {
        $report['SKIPPED'][] = "$fqcn (no installed package provides $root\\*; pass its project dir to check it)";
        if (isset($lock[$fqcn])) {
            $newLock[$fqcn] = $lock[$fqcn];
        }
    }
}

ksort($newLock);
$lockChanged = $newLock !== $lock;

printf("%s: %d installed rules, %d message files\n", $apply ? 'Apply' : 'Dry run', count($rules), count(glob($messagesDir . '/*.txt')));
foreach ($report as $type => $lines) {
    printf("  %-8s %d\n", $type, count($lines));
}
printf("  %-8s %d\n", 'OK', $unchanged);
if ($baselined > 0) {
    printf("  (%d existing file(s) without lock entry; baseline recorded)\n", $baselined);
}

foreach ($report as $type => $lines) {
    if ($lines === []) {
        continue;
    }
    echo "\n=== $type ===\n";
    foreach ($lines as $line) {
        echo "- $line\n";
    }
}

if (!$apply) {
    $pending = $writes !== [] || $deletes !== [] || $lockChanged;
    echo $pending
        ? "\nDry run, nothing changed. Run with --apply to write the changes above.\n"
        : "\nEverything is in sync.\n";
    exit(0);
}

foreach ($writes as $path => $content) {
    file_put_contents($path, $content);
}
foreach ($deletes as $path) {
    unlink($path);
}
if ($lockChanged) {
    file_put_contents($lockFile, json_encode($newLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
printf("\nWrote %d, deleted %d message file(s)%s.\n", count($writes), count($deletes), $lockChanged ? ', updated ' . basename($lockFile) : '');
