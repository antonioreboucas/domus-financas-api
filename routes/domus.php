<?php

// Inclui em escopo: $path, $method, $body, $config (definidos em index.php)
// Ações que não são CRUD genérico — não cabem em /entities/{Entity} porque
// mudam um campo específico com uma regra de negócio (não é "substituir o
// registro inteiro"), ou agregam dados entre linhas (relatório mensal).

$currentUser = Auth::currentUser($config);

switch (true) {
    // PATCH /accounts/{id}/status  { status: "pago" | "pendente" }
    case $method === 'PATCH' && preg_match('#^/accounts/([A-Za-z0-9-]+)/status$#', $path, $m):
        $status = $body['status'] ?? '';
        if (!in_array($status, ['pendente', 'pago'], true)) {
            Response::error('status deve ser "pendente" ou "pago"', 400);
        }
        Response::json(EntityController::update('Account', $m[1], ['status' => $status], $currentUser));
        break;

    // POST /goals/{id}/contribute  { valor: number }
    case $method === 'POST' && preg_match('#^/goals/([A-Za-z0-9-]+)/contribute$#', $path, $m):
        $valor = (float) ($body['valor'] ?? 0);
        if ($valor <= 0) {
            Response::error('valor deve ser maior que zero', 400);
        }
        $goal = EntityController::get('Goal', $m[1], $currentUser);
        if (!$goal) {
            Response::error('Meta não encontrada', 404);
        }
        $atual = min((float) $goal['meta'], (float) $goal['atual'] + $valor);
        Response::json(EntityController::update('Goal', $m[1], ['atual' => $atual], $currentUser));
        break;

    // PATCH /shared-expenses/{id}/toggle  — inverte "dividido"
    case $method === 'PATCH' && preg_match('#^/shared-expenses/([A-Za-z0-9-]+)/toggle$#', $path, $m):
        $expense = EntityController::get('SharedExpense', $m[1], $currentUser);
        if (!$expense) {
            Response::error('Despesa compartilhada não encontrada', 404);
        }
        $dividido = ((int) $expense['dividido']) ? 0 : 1;
        Response::json(EntityController::update('SharedExpense', $m[1], ['dividido' => $dividido], $currentUser));
        break;

    // GET /reports/monthly?months=6 — soma de transactions por mês, últimos N meses (incl. o atual)
    case $method === 'GET' && $path === '/reports/monthly':
        Response::json(reportsMonthly($currentUser['id'], (int) ($_GET['months'] ?? 6)));
        break;

    default:
        Response::error('Rota não encontrada', 404);
}

function reportsMonthly(string $userId, int $months): array
{
    $months = max(1, min($months, 24));
    $monthLabels = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', '05' => 'Mai', '06' => 'Jun',
                    '07' => 'Jul', '08' => 'Ago', '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];

    // Anda pra trás a partir do mês atual pra montar as N chaves "YYYY-MM"
    // que devem aparecer no relatório, mesmo que um mês não tenha nenhuma
    // transação (fica com renda/gasto = 0 em vez de sumir da lista).
    $keys = [];
    $cursor = new DateTime('first day of this month');
    for ($i = $months - 1; $i >= 0; $i--) {
        $d = (clone $cursor)->modify("-$i months");
        $keys[$d->format('Y-m')] = ['mes' => $monthLabels[$d->format('m')], 'ano' => (int) $d->format('Y'), 'renda' => 0.0, 'gasto' => 0.0];
    }

    $oldest = array_key_first($keys) . '-01';
    $stmt = Database::get()->prepare(
        "SELECT DATE_FORMAT(data, '%Y-%m') AS ym,
                SUM(CASE WHEN valor > 0 THEN valor ELSE 0 END) AS renda,
                SUM(CASE WHEN valor < 0 THEN -valor ELSE 0 END) AS gasto
         FROM transactions
         WHERE user_id = :user_id AND data >= :oldest
         GROUP BY ym"
    );
    $stmt->execute([':user_id' => $userId, ':oldest' => $oldest]);

    foreach ($stmt->fetchAll() as $row) {
        if (isset($keys[$row['ym']])) {
            $keys[$row['ym']]['renda'] = (float) $row['renda'];
            $keys[$row['ym']]['gasto'] = (float) $row['gasto'];
        }
    }

    return array_values($keys);
}
