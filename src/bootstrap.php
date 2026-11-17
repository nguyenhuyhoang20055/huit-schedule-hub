<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $name = $_ENV['DB_NAME'] ?? 'huit_db';
    $user = $_ENV['DB_USER'] ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
