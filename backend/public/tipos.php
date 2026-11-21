<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

function sendTiposJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readTiposJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (preg_match('/\Aapplication\/json(?:\s*;.*)?\z/i', trim($contentType)) !== 1) {
        sendTiposJson(415, ['error' => ['message' => 'El cuerpo debe enviarse como application/json.']]);
    }

    try {
        $body = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendTiposJson(400, ['error' => ['message' => 'El cuerpo de la solicitud no contiene JSON válido.']]);
    }

    if (!$body instanceof stdClass) {
        sendTiposJson(400, ['error' => ['message' => 'El cuerpo JSON debe ser un objeto.']]);
    }

    return get_object_vars($body);
}

function validateTipoData(array $input, string $method): array
{
    $allowedFields = ['nombre', 'descripcion'];
    $requiredFields = match ($method) {
        'POST' => ['nombre'],
        'PUT' => $allowedFields,
        default => [],
    };

    if ($method === 'PATCH' && $input === []) {
        sendTiposJson(400, ['error' => ['message' => 'Envía al menos un campo para actualizar.']]);
    }

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            sendTiposJson(422, ['error' => ['message' => sprintf('El campo "%s" no está permitido.', $field)]]);
        }
    }

    $missingFields = array_diff($requiredFields, array_keys($input));
    if ($missingFields !== []) {
        sendTiposJson(
            422,
            ['error' => ['message' => 'Faltan campos obligatorios: ' . implode(', ', $missingFields) . '.']]
        );
    }

    $validated = [];
    foreach ($input as $field => $value) {
        if ($field === 'nombre') {
            if (!is_string($value)) {
                sendTiposJson(422, ['error' => ['message' => 'El campo "nombre" debe ser texto.']]);
            }

            $value = trim($value);
            $characterCount = preg_match_all('/./us', $value, $matches);
            if ($value === '' || $characterCount === false) {
                sendTiposJson(422, ['error' => ['message' => 'El campo "nombre" no puede estar vacío.']]);
            }
            if ($characterCount > 50) {
                sendTiposJson(422, ['error' => ['message' => 'El campo "nombre" supera el máximo de 50 caracteres.']]);
            }
        } elseif ($value !== null && !is_string($value)) {
            sendTiposJson(422, ['error' => ['message' => 'El campo "descripcion" debe ser texto o null.']]);
        }

        $validated[$field] = $value;
    }

    if ($method === 'POST') {
        $validated['descripcion'] = $validated['descripcion'] ?? null;
    }

    return $validated;
}

function findTipo(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id, nombre, descripcion, fecha_creacion, fecha_actualizacion
         FROM tipos
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $tipo = $statement->fetch();

    return $tipo === false ? null : $tipo;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = explode('/', trim(rawurldecode((string) $requestPath), '/'));
if (count($segments) < 2 || $segments[0] !== 'api' || $segments[1] !== 'tipos' || count($segments) > 3) {
    sendTiposJson(404, ['error' => ['message' => 'Ruta no encontrada. Usa /api/tipos.']]);
}

$id = null;
if (isset($segments[2])) {
    if (!ctype_digit($segments[2]) || (int) $segments[2] < 1) {
        sendTiposJson(400, ['error' => ['message' => 'El identificador debe ser un entero positivo.']]);
    }

    $id = filter_var($segments[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendTiposJson(400, ['error' => ['message' => 'El identificador está fuera del rango permitido.']]);
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
    sendTiposJson(405, ['error' => ['message' => 'Método HTTP no permitido.']]);
}

if ($method === 'POST' && $id !== null) {
    sendTiposJson(405, ['error' => ['message' => 'POST solo se permite en /api/tipos.']]);
}

if ($method !== 'GET' && $method !== 'POST' && $id === null) {
    sendTiposJson(405, ['error' => ['message' => 'Esta operación requiere el identificador del registro.']]);
}

try {
    $connection = getDatabaseConnection();

    if ($method === 'GET') {
        if ($id !== null) {
            $tipo = findTipo($connection, $id);
            if ($tipo === null) {
                sendTiposJson(404, ['error' => ['message' => 'Tipo no encontrado.']]);
            }

            sendTiposJson(200, ['data' => $tipo]);
        }

        $statement = $connection->query(
            'SELECT id, nombre, descripcion, fecha_creacion, fecha_actualizacion
             FROM tipos
             ORDER BY id'
        );
        sendTiposJson(200, ['data' => $statement->fetchAll()]);
    }

    if ($method === 'POST') {
        $data = validateTipoData(readTiposJsonBody(), 'POST');
        $statement = $connection->prepare(
            'INSERT INTO tipos (nombre, descripcion)
             VALUES (:nombre, :descripcion)'
        );
        $statement->execute($data);

        sendTiposJson(201, ['data' => findTipo($connection, (int) $connection->lastInsertId())]);
    }

    if (findTipo($connection, $id) === null) {
        sendTiposJson(404, ['error' => ['message' => 'Tipo no encontrado.']]);
    }

    if ($method === 'DELETE') {
        $statement = $connection->prepare('DELETE FROM tipos WHERE id = :id');
        $statement->execute(['id' => $id]);
        http_response_code(204);
        exit;
    }

    $data = validateTipoData(readTiposJsonBody(), $method);
    $assignments = implode(', ', array_map(
        static fn (string $field): string => '`' . $field . '` = :' . $field,
        array_keys($data)
    ));
    $data['id'] = $id;
    $statement = $connection->prepare('UPDATE tipos SET ' . $assignments . ' WHERE id = :id');
    $statement->execute($data);

    sendTiposJson(200, ['data' => findTipo($connection, $id)]);
} catch (PDOException $exception) {
    error_log('Error en la API de tipos: ' . $exception->getMessage());
    $sqlState = (string) $exception->getCode();

    if (str_starts_with($sqlState, '23')) {
        sendTiposJson(409, ['error' => ['message' => 'El nombre ya existe o el tipo está relacionado con otros registros.']]);
    }

    sendTiposJson(500, ['error' => ['message' => 'Error interno al consultar la base de datos.']]);
}
