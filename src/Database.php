<?php

declare(strict_types=1);

namespace Aura\Api;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Config::string('DB_HOST', '127.0.0.1');
        $port = Config::int('DB_PORT', 3306);
        $database = Config::string('DB_DATABASE', 'aura');
        $username = Config::string('DB_USERNAME', 'aura');
        $password = Config::string('DB_PASSWORD', '');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }
}

