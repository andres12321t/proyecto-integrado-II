<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$allowedOrigin = getenv('FRONTEND_ORIGIN');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($allowedOrigin !== false && $requestOrigin !== '' && hash_equals($allowedOrigin, $requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($requestPath === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok']);
    exit;
}

$routes = [
    'medias' => __DIR__ . '/medias.php',
    'generos' => __DIR__ . '/generos.php',
    'directores' => __DIR__ . '/directores.php',
    'productoras' => __DIR__ . '/productoras.php',
    'tipos' => __DIR__ . '/tipos.php',
];

foreach ($routes as $resource => $endpoint) {
    if (preg_match('#\A/api/' . preg_quote($resource, '#') . '(?:/|\z)#', (string) $requestPath) === 1) {
        require $endpoint;
        exit;
    }
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    ['error' => ['message' => 'Ruta no encontrada.']],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
