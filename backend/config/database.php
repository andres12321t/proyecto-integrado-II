<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $portValue = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_NAME') ?: 'iud_peliculas_db_ea1';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS');
    $password = $password === false ? '' : $password;
    $port = filter_var(
        $portValue,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );

    if ($port === false) {
        throw new RuntimeException('DB_PORT debe ser un puerto válido entre 1 y 65535.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $sslCa = getenv('DB_SSL_CA');

    if ($sslCa !== false && $sslCa !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $connection = new PDO($dsn, $username, $password, $options);

    return $connection;
}
