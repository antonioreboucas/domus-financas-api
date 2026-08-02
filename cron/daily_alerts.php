<?php

// Gera os alertas descritos no CLAUDE.md ("Fase 2: job/rotina que gera esses
// alertas automaticamente"). Roda via Cron Job do cPanel, ex:
// 0 8 * * * php /home/usuario/domus-financas-api/cron/daily_alerts.php
// Acessa o banco diretamente (sem passar pela API HTTP).

if (!empty($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('Forbidden: este script só pode ser executado via CLI/cron.');
}

// Ver comentário equivalente em index.php.
ini_set('serialize_precision', -1);

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Uuid.php';
require_once __DIR__ . '/../lib/Mailer.php';

function fmtBRL($n): string
{
    return 'R$ ' . number_format((float) $n, 2, ',', '.');
}

// Upsert por (user_id, source_key) — não por título. O título muda todo dia
// ("vence em 2 dias" -> "vence em 1 dia"); a condição que gerou o alerta
// (essa conta, esse cartão, essa categoria) não. Dedupar por título faria
// esse mesmo alerta ser inserido de novo a cada execução. Retorna true só
// quando a linha é nova (pra decidir o que entra no email do dia).
function upsertAlert(PDO $db, string $userId, string $sourceKey, string $nivel, string $titulo, string $desc): bool
{
    $stmt = $db->prepare('SELECT id FROM alerts WHERE user_id = ? AND source_key = ?');
    $stmt->execute([$userId, $sourceKey]);
    $existing = $stmt->fetch();

    if ($existing) {
        $db->prepare('UPDATE alerts SET nivel = ?, titulo = ?, descricao = ? WHERE id = ?')
            ->execute([$nivel, $titulo, $desc, $existing['id']]);
        return false;
    }

    $db->prepare('INSERT INTO alerts (id, user_id, nivel, titulo, descricao, source_key) VALUES (?,?,?,?,?,?)')
        ->execute([Uuid::v4(), $userId, $nivel, $titulo, $desc, $sourceKey]);
    return true;
}

// Remove alertas cuja condição não é mais verdadeira (conta foi paga,
// categoria voltou a ficar dentro do limite, fatura já passou da janela de
// aviso) — sem isso, um alerta resolvido ficaria pra sempre na tela porque
// nada nunca o deleta. Só mexe em linhas com source_key (as geradas por
// este script); um alerta sem source_key nunca existe hoje, mas se um dia
// existir, fica de fora dessa limpeza de propósito.
function pruneResolvedAlerts(PDO $db, string $userId, array $currentKeys): void
{
    if (empty($currentKeys)) {
        $db->prepare('DELETE FROM alerts WHERE user_id = ? AND source_key IS NOT NULL')->execute([$userId]);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($currentKeys), '?'));
    $stmt = $db->prepare(
        "DELETE FROM alerts WHERE user_id = ? AND source_key IS NOT NULL AND source_key NOT IN ($placeholders)"
    );
    $stmt->execute([$userId, ...$currentKeys]);
}

$config = require __DIR__ . '/../config.php';
$db = Database::get();
$today = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

$users = $db->query('SELECT * FROM users WHERE ativo = 1')->fetchAll();
$processed = 0;
$totalNew = 0;

foreach ($users as $user) {
    $userId = $user['id'];
    $currentKeys = [];
    $newAlertTitles = [];

    // Contas atrasadas ou vencendo nos próximos 3 dias (mesmo corte de
    // "prioridade ALTA" usado no frontend — frontend/src/lib/format.js).
    $stmt = $db->prepare("SELECT * FROM accounts WHERE user_id = ? AND status = 'pendente'");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $account) {
        $dias = (int) (new DateTime($today))->diff(new DateTime($account['vencimento']))->format('%r%a');
        if ($dias < 0) {
            $key = "account:{$account['id']}:atrasado";
            $titulo = "{$account['nome']} atrasada";
            $desc = 'Venceu em ' . (new DateTime($account['vencimento']))->format('d/m') . ' — ' . fmtBRL($account['valor']) . ' ainda pendente.';
            $currentKeys[] = $key;
            if (upsertAlert($db, $userId, $key, 'alta', $titulo, $desc)) {
                $newAlertTitles[] = $titulo;
            }
        } elseif ($dias <= 3) {
            $key = "account:{$account['id']}:vencendo";
            $titulo = "{$account['nome']} vence em breve";
            $desc = 'Vence em ' . (new DateTime($account['vencimento']))->format('d/m') . ' — ' . fmtBRL($account['valor']) . '.';
            $currentKeys[] = $key;
            if (upsertAlert($db, $userId, $key, 'media', $titulo, $desc)) {
                $newAlertTitles[] = $titulo;
            }
        }
    }

    // Categorias de gasto acima do limite mensal.
    $stmt = $db->prepare('SELECT * FROM expense_categories WHERE user_id = ? AND gasto > limite');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $cat) {
        $key = "category:{$cat['id']}:limite";
        $titulo = "{$cat['nome']} acima do limite";
        $desc = 'Você já gastou ' . fmtBRL($cat['gasto']) . ' de um limite de ' . fmtBRL($cat['limite']) . '.';
        $currentKeys[] = $key;
        if (upsertAlert($db, $userId, $key, 'media', $titulo, $desc)) {
            $newAlertTitles[] = $titulo;
        }
    }

    // Faturas de cartão vencendo nos próximos 8 dias.
    $stmt = $db->prepare('SELECT * FROM cards WHERE user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $card) {
        $dias = (int) (new DateTime($today))->diff(new DateTime($card['vencimento']))->format('%r%a');
        if ($dias >= 0 && $dias <= 8) {
            $key = "card:{$card['id']}:fatura";
            $titulo = "Fatura {$card['nome']} chega em $dias dia(s)";
            $desc = 'Fatura atual de ' . fmtBRL($card['fatura']) . ' vence em ' . (new DateTime($card['vencimento']))->format('d/m') . '.';
            $currentKeys[] = $key;
            if (upsertAlert($db, $userId, $key, 'baixa', $titulo, $desc)) {
                $newAlertTitles[] = $titulo;
            }
        }
    }

    pruneResolvedAlerts($db, $userId, $currentKeys);

    if (count($newAlertTitles) > 0 && (int) $user['notificacoes'] === 1) {
        $html = '<h2>Alertas do Domus Finanças</h2><ul>';
        foreach ($newAlertTitles as $titulo) {
            $html .= '<li>' . htmlspecialchars($titulo) . '</li>';
        }
        $html .= '</ul><p>Acesse o app para ver os detalhes.</p>';
        try {
            Mailer::send($config, $user['email'], count($newAlertTitles) . ' novo(s) alerta(s) — Domus Finanças', $html);
        } catch (Throwable $e) {
            // best-effort — não interrompe o processamento dos outros usuários
        }
    }

    $totalNew += count($newAlertTitles);
    $processed++;
}

echo "OK: $processed usuário(s) processado(s), $totalNew alerta(s) novo(s)\n";
