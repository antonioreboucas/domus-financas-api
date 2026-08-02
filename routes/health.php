<?php

// Endpoint público (sem autenticação) para checar se a API está no ar e
// conseguindo falar com o MySQL. Use para confirmar o deploy:
// GET https://SEU-DOMINIO/health

try {
    Database::get()->query('SELECT 1');
    Response::json([
        'status' => 'ok',
        'database' => 'connected',
        'time' => date('c'),
    ]);
} catch (Throwable $e) {
    Response::json([
        'status' => 'error',
        'database' => 'unreachable',
        'message' => $e->getMessage(),
    ], 500);
}
