<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

function sendJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (preg_match('/\Aapplication\/json(?:\s*;.*)?\z/i', trim($contentType)) !== 1) {
        sendJson(415, ['error' => ['message' => 'El cuerpo debe enviarse como application/json.']]);
    }

    try {
        $body = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        sendJson(400, ['error' => ['message' => 'El cuerpo de la solicitud no contiene JSON válido.']]);
    }

    if (!$body instanceof stdClass) {
        sendJson(400, ['error' => ['message' => 'El cuerpo JSON debe ser un objeto.']]);
    }

    return get_object_vars($body);
}

function validateMediaData(array $input, bool $partial, PDO $connection): array
{
    $fields = [
        'serial' => ['type' => 'string', 'max' => 50],
        'titulo' => ['type' => 'string', 'max' => 200],
        'sinopsis' => ['type' => 'string'],
        'url_pelicula' => ['type' => 'string', 'max' => 255],
        'imagen_portada' => ['type' => 'string', 'max' => 255],
        'ano_estreno' => ['type' => 'integer'],
        'genero_id' => ['type' => 'reference', 'table' => 'generos', 'active_only' => true],
        'director_id' => ['type' => 'reference', 'table' => 'directores', 'active_only' => true],
        'productora_id' => ['type' => 'reference', 'table' => 'productoras', 'active_only' => true],
        'tipo_id' => ['type' => 'reference', 'table' => 'tipos'],
    ];
    $required = array_keys($fields);

    if ($partial && $input === []) {
        sendJson(400, ['error' => ['message' => 'Envía al menos un campo para actualizar.']]);
    }

    foreach ($input as $field => $value) {
        if (!array_key_exists($field, $fields)) {
            sendJson(422, ['error' => ['message' => sprintf('El campo "%s" no está permitido.', $field)]]);
        }
    }

    if (!$partial) {
        $missing = array_diff($required, array_keys($input));
        if ($missing !== []) {
            sendJson(422, ['error' => ['message' => 'Faltan campos obligatorios: ' . implode(', ', $missing) . '.']]);
        }
    }

    $validated = [];

    foreach ($input as $field => $value) {
        $definition = $fields[$field];

        if ($definition['type'] === 'string') {
            if (!is_string($value)) {
                sendJson(422, ['error' => ['message' => sprintf('El campo "%s" debe ser texto.', $field)]]);
            }

            $value = trim($value);
            if ($value === '') {
                sendJson(422, ['error' => ['message' => sprintf('El campo "%s" no puede estar vacío.', $field)]]);
            }

            if (isset($definition['max'])) {
                $characterCount = preg_match_all('/./us', $value, $matches);
                if ($characterCount === false || $characterCount > $definition['max']) {
                    sendJson(422, ['error' => ['message' => sprintf(
                        'El campo "%s" supera el máximo de %d caracteres.',
                        $field,
                        $definition['max']
                    )]]);
                }
            }
        } elseif ($definition['type'] === 'integer') {
            if (!is_int($value) || $value < 1 || $value > 2147483647) {
                sendJson(422, ['error' => ['message' => sprintf('El campo "%s" debe ser un entero positivo.', $field)]]);
            }
        } else {
            if (!is_int($value) || $value < 1 || $value > 2147483647) {
                sendJson(422, ['error' => ['message' => sprintf('El campo "%s" debe ser un identificador entero positivo.', $field)]]);
            }

            $statement = $connection->prepare(
                'SELECT ' . (isset($definition['active_only']) ? 'estado' : '1') .
                ' FROM `' . $definition['table'] . '` WHERE id = :id'
            );
            $statement->execute(['id' => $value]);
            $referenceValue = $statement->fetchColumn();

            if ($referenceValue === false) {
                sendJson(422, ['error' => ['message' => sprintf('No existe el registro indicado en "%s".', $field)]]);
            }

            if (isset($definition['active_only']) && $referenceValue !== 'Activo') {
                sendJson(422, ['error' => ['message' => sprintf(
                    'El registro indicado en "%s" debe estar activo.',
                    $field
                )]]);
            }
        }

        $validated[$field] = $value;
    }

    return $validated;
}

