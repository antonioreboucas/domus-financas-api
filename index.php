<?php

// Alguns hosts deixam serialize_precision != -1 no php.ini, o que faz
// json_encode() imprimir a expansão decimal completa de um double (ex:
// 8.99 vira "8.9900000000000002...") em qualquer coluna JSON. -1 usa a
// representação mais curta que ainda faz round-trip exato — mesmo
// comportamento em qualquer host, independente da config dele.
ini_set('serialize_precision', -1);

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/Uuid.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/EntityRegistry.php';
require_once __DIR__ . '/lib/EntityController.php';
require_once __DIR__ . '/lib/Mailer.php';

$config = Config::load();

Response::cors($config['cors_allowed_origins']);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir));
}
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$rawBody = file_get_contents('php://input');
$body = $rawBody ? (json_decode($rawBody, true) ?? []) : [];

try {
    if ($path === '/health') {
        require __DIR__ . '/routes/health.php';
        exit;
    }

    if (str_starts_with($path, '/auth/')) {
        require __DIR__ . '/routes/auth.php';
        exit;
    }

    if (preg_match('#^/entities/([A-Za-z]+)(?:/([A-Za-z0-9-]+))?$#', $path, $entityMatches)) {
        require __DIR__ . '/routes/entities.php';
        exit;
    }

    // Ações específicas que não são CRUD genérico (ver routes/domus.php).
    $isDomusAction =
        ($method === 'PATCH' && preg_match('#^/accounts/[A-Za-z0-9-]+/status$#', $path)) ||
        ($method === 'POST' && preg_match('#^/goals/[A-Za-z0-9-]+/contribute$#', $path)) ||
        ($method === 'PATCH' && preg_match('#^/shared-expenses/[A-Za-z0-9-]+/toggle$#', $path)) ||
        ($method === 'GET' && $path === '/reports/monthly');
    if ($isDomusAction) {
        require __DIR__ . '/routes/domus.php';
        exit;
    }

    Response::error('Rota não encontrada', 404);
} catch (PDOException $e) {
    Response::error('Erro de banco de dados: ' . $e->getMessage(), 400);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 500);
}
