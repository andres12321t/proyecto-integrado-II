<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

function sendDirectorsJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readDirectorsJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (preg_match('/\Aapplication\/json(?:\s*;.*)?\z/i', trim($contentType)) !== 1) {
        sendDirectorsJson(415, ['error' => ['message' => 'El cuerpo debe enviarse como application/json.']]);
    }

    try {
        $body = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendDirectorsJson(400, ['error' => ['message' => 'El cuerpo de la solicitud no contiene JSON válido.']]);
    }

    if (!$body instanceof stdClass) {
        sendDirectorsJson(400, ['error' => ['message' => 'El cuerpo JSON debe ser un objeto.']]);
    }

    return get_object_vars($body);
}

function validateDirectorsData(array $input, string $method): array
{
    $allowedFields = ['nombres', 'estado'];
    $requiredFields = match ($method) {
        'POST' => ['nombres'],
        'PUT' => $allowedFields,
        default => [],
    };

    if ($method === 'PATCH' && $input === []) {
        sendDirectorsJson(400, ['error' => ['message' => 'Envía al menos un campo para actualizar.']]);
    }

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            sendDirectorsJson(
                422,
                ['error' => ['message' => sprintf('El campo "%s" no está permitido.', $field)]]
            );
        }
    }

    $missingFields = array_diff($requiredFields, array_keys($input));
    if ($missingFields !== []) {
        sendDirectorsJson(
            422,
            ['error' => ['message' => 'Faltan campos obligatorios: ' . implode(', ', $missingFields) . '.']]
        );
    }

    $validated = [];
    foreach ($input as $field => $value) {
        if ($field === 'nombres') {
            if (!is_string($value)) {
                sendDirectorsJson(422, ['error' => ['message' => 'El campo "nombres" debe ser texto.']]);
            }

            $value = trim($value);
            $characterCount = preg_match_all('/./us', $value, $matches);
            if ($value === '' || $characterCount === false) {
                sendDirectorsJson(422, ['error' => ['message' => 'El campo "nombres" no puede estar vacío.']]);
            }
            if ($characterCount > 150) {
                sendDirectorsJson(
                    422,
                    ['error' => ['message' => 'El campo "nombres" supera el máximo de 150 caracteres.']]
                );
            }
        } elseif (!is_string($value) || !in_array($value, ['Activo', 'Inactivo'], true)) {
            sendDirectorsJson(
                422,
                ['error' => ['message' => 'El campo "estado" debe ser "Activo" o "Inactivo".']]
            );
        }

        $validated[$field] = $value;
    }

    if ($method === 'POST') {
        $validated['estado'] = $validated['estado'] ?? 'Activo';
    }

    return $validated;
}

function findDirector(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id, nombres, estado, fecha_creacion, fecha_actualizacion
         FROM directores
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $director = $statement->fetch();

    return $director === false ? null : $director;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = explode('/', trim(rawurldecode((string) $requestPath), '/'));
if (count($segments) < 2 || $segments[0] !== 'api' || $segments[1] !== 'directores' || count($segments) > 3) {
    sendDirectorsJson(404, ['error' => ['message' => 'Ruta no encontrada. Usa /api/directores.']]);
}

$id = null;
if (isset($segments[2])) {
    if (!ctype_digit($segments[2]) || (int) $segments[2] < 1) {
        sendDirectorsJson(400, ['error' => ['message' => 'El identificador debe ser un entero positivo.']]);
    }

    $id = filter_var($segments[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendDirectorsJson(400, ['error' => ['message' => 'El identificador está fuera del rango permitido.']]);
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
    sendDirectorsJson(405, ['error' => ['message' => 'Método HTTP no permitido.']]);
}

if ($method === 'POST' && $id !== null) {
    sendDirectorsJson(405, ['error' => ['message' => 'POST solo se permite en /api/directores.']]);
}

if ($method !== 'GET' && $method !== 'POST' && $id === null) {
    sendDirectorsJson(405, ['error' => ['message' => 'Esta operación requiere el identificador del registro.']]);
}

try {
    $connection = getDatabaseConnection();

    if ($method === 'GET') {
        if ($id !== null) {
            $director = findDirector($connection, $id);
            if ($director === null) {
                sendDirectorsJson(404, ['error' => ['message' => 'Director no encontrado.']]);
            }

            sendDirectorsJson(200, ['data' => $director]);
        }

        $statement = $connection->query(
            'SELECT id, nombres, estado, fecha_creacion, fecha_actualizacion
             FROM directores
             ORDER BY id'
        );
        sendDirectorsJson(200, ['data' => $statement->fetchAll()]);
    }

    if ($method === 'POST') {
        $data = validateDirectorsData(readDirectorsJsonBody(), 'POST');
        $statement = $connection->prepare(
            'INSERT INTO directores (nombres, estado)
             VALUES (:nombres, :estado)'
        );
        $statement->execute($data);

        sendDirectorsJson(201, ['data' => findDirector($connection, (int) $connection->lastInsertId())]);
    }

    if (findDirector($connection, $id) === null) {
        sendDirectorsJson(404, ['error' => ['message' => 'Director no encontrado.']]);
    }

    if ($method === 'DELETE') {
        $statement = $connection->prepare('DELETE FROM directores WHERE id = :id');
        $statement->execute(['id' => $id]);
        http_response_code(204);
        exit;
    }

    $data = validateDirectorsData(readDirectorsJsonBody(), $method);
    $assignments = implode(', ', array_map(
        static fn (string $field): string => '`' . $field . '` = :' . $field,
        array_keys($data)
    ));
    $data['id'] = $id;
    $statement = $connection->prepare('UPDATE directores SET ' . $assignments . ' WHERE id = :id');
    $statement->execute($data);

    sendDirectorsJson(200, ['data' => findDirector($connection, $id)]);
} catch (PDOException $exception) {
    error_log('Error en la API de directores: ' . $exception->getMessage());
    $sqlState = (string) $exception->getCode();

    if (str_starts_with($sqlState, '23')) {
        sendDirectorsJson(
            409,
            ['error' => ['message' => 'No se puede eliminar el director porque está relacionado con otros registros.']]
        );
    }

    sendDirectorsJson(500, ['error' => ['message' => 'Error interno al consultar la base de datos.']]);
}
