<?php
// Shared helpers for the rule message scripts (verify-rule-messages.php,
// sync-rule-messages.php).

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
                // A concatenation like 'Remove ' . Assert::class . '...' is
                // no complete literal; null lets the caller resolve it.
                for ($n = $k + 1; $n < $count && is_array($tokens[$n]) && in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT], true); $n++);
                if (($tokens[$n] ?? null) === '.') {
                    return null;
                }
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

/**
 * Description of a loaded rule class, taken from the class that actually
 * declares getRuleDefinition() (it may be inherited). Null if not found.
 *
 * When the description is no plain literal (e.g. "self::DESCRIPTION"), the
 * rule's getRuleDefinition() is called on an instance created without its
 * constructor, which needs no services for these plain value objects.
 */
function ruleDescription(ReflectionClass $ref): ?string
{
    if (!$ref->hasMethod('getRuleDefinition')) {
        return null;
    }
    $file = $ref->getMethod('getRuleDefinition')->getDeclaringClass()->getFileName();
    $description = $file === false ? null : extractRuleDefinitionDescription($file);
    if ($description !== null) {
        return $description;
    }

    try {
        $description = $ref->newInstanceWithoutConstructor()->getRuleDefinition()->getDescription();
    } catch (Throwable) {
        return null;
    }
    return is_string($description) ? $description : null;
}

/**
 * Links for a rule's commit message: every "@changelog <url>" of the class
 * docblock, otherwise the getrector.com rule detail page for rules that are
 * documented there (same URL scheme as rector-single-commit.sh).
 */
function ruleLinks(ReflectionClass $ref): array
{
    preg_match_all('/@changelog\s+(\S+)/', (string) $ref->getDocComment(), $m);
    if ($m[1] !== []) {
        return $m[1];
    }

    $rootNamespace = strtok($ref->getName(), '\\');
    if (in_array($rootNamespace, ['Rector', 'RectorLaravel'], true)) {
        $slug = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $ref->getShortName()));
        return ['https://getrector.com/rule-detail/' . $slug];
    }

    return [];
}

/**
 * Generated messages/*.txt content: description, then a blank line and the
 * links (one per line). Null when the description could not be extracted.
 */
function ruleMessageContent(ReflectionClass $ref): ?string
{
    $description = trim((string) ruleDescription($ref));
    if ($description === '') {
        return null;
    }

    $content = $description . "\n";
    $links = ruleLinks($ref);
    if ($links !== []) {
        $content .= "\n" . implode("\n", $links) . "\n";
    }
    return $content;
}

/** messages/ file name (without .txt) for a rule class, and back. */
function ruleMessageName(string $fqcn): string
{
    return str_replace('\\', '.', $fqcn);
}

function ruleClassFromMessageName(string $name): string
{
    return str_replace('.', '\\', $name);
}

/**
 * Root directories that contain rector rules, read from a composer
 * vendor/composer/installed.json: rector/rector itself (incl. the rule
 * packages it bundles under its own vendor/rector/) and every package of
 * type "rector-extension". Pass $includeRectorCore = false for a project's
 * vendor dir, so only its extra rule packages are scanned.
 */
function rectorRuleDirs(string $vendorDir, bool $includeRectorCore = true): array
{
    $installedFile = rtrim($vendorDir, '/') . '/composer/installed.json';
    if (!is_file($installedFile)) {
        return [];
    }
    $installed = json_decode(file_get_contents($installedFile), true);
    $packages = $installed['packages'] ?? $installed;

    $dirs = [];
    foreach ($packages as $package) {
        $isCore = $package['name'] === 'rector/rector';
        if (!($isCore && $includeRectorCore) && ($package['type'] ?? '') !== 'rector-extension') {
            continue;
        }
        $path = realpath(dirname($installedFile) . '/' . ($package['install-path'] ?? '../' . $package['name']));
        if ($path === false) {
            continue;
        }
        if ($isCore) {
            foreach (['rules', 'src'] as $sub) {
                if (is_dir("$path/$sub")) {
                    $dirs[] = "$path/$sub";
                }
            }
            foreach (glob("$path/vendor/rector/*", GLOB_ONLYDIR) as $bundled) {
                $dirs[] = $bundled;
            }
        } else {
            $dirs[] = $path;
        }
    }
    return $dirs;
}

/**
 * All instantiable rector rule classes (FQCN => ReflectionClass) found below
 * the given directories. Test, fixture, stub and nested vendor dirs are
 * skipped; candidates must be autoloadable and implement RectorInterface.
 */
function discoverRules(array $dirs): array
{
    $rules = [];
    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $f) => !$f->isDir()
                || !preg_match('/^(tests?|fixtures?|stubs?|vendor)$/i', $f->getFilename())
        ));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (!str_contains($source, 'function getRuleDefinition')) {
                continue;
            }
            $fqcn = declaredClassName($source);
            if ($fqcn === null || isset($rules[$fqcn])) {
                continue;
            }
            try {
                if (!class_exists($fqcn)) {
                    continue;
                }
                $ref = new ReflectionClass($fqcn);
            } catch (Throwable) {
                continue;
            }
            if ($ref->isInstantiable() && $ref->implementsInterface('Rector\\Contract\\Rector\\RectorInterface')) {
                $rules[$fqcn] = $ref;
            }
        }
    }
    ksort($rules);
    return $rules;
}

/** FQCN of the first class declared in a PHP source, or null. */
function declaredClassName(string $source): ?string
{
    $tokens = token_get_all($source);
    $namespace = '';
    foreach ($tokens as $i => $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $namespace .= $tokens[$j][1];
                }
            }
            continue;
        }
        if ($token[0] !== T_CLASS) {
            continue;
        }
        // Skip "Foo::class" and anonymous "new class".
        for ($p = $i - 1; isset($tokens[$p]) && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE; $p--);
        if (isset($tokens[$p]) && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }
        for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
        if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            return ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j][1];
        }
    }
    return null;
}
