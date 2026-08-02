<?php

// Inclui em escopo: $method, $body, $config, $entityMatches (definidos em index.php)

$entityName = $entityMatches[1];
$id = $entityMatches[2] ?? null;

// Toda entidade registrada é 'user_scoped' — leitura e escrita sempre
// exigem token válido (ver EntityController::scopeFilter()).
$currentUser = Auth::currentUser($config);

if ($method === 'GET' && $id === null) {
    $filter = isset($_GET['filter']) ? (json_decode($_GET['filter'], true) ?? []) : [];
    $sort = $_GET['sort'] ?? null;
    $limit = isset($_GET['limit']) && $_GET['limit'] !== '' ? (int) $_GET['limit'] : null;
    Response::json(EntityController::query($entityName, $filter, $sort, $limit, $currentUser));
} elseif ($method === 'GET' && $id !== null) {
    $row = EntityController::get($entityName, $id, $currentUser);
    if (!$row) {
        Response::error('Não encontrado', 404);
    }
    Response::json($row);
} elseif ($method === 'POST' && $id === null) {
    if (EntityController::isListPayload($body)) {
        Response::json(EntityController::bulkCreate($entityName, $body, $currentUser), 201);
    } else {
        Response::json(EntityController::create($entityName, $body, $currentUser), 201);
    }
} elseif ($method === 'PUT' && $id !== null) {
    Response::json(EntityController::update($entityName, $id, $body, $currentUser));
} elseif ($method === 'DELETE' && $id !== null) {
    EntityController::delete($entityName, $id, $currentUser);
    Response::json(['success' => true]);
} else {
    Response::error('Método não suportado', 405);
}
