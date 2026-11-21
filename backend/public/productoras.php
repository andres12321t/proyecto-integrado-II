<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

function sendProductorasJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readProductorasJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (preg_match('/\Aapplication\/json(?:\s*;.*)?\z/i', trim($contentType)) !== 1) {
        sendProductorasJson(415, ['error' => ['message' => 'El cuerpo debe enviarse como application/json.']]);
    }

    try {
        $body = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendProductorasJson(400, ['error' => ['message' => 'El cuerpo de la solicitud no contiene JSON válido.']]);
    }

    if (!$body instanceof stdClass) {
        sendProductorasJson(400, ['error' => ['message' => 'El cuerpo JSON debe ser un objeto.']]);
    }

    return get_object_vars($body);
}

function validateProductoraData(array $input, string $method): array
{
    $allowedFields = ['nombre', 'slogan', 'descripcion', 'estado'];
    $requiredFields = match ($method) {
        'POST' => ['nombre'],
        'PUT' => $allowedFields,
        default => [],
    };

    if ($method === 'PATCH' && $input === []) {
        sendProductorasJson(400, ['error' => ['message' => 'Envía al menos un campo para actualizar.']]);
    }

    foreach ($input as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            sendProductorasJson(
                422,
                ['error' => ['message' => sprintf('El campo "%s" no está permitido.', $field)]]
            );
        }
    }

    $missingFields = array_diff($requiredFields, array_keys($input));
    if ($missingFields !== []) {
        sendProductorasJson(
            422,
            ['error' => ['message' => 'Faltan campos obligatorios: ' . implode(', ', $missingFields) . '.']]
        );
    }

    $maxLengths = ['nombre' => 100, 'slogan' => 255];
    $validated = [];
    foreach ($input as $field => $value) {
        if (isset($maxLengths[$field])) {
            if (!is_string($value)) {
                sendProductorasJson(
                    422,
                    ['error' => ['message' => sprintf('El campo "%s" debe ser texto.', $field)]]
                );
            }

            $value = trim($value);
            $characterCount = preg_match_all('/./us', $value, $matches);
            if ($characterCount === false || ($field === 'nombre' && $value === '')) {
                sendProductorasJson(
                    422,
                    ['error' => ['message' => sprintf('El campo "%s" no puede estar vacío.', $field)]]
                );
            }
            if ($characterCount > $maxLengths[$field]) {
                sendProductorasJson(
                    422,
                    ['error' => ['message' => sprintf(
                        'El campo "%s" supera el máximo de %d caracteres.',
                        $field,
                        $maxLengths[$field]
                    )]]
                );
            }
        } elseif ($field === 'descripcion') {
            if ($value !== null && !is_string($value)) {
                sendProductorasJson(
                    422,
                    ['error' => ['message' => 'El campo "descripcion" debe ser texto o null.']]
                );
            }
        } elseif (!is_string($value) || !in_array($value, ['Activo', 'Inactivo'], true)) {
            sendProductorasJson(
                422,
                ['error' => ['message' => 'El campo "estado" debe ser "Activo" o "Inactivo".']]
            );
        }

        $validated[$field] = $value;
    }

    if ($method === 'POST') {
        $validated['slogan'] = $validated['slogan'] ?? null;
        $validated['descripcion'] = $validated['descripcion'] ?? null;
        $validated['estado'] = $validated['estado'] ?? 'Activo';
    }

    return $validated;
}

function findProductora(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT id, nombre, slogan, descripcion, estado, fecha_creacion, fecha_actualizacion
         FROM productoras
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $productora = $statement->fetch();

    return $productora === false ? null : $productora;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = explode('/', trim(rawurldecode((string) $requestPath), '/'));
if (count($segments) < 2 || $segments[0] !== 'api' || $segments[1] !== 'productoras' || count($segments) > 3) {
    sendProductorasJson(404, ['error' => ['message' => 'Ruta no encontrada. Usa /api/productoras.']]);
}

$id = null;
if (isset($segments[2])) {
    if (!ctype_digit($segments[2]) || (int) $segments[2] < 1) {
        sendProductorasJson(400, ['error' => ['message' => 'El identificador debe ser un entero positivo.']]);
    }

    $id = filter_var($segments[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendProductorasJson(400, ['error' => ['message' => 'El identificador está fuera del rango permitido.']]);
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
    sendProductorasJson(405, ['error' => ['message' => 'Método HTTP no permitido.']]);
}

if ($method === 'POST' && $id !== null) {
    sendProductorasJson(405, ['error' => ['message' => 'POST solo se permite en /api/productoras.']]);
}

if ($method !== 'GET' && $method !== 'POST' && $id === null) {
    sendProductorasJson(405, ['error' => ['message' => 'Esta operación requiere el identificador del registro.']]);
}

try {
    $connection = getDatabaseConnection();

    if ($method === 'GET') {
        if ($id !== null) {
            $productora = findProductora($connection, $id);
            if ($productora === null) {
                sendProductorasJson(404, ['error' => ['message' => 'Productora no encontrada.']]);
            }

            sendProductorasJson(200, ['data' => $productora]);
        }

        $statement = $connection->query(
            'SELECT id, nombre, slogan, descripcion, estado, fecha_creacion, fecha_actualizacion
             FROM productoras
             ORDER BY id'
        );
        sendProductorasJson(200, ['data' => $statement->fetchAll()]);
    }

    if ($method === 'POST') {
        $data = validateProductoraData(readProductorasJsonBody(), 'POST');
        $statement = $connection->prepare(
            'INSERT INTO productoras (nombre, slogan, descripcion, estado)
             VALUES (:nombre, :slogan, :descripcion, :estado)'
        );
        $statement->execute($data);

        sendProductorasJson(201, ['data' => findProductora($connection, (int) $connection->lastInsertId())]);
    }

    if (findProductora($connection, $id) === null) {
        sendProductorasJson(404, ['error' => ['message' => 'Productora no encontrada.']]);
    }

    if ($method === 'DELETE') {
        $statement = $connection->prepare('DELETE FROM productoras WHERE id = :id');
        $statement->execute(['id' => $id]);
        http_response_code(204);
        exit;
    }

    $data = validateProductoraData(readProductorasJsonBody(), $method);
    $assignments = implode(', ', array_map(
        static fn (string $field): string => '`' . $field . '` = :' . $field,
        array_keys($data)
    ));
    $data['id'] = $id;
    $statement = $connection->prepare('UPDATE productoras SET ' . $assignments . ' WHERE id = :id');
    $statement->execute($data);

    sendProductorasJson(200, ['data' => findProductora($connection, $id)]);
} catch (PDOException $exception) {
    error_log('Error en la API de productoras: ' . $exception->getMessage());
    $sqlState = (string) $exception->getCode();

    if (str_starts_with($sqlState, '23')) {
        sendProductorasJson(
            409,
            ['error' => ['message' => 'El nombre ya existe o la productora está relacionada con otros registros.']]
        );
    }

    sendProductorasJson(500, ['error' => ['message' => 'Error interno al consultar la base de datos.']]);
}
