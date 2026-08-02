<?php

// Smoke tests sem framework (sem Composer/PHPUnit — mesma filosofia do
// resto do projeto, ver CLAUDE.md). Roda com "php tests/run.php" e sai com
// código != 0 se algo falhar, pra poder plugar num CI mais tarde. Cobre só
// funções puras de EntityController (sem tocar banco) via ReflectionMethod,
// já que elas são private static de propósito — o teste não deve forçar a
// visibilidade delas a mudar.

require_once __DIR__ . '/../lib/EntityRegistry.php';
require_once __DIR__ . '/../lib/EntityController.php';

$total = 0;
$failed = 0;

function callPrivate(string $method, array $args = [])
{
    // Sem setAccessible(true): a partir do PHP 8.1, ReflectionMethod::invoke()
    // já chama métodos private/protected normalmente (e o método ficou
    // deprecated no 8.5) — o projeto roda em PHP 8, então dispensa.
    $ref = new ReflectionMethod(EntityController::class, $method);
    return $ref->invoke(null, ...$args);
}

function check(string $label, $expected, $actual): void
{
    global $total, $failed;
    $total++;
    if ($expected === $actual) {
        echo "  OK  $label\n";
        return;
    }
    $failed++;
    echo "FALHA  $label\n";
    echo '       esperado: ' . var_export($expected, true) . "\n";
    echo '       obtido:   ' . var_export($actual, true) . "\n";
}

echo "== normalizeEmptyDates() ==\n";
check(
    'data vazia vira NULL',
    ['nome' => 'Leite', 'data_validade' => null],
    callPrivate('normalizeEmptyDates', [['nome' => 'Leite', 'data_validade' => ''], ['data_validade']])
);
check(
    'data preenchida não é alterada',
    ['data_validade' => '2026-12-31'],
    callPrivate('normalizeEmptyDates', [['data_validade' => '2026-12-31'], ['data_validade']])
);
check(
    'coluna que não é de data não é tocada mesmo vazia',
    ['nome' => ''],
    callPrivate('normalizeEmptyDates', [['nome' => ''], ['data_validade']])
);

echo "\n== decodeRow() ==\n";
check(
    'coluna marcada como JSON no schema é decodificada',
    ['itens' => ['a', 'b']],
    callPrivate('decodeRow', [['itens' => '["a","b"]'], ['itens']])
);
check(
    'coluna JSON no MariaDB (schema não marca, mas conteúdo parece array) é decodificada mesmo assim',
    ['itens' => [1, 2, 3]],
    callPrivate('decodeRow', [['itens' => '[1,2,3]'], []])
);
check(
    'string comum que não parece array JSON fica como está',
    ['nome' => 'Refrigerante 2L'],
    callPrivate('decodeRow', [['nome' => 'Refrigerante 2L'], []])
);
check(
    'JSON inválido não quebra, mantém a string original',
    ['itens' => '[não é json'],
    callPrivate('decodeRow', [['itens' => '[não é json'], ['itens']])
);

echo "\n== isListPayload() ==\n";
check('lista indexada numericamente é bulk', true, EntityController::isListPayload([['a' => 1], ['a' => 2]]));
check('objeto único (chaves string) não é bulk', false, EntityController::isListPayload(['a' => 1]));
check('array vazio não é bulk', false, EntityController::isListPayload([]));

echo "\n" . str_repeat('-', 40) . "\n";
$passed = $total - $failed;
echo "$total testes, $passed ok, $failed falhas\n";

exit($failed > 0 ? 1 : 0);
