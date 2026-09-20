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

function extractRuleDefinitionDescription(string $file): ?string
{
    $tokens = token_get_all(file_get_contents($file));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_NEW) {
            continue;
        }

        // Walk the (possibly namespaced) class name after "new", e.g.
        // "new RuleDefinition(" or "new \Rector\Definition\RuleDefinition(".
        $j = $i + 1;
        $lastName = null;
        while ($j < $count) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR], true)) {
                $j++;
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                $lastName = $t[1];
                $j++;
                continue;
            }
            break;
        }

        if ($lastName !== 'RuleDefinition' || !isset($tokens[$j]) || $tokens[$j] !== '(') {
            continue;
        }

        // Scan only within this call's own argument list (paren-depth
        // aware), so we never pick up an unrelated string from elsewhere in
        // the file (e.g. a later method or CodeSample).
        $depth = 1;
        for ($k = $j + 1; $k < $count && $depth > 0; $k++) {
            $t = $tokens[$k];
            if ($t === '(') {
                $depth++;
                continue;
            }
            if ($t === ')') {
                $depth--;
                continue;
            }
            if ($depth !== 1 || !is_array($t)) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                // eval() re-parses the literal exactly as PHP would, so both
                // single- and double-quoted escaping (incl. backslashes in
                // FQCNs like "TYPO3\CMS\...") is handled correctly.
                return eval('return ' . $t[1] . ';');
            }
            if ($t[0] === T_START_HEREDOC) {
                // Description given as a heredoc/nowdoc, e.g.
                // new RuleDefinition(<<<'DESCRIPTION' ... DESCRIPTION, [...]).
                $body = '';
                for ($m = $k + 1; $m < $count && !(is_array($tokens[$m]) && $tokens[$m][0] === T_END_HEREDOC); $m++) {
                    if (is_array($tokens[$m]) && $tokens[$m][0] === T_ENCAPSED_AND_WHITESPACE) {
                        $body .= $tokens[$m][1];
                    }
                }
                return rtrim($body, "\n");
            }
        }
    }

    return null;
}

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

    // Resolve to the class that actually DEFINES getRuleDefinition(), since
    // it may be inherited rather than declared on $fqcn itself.
    $declaringFile = $ref->getMethod('getRuleDefinition')->getDeclaringClass()->getFileName();
    $actualDescription = extractRuleDefinitionDescription($declaringFile);

    if ($actualDescription === null) {
        $extractionFailed[] = "$fqcn (could not locate 'new RuleDefinition(...)' in $declaringFile)";
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
