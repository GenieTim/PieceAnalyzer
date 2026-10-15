<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
if (str_starts_with((string) $databaseUrl, 'sqlite:')) {
    $testDb = dirname(__DIR__) . '/var/test.db';
    if (!file_exists($testDb)) {
        passthru(sprintf('php "%s/bin/console" doctrine:schema:create --env=test --quiet', dirname(__DIR__)));
    }
}
