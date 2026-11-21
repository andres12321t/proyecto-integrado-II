<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/config/database.php';

try {
    $connection = getDatabaseConnection();
    $database = $connection->query('SELECT DATABASE()')->fetchColumn();

    echo sprintf("Conexión exitosa con la base de datos: %s\n", $database);
} catch (PDOException $exception) {
    error_log('Error al conectar con MySQL: ' . $exception->getMessage());
    fwrite(STDERR, "No se pudo conectar con MySQL. Revisa el servicio, las credenciales y el log de PHP.\n");
    exit(1);
}
