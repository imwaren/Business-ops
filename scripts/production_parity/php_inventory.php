<?php
/** Private release tooling: emit token offsets or write configuration to a private file. */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1];
if ($mode === 'functions') {
    $source = file_get_contents($argv[2]);
    $tokens = token_get_all($source);
    $offsets = []; $offset = 0;
    foreach ($tokens as $token) { $offsets[] = $offset; $offset += strlen(is_array($token) ? $token[1] : $token); }
    $functions = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
        $name = null; $body = null;
        for ($next = $index + 1; $next < count($tokens); $next++) {
            $candidate = $tokens[$next];
            if (is_array($candidate) && $candidate[0] === T_STRING && $name === null) { $name = $candidate[1]; }
            if ($candidate === '(' && $name === null) { break; }
            if ($candidate === '{') { $body = $next; break; }
            if ($candidate === ';') { break; }
        }
        if ($name === null || $body === null) { continue; }
        $depth = 1;
        for ($end = $body + 1; $end < count($tokens); $end++) {
            if ($tokens[$end] === '{' || (is_array($tokens[$end]) && in_array($tokens[$end][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            if ($tokens[$end] === '}') { $depth--; }
            if ($depth === 0) { break; }
        }
        $line_start = strrpos(substr($source, 0, $offsets[$index]), "\n");
        $start = $line_start === false ? 0 : $line_start + 1;
        $functions[$name] = ['start' => $start, 'end' => $offsets[$end] + 1];
    }
    echo json_encode($functions); exit;
}
if ($mode === 'literals') {
    $tokens = array_values(array_filter(token_get_all(file_get_contents($argv[2])), static function ($token) {
        return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));
    $values = [];
    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], ['$key', '$iv'], true)
            && ($tokens[$index + 1] ?? null) === '=' && is_array($tokens[$index + 2] ?? null)
            && $tokens[$index + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
            $values[substr($token[1], 1)] = eval('return ' . $tokens[$index + 2][1] . ';');
        }
    }
    file_put_contents($argv[3], json_encode($values, JSON_THROW_ON_ERROR));
    exit;
}
ob_start();
try {
    $root = rtrim($argv[2], '/') . '/';
    chdir($root);
    require $root . 'vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    require $root . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
    $groups = [];
    foreach (get_object_vars(new Config\Database()) as $name => $settings) {
        if (is_array($settings) && isset($settings['DBDriver']) && $name !== 'tests') { $groups[$name] = $settings; }
    }
    $constants = get_defined_constants(true)['user'];
    $data = ['constants' => $constants, 'groups' => $groups, 'encryption' => get_object_vars(new Config\Encryption()),
        'app' => get_object_vars(new Config\App()), 'email' => get_object_vars(new Config\Email()),
        'env' => $_ENV, 'origins' => function_exists('cors_allowed_origins') ? cors_allowed_origins() : []];
    file_put_contents($argv[3], serialize($data), LOCK_EX);
    chmod($argv[3], 0600);
    // JSON is private as well; keep non-UTF8 values in the serialized original.
    file_put_contents($argv[3] . '.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    chmod($argv[3] . '.json', 0600);
    ob_end_clean(); echo json_encode(['ok' => true, 'groups' => count($groups)]) . PHP_EOL;
} catch (Throwable $error) {
    ob_end_clean(); echo json_encode(['ok' => false, 'type' => get_class($error), 'line' => $error->getLine()]) . PHP_EOL; exit(1);
}
