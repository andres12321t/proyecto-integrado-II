<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

function sendGenreJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readGenreJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (preg_match('/\Aapplication\/json(?:\s*;.*)?\z/i', trim($contentType)) !== 1) {
        sendGenreJson(415, ['error' => ['message' => 'El cuerpo debe enviarse como application/json.']]);
    }

    try {
        $body = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendGenreJson(400, ['error' => ['message' => 'El cuerpo de la solicitud no contiene JSON válido.']]);
    }

    if (!$body instanceof stdClass) {
        sendGenreJson(400, ['error' => ['message' => 'El cuerpo JSON debe ser un objeto.']]);
    }

    return get_object_vars($body);
}

function validateGenreData(array $input, string $method): array
{
    $allowedFields = ['nombre', 'estado', 'descripcion'];
    $requiredFields = $method === 'POST'
        ? ['nombre']
        : ($method === 'PUT' ? $allowedFields : []);

    if ($method === 'PATCH' && $input === []) {
        sendGenreJson(400, ['error' => ['message' => 'Envía al menos un campo para actualizar.']]);
    }

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            sendGenreJson(422, ['error' => ['message' => sprintf('El campo "%s" no está permitido.', $field)]]);
        }
    }

    $missingFields = array_diff($requiredFields, array_keys($input));
    if ($missingFields !== []) {
        sendGenreJson(
            422,
            ['error' => ['message' => 'Faltan campos obligatorios: ' . implode(', ', $missingFields) . '.']]
        );
    }

    $validated = [];
    foreach ($input as $field => $value) {
        if ($field === 'nombre') {
            if (!is_string($value)) {
                sendGenreJson(422, ['error' => ['message' => 'El campo "nombre" debe ser texto.']]);
            }

            $value = trim($value);
            $characterCount = preg_match_all('/./us', $value, $matches);
            if ($value === '' || $characterCount === false) {
                sendGenreJson(422, ['error' => ['message' => 'El campo "nombre" no puede estar vacío.']]);
            }
            if ($characterCount > 100) {
                sendGenreJson(422, ['error' => ['message' => 'El campo "nombre" supera el máximo de 100 caracteres.']]);
            }
        } elseif ($field === 'estado') {
            if (!is_string($value) || !in_array($value, ['Activo', 'Inactivo'], true)) {
                sendGenreJson(422, ['error' => ['message' => 'El campo "estado" debe ser "Activo" o "Inactivo".']]);
            }
        } elseif ($field === 'descripcion') {
            if ($value !== null && !is_string($value)) {
                sendGenreJson(422, ['error' => ['message' => 'El campo "descripcion" debe ser texto o null.']]);
            }
        }

        $validated[$field] = $value;
    }

    if ($method === 'POST') {
        $validated['estado'] = $validated['estado'] ?? 'Activo';
        $validated['descripcion'] = $validated['descripcion'] ?? null;
    }

    return $validated;
}

function findGenre(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id, nombre, estado, descripcion, fecha_creacion, fecha_actualizacion
         FROM generos
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $genre = $statement->fetch();

    return $genre === false ? null : $genre;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = explode('/', trim(rawurldecode((string) $requestPath), '/'));
if (count($segments) < 2 || $segments[0] !== 'api' || $segments[1] !== 'generos' || count($segments) > 3) {
    sendGenreJson(404, ['error' => ['message' => 'Ruta no encontrada. Usa /api/generos.']]);
}

$id = null;
if (isset($segments[2])) {
    if (!ctype_digit($segments[2]) || (int) $segments[2] < 1) {
        sendGenreJson(400, ['error' => ['message' => 'El identificador debe ser un entero positivo.']]);
    }

    $id = filter_var($segments[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendGenreJson(400, ['error' => ['message' => 'El identificador está fuera del rango permitido.']]);
    }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$allowedMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

if ($method === 'OPTIONS') {
    header('Allow: ' . $allowedMethods);
    http_response_code(204);
    exit;
}

if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    header('Allow: ' . $allowedMethods);
    sendGenreJson(405, ['error' => ['message' => 'Método HTTP no permitido.']]);
}

if ($method === 'POST' && $id !== null) {
    sendGenreJson(405, ['error' => ['message' => 'POST solo se permite en /api/generos.']]);
}

if ($method !== 'GET' && $method !== 'POST' && $id === null) {
    sendGenreJson(405, ['error' => ['message' => 'Esta operación requiere el identificador del registro.']]);
}

try {
    $connection = getDatabaseConnection();

    if ($method === 'GET') {
        if ($id !== null) {
            $genre = findGenre($connection, $id);
            if ($genre === null) {
                sendGenreJson(404, ['error' => ['message' => 'Género no encontrado.']]);
            }

            sendGenreJson(200, ['data' => $genre]);
        }

        $statement = $connection->query(
            'SELECT id, nombre, estado, descripcion, fecha_creacion, fecha_actualizacion
             FROM generos
             ORDER BY id'
        );
        sendGenreJson(200, ['data' => $statement->fetchAll()]);
    }

    if ($method === 'POST') {
        $data = validateGenreData(readGenreJsonBody(), 'POST');
        $statement = $connection->prepare(
            'INSERT INTO generos (nombre, estado, descripcion)
             VALUES (:nombre, :estado, :descripcion)'
        );
        $statement->execute($data);

        sendGenreJson(201, ['data' => findGenre($connection, (int) $connection->lastInsertId())]);
    }

    if (findGenre($connection, $id) === null) {
        sendGenreJson(404, ['error' => ['message' => 'Género no encontrado.']]);
    }

    if ($method === 'DELETE') {
        $statement = $connection->prepare('DELETE FROM generos WHERE id = :id');
        $statement->execute(['id' => $id]);
        http_response_code(204);
        exit;
    }

    $data = validateGenreData(readGenreJsonBody(), $method);
    $assignments = implode(', ', array_map(
        static fn (string $field): string => '`' . $field . '` = :' . $field,
        array_keys($data)
    ));
    $data['id'] = $id;
    $statement = $connection->prepare('UPDATE generos SET ' . $assignments . ' WHERE id = :id');
    $statement->execute($data);

    sendGenreJson(200, ['data' => findGenre($connection, $id)]);
} catch (PDOException $exception) {
    error_log('Error en la API de géneros: ' . $exception->getMessage());
    $sqlState = (string) $exception->getCode();

    if (str_starts_with($sqlState, '23')) {
        sendGenreJson(
            409,
            ['error' => ['message' => 'El nombre ya existe o el género está relacionado con otros registros.']]
        );
    }

    sendGenreJson(500, ['error' => ['message' => 'Error interno al consultar la base de datos.']]);
}
