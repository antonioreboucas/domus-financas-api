<?php

// Mapeia os nomes de entidade usados pelo frontend (src/api/httpClient.js)
// para tabelas MySQL. Todas as entidades de dado financeiro são
// 'user_scoped': cada usuário só enxerga e só escreve nas próprias linhas
// (EntityController::scopeFilter() força user_id = usuário autenticado,
// ignorando qualquer user_id vindo do client).
class EntityRegistry
{
    private const MAP = [
        'Income' => ['table' => 'incomes', 'mode' => 'user_scoped'],
        'ExpenseCategory' => ['table' => 'expense_categories', 'mode' => 'user_scoped'],
        'Account' => ['table' => 'accounts', 'mode' => 'user_scoped'],
        'Card' => ['table' => 'cards', 'mode' => 'user_scoped'],
        'Goal' => ['table' => 'goals', 'mode' => 'user_scoped'],
        'Alert' => ['table' => 'alerts', 'mode' => 'user_scoped'],
        'Transaction' => ['table' => 'transactions', 'mode' => 'user_scoped'],
        'SharedExpense' => ['table' => 'shared_expenses', 'mode' => 'user_scoped'],
    ];

    public static function get(string $name): ?array
    {
        return self::MAP[$name] ?? null;
    }
}
