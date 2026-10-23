<?php
// Compares each messages/*.txt file's stored description against the actual
// description returned by the corresponding rule class's getRuleDefinition().
//
// Usage: verify-rule-messages.php <global-autoload.php> <messages-dir> [<project-dir>]

[, $globalAutoload, $messagesDir, $projectDir] = array_pad($argv, 4, '.');

foreach (array_filter([
    $globalAutoload,
    rtrim($projectDir, '/') . '/vendor/autoload.php',
    rtrim($projectDir, '/') . '/.Build/vendor/autoload.php',
]) as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

require_once __DIR__ . '/rule-messages-lib.php';

$files = glob(rtrim($messagesDir, '/') . '/*.txt');
sort($files);

$matches = [];
$mismatches = [];
$notFound = [];
$extractionFailed = [];

foreach ($files as $file) {
    $fqcn = str_replace('.', '\\', basename($file, '.txt'));
    $storedDescription = trim(strtok(file_get_contents($file), "\n"));

    if (!class_exists($fqcn)) {
        $notFound[] = $fqcn;
        continue;
    }

    $ref = new ReflectionClass($fqcn);
    if (!$ref->hasMethod('getRuleDefinition')) {
        $extractionFailed[] = "$fqcn (no getRuleDefinition() method)";
        continue;
    }

    // Same extraction as sync-rule-messages.php (incl. inherited
    // getRuleDefinition() and non-literal descriptions).
    $actualDescription = ruleDescription($ref);
    if ($actualDescription !== null) {
        // Message files store the description trimmed; compare its first line.
        $actualDescription = trim(strtok(trim($actualDescription), "\n"));
    }

    if ($actualDescription === null) {
        $extractionFailed[] = "$fqcn (could not determine the description of 'new RuleDefinition(...)')";
        continue;
    }

    if ($actualDescription === $storedDescription) {
        $matches[] = $fqcn;
    } else {
        $mismatches[] = [
            'rule' => $fqcn,
            'stored' => $storedDescription,
            'actual' => $actualDescription,
        ];
    }
}

printf("Checked %d rule message file(s)\n", count($files));
printf("  Matches:            %d\n", count($matches));
printf("  Mismatches:         %d\n", count($mismatches));
printf("  Not found (class):  %d\n", count($notFound));
printf("  Extraction failed:  %d\n", count($extractionFailed));
echo "\n";

if ($mismatches) {
    echo "=== MISMATCHES ===\n";
    foreach ($mismatches as $m) {
        echo "- {$m['rule']}\n";
        echo "    stored: {$m['stored']}\n";
        echo "    actual: {$m['actual']}\n";
    }
    echo "\n";
}

if ($notFound) {
    echo "=== NOT FOUND (class does not exist / not autoloadable) ===\n";
    foreach ($notFound as $r) {
        echo "- $r\n";
    }
    echo "\n";
}

if ($extractionFailed) {
    echo "=== EXTRACTION FAILED (needs manual check) ===\n";
    foreach ($extractionFailed as $r) {
        echo "- $r\n";
    }
}
