<?php

/** Isolated bootstrap logger cases; no framework or configured application DB connection. */
$mode = $argv[1];
$root = $argv[2];
if ($mode !== 'no_logger' && $mode !== 'unwritable') {
    function log_message($level, $message)
    {
        global $mode, $root;
        if ($mode === 'throwing_logger') {
            throw new RuntimeException('Synthetic unavailable logger.');
        }
        if ($mode === 'false_logger') {
            return false;
        }
        file_put_contents($root . '/framework.log', $level . ': ' . $message . "\n", FILE_APPEND);
        return true;
    }
}
require dirname(__DIR__, 3) . '/app/Config/BootstrapGuard.php';
ini_set('error_log', $root . '/server.log');
if ($mode === 'unwritable') {
    file_put_contents($root . '/blocked', 'Synthetic path obstruction.');
    $root .= '/blocked';
}
$timezone = date_default_timezone_get();
bootstrap_write_error($root, 'Synthetic logging failure password=fixture-secret', new DateTimeImmutable('2026-10-15T16:00:00+00:00'));
echo json_encode(['timezone_restored' => date_default_timezone_get() === $timezone]);
