<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// No FOSSBilling bootstrap, installer, cron, logger, external APIs or Composer changes.
$root = dirname(__DIR__, 3);
if (!is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException('Run this extension test suite from inside a FOSSBilling 0.8.9 installation.');
}
require_once $root . '/vendor/autoload.php';
if (!defined('PATH_CONFIG')) {
    define('PATH_CONFIG', $root . '/config.php');
}
if (!defined('DEBUG')) {
    define('DEBUG', false);
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function rejects(callable $operation, string $message): void
{
    $rejected = false;
    try {
        $operation();
    } catch (\Box\Mod\Whmcsmigrator\Security\SafeException) {
        $rejected = true;
    }
    expect($rejected, $message);
}