function findMedia(PDO $connection, int $id): ?array
{
    $statement = $connection->prepare(
        'SELECT m.id, m.serial, m.titulo, m.sinopsis, m.url_pelicula, m.imagen_portada,
                m.ano_estreno, m.genero_id, g.nombre AS genero,
                m.director_id, d.nombres AS director,
                m.productora_id, p.nombre AS productora,
                m.tipo_id, t.nombre AS tipo,
                m.fecha_creacion, m.fecha_actualizacion
         FROM medias AS m
         INNER JOIN generos AS g ON g.id = m.genero_id
         INNER JOIN directores AS d ON d.id = m.director_id
         INNER JOIN productoras AS p ON p.id = m.productora_id
         INNER JOIN tipos AS t ON t.id = m.tipo_id
         WHERE m.id = :id'
    );
    $statement->execute(['id' => $id]);
    $media = $statement->fetch();

    return $media === false ? null : $media;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$segments = explode('/', trim(rawurldecode((string) $requestPath), '/'));

if (count($segments) < 2 || $segments[0] !== 'api' || $segments[1] !== 'medias' || count($segments) > 3) {
    sendJson(404, ['error' => ['message' => 'Ruta no encontrada. Usa /api/medias.']]);
}

$id = null;
if (isset($segments[2])) {
    if (!ctype_digit($segments[2]) || (int) $segments[2] < 1) {
        sendJson(400, ['error' => ['message' => 'El identificador debe ser un entero positivo.']]);
    }

    $id = filter_var($segments[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendJson(400, ['error' => ['message' => 'El identificador está fuera del rango permitido.']]);
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
    sendJson(405, ['error' => ['message' => 'Método HTTP no permitido.']]);
}

if ($method === 'POST' && $id !== null) {
    sendJson(405, ['error' => ['message' => 'POST solo se permite en /api/medias.']]);
}

if ($method !== 'GET' && $method !== 'POST' && $id === null) {
    sendJson(405, ['error' => ['message' => 'Esta operación requiere el identificador del registro.']]);
}

try {
    $connection = getDatabaseConnection();

    if ($method === 'GET') {
        if ($id !== null) {
            $media = findMedia($connection, $id);
            if ($media === null) {
                sendJson(404, ['error' => ['message' => 'Película no encontrada.']]);
            }

            sendJson(200, ['data' => $media]);
        }

        $statement = $connection->query(
            'SELECT m.id, m.serial, m.titulo, m.sinopsis, m.url_pelicula, m.imagen_portada,
                    m.ano_estreno, m.genero_id, g.nombre AS genero,
                    m.director_id, d.nombres AS director,
                    m.productora_id, p.nombre AS productora,
                    m.tipo_id, t.nombre AS tipo,
                    m.fecha_creacion, m.fecha_actualizacion
             FROM medias AS m
             INNER JOIN generos AS g ON g.id = m.genero_id
             INNER JOIN directores AS d ON d.id = m.director_id
             INNER JOIN productoras AS p ON p.id = m.productora_id
             INNER JOIN tipos AS t ON t.id = m.tipo_id
             ORDER BY m.id'
        );
        sendJson(200, ['data' => $statement->fetchAll()]);
    }

    if ($method === 'POST') {
        $data = validateMediaData(readJsonBody(), false, $connection);
        $columns = array_keys($data);
        $columnList = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
        $statement = $connection->prepare(
            'INSERT INTO medias (' . $columnList . ') VALUES (' . $placeholders . ')'
        );
        $statement->execute($data);

        sendJson(201, ['data' => findMedia($connection, (int) $connection->lastInsertId())]);
    }

    $existingMedia = findMedia($connection, $id);
    if ($existingMedia === null) {
        sendJson(404, ['error' => ['message' => 'Película no encontrada.']]);
    }

    if ($method === 'DELETE') {
        $statement = $connection->prepare('DELETE FROM medias WHERE id = :id');
        $statement->execute(['id' => $id]);
        http_response_code(204);
        exit;
    }

    $data = validateMediaData(readJsonBody(), $method === 'PATCH', $connection);

    $assignments = implode(', ', array_map(
        static fn (string $column): string => '`' . $column . '` = :' . $column,
        array_keys($data)
    ));
    $data['id'] = $id;
    $statement = $connection->prepare('UPDATE medias SET ' . $assignments . ' WHERE id = :id');
    $statement->execute($data);
    sendJson(200, ['data' => findMedia($connection, $id)]);
} catch (PDOException $exception) {
    error_log('Error en la API de películas: ' . $exception->getMessage());
    $sqlState = (string) $exception->getCode();

    if (str_starts_with($sqlState, '23')) {
        sendJson(409, ['error' => ['message' => 'La operación entra en conflicto con un registro existente o relacionado.']]);
    }

    sendJson(500, ['error' => ['message' => 'Error interno al consultar la base de datos.']]);
}
